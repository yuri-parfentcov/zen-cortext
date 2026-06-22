<?php
/**
 * Live admin takeover of client chat sessions.
 *
 * Manages the lifecycle: invite → attach (AI pauses) → chat → detach (AI resumes).
 * Coordinates between the visitor's polling, the admin's polling, and the
 * existing stream_chat() path which checks is_attached() before calling Anthropic.
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

class Zen_Cortext_Takeover {

    /**
     * Attach an admin to a chat. Atomic — fails if another admin is already
     * attached. Sets admin_user_id + admin_attached_at on the chat row and
     * inserts an admin_attached event so the visitor's poller picks it up.
     */
    public static function attach($chat_uid, $user_id) {
        global $wpdb;
        $table = Zen_Cortext_Chats::table();
        $user_id = (int) $user_id;
        $user = get_userdata($user_id);
        if (!$user) return new WP_Error('zen_cortext_takeover', 'Invalid user.');

        $now = current_time('mysql');
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET admin_user_id = %d, admin_attached_at = %s, admin_detached_at = NULL
             WHERE chat_uid = %s AND admin_user_id IS NULL AND deleted_at IS NULL",
            $user_id, $now, $chat_uid
        ));

        if ($result === 0 || $result === false) {
            // Check if the same admin is already attached (idempotent case).
            $chat = Zen_Cortext_Chats::get_by_uid($chat_uid, true);
            if ($chat && (int) $chat['admin_user_id'] === $user_id) {
                return true; // Already attached by this admin.
            }
            return new WP_Error('zen_cortext_takeover', 'Another admin is already in this chat, or the chat does not exist.');
        }

        Zen_Cortext_Chat_Events::insert($chat_uid, 'admin_attached', array(
            'user_id'      => $user_id,
            'display_name' => $user->display_name,
        ), 'admin', $user_id);

        // Clear the invited_user_ids — the invite served its purpose.
        $wpdb->update($table, array('invited_user_ids' => ''), array('chat_uid' => $chat_uid));

        // No system message stored in messages JSON — the visitor gets notified
        // via the admin_attached event through polling. Storing it in messages
        // created permanent duplicates on every attach/detach cycle.

        return true;
    }

    /**
     * Detach the admin from a chat. Clears admin_user_id, sets admin_detached_at.
     * Only the currently-attached admin (or the auto-detach mechanism) can call this.
     * $auto = true when called from the heartbeat-timeout auto-detach path.
     */
    public static function detach($chat_uid, $user_id = null, $auto = false) {
        global $wpdb;
        $table = Zen_Cortext_Chats::table();
        $now = current_time('mysql');

        if ($user_id !== null) {
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET admin_user_id = NULL, admin_detached_at = %s
                 WHERE chat_uid = %s AND admin_user_id = %d",
                $now, $chat_uid, (int) $user_id
            ));
        } else {
            // Force-detach (auto-detach from heartbeat timeout).
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET admin_user_id = NULL, admin_detached_at = %s
                 WHERE chat_uid = %s AND admin_user_id IS NOT NULL",
                $now, $chat_uid
            ));
        }

        if ($result > 0) {
            $display_name = $user_id ? (get_userdata((int) $user_id)->display_name ?? 'Admin') : 'Admin';
            Zen_Cortext_Chat_Events::insert($chat_uid, 'admin_detached', array(
                'user_id'      => $user_id,
                'display_name' => $display_name,
            ), 'admin', $user_id);

            // No system message stored — visitor gets notified via the
            // admin_detached event through polling.
        }

        return true;
    }

    /**
     * Check if an admin is currently attached. Returns the admin WP user_id
     * or null. Also runs the auto-detach check (heartbeat timeout).
     */
    public static function is_attached($chat_uid) {
        $chat = Zen_Cortext_Chats::get_by_uid($chat_uid, true);
        if (!$chat || empty($chat['admin_user_id'])) return null;

        $admin_id = (int) $chat['admin_user_id'];

        // Auto-detach if admin's heartbeat is stale (> 15 minutes without any event).
        // Mobile browsers heavily throttle background tab timers — Android Chrome
        // can stretch 2s intervals to 60s+ or pause them entirely. 5 min was too
        // aggressive and caused false detach/re-attach cycles mid-conversation.
        $secs = Zen_Cortext_Chat_Events::seconds_since_last_admin_event($chat_uid, $admin_id);
        if ($secs > 900) {
            self::detach($chat_uid, $admin_id, true);
            return null;
        }

        return $admin_id;
    }

    /**
     * Admin sends a message to the visitor. Appends to the messages JSON
     * with role 'admin' and inserts a message_admin event.
     */
    public static function send_admin_message($chat_uid, $user_id, $content) {
        $user_id = (int) $user_id;
        $user = get_userdata($user_id);
        if (!$user) return new WP_Error('zen_cortext_takeover', 'Invalid user.');

        $content = trim((string) $content);
        if ($content === '') return new WP_Error('zen_cortext_takeover', 'Message cannot be empty.');

        $chat = Zen_Cortext_Chats::get_by_uid($chat_uid, true);
        if (!$chat) return new WP_Error('zen_cortext_takeover', 'Chat not found.');
        if ((int) $chat['admin_user_id'] !== $user_id) {
            return new WP_Error('zen_cortext_takeover', 'You are not attached to this chat.');
        }

        // Append admin message to the messages array.
        $messages = json_decode($chat['messages'], true);
        if (!is_array($messages)) $messages = array();
        $messages[] = array(
            'role'       => 'admin',
            'content'    => $content,
            'admin_name' => $user->display_name,
        );
        Zen_Cortext_Chats::set_messages_by_uid($chat_uid, $messages);

        // Insert event so the visitor's poller picks it up.
        Zen_Cortext_Chat_Events::insert($chat_uid, 'message_admin', array(
            'content'      => $content,
            'admin_name'   => $user->display_name,
        ), 'admin', $user_id);

        return true;
    }

    /**
     * Record a visitor message event (for the admin's poller). Called from
     * the /send endpoint when admin is attached and AI is suppressed.
     * The message itself is already saved to the chat row by the /send handler.
     */
    public static function record_visitor_message($chat_uid, $content) {
        Zen_Cortext_Chat_Events::insert($chat_uid, 'message_visitor', array(
            'content' => (string) $content,
        ), 'visitor', null);
    }

    /**
     * Invite an admin to a chat. Updates invited_user_ids, inserts event,
     * and sends notifications.
     */
    public static function invite($chat_uid, $user_id) {
        global $wpdb;
        $user_id = (int) $user_id;
        $user = get_userdata($user_id);
        if (!$user) return new WP_Error('zen_cortext_takeover', 'Invalid user.');

        // Check this user is in the invitable list.
        $invitable = self::get_invitable_users();
        $invitable_ids = wp_list_pluck($invitable, 'ID');
        if (!in_array($user_id, array_map('intval', $invitable_ids), true)) {
            return new WP_Error('zen_cortext_takeover', 'This user is not available for chat.');
        }

        // Rate limit: 1 invite per user per chat per 5 minutes.
        $rate_key = 'zen_cortext_invite_' . md5($chat_uid . '_' . $user_id);
        if (get_transient($rate_key)) {
            return new WP_Error('zen_cortext_takeover', 'This person was already invited recently. Please wait.');
        }
        set_transient($rate_key, 1, 300);

        // Append to invited_user_ids (comma-separated).
        $chat = Zen_Cortext_Chats::get_by_uid($chat_uid, true);
        if (!$chat) return new WP_Error('zen_cortext_takeover', 'Chat not found.');

        $existing = array_filter(array_map('intval', explode(',', $chat['invited_user_ids'])));
        if (!in_array($user_id, $existing, true)) {
            $existing[] = $user_id;
            $wpdb->update(Zen_Cortext_Chats::table(), array(
                'invited_user_ids' => implode(',', $existing),
            ), array('id' => (int) $chat['id']));
        }

        Zen_Cortext_Chat_Events::insert($chat_uid, 'admin_invited', array(
            'user_id'      => $user_id,
            'display_name' => $user->display_name,
        ), 'visitor', null);

        // Prepare preview for the notification.
        $messages = json_decode($chat['messages'], true);
        $preview = '';
        if (is_array($messages)) {
            foreach ($messages as $m) {
                if (isset($m['role'], $m['content']) && $m['role'] === 'user') {
                    $preview = mb_substr((string) $m['content'], 0, 200);
                    break;
                }
            }
        }

        self::notify_invite($chat_uid, $user_id, $preview);

        return true;
    }

    /**
     * Send invitation notifications (email + push).
     */
    private static function notify_invite($chat_uid, $user_id, $preview) {
        $user = get_userdata($user_id);
        if (!$user) return;

        $site_name = get_bloginfo('name');

        // Link directly to the specific chat in the livechat page.
        $livechat_url = home_url('/zen-livechat/?open_chat=' . urlencode($chat_uid));
        // Push notification also uses this URL so tapping it opens the right chat.
        $push_url = $livechat_url;

        $subject = sprintf('[%s] You\'ve been invited to a live chat', $site_name);
        $body = '<html><body style="font-family:sans-serif;font-size:14px;color:#333;">'
              . '<h2 style="color:#646B3A;">You\'ve been invited to a live chat</h2>'
              . '<p>A visitor on <strong>' . esc_html($site_name) . '</strong> would like to chat with you.</p>';
        if ($preview !== '') {
            $body .= '<blockquote style="border-left:3px solid #DBEB7E;padding:8px 14px;margin:16px 0;background:#f6f7ee;color:#333;">'
                   . esc_html($preview)
                   . '</blockquote>';
        }
        $body .= '<p><a href="' . esc_url($livechat_url) . '" style="display:inline-block;padding:12px 24px;background:#646B3A;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;">Open Live Chat</a></p>'
              . '<p style="color:#888;font-size:12px;margin-top:24px;">Chat ID: ' . esc_html($chat_uid) . '</p>'
              . '</body></html>';

        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($user->user_email, $subject, $body, $headers);

        // Push notification — delivers instantly if the admin has the PWA installed.
        if (class_exists('Zen_Cortext_Push')) {
            Zen_Cortext_Push::send(
                $user_id,
                'Live chat invitation',
                $preview !== '' ? $preview : 'A visitor wants to chat with you.',
                $push_url,
                'invite-' . $chat_uid
            );
        }
    }

    /* ================================================================
       User online/offline status
       ================================================================ */

    const META_STATUS    = 'zen_cortext_status';      // 'online', 'away', 'offline'
    const META_LAST_SEEN = 'zen_cortext_last_seen';   // unix timestamp
    const META_SCHEDULE  = 'zen_cortext_schedule';    // serialized schedule
    const AWAY_TIMEOUT   = 300;  // 5 min → auto-away
    const OFFLINE_TIMEOUT = 600; // 10 min → auto-offline (or → reachable, if push subscribed)
    const REACHABLE_MAX  = 604800; // 7 days — past this, treat push subs as stale

    /**
     * Admin manually sets their status (online/away/offline).
     */
    public static function set_user_status($user_id, $status) {
        $user_id = (int) $user_id;
        $allowed = array('online', 'away', 'offline');
        if (!in_array($status, $allowed, true)) $status = 'offline';
        update_user_meta($user_id, self::META_STATUS, $status);
        self::touch_last_seen($user_id);
        return true;
    }

    /**
     * Update the last_seen timestamp. Called on every authenticated REST
     * request from the admin livechat page — piggybacks on existing polls.
     */
    public static function touch_last_seen($user_id) {
        update_user_meta((int) $user_id, self::META_LAST_SEEN, time());
    }

    /**
     * Get the effective status for a user. Combines the manual status with
     * the last_seen timestamp to auto-degrade:
     *   - manual=online + last_seen < 5min → online
     *   - manual=online + last_seen 5-10min → away
     *   - manual=online + last_seen > 10min → reachable (if push subscribed) | offline
     *   - manual=away + last_seen ≤ 10min → away
     *   - manual=away + last_seen > 10min → reachable (if push subscribed) | offline
     *   - manual=offline → offline (no auto-promotion to reachable, even with push)
     *   - 'reachable' downgrades to 'offline' once last_seen is older than REACHABLE_MAX (7d).
     */
    public static function get_effective_status($user_id) {
        $user_id = (int) $user_id;
        $manual = get_user_meta($user_id, self::META_STATUS, true);
        if ($manual === '' || $manual === false) $manual = 'offline';
        if ($manual === 'offline') return 'offline';

        $last_seen = (int) get_user_meta($user_id, self::META_LAST_SEEN, true);
        if ($last_seen === 0) return 'offline';

        $age = time() - $last_seen;

        // Availability schedule: outside the configured hours/days we force
        // offline — but ONLY when the admin is not actively present. An admin
        // who is manually online/away AND still heartbeating (fresh last_seen)
        // has explicitly signalled "I am here right now", so that wins over the
        // calendar. The schedule resumes the moment they go idle / close the
        // app (heartbeat older than AWAY_TIMEOUT), which is its real purpose:
        // not advertising availability when nobody is actually watching.
        if (!self::is_within_schedule($user_id) && $age > self::AWAY_TIMEOUT) {
            return 'offline';
        }

        if ($age > self::OFFLINE_TIMEOUT) {
            if ($age > self::REACHABLE_MAX) return 'offline';
            $has_push = count(Zen_Cortext_Push::get_subscriptions($user_id)) > 0;
            return $has_push ? 'reachable' : 'offline';
        }
        if ($manual === 'away') return 'away';
        if ($age > self::AWAY_TIMEOUT) return 'away';
        return 'online';
    }

    /* ================================================================
       Per-user availability schedule (timezone + days + start/end time)
       ================================================================ */

    /**
     * Default schedule: disabled, browser-friendly defaults.
     * Days follow ISO-8601 (1=Mon … 7=Sun).
     */
    public static function default_schedule() {
        return array(
            'enabled' => false,
            'tz'      => 'UTC',
            'start'   => '09:00',
            'end'     => '17:00',
            'days'    => array(1, 2, 3, 4, 5),
        );
    }

    public static function get_schedule($user_id) {
        $raw = get_user_meta((int) $user_id, self::META_SCHEDULE, true);
        if (!is_array($raw)) return self::default_schedule();
        return array_merge(self::default_schedule(), $raw);
    }

    /**
     * Validate + persist a schedule. Returns the stored schedule or WP_Error.
     */
    public static function set_schedule($user_id, $schedule) {
        if (!is_array($schedule)) {
            return new WP_Error('zen_cortext_schedule', 'Schedule must be an object.');
        }

        $clean = self::default_schedule();
        $clean['enabled'] = !empty($schedule['enabled']);

        $tz = isset($schedule['tz']) ? (string) $schedule['tz'] : '';
        if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
            $clean['tz'] = $tz;
        }

        foreach (array('start', 'end') as $k) {
            if (isset($schedule[$k]) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $schedule[$k])) {
                $clean[$k] = (string) $schedule[$k];
            }
        }

        if (isset($schedule['days']) && is_array($schedule['days'])) {
            $days = array();
            foreach ($schedule['days'] as $d) {
                $d = (int) $d;
                if ($d >= 1 && $d <= 7) $days[$d] = true;
            }
            $clean['days'] = array_values(array_keys($days));
            sort($clean['days']);
        }

        update_user_meta((int) $user_id, self::META_SCHEDULE, $clean);
        return $clean;
    }

    /**
     * Returns true when:
     *   - schedule is disabled, or
     *   - now (in user's tz) falls inside a configured day+window.
     * Cross-midnight windows (end < start) are supported: the window starts
     * on each selected day at `start` and runs into the early hours of the
     * following day until `end`.
     */
    public static function is_within_schedule($user_id) {
        $sched = self::get_schedule($user_id);
        if (empty($sched['enabled'])) return true;
        if (empty($sched['days']))    return false;

        try {
            $tz = new DateTimeZone($sched['tz']);
        } catch (Exception $e) {
            return true; // fail open on bad tz config
        }

        $now   = new DateTime('now', $tz);
        $dow   = (int) $now->format('N'); // 1..7
        $hm    = $now->format('H:i');
        $start = $sched['start'];
        $end   = $sched['end'];

        if ($start === $end) {
            // 24h coverage on selected days.
            return in_array($dow, $sched['days'], true);
        }

        if ($start < $end) {
            return in_array($dow, $sched['days'], true) && $hm >= $start && $hm < $end;
        }

        // Cross-midnight: today's portion (>= start) OR yesterday's spillover (< end).
        $prev = $dow - 1; if ($prev < 1) $prev = 7;
        if (in_array($dow, $sched['days'], true)  && $hm >= $start) return true;
        if (in_array($prev, $sched['days'], true) && $hm <  $end)   return true;
        return false;
    }

    /**
     * Get the list of WP users marked as invitable. Returns array of WP_User objects.
     */
    public static function get_invitable_users() {
        $user_ids = get_option('zen_cortext_invitable_users', array());
        if (!is_array($user_ids) || empty($user_ids)) return array();
        $user_ids = array_values(array_filter(array_map('intval', $user_ids)));
        if (empty($user_ids)) return array();

        $users = array();
        foreach ($user_ids as $uid) {
            $user = get_userdata($uid);
            if ($user) $users[] = $user;
        }
        return $users;
    }

    /**
     * Auto-detach stale admin connections. Called from the visitor's poll
     * handler and/or a cron job. Checks the heartbeat timeout.
     */
    public static function auto_detach_stale($chat_uid) {
        $admin_id = self::is_attached($chat_uid); // is_attached already handles auto-detach
        return $admin_id === null;
    }

    /**
     * Append a system-level message to the chat (e.g., "Admin has joined",
     * "The AI consultant is back"). Stored as role 'assistant' with a
     * distinct prefix so it renders naturally in the visitor's chat.
     */
    private static function append_system_message($chat_uid, $text) {
        $chat = Zen_Cortext_Chats::get_by_uid($chat_uid, true);
        if (!$chat) return;

        $messages = json_decode($chat['messages'], true);
        if (!is_array($messages)) $messages = array();
        $messages[] = array(
            'role'    => 'assistant',
            'content' => '*' . $text . '*',
        );
        Zen_Cortext_Chats::set_messages_by_uid($chat_uid, $messages);
    }
}
