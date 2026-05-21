<?php
/**
 * Outbound webhooks: fire JSON POSTs to admin-configured endpoints when
 * chat lifecycle events happen (lead.captured, invite.sent, admin.joined /
 * admin.left, chat.started). Endpoints are stored in the WP option
 * `zen_cortext_webhooks` as an array of {id,label,url,events,enabled}.
 *
 * Delivery is fire-and-forget — `wp_remote_post` with `blocking => false`
 * so a slow target never stalls the visitor's request that triggered the
 * event. No queue, no retries, no delivery log: that was the explicit
 * design choice. Admins verify endpoint reachability with the "Send test"
 * button in the admin UI.
 *
 * Internal -> public event mapping:
 *   lead_captured  -> lead.captured
 *   admin_invited  -> invite.sent
 *   admin_attached -> admin.joined
 *   admin_detached -> admin.left
 *   chat_started   -> chat.started   (fired once by Chats::upsert on first INSERT)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zen_Cortext_Webhooks {

    const OPTION = 'zen_cortext_webhooks';

    /** Public webhook event names — what an admin selects per endpoint. */
    public static function event_catalog() {
        return array(
            'lead.captured' => array(
                'label'       => __('Lead captured', 'zen-cortext'),
                'description' => __('Visitor submitted the contact form (name + email + optional WhatsApp).', 'zen-cortext'),
            ),
            'invite.sent' => array(
                'label'       => __('Invite sent', 'zen-cortext'),
                'description' => __('Visitor paged a team member (live-agent call). The team member is being notified.', 'zen-cortext'),
            ),
            'admin.joined' => array(
                'label'       => __('Admin joined', 'zen-cortext'),
                'description' => __('Team member attached to the chat — AI is now paused, they are driving the conversation.', 'zen-cortext'),
            ),
            'admin.left' => array(
                'label'       => __('Admin left', 'zen-cortext'),
                'description' => __('Team member detached — AI resumes.', 'zen-cortext'),
            ),
            'chat.started' => array(
                'label'       => __('Chat started', 'zen-cortext'),
                'description' => __('Visitor sent their first message in a new session.', 'zen-cortext'),
            ),
            'session.started' => array(
                'label'       => __('Session started', 'zen-cortext'),
                'description' => __('Visitor arrived on the site and a new user session was minted (GA-style: new session after 30 min inactivity OR when attribution changes). Fires before the visitor opens chat — useful for tracking every attributed arrival, not just chat engagements.', 'zen-cortext'),
            ),
        );
    }

    /* ---------------- CRUD on the option ---------------- */

    public static function list_all() {
        $raw = get_option(self::OPTION, array());
        return is_array($raw) ? array_values($raw) : array();
    }

    public static function get($id) {
        $id = (string) $id;
        if ($id === '') return null;
        foreach (self::list_all() as $row) {
            if (isset($row['id']) && (string) $row['id'] === $id) return $row;
        }
        return null;
    }

    /**
     * Insert when $id is empty, update otherwise. Returns the stored row
     * or WP_Error on validation failure.
     */
    public static function save($id, $data) {
        $url = isset($data['url']) ? esc_url_raw(trim((string) $data['url'])) : '';
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return new WP_Error('zen_cortext_webhooks', __('A valid http(s) URL is required.', 'zen-cortext'));
        }
        $label  = isset($data['label'])  ? sanitize_text_field((string) $data['label']) : '';
        if ($label === '') $label = wp_parse_url($url, PHP_URL_HOST) ?: $url;

        $catalog = self::event_catalog();
        $events  = array();
        if (isset($data['events']) && is_array($data['events'])) {
            foreach ($data['events'] as $e) {
                $e = (string) $e;
                if (isset($catalog[$e])) $events[] = $e;
            }
            $events = array_values(array_unique($events));
        }

        $rows = self::list_all();
        $id   = (string) $id;
        $now  = current_time('mysql');

        if ($id !== '') {
            $found = false;
            foreach ($rows as $i => $row) {
                if (isset($row['id']) && (string) $row['id'] === $id) {
                    $rows[$i] = array(
                        'id'         => $id,
                        'label'      => $label,
                        'url'        => $url,
                        'events'     => $events,
                        'enabled'    => !empty($data['enabled']) ? 1 : 0,
                        'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : $now,
                        'updated_at' => $now,
                    );
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return new WP_Error('zen_cortext_webhooks', __('Endpoint not found.', 'zen-cortext'));
            }
        } else {
            $id = 'wh_' . wp_generate_password(12, false, false);
            $rows[] = array(
                'id'         => $id,
                'label'      => $label,
                'url'        => $url,
                'events'     => $events,
                'enabled'    => !empty($data['enabled']) ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            );
        }
        update_option(self::OPTION, $rows, false);
        return self::get($id);
    }

    public static function delete($id) {
        $id   = (string) $id;
        $rows = self::list_all();
        $out  = array();
        foreach ($rows as $row) {
            if (isset($row['id']) && (string) $row['id'] === $id) continue;
            $out[] = $row;
        }
        update_option(self::OPTION, $out, false);
        return true;
    }

    /* ---------------- Event listener + dispatch ---------------- */

    /**
     * Translation table for internal Chat_Events event_type -> public
     * webhook event name. Unmapped types (heartbeat, message_visitor,
     * message_admin, blocked) silently no-op — future analytics
     * subscribers can read them straight off `zen_cortext_chat_event`.
     */
    private static function event_map() {
        return array(
            'lead_captured'  => 'lead.captured',
            'admin_invited'  => 'invite.sent',
            'admin_attached' => 'admin.joined',
            'admin_detached' => 'admin.left',
            'chat_started'   => 'chat.started',
        );
    }

    /**
     * Subscriber for `zen_cortext_chat_event`. Maps the internal event
     * to a public one, builds the per-event payload, and hands it to
     * fire().
     */
    public static function on_chat_event($chat_uid, $event_type, $payload, $sender_type, $sender_id) {
        $chat_uid   = (string) $chat_uid;
        $event_type = (string) $event_type;
        $map        = self::event_map();
        if (!isset($map[$event_type])) return;
        $public = $map[$event_type];
        $data   = self::build_event_data($public, $chat_uid, is_array($payload) ? $payload : array());
        if ($data !== null) self::fire($public, $data);
    }

    /**
     * Assemble the event-specific `data` block. Returns null if a
     * required lookup fails (e.g. orphan chat_uid). Every event payload
     * carries a `session` block when the chat is attached to a session
     * row — null when the chat predates the sessions layer or the row
     * was hard-deleted.
     */
    private static function build_event_data($public_event, $chat_uid, $internal_payload) {
        $chat = class_exists('Zen_Cortext_Chats')
            ? Zen_Cortext_Chats::get_by_uid($chat_uid, true)
            : null;
        $attribution = self::attribution_from_chat($chat);
        $session     = class_exists('Zen_Cortext_Sessions')
            ? Zen_Cortext_Sessions::summary_for_chat($chat)
            : null;

        switch ($public_event) {
            case 'lead.captured':
                $messages = self::transcript_from_chat($chat);
                return array(
                    'chat_uid'        => $chat_uid,
                    'name'            => (string) ($internal_payload['name']     ?? ($chat['lead_name']     ?? '')),
                    'email'           => (string) ($internal_payload['email']    ?? ($chat['lead_email']    ?? '')),
                    'whatsapp'        => (string) ($internal_payload['whatsapp'] ?? ($chat['lead_whatsapp'] ?? '')),
                    'submitted_at'    => (string) ($chat['lead_submitted_at'] ?? current_time('mysql')),
                    'chat_admin_url'  => home_url('/zen-livechat/?open_chat=' . rawurlencode($chat_uid)),
                    'messages'        => $messages,
                    'attribution'     => $attribution,
                    'session'         => $session,
                );

            case 'invite.sent':
                $invited_user_id = (int) ($internal_payload['user_id'] ?? 0);
                return array(
                    'chat_uid'        => $chat_uid,
                    'invited_user'    => self::user_summary($invited_user_id, (string) ($internal_payload['display_name'] ?? '')),
                    'visitor_preview' => self::visitor_preview_from_chat($chat),
                    'attribution'     => $attribution,
                    'session'         => $session,
                );

            case 'admin.joined':
            case 'admin.left':
                $user_id = (int) ($internal_payload['user_id'] ?? 0);
                return array(
                    'chat_uid'    => $chat_uid,
                    'user'        => self::user_summary($user_id, (string) ($internal_payload['display_name'] ?? '')),
                    'attribution' => $attribution,
                    'session'     => $session,
                );

            case 'chat.started':
                return array(
                    'chat_uid'      => $chat_uid,
                    'first_message' => self::visitor_preview_from_chat($chat, 2000),
                    'attribution'   => $attribution,
                    'session'       => $session,
                );
        }
        return null;
    }

    /**
     * Subscriber for `zen_cortext_session_started`. Fires the public
     * `session.started` webhook event for every freshly-minted session
     * row — chat may or may not happen later. Payload mirrors the
     * `session` block embedded in chat-tied events for shape consistency.
     */
    public static function on_session_started($session_uid, $attribution, $rule_id, $enriched) {
        $session_uid = (string) $session_uid;
        if ($session_uid === '') return;
        if (!class_exists('Zen_Cortext_Sessions')) return;

        $row = Zen_Cortext_Sessions::get_by_uid($session_uid);
        if (!$row) return;

        $session_block = Zen_Cortext_Sessions::shape_summary($row);
        $data = array(
            'session_uid' => $session_uid,
            'enriched'    => !empty($enriched),
            'attribution' => is_array($attribution) && !empty($attribution) ? $attribution : new stdClass(),
            'rule_id'     => $rule_id !== null ? (int) $rule_id : null,
            'session'     => $session_block,
        );
        self::fire('session.started', $data);
    }

    private static function attribution_from_chat($chat) {
        if (!is_array($chat)) return new stdClass();
        $keys = array('referrer','landing_page','utm_source','utm_medium','utm_campaign','utm_term','utm_content','gclid','msclkid','fbc','fbp');
        $out = array();
        foreach ($keys as $k) {
            $v = (string) ($chat[$k] ?? '');
            if ($v !== '') $out[$k] = $v;
        }
        return empty($out) ? new stdClass() : $out;
    }

    private static function transcript_from_chat($chat) {
        if (!is_array($chat) || empty($chat['messages'])) return array();
        $decoded = json_decode((string) $chat['messages'], true);
        return is_array($decoded) ? $decoded : array();
    }

    private static function visitor_preview_from_chat($chat, $max_chars = 200) {
        foreach (self::transcript_from_chat($chat) as $m) {
            if (!is_array($m)) continue;
            $role = (string) ($m['role'] ?? '');
            if ($role !== 'user' && $role !== 'visitor') continue;
            $content = trim((string) ($m['content'] ?? ''));
            if ($content === '') continue;
            if (function_exists('mb_substr') && function_exists('mb_strlen')) {
                if (mb_strlen($content) > $max_chars) $content = mb_substr($content, 0, $max_chars) . '…';
            } else {
                if (strlen($content) > $max_chars) $content = substr($content, 0, $max_chars) . '…';
            }
            return $content;
        }
        return '';
    }

    private static function user_summary($user_id, $fallback_name = '') {
        $user_id = (int) $user_id;
        if ($user_id > 0) {
            $u = get_userdata($user_id);
            if ($u) {
                return array(
                    'id'           => $user_id,
                    'display_name' => (string) $u->display_name,
                    'user_email'   => (string) $u->user_email,
                );
            }
        }
        return array(
            'id'           => $user_id > 0 ? $user_id : 0,
            'display_name' => $fallback_name,
            'user_email'   => '',
        );
    }

    /**
     * Public entry point for firing arbitrary events. Loops enabled
     * endpoints subscribed to $event_type and dispatches each. Safe to
     * call from anywhere (does nothing if no endpoints match).
     */
    public static function fire($event_type, $data) {
        $rows = self::list_all();
        if (empty($rows)) return;
        $payload = self::envelope($event_type, $data);
        foreach ($rows as $row) {
            if (empty($row['enabled'])) continue;
            $events = isset($row['events']) && is_array($row['events']) ? $row['events'] : array();
            if (!in_array($event_type, $events, true)) continue;
            self::dispatch_one($row, $payload);
        }
    }

    private static function envelope($event_type, $data) {
        return array(
            'event'       => $event_type,
            'delivery_id' => self::uuid4(),
            'occurred_at' => mysql2date('c', current_time('mysql'), false),
            'site'        => array(
                'url'  => home_url('/'),
                'name' => get_bloginfo('name'),
            ),
            'data'        => $data,
        );
    }

    private static function dispatch_one($endpoint, $payload) {
        $url = isset($endpoint['url']) ? (string) $endpoint['url'] : '';
        if ($url === '') return;
        wp_remote_post($url, array(
            'blocking' => false,
            'timeout'  => 2,
            'headers'  => array(
                'Content-Type'           => 'application/json',
                'X-Zen-Cortext-Event'    => (string) ($payload['event']       ?? ''),
                'X-Zen-Cortext-Delivery' => (string) ($payload['delivery_id'] ?? ''),
                'User-Agent'             => 'Zen-Cortext-Webhooks/' . (defined('ZEN_CORTEXT_VERSION') ? ZEN_CORTEXT_VERSION : 'dev'),
            ),
            'body'     => wp_json_encode($payload),
        ));
    }

    /**
     * Synchronous one-off used by the admin "Send test" button. Blocks
     * so the UI can show success/error; not used in the normal event
     * fan-out path. Returns array { ok:bool, status:int|null, error:string }.
     */
    public static function send_test($endpoint_id) {
        $row = self::get($endpoint_id);
        if (!$row) return array('ok' => false, 'status' => null, 'error' => 'Endpoint not found.');
        $payload = self::envelope('test.ping', array(
            'message' => 'This is a test ping from Zen Cortext.',
            'site'    => array(
                'url'  => home_url('/'),
                'name' => get_bloginfo('name'),
            ),
        ));
        $resp = wp_remote_post((string) $row['url'], array(
            'blocking' => true,
            'timeout'  => 10,
            'headers'  => array(
                'Content-Type'           => 'application/json',
                'X-Zen-Cortext-Event'    => 'test.ping',
                'X-Zen-Cortext-Delivery' => (string) $payload['delivery_id'],
                'User-Agent'             => 'Zen-Cortext-Webhooks/' . (defined('ZEN_CORTEXT_VERSION') ? ZEN_CORTEXT_VERSION : 'dev'),
            ),
            'body'     => wp_json_encode($payload),
        ));
        if (is_wp_error($resp)) {
            return array('ok' => false, 'status' => null, 'error' => $resp->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        return array(
            'ok'     => $code >= 200 && $code < 300,
            'status' => $code,
            'error'  => $code >= 200 && $code < 300 ? '' : 'HTTP ' . $code,
        );
    }

    private static function uuid4() {
        $bytes = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40); // version 4
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80); // variant 10
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8)  . '-' . substr($hex, 8, 4)  . '-'
             . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-'
             . substr($hex, 20, 12);
    }
}
