<?php
/**
 * Visitor sessions (custom table wp_zen_cortext_sessions).
 *
 * A "session" is one browser visit, GA-style: a new session is minted on
 * arrival when the visitor has no active session OR when their attribution
 * changes mid-visit OR when their last_seen_at is older than 30 minutes.
 * Sessions sit ABOVE chats — a single session can have zero, one, or many
 * attached chats; a chat belongs to exactly one session (or none, for
 * pre-sessions legacy rows).
 *
 * Created from the public /session/beacon REST endpoint that chat.js fires
 * on every page load. Attribution columns mirror wp_zen_cortext_chats so
 * the same UTM/click-id/referrer fields are available in both layers.
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

class Zen_Cortext_Sessions {

    // GA-style inactivity timeout in seconds. A beacon arriving more than
    // this long after the previous beacon mints a fresh session.
    const INACTIVITY_TIMEOUT_SEC = 1800; // 30 minutes

    // Hard cap on the pageviews journey array we store per session, to
    // keep the JSON blob bounded for very long-lived browsing.
    const PAGEVIEWS_CAP = 50;

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'zen_cortext_sessions';
    }

    /**
     * Generate a 32-char unguessable public uid for the session. Same
     * shape as chat_uid for consistency.
     */
    public static function generate_uid() {
        return wp_generate_password(32, false, false);
    }

    /**
     * Whitelist + sanitize the incoming attribution payload to the same
     * shape Zen_Cortext_Rest::sanitize_attribution() uses for /send. Kept
     * here as a static so both the REST layer and any future caller can
     * normalize through one place.
     */
    public static function sanitize_attribution($attribution) {
        if (!is_array($attribution)) return array();
        $allowed = array(
            'referrer', 'landing_page',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'gclid', 'msclkid', 'fbc', 'fbp',
        );
        $clean = array();
        foreach ($allowed as $k) {
            if (isset($attribution[$k])) {
                $clean[$k] = sanitize_text_field((string) $attribution[$k]);
            }
        }
        return $clean;
    }

    /**
     * Core resolution: extend an existing active session OR mint a new one.
     *
     * Extend rules — all must hold:
     *   - client provided a session_uid that exists in our DB
     *   - the row's last_seen_at is within the 30-min window
     *   - the incoming attribution either matches the stored attribution
     *     on every populated field, or is entirely empty (a typical
     *     subsequent pageview within the same visit, no fresh UTMs)
     * Anything else → mint a new session row.
     *
     * Returns ['session_uid' => str, 'action' => 'created'|'extended'].
     */
    public static function beacon($attribution, $client_session_uid, $ip, $user_agent) {
        global $wpdb;
        $table = self::table();

        $attribution = self::sanitize_attribution($attribution);
        $client_session_uid = self::clean_uid((string) $client_session_uid);
        $now_mysql = current_time('mysql');
        $now_ts    = current_time('timestamp');
        $landing   = (string) ($attribution['landing_page'] ?? '');

        // Try to extend an existing row.
        if ($client_session_uid !== '') {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE session_uid = %s",
                $client_session_uid
            ), ARRAY_A);

            if ($existing) {
                $last_seen_ts = strtotime((string) $existing['last_seen_at']);
                $within_window = $last_seen_ts && ($now_ts - $last_seen_ts) <= self::INACTIVITY_TIMEOUT_SEC;
                $attribution_compatible = self::attribution_compatible($existing, $attribution);

                if ($within_window && $attribution_compatible) {
                    $pageviews = self::append_pageview(
                        (string) $existing['pageviews_json'],
                        $landing,
                        $now_mysql
                    );
                    $wpdb->update(
                        $table,
                        array(
                            'last_seen_at'   => $now_mysql,
                            'pageviews_json' => $pageviews,
                        ),
                        array('id' => (int) $existing['id'])
                    );
                    return array(
                        'session_uid' => (string) $existing['session_uid'],
                        'action'      => 'extended',
                    );
                }
            }
        }

        // Mint a new session row. When the client supplied a well-formed
        // uid (via navigator.sendBeacon, which can't read the response),
        // honour it so client and server agree on the uid without a
        // round-trip. Guard against collision with an existing row's
        // uid — fall back to server-minted if so (extremely unlikely
        // with 32 chars of crypto-random, but the UNIQUE constraint
        // would block the insert otherwise).
        $session_uid = '';
        if ($client_session_uid !== '' && strlen($client_session_uid) >= 16) {
            $collision = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE session_uid = %s",
                $client_session_uid
            ));
            if (!$collision) {
                $session_uid = $client_session_uid;
            }
        }
        if ($session_uid === '') {
            $session_uid = self::generate_uid();
        }
        $rule_id = null;
        if (class_exists('Zen_Cortext_Attribution')) {
            $matched = Zen_Cortext_Attribution::resolve($attribution);
            if (is_array($matched) && !empty($matched['id'])) {
                $rule_id = (int) $matched['id'];
            }
        }

        $enriched = self::is_enriched($attribution) ? 1 : 0;
        $pageviews = self::append_pageview('', $landing, $now_mysql);

        $row = array(
            'session_uid'    => $session_uid,
            'utm_source'     => self::truncate($attribution['utm_source']   ?? '', 255),
            'utm_medium'     => self::truncate($attribution['utm_medium']   ?? '', 255),
            'utm_campaign'   => self::truncate($attribution['utm_campaign'] ?? '', 255),
            'utm_term'       => self::truncate($attribution['utm_term']     ?? '', 255),
            'utm_content'    => self::truncate($attribution['utm_content']  ?? '', 255),
            'gclid'          => self::truncate($attribution['gclid']        ?? '', 255),
            'msclkid'        => self::truncate($attribution['msclkid']      ?? '', 255),
            'fbc'            => self::truncate($attribution['fbc']          ?? '', 255),
            'fbp'            => self::truncate($attribution['fbp']          ?? '', 255),
            'referrer'       => self::truncate($attribution['referrer']     ?? '', 2048),
            'landing_page'   => self::truncate($landing, 2048),
            'user_agent'     => self::truncate((string) $user_agent, 1024),
            'ip_hash'        => Zen_Cortext_Chats::hash_ip((string) $ip),
            'rule_id'        => $rule_id,
            'pageviews_json' => $pageviews,
            'chat_count'     => 0,
            'first_seen_at'  => $now_mysql,
            'last_seen_at'   => $now_mysql,
            'enriched'       => $enriched,
        );

        $ok = $wpdb->insert($table, $row);
        if ($ok === false) {
            return new WP_Error('zen_cortext_sessions', 'DB insert failed: ' . $wpdb->last_error);
        }

        // Fire a public action when a new session is born. Webhooks
        // subscribe to this and map it to the public session.started
        // event; future analytics listeners can plug into the same signal.
        // Sent attribution is the sanitized incoming payload (no PII).
        do_action(
            'zen_cortext_session_started',
            $session_uid,
            $attribution,
            $rule_id,
            $enriched
        );

        return array(
            'session_uid' => $session_uid,
            'action'      => 'created',
        );
    }

    /**
     * Attach an existing chat row to a session. Idempotent — only stamps
     * the chat row's session_uid when currently NULL/empty, and only bumps
     * the session's chat_count once per chat. Safe to call on every /send
     * because of the WHERE guard on the chat update.
     */
    public static function attach_chat($session_uid, $chat_uid) {
        global $wpdb;
        $session_uid = self::clean_uid((string) $session_uid);
        $chat_uid    = self::clean_uid((string) $chat_uid);
        if ($session_uid === '' || $chat_uid === '') return false;

        $chats_table = Zen_Cortext_Chats::table();
        // Only stamp the chat row when its session_uid is still empty —
        // first /send wins, later sends are no-ops.
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$chats_table}
             SET session_uid = %s
             WHERE chat_uid = %s
               AND (session_uid IS NULL OR session_uid = '')",
            $session_uid,
            $chat_uid
        ));

        if ($updated > 0) {
            $table = self::table();
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET chat_count = chat_count + 1,
                     last_seen_at = %s
                 WHERE session_uid = %s",
                current_time('mysql'),
                $session_uid
            ));
            return true;
        }
        return false;
    }

    /**
     * Single fetch by session_uid.
     */
    public static function get_by_uid($session_uid) {
        global $wpdb;
        $session_uid = self::clean_uid((string) $session_uid);
        if ($session_uid === '') return null;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE session_uid = %s",
            $session_uid
        ), ARRAY_A);
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d",
            (int) $id
        ), ARRAY_A);
    }

    /**
     * Hard-delete a session row by primary key. Sessions don't carry a
     * deleted_at column — they're attribution-event logs, not user-
     * authored content, so soft-delete adds no value. Chats stamped with
     * this session_uid become orphaned (their session_uid column still
     * holds the old value); Sessions::summary_for_chat returns null for
     * orphans, so /chats responses and the admin chat detail view
     * degrade gracefully — no cascade needed.
     */
    public static function delete($id) {
        global $wpdb;
        $id = (int) $id;
        if ($id <= 0) return new WP_Error('zen_cortext_sessions', 'Invalid id');
        $wpdb->delete(self::table(), array('id' => $id), array('%d'));
        return true;
    }

    /**
     * Paged admin list. Args:
     *   per_page (default 25), page (1+),
     *   search   (matches session_uid / utm_source / utm_campaign / gclid / landing_page / referrer),
     *   enriched_only (default TRUE — defaults to enriched-only because the
     *                  user-facing view is interesting only when attribution is present),
     *   has_chats (only sessions with at least one attached chat),
     *   rule_id   (filter to a specific attribution rule)
     * Returns ['rows', 'total', 'pages'].
     */
    public static function paged($args = array()) {
        global $wpdb;
        $table = self::table();

        $args = wp_parse_args($args, array(
            'per_page'      => 25,
            'page'          => 1,
            'search'        => '',
            'enriched_only' => true,
            'has_chats'     => false,
            'rule_id'       => 0,
        ));
        $per_page = max(1, min(200, (int) $args['per_page']));
        $page     = max(1, (int) $args['page']);
        $offset   = ($page - 1) * $per_page;

        $where  = '1=1';
        $params = array();

        if (!empty($args['enriched_only'])) {
            $where .= ' AND enriched = 1';
        }
        if (!empty($args['has_chats'])) {
            $where .= ' AND chat_count > 0';
        }
        if (!empty($args['rule_id'])) {
            $where .= ' AND rule_id = %d';
            $params[] = (int) $args['rule_id'];
        }
        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $where .= ' AND (session_uid LIKE %s OR utm_source LIKE %s OR utm_campaign LIKE %s OR gclid LIKE %s OR msclkid LIKE %s OR landing_page LIKE %s OR referrer LIKE %s)';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        if (!empty($params)) {
            $count_sql = $wpdb->prepare($count_sql, $params);
        }
        $total = (int) $wpdb->get_var($count_sql);

        $list_sql = "SELECT id, session_uid, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                            gclid, msclkid, fbc, fbp, referrer, landing_page, user_agent, ip_hash,
                            rule_id, chat_count, first_seen_at, last_seen_at, enriched
                     FROM {$table}
                     WHERE {$where}
                     ORDER BY last_seen_at DESC, id DESC
                     LIMIT %d OFFSET %d";
        $list_params = array_merge($params, array($per_page, $offset));
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, $list_params), ARRAY_A);

        return array(
            'rows'  => $rows ?: array(),
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $per_page)),
        );
    }

    /**
     * Headline numbers for the admin stats row.
     */
    public static function stats() {
        global $wpdb;
        $table = self::table();
        return array(
            'total'        => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'enriched'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE enriched = 1"),
            'with_chats'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE chat_count > 0"),
            'last_24h'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE last_seen_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
            'last_7d'      => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE last_seen_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
        );
    }

    /**
     * Fetch other sessions matching a given ip_hash, excluding one
     * session_uid (the "current" one shown elsewhere on the page). Used
     * by the chat-detail view to show the visitor's other visits — the
     * ip_hash is the only persistent identifier we have for a returning
     * visitor since sessions are intentionally standalone (no browser_id).
     * Capped at $limit rows newest-first to keep the view bounded.
     */
    public static function for_ip_hash($ip_hash, $exclude_session_uid = '', $limit = 20) {
        global $wpdb;
        $ip_hash = (string) $ip_hash;
        if ($ip_hash === '') return array();
        $table = self::table();
        $limit = max(1, min(100, (int) $limit));
        $exclude_session_uid = self::clean_uid((string) $exclude_session_uid);

        $sql = "SELECT id, session_uid, utm_source, utm_medium, utm_campaign,
                       gclid, msclkid, landing_page, rule_id, chat_count,
                       first_seen_at, last_seen_at, enriched
                FROM {$table}
                WHERE ip_hash = %s";
        $params = array($ip_hash);
        if ($exclude_session_uid !== '') {
            $sql .= " AND session_uid != %s";
            $params[] = $exclude_session_uid;
        }
        $sql .= " ORDER BY last_seen_at DESC, id DESC LIMIT %d";
        $params[] = $limit;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return $rows ?: array();
    }

    /**
     * Fetch every chat row that's been stamped with this session_uid.
     * Used by the expand-detail view to render the attached chats list.
     */
    public static function chats_for($session_uid) {
        global $wpdb;
        $session_uid = self::clean_uid((string) $session_uid);
        if ($session_uid === '') return array();
        $chats_table = Zen_Cortext_Chats::table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, chat_uid, message_count, lead_name, lead_email, lead_submitted_at,
                    admin_user_id, created_at, updated_at, deleted_at, messages
             FROM {$chats_table}
             WHERE session_uid = %s
             ORDER BY updated_at DESC, id DESC",
            $session_uid
        ), ARRAY_A);
        return $rows ?: array();
    }

    /* ---------------- helpers ---------------- */

    private static function clean_uid($uid) {
        $uid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $uid);
        if (strlen($uid) > 64) $uid = substr($uid, 0, 64);
        return $uid;
    }

    private static function truncate($s, $max) {
        $s = (string) $s;
        if ($s === '') return '';
        return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
    }

    /**
     * "Did this beacon carry any marketing attribution worth recording?"
     * We treat referrer as enriching too — a non-direct visit is interesting
     * even without UTM tags. Bare landing_page and user_agent don't count.
     */
    private static function is_enriched($attribution) {
        $signal_fields = array(
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'gclid', 'msclkid', 'fbc', 'fbp', 'referrer',
        );
        foreach ($signal_fields as $k) {
            $v = trim((string) ($attribution[$k] ?? ''));
            if ($v !== '') return true;
        }
        return false;
    }

    /**
     * Decide whether an incoming beacon's attribution is compatible with
     * an existing session row. Compatible = either the incoming has no
     * attribution signals (a within-visit pageview without fresh UTMs) OR
     * every populated incoming field matches what's already stored.
     * Mismatch on any signal field = start a new session.
     */
    private static function attribution_compatible($existing_row, $incoming_attribution) {
        $signal_fields = array(
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'gclid', 'msclkid', 'fbc', 'fbp',
        );
        $incoming_has_signal = false;
        foreach ($signal_fields as $k) {
            $v = trim((string) ($incoming_attribution[$k] ?? ''));
            if ($v !== '') { $incoming_has_signal = true; break; }
        }
        if (!$incoming_has_signal) return true;

        foreach ($signal_fields as $k) {
            $incoming = trim((string) ($incoming_attribution[$k] ?? ''));
            if ($incoming === '') continue;
            $stored = trim((string) ($existing_row[$k] ?? ''));
            if ($stored === '' || strcasecmp($stored, $incoming) !== 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Compact session summary keyed off a chat row's session_uid.
     * Returns null when the chat is unattached (legacy/pre-sessions) or
     * the session row was hard-deleted. Used by webhooks + public API
     * to surface session context alongside chat data without leaking
     * the full pageview journey or stored attribution duplication
     * (the chat row already carries its own attribution snapshot).
     */
    public static function summary_for_chat($chat) {
        if (!is_array($chat)) return null;
        $session_uid = isset($chat['session_uid']) ? trim((string) $chat['session_uid']) : '';
        if ($session_uid === '') return null;
        $row = self::get_by_uid($session_uid);
        if (!$row) return null;
        return self::shape_summary($row);
    }

    /**
     * Compact session block shared by webhooks + public API. Excludes
     * the pageview journey (large) and ip_hash (only ever useful inside
     * the admin UI where we trust the viewer).
     */
    public static function shape_summary($row) {
        if (!is_array($row)) return null;
        return array(
            'session_uid'   => (string) ($row['session_uid']   ?? ''),
            'first_seen_at' => (string) ($row['first_seen_at'] ?? ''),
            'last_seen_at'  => (string) ($row['last_seen_at']  ?? ''),
            'enriched'      => !empty($row['enriched']),
            'chat_count'    => (int) ($row['chat_count'] ?? 0),
            'rule_id'       => !empty($row['rule_id']) ? (int) $row['rule_id'] : null,
            'utm_source'    => (string) ($row['utm_source']   ?? ''),
            'utm_medium'    => (string) ($row['utm_medium']   ?? ''),
            'utm_campaign'  => (string) ($row['utm_campaign'] ?? ''),
            'utm_term'      => (string) ($row['utm_term']     ?? ''),
            'utm_content'   => (string) ($row['utm_content']  ?? ''),
            'gclid'         => (string) ($row['gclid']        ?? ''),
            'msclkid'       => (string) ($row['msclkid']      ?? ''),
            'referrer'      => (string) ($row['referrer']     ?? ''),
            'landing_page'  => (string) ($row['landing_page'] ?? ''),
        );
    }

    /**
     * Append one pageview entry {url, ts} to the journey JSON. Drops oldest
     * entries past PAGEVIEWS_CAP so the column stays bounded.
     */
    private static function append_pageview($existing_json, $url, $ts) {
        $arr = array();
        if ($existing_json !== '' && $existing_json !== null) {
            $decoded = json_decode((string) $existing_json, true);
            if (is_array($decoded)) $arr = $decoded;
        }
        $url = trim((string) $url);
        if ($url !== '') {
            $arr[] = array(
                'url' => self::truncate($url, 2048),
                'ts'  => (string) $ts,
            );
        }
        if (count($arr) > self::PAGEVIEWS_CAP) {
            $arr = array_slice($arr, -self::PAGEVIEWS_CAP);
        }
        return wp_json_encode($arr);
    }
}
