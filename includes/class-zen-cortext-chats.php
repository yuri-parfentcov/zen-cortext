<?php
/**
 * Saved client chat sessions (custom table wp_zen_cortext_chats).
 *
 * Captures the conversation transcript + marketing attribution (UTM, gclid,
 * fbc/fbp, msclkid, referrer, landing page) for every public chat session.
 * Each session has a public chat_uid that doubles as a share link slug so
 * the visitor can come back to their conversation later.
 */


/*
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 *
 * Justification: this file is a data-access layer for plugin-owned tables
 * (wp_zen_cortext_*). Each query is built around a $wpdb->prefix . 'zen_cortext_…'
 * table name, which cannot be passed via a %s placeholder ($wpdb->prepare does
 * not bind identifiers). Every user-controlled value in WHERE / VALUES /
 * SET clauses goes through $wpdb->prepare(). Admin analytics aggregates
 * (SUM / COUNT / CASE over plugin-owned tables) are real-time and not
 * candidates for the WP_Object_Cache.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zen_Cortext_Chats {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'zen_cortext_chats';
    }

    /**
     * Generate a 32-char unguessable public uid for the chat.
     */
    public static function generate_uid() {
        return wp_generate_password(32, false, false);
    }

    /**
     * Hash an IP with the WP auth salt so we can count uniques without
     * storing PII directly.
     */
    public static function hash_ip($ip) {
        $ip = (string) $ip;
        if ($ip === '') return '';
        $salt = defined('AUTH_SALT') ? AUTH_SALT : 'zen-cortext';
        return hash('sha256', $ip . '|' . $salt);
    }

    /**
     * Hash the visitor's owner_token with a domain-separated WP salt so
     * the DB row can verify ownership without storing the raw token. The
     * token itself stays only in the original visitor's localStorage.
     */
    public static function hash_owner_token($token) {
        $token = trim((string) $token);
        if ($token === '') return '';
        $salt = defined('AUTH_SALT') ? AUTH_SALT : 'zen-cortext';
        return hash('sha256', 'owner|' . $token . '|' . $salt);
    }

    /**
     * Verify a visitor's owner_token against the stored hash for a chat.
     * Legacy rows (empty hash) pre-date this check and stay unenforced —
     * we don't lock out chats that were created before owner tokens
     * shipped. Returns one of:
     *   'ok'        — chat exists and token matches (or row is legacy)
     *   'mismatch'  — chat exists with a stored hash that doesn't match
     *   'new'       — chat row doesn't exist yet (first message; caller
     *                 should create the row and persist this token)
     */
    public static function check_owner_token($uid, $token) {
        global $wpdb;
        $uid = (string) $uid;
        if ($uid === '') return 'mismatch';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT owner_token_hash FROM " . self::table() . " WHERE chat_uid = %s",
            $uid
        ));
        if (!$row) return 'new';

        $stored = (string) $row->owner_token_hash;
        if ($stored === '') return 'ok'; // legacy row — unenforced

        $given  = self::hash_owner_token($token);
        if ($given === '') return 'mismatch';
        return hash_equals($stored, $given) ? 'ok' : 'mismatch';
    }

    /**
     * Upsert a chat row by chat_uid. Attribution fields are only written when
     * the row is first created (or when the existing field is empty), so a
     * later visit without UTMs doesn't blank out the original attribution.
     *
     * $data keys:
     *   chat_uid (required)
     *   messages (array)
     *   referrer, landing_page, user_agent
     *   utm_source, utm_medium, utm_campaign, utm_term, utm_content
     *   gclid, msclkid, fbc, fbp
     *   ip
     */
    public static function upsert($data) {
        global $wpdb;

        $uid = isset($data['chat_uid']) ? trim((string) $data['chat_uid']) : '';
        if ($uid === '' || strlen($uid) > 64) {
            return new WP_Error('zen_cortext_chats', 'Invalid chat_uid');
        }

        $messages = isset($data['messages']) && is_array($data['messages']) ? $data['messages'] : array();
        $messages_json = wp_json_encode(self::sanitize_messages($messages));

        $owner_token      = isset($data['owner_token']) ? trim((string) $data['owner_token']) : '';
        $owner_token_hash = self::hash_owner_token($owner_token);

        $now = current_time('mysql');
        $table = self::table();
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id, deleted_at, owner_token_hash FROM {$table} WHERE chat_uid = %s", $uid));

        if ($existing) {
            // Soft-deleted rows are read-only — never overwrite them. The
            // visitor's local state may still reference the uid, but we
            // refuse to update so the admin's record stays intact.
            if (!empty($existing->deleted_at)) {
                return new WP_Error('zen_cortext_chats_deleted', 'Chat has been deleted');
            }

            // Build update set: messages always, attribution only if currently empty.
            $update = array(
                'messages'      => $messages_json,
                'message_count' => count($messages),
                'updated_at'    => $now,
            );

            // Backfill the owner_token_hash on legacy rows that pre-date
            // this check. Once set, it's immutable — never overwrite a
            // hash that's already there, even with a different token.
            if (empty($existing->owner_token_hash) && $owner_token_hash !== '') {
                $update['owner_token_hash'] = $owner_token_hash;
            }
            $wpdb->update($table, $update, array('id' => (int) $existing->id));

            // Backfill any attribution fields that were empty when the row
            // was first created.
            self::backfill_attribution((int) $existing->id, $data);
            return (int) $existing->id;
        }

        $row = array(
            'chat_uid'         => $uid,
            'owner_token_hash' => $owner_token_hash,
            'messages'         => $messages_json,
            'message_count' => count($messages),
            'referrer'      => self::truncate(self::pick($data, 'referrer'), 2048),
            'landing_page'  => self::truncate(self::pick($data, 'landing_page'), 2048),
            'user_agent'    => self::truncate(self::pick($data, 'user_agent'), 1024),
            'ip_hash'       => self::hash_ip(self::pick($data, 'ip')),
            'utm_source'    => self::truncate(self::pick($data, 'utm_source'), 255),
            'utm_medium'    => self::truncate(self::pick($data, 'utm_medium'), 255),
            'utm_campaign'  => self::truncate(self::pick($data, 'utm_campaign'), 255),
            'utm_term'      => self::truncate(self::pick($data, 'utm_term'), 255),
            'utm_content'   => self::truncate(self::pick($data, 'utm_content'), 255),
            'gclid'         => self::truncate(self::pick($data, 'gclid'), 255),
            'msclkid'       => self::truncate(self::pick($data, 'msclkid'), 255),
            'fbc'           => self::truncate(self::pick($data, 'fbc'), 255),
            'fbp'           => self::truncate(self::pick($data, 'fbp'), 255),
            'created_at'    => $now,
            'updated_at'    => $now,
        );

        $ok = $wpdb->insert($table, $row);
        if ($ok === false) {
            return new WP_Error('zen_cortext_chats', 'DB insert failed: ' . $wpdb->last_error);
        }
        // First-write hook — fires exactly once per chat (the existing-row
        // branch above returns before reaching here). Webhook subscribers
        // route this to the public chat.started event; future analytics
        // listeners can plug into the same signal.
        if (class_exists('Zen_Cortext_Chat_Events')) {
            Zen_Cortext_Chat_Events::insert($uid, 'chat_started', array(
                'message_count' => count($messages),
            ), 'system', null);
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Update only the messages array on an existing row (used by the
     * server-side stream capture, after the assistant response finishes).
     * No-op if the row is soft-deleted.
     */
    public static function set_messages_by_uid($uid, $messages) {
        global $wpdb;
        $table = self::table();

        // Don't touch soft-deleted rows.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, deleted_at FROM {$table} WHERE chat_uid = %s",
            $uid
        ));
        if (!$row || !empty($row->deleted_at)) return;

        $messages = is_array($messages) ? self::sanitize_messages($messages) : array();
        $wpdb->update(
            $table,
            array(
                'messages'      => wp_json_encode($messages),
                'message_count' => count($messages),
                'updated_at'    => current_time('mysql'),
            ),
            array('id' => (int) $row->id)
        );
    }

    /**
     * Fetch by chat_uid. By default, soft-deleted rows are excluded — that's
     * what the public replay endpoint wants. Pass $include_deleted = true
     * from the admin if you need to see deleted rows.
     */
    public static function get_by_uid($uid, $include_deleted = false) {
        global $wpdb;
        $sql = "SELECT * FROM " . self::table() . " WHERE chat_uid = %s";
        if (!$include_deleted) {
            $sql .= " AND deleted_at IS NULL";
        }
        return $wpdb->get_row($wpdb->prepare($sql, $uid), ARRAY_A);
    }

    /**
     * Soft-delete by public chat_uid (the user-facing delete button).
     * Idempotent — already-deleted rows return true silently.
     */
    public static function soft_delete_by_uid($uid) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM " . self::table() . " WHERE chat_uid = %s",
            $uid
        ));
        if (!$row) return new WP_Error('zen_cortext_chats', 'Chat not found');
        $wpdb->update(
            self::table(),
            array('deleted_at' => current_time('mysql')),
            array('id' => (int) $row->id)
        );
        return true;
    }

    /**
     * Restore a soft-deleted chat (admin-only).
     */
    public static function restore($id) {
        global $wpdb;
        $id = (int) $id;
        if ($id <= 0) return new WP_Error('zen_cortext_chats', 'Invalid id');
        $wpdb->update(self::table(), array('deleted_at' => null), array('id' => $id));
        return true;
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d",
            (int) $id
        ), ARRAY_A);
    }

    /**
     * Persist a captured lead onto the chat row. Requires at least
     * name+email. Chat must already exist — the row is created by
     * upsert() on the first /send call, so any real lead-capture flow
     * will have it. Returns the updated row or WP_Error.
     */
    public static function save_lead($uid, $name, $email, $whatsapp = '') {
        global $wpdb;
        $uid   = (string) $uid;
        $name  = trim(sanitize_text_field((string) $name));
        $email = trim(sanitize_email((string) $email));
        $whatsapp = trim(sanitize_text_field((string) $whatsapp));

        if ($name === '' || $email === '' || !is_email($email)) {
            return new WP_Error('zen_cortext_lead', 'Name and a valid email are required.');
        }

        $row = self::get_by_uid($uid, true);
        if (!$row) return new WP_Error('zen_cortext_lead', 'Chat not found.');

        $wpdb->update(
            self::table(),
            array(
                'lead_name'         => $name,
                'lead_email'        => $email,
                'lead_whatsapp'     => $whatsapp,
                'lead_submitted_at' => current_time('mysql'),
                'updated_at'        => current_time('mysql'),
            ),
            array('id' => (int) $row['id']),
            array('%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        return self::get((int) $row['id']);
    }

    /**
     * Quietly persist the visitor's email after a self-archive ("Email me a
     * copy" button) so subsequent renders of the form prefill it. Distinct
     * from save_lead() in two ways:
     *   - Only writes when lead_email is currently empty (never overwrites
     *     an explicit contact-form submission, which carries name + whatsapp
     *     metadata we don't want to clobber).
     *   - Does NOT trigger the lead-captured admin notification email.
     *     Self-archive is a visitor convenience, not a sales signal.
     * Returns true when a write happened, false otherwise.
     */
    public static function set_lead_email_if_empty($uid, $email) {
        global $wpdb;
        $email = trim(sanitize_email((string) $email));
        if ($email === '' || !is_email($email)) return false;

        $row = self::get_by_uid($uid, true);
        if (!$row) return false;
        if (!empty($row['lead_email'])) return false;

        $wpdb->update(
            self::table(),
            array(
                'lead_email' => $email,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => (int) $row['id']),
            array('%s', '%s'),
            array('%d')
        );
        return true;
    }

    public static function delete($id) {
        global $wpdb;
        $id = (int) $id;
        if ($id <= 0) return new WP_Error('zen_cortext_chats', 'Invalid id');
        $wpdb->delete(self::table(), array('id' => $id), array('%d'));
        return true;
    }

    /**
     * Paged list for the admin UI.
     * $args: per_page, page, search, has_utm
     */
    public static function paged($args = array()) {
        global $wpdb;
        $table = self::table();

        $args = wp_parse_args($args, array(
            'per_page'     => 25,
            'page'         => 1,
            'search'       => '',
            'has_utm'      => false,
            'hide_deleted' => false,
        ));
        $per_page = max(1, min(200, (int) $args['per_page']));
        $page     = max(1, (int) $args['page']);
        $offset   = ($page - 1) * $per_page;

        $where  = '1=1';
        $params = array();

        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $where .= ' AND (chat_uid LIKE %s OR messages LIKE %s OR utm_source LIKE %s OR utm_campaign LIKE %s OR gclid LIKE %s OR msclkid LIKE %s)';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if (!empty($args['has_utm'])) {
            $where .= " AND (utm_source != '' OR utm_campaign != '' OR gclid != '' OR msclkid != '')";
        }
        if (!empty($args['hide_deleted'])) {
            $where .= ' AND deleted_at IS NULL';
        }

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        if (!empty($params)) {
            $count_sql = $wpdb->prepare($count_sql, $params);
        }
        $total = (int) $wpdb->get_var($count_sql);

        $list_sql = "SELECT id, chat_uid, message_count, referrer, landing_page,
                            admin_user_id, admin_attached_at, admin_detached_at, invited_user_ids,
                            utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                            gclid, msclkid, fbc, fbp, visitor_last_seen, created_at, updated_at, deleted_at, messages
                     FROM {$table}
                     WHERE {$where}
                     ORDER BY updated_at DESC, id DESC
                     LIMIT %d OFFSET %d";
        $list_params = array_merge($params, array($per_page, $offset));
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, $list_params), ARRAY_A);

        return array(
            'rows'  => $rows,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $per_page)),
        );
    }

    public static function stats() {
        global $wpdb;
        $table = self::table();
        return array(
            'total'         => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'active'        => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE deleted_at IS NULL"),
            'deleted'       => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE deleted_at IS NOT NULL"),
            'with_utm'      => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE deleted_at IS NULL AND (utm_source != '' OR utm_campaign != '')"),
            'with_gclid'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE deleted_at IS NULL AND gclid != ''"),
            'with_msclkid'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE deleted_at IS NULL AND msclkid != ''"),
            'multi_message' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE deleted_at IS NULL AND message_count >= 2"),
        );
    }

    /* ---------------- helpers ---------------- */

    private static function pick($data, $key) {
        return isset($data[$key]) ? (string) $data[$key] : '';
    }

    private static function truncate($s, $max) {
        $s = (string) $s;
        if ($s === '') return '';
        return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
    }

    private static function sanitize_messages($messages) {
        $clean = array();
        $allowed_roles = array('user', 'assistant', 'admin');
        foreach ($messages as $msg) {
            if (!is_array($msg)) continue;
            $role    = isset($msg['role']) ? (string) $msg['role'] : '';
            $content = isset($msg['content']) ? (string) $msg['content'] : '';
            if ($role === '' || $content === '') continue;
            if (!in_array($role, $allowed_roles, true)) continue;
            $entry = array(
                'role'    => $role,
                'content' => self::truncate($content, 20000),
            );
            // Preserve admin display name for attribution in admin views.
            if ($role === 'admin' && !empty($msg['admin_name'])) {
                $entry['admin_name'] = self::truncate((string) $msg['admin_name'], 100);
            }
            // Preserve Haiku enrichment on user messages when well-formed.
            // Silent drop on any validation failure — the rest of the
            // message still saves.
            if ($role === 'user' && isset($msg['enrichment']) && is_array($msg['enrichment'])) {
                $enrichment = self::sanitize_enrichment($msg['enrichment']);
                if ($enrichment !== null) {
                    $entry['enrichment'] = $enrichment;
                }
            }
            $clean[] = $entry;
        }
        return $clean;
    }

    /**
     * Validate a per-message enrichment record. Returns the sanitized
     * array, or null if the input is not a well-formed enrichment
     * record. Caller discards silently on null — enrichment is
     * telemetry, not load-bearing.
     */
    private static function sanitize_enrichment($raw) {
        if (!is_array($raw) || empty($raw)) return null;
        $allowed_keys = array(
            'intent', 'conversation_quality', 'urgency_to_action',
            'expertise_signal', 'classified_at',
        );
        $out = array();
        foreach ($allowed_keys as $k) {
            if (!array_key_exists($k, $raw)) continue;
            $v = $raw[$k];
            if (!is_scalar($v)) continue;
            $v = self::truncate((string) $v, 64);
            if ($v === '') continue;
            $out[$k] = $v;
        }
        // Require at least one classification field (intent / quality /
        // urgency / expertise). classified_at alone is not useful.
        $has_field = false;
        foreach (array('intent', 'conversation_quality', 'urgency_to_action', 'expertise_signal') as $k) {
            if (isset($out[$k])) { $has_field = true; break; }
        }
        return $has_field ? $out : null;
    }

    private static function backfill_attribution($id, $data) {
        global $wpdb;
        $existing = self::get($id);
        if (!$existing) return;

        $update = array();
        $fields = array(
            'referrer'     => 2048,
            'landing_page' => 2048,
            'user_agent'   => 1024,
            'utm_source'   => 255,
            'utm_medium'   => 255,
            'utm_campaign' => 255,
            'utm_term'     => 255,
            'utm_content'  => 255,
            'gclid'        => 255,
            'msclkid'      => 255,
            'fbc'          => 255,
            'fbp'          => 255,
        );
        foreach ($fields as $field => $max) {
            if (empty($existing[$field])) {
                $value = self::pick($data, $field);
                if ($value !== '') {
                    $update[$field] = self::truncate($value, $max);
                }
            }
        }
        if (empty($existing['ip_hash'])) {
            $hashed = self::hash_ip(self::pick($data, 'ip'));
            if ($hashed !== '') $update['ip_hash'] = $hashed;
        }

        if (!empty($update)) {
            $wpdb->update(self::table(), $update, array('id' => $id));
        }
    }
}
