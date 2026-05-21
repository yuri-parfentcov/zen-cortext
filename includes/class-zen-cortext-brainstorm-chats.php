<?php
/**
 * Saved admin Brainstorm sessions (custom table wp_zen_cortext_brainstorm_chats).
 *
 * Each row is a single brainstorm conversation owned by one WordPress user
 * (admin). Distinct from the visitor wp_zen_cortext_chats table — different
 * schema, different ownership semantics, no attribution / takeover state.
 *
 * Hard-deleted (no soft delete): admins own these fully and there's no
 * audit/legal reason to keep deleted history around.
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

class Zen_Cortext_Brainstorm_Chats {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'zen_cortext_brainstorm_chats';
    }

    public static function generate_uid() {
        return wp_generate_password(32, false, false);
    }

    /**
     * Create or update a brainstorm chat. Title is auto-derived from the
     * first user message on creation, never overwritten on update — so
     * subsequent turns don't keep renaming the chat in the sidebar.
     *
     * Returns true on success, WP_Error on failure.
     */
    public static function upsert($uid, $user_id, $messages) {
        global $wpdb;

        $uid     = self::sanitize_uid($uid);
        $user_id = (int) $user_id;
        if ($uid === '' || $user_id <= 0) {
            return new WP_Error('zen_cortext_brainstorm_chats', 'Invalid uid or user_id');
        }
        if (!is_array($messages)) {
            return new WP_Error('zen_cortext_brainstorm_chats', 'messages must be an array');
        }

        $messages       = self::sanitize_messages($messages);
        $messages_json  = wp_json_encode($messages);
        $now            = current_time('mysql');
        $table          = self::table();

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_id FROM {$table} WHERE uid = %s",
            $uid
        ));

        if ($existing) {
            // Hard ownership check — never let one admin overwrite another's chat.
            if ((int) $existing->user_id !== $user_id) {
                return new WP_Error('zen_cortext_brainstorm_chats', 'Chat is owned by another user');
            }
            $wpdb->update(
                $table,
                array(
                    'messages'      => $messages_json,
                    'message_count' => count($messages),
                    'updated_at'    => $now,
                ),
                array('id' => (int) $existing->id),
                array('%s', '%d', '%s'),
                array('%d')
            );
            return true;
        }

        $title = self::derive_title($messages);
        $ok    = $wpdb->insert(
            $table,
            array(
                'uid'           => $uid,
                'user_id'       => $user_id,
                'title'         => $title,
                'messages'      => $messages_json,
                'message_count' => count($messages),
                'created_at'    => $now,
                'updated_at'    => $now,
            ),
            array('%s', '%d', '%s', '%s', '%d', '%s', '%s')
        );
        if ($ok === false) {
            return new WP_Error('zen_cortext_brainstorm_chats', 'DB insert failed: ' . $wpdb->last_error);
        }
        return true;
    }

    /**
     * Fetch a single chat. Returns null if not found OR not owned by $user_id.
     */
    public static function get_for_user($uid, $user_id) {
        global $wpdb;
        $uid     = self::sanitize_uid($uid);
        $user_id = (int) $user_id;
        if ($uid === '' || $user_id <= 0) return null;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE uid = %s AND user_id = %d",
            $uid,
            $user_id
        ), ARRAY_A);
    }

    /**
     * List a user's most-recently-updated chats. Returns an array of
     * lightweight rows for the sidebar — no messages payload.
     */
    public static function list_for_user($user_id, $limit = 50) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ($user_id <= 0) return array();
        $limit = max(1, min(200, (int) $limit));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, uid, title, message_count, created_at, updated_at
             FROM " . self::table() . "
             WHERE user_id = %d
             ORDER BY updated_at DESC
             LIMIT %d",
            $user_id,
            $limit
        ), ARRAY_A);
    }

    /**
     * Hard-delete a chat. Ownership-checked. Returns true on success
     * (including idempotent "already gone"), WP_Error on ownership violation.
     */
    public static function delete_for_user($uid, $user_id) {
        global $wpdb;
        $uid     = self::sanitize_uid($uid);
        $user_id = (int) $user_id;
        if ($uid === '' || $user_id <= 0) {
            return new WP_Error('zen_cortext_brainstorm_chats', 'Invalid uid or user_id');
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_id FROM " . self::table() . " WHERE uid = %s",
            $uid
        ));
        if (!$row) return true; // already gone
        if ((int) $row->user_id !== $user_id) {
            return new WP_Error('zen_cortext_brainstorm_chats', 'Chat is owned by another user');
        }
        $wpdb->delete(self::table(), array('id' => (int) $row->id), array('%d'));
        return true;
    }

    /* ---------------- helpers ---------------- */

    public static function sanitize_uid($uid) {
        $uid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $uid);
        if (strlen($uid) > 64) $uid = substr($uid, 0, 64);
        return $uid;
    }

    /**
     * Trim messages to the storable shape: role in {user, assistant},
     * non-empty content. No length cap — brainstorm is admin-only; whatever
     * was sent to the model needs to round-trip through storage intact so
     * reloading the chat shows what actually happened.
     */
    public static function sanitize_messages($messages) {
        $clean = array();
        foreach ($messages as $m) {
            if (!is_array($m)) continue;
            $role    = isset($m['role']) ? (string) $m['role'] : '';
            $content = isset($m['content']) ? (string) $m['content'] : '';
            if ($content === '') continue;
            if ($role !== 'user' && $role !== 'assistant') continue;
            $clean[] = array(
                'role'    => $role,
                'content' => $content,
            );
        }
        return $clean;
    }

    /**
     * First user message, collapsed and truncated to ~80 chars, used as
     * the sidebar label. Falls back to a date string if no user message.
     */
    private static function derive_title($messages) {
        foreach ($messages as $m) {
            if (!is_array($m)) continue;
            if (isset($m['role'], $m['content']) && $m['role'] === 'user') {
                $text = trim((string) $m['content']);
                $text = preg_replace('/\s+/', ' ', $text);
                if ($text === '') continue;
                if (function_exists('mb_substr') && function_exists('mb_strlen')) {
                    if (mb_strlen($text) > 80) $text = mb_substr($text, 0, 77) . '…';
                } else {
                    if (strlen($text) > 80) $text = substr($text, 0, 77) . '…';
                }
                return $text;
            }
        }
        return 'Brainstorm ' . current_time('Y-m-d H:i');
    }
}
