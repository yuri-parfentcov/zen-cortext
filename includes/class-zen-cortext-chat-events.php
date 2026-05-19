<?php
/**
 * Chat event stream for real-time polling (table wp_zen_cortext_chat_events).
 *
 * Events are ephemeral — they exist so the visitor and admin can poll for
 * new messages / state changes. The canonical transcript is still the
 * `messages` JSON column on `wp_zen_cortext_chats`; events are a
 * notification sidecar, not a source of truth.
 *
 * Event types:
 *   message_visitor  — visitor sent a message (admin needs to see it)
 *   message_admin    — admin sent a message (visitor needs to see it)
 *   admin_attached   — admin took over the chat (AI paused)
 *   admin_detached   — admin released the chat (AI resumes)
 *   admin_invited    — visitor invited an admin
 *   heartbeat        — admin is still connected (for auto-detach)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zen_Cortext_Chat_Events {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'zen_cortext_chat_events';
    }

    /**
     * Insert an event.
     * $payload should be an associative array — it gets JSON-encoded.
     */
    public static function insert($chat_uid, $event_type, $payload = array(), $sender_type = '', $sender_id = null) {
        global $wpdb;
        $payload_arr = is_array($payload) ? $payload : array();
        $wpdb->insert(self::table(), array(
            'chat_uid'    => (string) $chat_uid,
            'event_type'  => (string) $event_type,
            'payload'     => wp_json_encode($payload_arr),
            'sender_type' => (string) $sender_type,
            'sender_id'   => $sender_id !== null ? (int) $sender_id : null,
            'created_at'  => current_time('mysql'),
        ));
        $insert_id = (int) $wpdb->insert_id;
        // Fan-out hook for outbound integrations (webhooks, etc.). Listeners
        // get every internal event — they decide which ones to expose. Heart-
        // beats and admin polling chatter are intentionally still fired here;
        // it's the listener's job to filter, not ours, so future subscribers
        // (analytics, audit log, …) don't miss anything.
        do_action('zen_cortext_chat_event', (string) $chat_uid, (string) $event_type, $payload_arr, (string) $sender_type, $sender_id);
        return $insert_id;
    }

    /**
     * Poll for events newer than $since_id. Returns array of rows ordered ASC.
     * Used by both visitor-side and admin-side pollers.
     */
    public static function poll($chat_uid, $since_id = 0) {
        global $wpdb;
        $since_id = max(0, (int) $since_id);
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, event_type, payload, sender_type, sender_id, created_at
             FROM " . self::table() . "
             WHERE chat_uid = %s AND id > %d
             ORDER BY id ASC
             LIMIT 100",
            $chat_uid, $since_id
        ), ARRAY_A);
    }

    /**
     * Seconds since the last event from a specific admin in a specific chat.
     * Used for heartbeat-based auto-detach.
     */
    public static function seconds_since_last_admin_event($chat_uid, $admin_user_id = null) {
        global $wpdb;
        $table = self::table();
        if ($admin_user_id !== null) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT created_at FROM {$table}
                 WHERE chat_uid = %s AND sender_type = 'admin' AND sender_id = %d
                 ORDER BY id DESC LIMIT 1",
                $chat_uid, (int) $admin_user_id
            ));
        } else {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT created_at FROM {$table}
                 WHERE chat_uid = %s AND sender_type = 'admin'
                 ORDER BY id DESC LIMIT 1",
                $chat_uid
            ));
        }
        if (!$row) return PHP_INT_MAX;
        return max(0, time() - strtotime($row->created_at));
    }

    /**
     * Check if a user has a pending invite to a specific chat.
     * Pending = the most recent admin_invited event for this user is newer
     * than the most recent admin_attached event by this user (or no attach exists).
     */
    public static function has_pending_invite($chat_uid, $user_id) {
        global $wpdb;
        $table = self::table();
        $user_id = (int) $user_id;
        $like = '%"user_id":' . $user_id . '%';

        // Most recent invite for this user.
        $last_invite = $wpdb->get_var($wpdb->prepare(
            "SELECT created_at FROM {$table}
             WHERE chat_uid = %s AND event_type = 'admin_invited' AND payload LIKE %s
             ORDER BY id DESC LIMIT 1",
            $chat_uid, $like
        ));
        if (!$last_invite) return false;

        // Most recent attach by this user.
        $last_attach = $wpdb->get_var($wpdb->prepare(
            "SELECT created_at FROM {$table}
             WHERE chat_uid = %s AND event_type = 'admin_attached' AND sender_id = %d
             ORDER BY id DESC LIMIT 1",
            $chat_uid, $user_id
        ));
        if (!$last_attach) return true; // invited but never attached

        return strtotime($last_invite) > strtotime($last_attach);
    }

    /**
     * Get the latest event ID for a chat. Used to seed the poll so it
     * doesn't replay the full history on first load.
     */
    public static function latest_id($chat_uid) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(id) FROM " . self::table() . " WHERE chat_uid = %s",
            $chat_uid
        ));
    }

    /**
     * Purge events older than $hours. Called by cron or on-demand.
     */
    public static function cleanup($hours = 48) {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($hours * 3600));
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM " . self::table() . " WHERE created_at < %s",
            $cutoff
        ));
    }
}
