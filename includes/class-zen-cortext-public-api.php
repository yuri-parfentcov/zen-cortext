<?php
/**
 * External read API for downstream integrations (CRM, BI, audit tooling).
 *
 * Namespace `zc/v1` is deliberately separate from the internal
 * `zen-cortext/v1` namespace used by visitor / livechat / Apps Script
 * ingest, so the external surface can evolve independently and its
 * auth model (multi-key bearer tokens with scopes) isn't entangled
 * with the visitor flow.
 *
 * All routes are GET, all are auth-gated via Zen_Cortext_Api_Keys,
 * and each route requires a specific scope:
 *   /chats              → read:chats
 *   /chats/{id}         → read:chats
 *   /chats/stats        → read:stats
 *   /leads              → read:leads
 *   /attribution-rules  → read:attribution
 *   /knowledge          → read:knowledge
 *
 * `outcome` is derived at query time (no schema change) from
 * existing lifecycle columns: lead_submitted_at, admin_*, deleted_at,
 * updated_at. See outcome_case_sql() for the full mapping.
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

class Zen_Cortext_Public_API {

    const NAMESPACE_V1 = 'zc/v1';
    const DEFAULT_LIMIT = 50;
    const MAX_LIMIT     = 200;

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        $with_scope = function ($scope) {
            return function () use ($scope) {
                $check = Zen_Cortext_Api_Keys::authenticate($scope);
                if ($check === true) return true;
                return $check; // WP_Error: REST layer turns into proper HTTP response
            };
        };

        register_rest_route(self::NAMESPACE_V1, '/chats', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_chats_list'),
            'permission_callback' => $with_scope('read:chats'),
        ));

        register_rest_route(self::NAMESPACE_V1, '/chats/stats', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_chats_stats'),
            'permission_callback' => $with_scope('read:stats'),
        ));

        register_rest_route(self::NAMESPACE_V1, '/chats/(?P<id>[A-Za-z0-9_-]+)', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_chats_detail'),
            'permission_callback' => $with_scope('read:chats'),
        ));

        register_rest_route(self::NAMESPACE_V1, '/leads', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_leads_list'),
            'permission_callback' => $with_scope('read:leads'),
        ));

        register_rest_route(self::NAMESPACE_V1, '/attribution-rules', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_attribution_rules'),
            'permission_callback' => $with_scope('read:attribution'),
        ));

        register_rest_route(self::NAMESPACE_V1, '/knowledge', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_knowledge'),
            'permission_callback' => $with_scope('read:knowledge'),
        ));

        register_rest_route(self::NAMESPACE_V1, '/sessions', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_sessions_list'),
            'permission_callback' => $with_scope('read:sessions'),
        ));

        register_rest_route(self::NAMESPACE_V1, '/sessions/(?P<id>[A-Za-z0-9_-]+)', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_sessions_detail'),
            'permission_callback' => $with_scope('read:sessions'),
        ));
    }

    /* ============================================================
       /chats list (filters + cursor pagination)
       ============================================================ */

    public function handle_chats_list($request) {
        $filters = $this->parse_chat_filters($request);
        if (is_wp_error($filters)) return $filters;

        $rows = $this->query_chats($filters, /*include_messages=*/ false);

        $limit       = $filters['limit'];
        $has_more    = count($rows) > $limit;
        if ($has_more) array_pop($rows);

        $next_cursor = null;
        if ($has_more && !empty($rows)) {
            $last = $rows[count($rows) - 1];
            $next_cursor = $this->encode_cursor($last['updated_at'], (int) $last['id']);
        }

        $data = array();
        foreach ($rows as $r) $data[] = $this->shape_chat_row($r, /*include_messages=*/ false);

        return $this->envelope($data, array(
            'next_cursor' => $next_cursor,
            'has_more'    => $has_more,
            'limit'       => $limit,
        ));
    }

    /* ============================================================
       /chats/{id} single chat (full transcript)
       ============================================================ */

    public function handle_chats_detail($request) {
        $id = (string) $request->get_param('id');
        $row = $this->lookup_chat_by_id_or_uid($id);
        if (!$row) {
            return new WP_Error('zc_not_found', __('Chat not found.', 'zen-cortext'), array('status' => 404));
        }
        return rest_ensure_response($this->shape_chat_row($row, /*include_messages=*/ true));
    }

    /* ============================================================
       /chats/stats — totals + by_utm_source + by_day
       ============================================================ */

    public function handle_chats_stats($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'zen_cortext_chats';

        $from = $this->parse_date($request->get_param('from_date'));
        $to   = $this->parse_date($request->get_param('to_date'));
        if (!$from) $from = gmdate('Y-m-d 00:00:00', strtotime('-29 days'));
        if (!$to)   $to   = gmdate('Y-m-d 23:59:59');

        $oc = self::outcome_case_sql();

        // Totals (one row, columns per outcome).
        $totals_sql = $wpdb->prepare(
            "SELECT
                SUM(CASE WHEN {$oc} = 'active'     THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN {$oc} = 'qualified'  THEN 1 ELSE 0 END) AS qualified,
                SUM(CASE WHEN {$oc} = 'handoff'    THEN 1 ELSE 0 END) AS handoff,
                SUM(CASE WHEN {$oc} = 'resolved'   THEN 1 ELSE 0 END) AS resolved,
                SUM(CASE WHEN {$oc} = 'abandoned'  THEN 1 ELSE 0 END) AS abandoned
             FROM {$table}
             WHERE created_at BETWEEN %s AND %s",
            $from, $to
        );
        $totals_row = $wpdb->get_row($totals_sql, ARRAY_A) ?: array();

        // By utm_source.
        $by_src_sql = $wpdb->prepare(
            "SELECT utm_source,
                COUNT(*) AS total,
                SUM(CASE WHEN {$oc} = 'qualified' THEN 1 ELSE 0 END) AS qualified,
                SUM(CASE WHEN {$oc} = 'handoff'   THEN 1 ELSE 0 END) AS handoff,
                SUM(CASE WHEN {$oc} = 'resolved'  THEN 1 ELSE 0 END) AS resolved,
                SUM(CASE WHEN {$oc} = 'abandoned' THEN 1 ELSE 0 END) AS abandoned
             FROM {$table}
             WHERE created_at BETWEEN %s AND %s
             GROUP BY utm_source
             ORDER BY total DESC
             LIMIT 100",
            $from, $to
        );
        $by_src = $wpdb->get_results($by_src_sql, ARRAY_A) ?: array();

        // By day.
        $by_day_sql = $wpdb->prepare(
            "SELECT DATE(created_at) AS date,
                COUNT(*) AS total,
                SUM(CASE WHEN {$oc} = 'qualified' THEN 1 ELSE 0 END) AS qualified
             FROM {$table}
             WHERE created_at BETWEEN %s AND %s
             GROUP BY DATE(created_at)
             ORDER BY DATE(created_at) ASC",
            $from, $to
        );
        $by_day = $wpdb->get_results($by_day_sql, ARRAY_A) ?: array();

        return rest_ensure_response(array(
            'window' => array(
                'from' => substr($from, 0, 10),
                'to'   => substr($to,   0, 10),
            ),
            'totals' => array(
                'active'    => (int) ($totals_row['active']    ?? 0),
                'qualified' => (int) ($totals_row['qualified'] ?? 0),
                'handoff'   => (int) ($totals_row['handoff']   ?? 0),
                'resolved'  => (int) ($totals_row['resolved']  ?? 0),
                'abandoned' => (int) ($totals_row['abandoned'] ?? 0),
            ),
            'by_utm_source' => array_map(function ($r) {
                return array(
                    'utm_source' => (string) $r['utm_source'],
                    'total'      => (int)    $r['total'],
                    'qualified'  => (int)    $r['qualified'],
                    'handoff'    => (int)    $r['handoff'],
                    'resolved'   => (int)    $r['resolved'],
                    'abandoned'  => (int)    $r['abandoned'],
                );
            }, $by_src),
            'by_day' => array_map(function ($r) {
                return array(
                    'date'      => (string) $r['date'],
                    'total'     => (int)    $r['total'],
                    'qualified' => (int)    $r['qualified'],
                );
            }, $by_day),
        ));
    }

    /* ============================================================
       /leads — only rows with a submitted lead
       ============================================================ */

    public function handle_leads_list($request) {
        $filters = $this->parse_chat_filters($request);
        if (is_wp_error($filters)) return $filters;
        $filters['_force_lead_present'] = true;

        $rows = $this->query_chats($filters, /*include_messages=*/ true);

        $limit    = $filters['limit'];
        $has_more = count($rows) > $limit;
        if ($has_more) array_pop($rows);

        $next_cursor = null;
        if ($has_more && !empty($rows)) {
            $last = $rows[count($rows) - 1];
            $next_cursor = $this->encode_cursor($last['updated_at'], (int) $last['id']);
        }

        $data = array();
        foreach ($rows as $r) $data[] = $this->shape_chat_row($r, /*include_messages=*/ true);

        return $this->envelope($data, array(
            'next_cursor' => $next_cursor,
            'has_more'    => $has_more,
            'limit'       => $limit,
        ));
    }

    /* ============================================================
       /attribution-rules
       ============================================================ */

    public function handle_attribution_rules($request) {
        $rows = class_exists('Zen_Cortext_Attribution') ? Zen_Cortext_Attribution::list_all() : array();
        $out = array();
        foreach ($rows as $r) {
            if (empty($r['enabled'])) continue;          // active rules only
            $chips = array();
            if (!empty($r['chips_json'])) {
                $decoded = json_decode((string) $r['chips_json'], true);
                if (is_array($decoded)) $chips = $decoded;
            }
            $out[] = array(
                'id'                => (int) $r['id'],
                'label'             => (string) $r['label'],
                'enabled'           => (bool) (int) $r['enabled'],
                'priority'          => (int) $r['priority'],
                'match' => array(
                    'utm_source'    => (string) ($r['match_utm_source']    ?? ''),
                    'utm_medium'    => (string) ($r['match_utm_medium']    ?? ''),
                    'utm_campaign'  => (string) ($r['match_utm_campaign']  ?? ''),
                    'referrer_host' => (string) ($r['match_referrer_host'] ?? ''),
                    'gclid_present' => !empty($r['match_gclid_present']),
                ),
                'survey_id'         => !empty($r['survey_id']) ? (int) $r['survey_id'] : null,
                'has_context_text'  => trim((string) ($r['context_text']    ?? '')) !== '',
                'has_intro_card'    => trim((string) ($r['intro_card_json'] ?? '')) !== '',
                'chips_count'       => count($chips),
                'created_at'        => (string) ($r['created_at'] ?? ''),
                'updated_at'        => (string) ($r['updated_at'] ?? ''),
            );
        }
        return rest_ensure_response(array('data' => $out, 'meta' => array('count' => count($out))));
    }

    /* ============================================================
       /knowledge — metadata only
       ============================================================ */

    public function handle_knowledge($request) {
        global $wpdb;
        $kb_table = $wpdb->prefix . 'zen_cortext_kb';

        $limit  = max(1, min(self::MAX_LIMIT, (int) ($request->get_param('limit') ?: self::DEFAULT_LIMIT)));
        $cursor = $this->decode_cursor((string) $request->get_param('cursor'));

        $where  = '1=1';
        $params = array();
        if ($cursor) {
            $where .= ' AND (updated_at < %s OR (updated_at = %s AND id < %d))';
            $params[] = gmdate('Y-m-d H:i:s', $cursor['u']);
            $params[] = gmdate('Y-m-d H:i:s', $cursor['u']);
            $params[] = (int) $cursor['i'];
        }

        $sql = "SELECT id, post_id, post_type, title, classification, classified_at, structured_at, updated_at
                FROM {$kb_table}
                WHERE {$where}
                ORDER BY updated_at DESC, id DESC
                LIMIT %d";
        $params[] = $limit + 1;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: array();

        $has_more = count($rows) > $limit;
        if ($has_more) array_pop($rows);

        $next_cursor = null;
        if ($has_more && !empty($rows)) {
            $last = $rows[count($rows) - 1];
            $next_cursor = $this->encode_cursor($last['updated_at'], (int) $last['id']);
        }

        $data = array();
        foreach ($rows as $r) {
            $post_id = (int) $r['post_id'];
            $data[] = array(
                'id'             => (int) $r['id'],
                'post_id'        => $post_id,
                'post_type'      => (string) $r['post_type'],
                'title'          => (string) $r['title'],
                'classification' => $r['classification'] !== null ? (string) $r['classification'] : null,
                'source_url'     => $post_id > 0 ? get_permalink($post_id) ?: null : null,
                'is_classified'  => !empty($r['classified_at']),
                'is_structured'  => !empty($r['structured_at']),
                'last_updated'   => $this->to_iso8601($r['updated_at']),
            );
        }

        return $this->envelope($data, array(
            'next_cursor' => $next_cursor,
            'has_more'    => $has_more,
            'limit'       => $limit,
        ));
    }

    /* ============================================================
       /sessions list (filters + cursor pagination)
       ============================================================ */

    public function handle_sessions_list($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'zen_cortext_sessions';

        $limit  = max(1, min(self::MAX_LIMIT, (int) ($request->get_param('limit') ?: self::DEFAULT_LIMIT)));
        $cursor = $this->decode_cursor((string) $request->get_param('cursor'));

        $where  = array('1=1');
        $params = array();

        // Date window — applied to last_seen_at (the relevant "when did
        // we see this visitor" signal for sessions, vs. created_at for chats).
        $from = $this->parse_date($request->get_param('from_date'));
        $to   = $this->parse_date($request->get_param('to_date'));
        if ($from) { $where[] = 'last_seen_at >= %s'; $params[] = $from; }
        if ($to)   { $where[] = 'last_seen_at <= %s'; $params[] = $to; }

        // Attribution equality / prefix filters — mirror /chats syntax
        // (trailing * = LIKE prefix match).
        foreach (array('utm_source', 'utm_medium', 'utm_campaign') as $f) {
            $v = trim((string) $request->get_param($f));
            if ($v === '') continue;
            if (substr($v, -1) === '*') {
                $where[] = "{$f} LIKE %s";
                $params[] = str_replace('*', '', $v) . '%';
            } else {
                $where[] = "{$f} = %s";
                $params[] = $v;
            }
        }
        foreach (array('gclid', 'msclkid') as $f) {
            $v = trim((string) $request->get_param($f));
            if ($v === '') continue;
            if ($v === 'present') {
                $where[] = "{$f} != ''";
            } elseif ($v === 'absent') {
                $where[] = "{$f} = ''";
            } else {
                $where[] = "{$f} = %s";
                $params[] = $v;
            }
        }

        $enriched = $request->get_param('enriched');
        if ($enriched !== null && $enriched !== '') {
            $where[] = $this->bool_param($enriched) ? 'enriched = 1' : 'enriched = 0';
        }

        $has_chats = $request->get_param('has_chats');
        if ($has_chats !== null && $has_chats !== '') {
            $where[] = $this->bool_param($has_chats) ? 'chat_count > 0' : 'chat_count = 0';
        }

        $rule_id = $request->get_param('rule_id');
        if ($rule_id !== null && $rule_id !== '') {
            $where[] = 'rule_id = %d';
            $params[] = (int) $rule_id;
        }

        if ($cursor) {
            $where[] = '(last_seen_at < %s OR (last_seen_at = %s AND id < %d))';
            $params[] = gmdate('Y-m-d H:i:s', $cursor['u']);
            $params[] = gmdate('Y-m-d H:i:s', $cursor['u']);
            $params[] = (int) $cursor['i'];
        }

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT id, session_uid, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                       gclid, msclkid, fbc, fbp, referrer, landing_page,
                       rule_id, chat_count, first_seen_at, last_seen_at, enriched
                FROM {$table}
                WHERE {$where_sql}
                ORDER BY last_seen_at DESC, id DESC
                LIMIT %d";
        $params[] = $limit + 1;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: array();

        $has_more = count($rows) > $limit;
        if ($has_more) array_pop($rows);

        $next_cursor = null;
        if ($has_more && !empty($rows)) {
            $last = $rows[count($rows) - 1];
            $next_cursor = $this->encode_cursor($last['last_seen_at'], (int) $last['id']);
        }

        $data = array();
        foreach ($rows as $r) $data[] = $this->shape_session_row($r, /*include_chats=*/ false);

        return $this->envelope($data, array(
            'next_cursor' => $next_cursor,
            'has_more'    => $has_more,
            'limit'       => $limit,
        ));
    }

    /* ============================================================
       /sessions/{id} — single session + attached chats
       ============================================================ */

    public function handle_sessions_detail($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'zen_cortext_sessions';
        $id = (string) $request->get_param('id');

        // Numeric → primary key lookup, else session_uid lookup. Mirrors
        // the /chats/{id} behaviour so callers can use either form.
        $row = null;
        if (ctype_digit($id)) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $id), ARRAY_A);
        }
        if (!$row) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE session_uid = %s", $id), ARRAY_A);
        }
        if (!$row) {
            return new WP_Error('zc_not_found', __('Session not found.', 'zen-cortext'), array('status' => 404));
        }
        return rest_ensure_response($this->shape_session_row($row, /*include_chats=*/ true));
    }

    /**
     * Reshape a session row for the public API envelope. When
     * include_chats is true, attaches a compact list of every chat
     * stamped with this session_uid (no transcripts — pull /chats/{id}
     * for full message arrays).
     */
    private function shape_session_row($r, $include_chats = false) {
        $attribution = array();
        foreach (array('referrer','landing_page','utm_source','utm_medium','utm_campaign','utm_term','utm_content','gclid','msclkid','fbc','fbp') as $k) {
            $v = (string) ($r[$k] ?? '');
            if ($v !== '') $attribution[$k] = $v;
        }
        $shape = array(
            'id'             => (int) $r['id'],
            'session_uid'    => (string) $r['session_uid'],
            'enriched'       => !empty($r['enriched']),
            'chat_count'     => (int) ($r['chat_count'] ?? 0),
            'rule_id'        => !empty($r['rule_id']) ? (int) $r['rule_id'] : null,
            'first_seen_at'  => $this->to_iso8601($r['first_seen_at']),
            'last_seen_at'   => $this->to_iso8601($r['last_seen_at']),
            'attribution'    => (object) $attribution,
        );
        if ($include_chats) {
            $chats = class_exists('Zen_Cortext_Sessions')
                ? Zen_Cortext_Sessions::chats_for((string) $r['session_uid'])
                : array();
            $compact = array();
            foreach ($chats as $c) {
                $compact[] = array(
                    'id'                => (int) $c['id'],
                    'chat_uid'          => (string) $c['chat_uid'],
                    'message_count'     => (int) ($c['message_count'] ?? 0),
                    'lead_submitted'    => !empty($c['lead_submitted_at']),
                    'lead_name'         => (string) ($c['lead_name']  ?? ''),
                    'lead_email'        => (string) ($c['lead_email'] ?? ''),
                    'admin_user_id'     => $c['admin_user_id'] !== null ? (int) $c['admin_user_id'] : null,
                    'created_at'        => $this->to_iso8601($c['created_at']),
                    'updated_at'        => $this->to_iso8601($c['updated_at']),
                    'deleted_at'        => $c['deleted_at'] ? $this->to_iso8601($c['deleted_at']) : null,
                );
            }
            $shape['chats'] = $compact;
        }
        return $shape;
    }

    /* ============================================================
       Shared helpers
       ============================================================ */

    /**
     * Parse & validate filter inputs for /chats and /leads. Returns
     * either a clean filter array (with `where`, `params`, `limit`,
     * `cursor`) or a WP_Error.
     */
    private function parse_chat_filters($request) {
        $limit  = (int) ($request->get_param('limit') ?: self::DEFAULT_LIMIT);
        $limit  = max(1, min(self::MAX_LIMIT, $limit));
        $cursor = $this->decode_cursor((string) $request->get_param('cursor'));

        $where  = array('1=1');
        $params = array();

        $from = $this->parse_date($request->get_param('from_date'));
        $to   = $this->parse_date($request->get_param('to_date'));
        if ($from) { $where[] = 'created_at >= %s'; $params[] = $from; }
        if ($to)   { $where[] = 'created_at <= %s'; $params[] = $to; }

        foreach (array('utm_source', 'utm_medium', 'utm_campaign') as $f) {
            $v = (string) $request->get_param($f);
            $v = trim($v);
            if ($v === '') continue;
            if (substr($v, -1) === '*') {
                $where[] = "{$f} LIKE %s";
                $params[] = $wpdb_like = str_replace('*', '', $v) . '%';
            } else {
                $where[] = "{$f} = %s";
                $params[] = $v;
            }
        }

        foreach (array('gclid', 'fbc') as $f) {
            $v = (string) $request->get_param($f);
            $v = trim($v);
            if ($v === '') continue;
            if ($v === 'present') {
                $where[] = "{$f} != ''";
            } elseif ($v === 'absent') {
                $where[] = "{$f} = ''";
            } else {
                $where[] = "{$f} = %s";
                $params[] = $v;
            }
        }

        $has_email = $request->get_param('has_email');
        if ($has_email !== null && $has_email !== '') {
            $where[] = $this->bool_param($has_email) ? "lead_email != ''" : "lead_email = ''";
        }
        $has_phone = $request->get_param('has_phone');
        if ($has_phone !== null && $has_phone !== '') {
            $where[] = $this->bool_param($has_phone) ? "lead_whatsapp != ''" : "lead_whatsapp = ''";
        }

        $min_messages = $request->get_param('min_messages');
        if ($min_messages !== null && $min_messages !== '') {
            $where[]  = 'message_count >= %d';
            $params[] = max(0, (int) $min_messages);
        }
        $max_messages = $request->get_param('max_messages');
        if ($max_messages !== null && $max_messages !== '') {
            $where[]  = 'message_count <= %d';
            $params[] = max(0, (int) $max_messages);
        }

        $outcome = trim((string) $request->get_param('outcome'));
        if ($outcome !== '') {
            $allowed = array('active', 'qualified', 'handoff', 'resolved', 'abandoned');
            if (!in_array($outcome, $allowed, true)) {
                $msg = ($outcome === 'disqualified')
                    ? __('Outcome "disqualified" is not implemented in v1 (no signal exists yet).', 'zen-cortext')
                    /* translators: %1$s is the unknown outcome name supplied by the caller, %2$s is the comma-separated list of allowed outcomes. */
                    : sprintf(__('Unknown outcome "%1$s". Allowed: %2$s.', 'zen-cortext'), $outcome, implode(', ', $allowed));
                return new WP_Error('zc_bad_outcome', $msg, array('status' => 400));
            }
            $where[] = '(' . self::outcome_case_sql() . ') = %s';
            $params[] = $outcome;
        }

        if ($cursor) {
            $where[] = '(updated_at < %s OR (updated_at = %s AND id < %d))';
            $params[] = gmdate('Y-m-d H:i:s', $cursor['u']);
            $params[] = gmdate('Y-m-d H:i:s', $cursor['u']);
            $params[] = (int) $cursor['i'];
        }

        return array(
            'where'  => implode(' AND ', $where),
            'params' => $params,
            'limit'  => $limit,
            'cursor' => $cursor,
        );
    }

    /**
     * Execute the chats query with the prepared filters. limit+1 for
     * keyset pagination boundary detection. `include_messages` decides
     * whether the LONGTEXT messages column is in the SELECT list — the
     * list endpoint omits it to keep payloads small, the detail / leads
     * endpoints include it.
     */
    private function query_chats($filters, $include_messages = false) {
        global $wpdb;
        $table = $wpdb->prefix . 'zen_cortext_chats';

        $where  = $filters['where'];
        $params = $filters['params'];

        // /leads-only constraint: lead_submitted_at IS NOT NULL.
        if (!empty($filters['_force_lead_present'])) {
            $where .= ' AND lead_submitted_at IS NOT NULL';
        }

        $cols = 'id, chat_uid, session_uid, message_count, created_at, updated_at, deleted_at,
                 referrer, landing_page,
                 utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                 gclid, msclkid, fbc, fbp,
                 lead_name, lead_email, lead_whatsapp, lead_submitted_at,
                 admin_user_id, admin_attached_at, admin_detached_at';
        if ($include_messages) $cols .= ', messages';

        $sql = "SELECT {$cols} FROM {$table} WHERE {$where} ORDER BY updated_at DESC, id DESC LIMIT %d";
        $params[] = $filters['limit'] + 1;
        $prepared = $wpdb->prepare($sql, $params);
        return $wpdb->get_results($prepared, ARRAY_A) ?: array();
    }

    private function lookup_chat_by_id_or_uid($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'zen_cortext_chats';
        // Numeric → primary key lookup, else chat_uid lookup. Chat uids are
        // alphanumeric-with-dashes (visitor-side generator), so we don't
        // accidentally collide with a numeric id.
        if (ctype_digit($id)) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $id), ARRAY_A);
            if ($row) return $row;
        }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE chat_uid = %s", (string) $id), ARRAY_A);
    }

    /**
     * Reshape a raw chat row into the public response envelope. Pulls
     * out the attribution block, derives outcome in PHP (matching the
     * SQL CASE so the two stay in sync), and conditionally includes
     * the message transcript.
     */
    private function shape_chat_row($r, $include_messages = false) {
        $attribution = array();
        foreach (array('referrer','landing_page','utm_source','utm_medium','utm_campaign','utm_term','utm_content','gclid','msclkid','fbc','fbp') as $k) {
            $v = (string) ($r[$k] ?? '');
            if ($v !== '') $attribution[$k] = $v;
        }

        $lead = null;
        if (!empty($r['lead_submitted_at'])) {
            $lead = array(
                'name'         => (string) ($r['lead_name']     ?? ''),
                'email'        => (string) ($r['lead_email']    ?? ''),
                'whatsapp'     => (string) ($r['lead_whatsapp'] ?? ''),
                'submitted_at' => $this->to_iso8601($r['lead_submitted_at']),
            );
        }

        $handoff = null;
        if (!empty($r['admin_attached_at']) || !empty($r['admin_user_id'])) {
            $handoff = array(
                'admin_user_id' => $r['admin_user_id'] !== null ? (int) $r['admin_user_id'] : null,
                'attached_at'   => $this->to_iso8601($r['admin_attached_at']),
                'detached_at'   => $this->to_iso8601($r['admin_detached_at']),
            );
        }

        $shape = array(
            'id'            => (int) $r['id'],
            'chat_uid'      => (string) $r['chat_uid'],
            'outcome'       => $this->derive_outcome($r),
            'message_count' => (int) ($r['message_count'] ?? 0),
            'created_at'    => $this->to_iso8601($r['created_at']),
            'updated_at'    => $this->to_iso8601($r['updated_at']),
            'attribution'   => (object) $attribution, // empty -> {} in JSON, not []
            'lead'          => $lead,
            'handoff'       => $handoff,
            'survey'        => $this->survey_reference_for($r),
            'session'       => class_exists('Zen_Cortext_Sessions')
                ? Zen_Cortext_Sessions::summary_for_chat($r)
                : null,
        );

        if ($include_messages) {
            $msgs = array();
            if (!empty($r['messages'])) {
                $decoded = json_decode((string) $r['messages'], true);
                if (is_array($decoded)) $msgs = $decoded;
            }
            $shape['messages'] = $msgs;
        }
        return $shape;
    }

    /**
     * PHP mirror of outcome_case_sql() — same logic so filtering by
     * `outcome` in SQL gives the same value that ends up in the JSON
     * response.
     */
    private function derive_outcome($r) {
        $deleted_at         = $r['deleted_at']         ?? null;
        $lead_submitted_at  = $r['lead_submitted_at']  ?? null;
        $admin_user_id      = $r['admin_user_id']      ?? null;
        $admin_attached_at  = $r['admin_attached_at']  ?? null;
        $admin_detached_at  = $r['admin_detached_at']  ?? null;
        $updated_at         = $r['updated_at']         ?? null;

        $seven_days_ago = strtotime('-7 days');
        $idle = $updated_at && strtotime($updated_at) < $seven_days_ago;

        if ($deleted_at) return 'abandoned';
        if (!$lead_submitted_at && !$admin_user_id && $idle) return 'abandoned';
        if ($admin_detached_at && $lead_submitted_at) return 'resolved';
        if ($admin_attached_at) return 'handoff';
        if ($lead_submitted_at) return 'qualified';
        return 'active';
    }

    /**
     * Authoritative SQL fragment for the outcome derivation. Used in
     * the /chats outcome filter (WHERE) and the /chats/stats counters
     * (SUM(CASE ... END)). Keep in sync with derive_outcome().
     */
    public static function outcome_case_sql() {
        return "
            CASE
              WHEN deleted_at IS NOT NULL
                OR (lead_submitted_at IS NULL AND admin_user_id IS NULL AND updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY))
                THEN 'abandoned'
              WHEN admin_detached_at IS NOT NULL AND lead_submitted_at IS NOT NULL THEN 'resolved'
              WHEN admin_attached_at IS NOT NULL THEN 'handoff'
              WHEN lead_submitted_at IS NOT NULL THEN 'qualified'
              ELSE 'active'
            END
        ";
    }

    /**
     * Resolve the survey reference for a chat: matched attribution rule's
     * survey_id wins, else the global default survey_id option. Returns
     * null if neither yields a survey row. Surfacing only id+label —
     * answers extraction is explicitly out of scope for v1.
     */
    private function survey_reference_for($chat_row) {
        if (!class_exists('Zen_Cortext_Surveys')) return null;

        $survey_id = 0;
        if (class_exists('Zen_Cortext_Attribution')) {
            $att = array(
                'utm_source'   => (string) ($chat_row['utm_source']   ?? ''),
                'utm_medium'   => (string) ($chat_row['utm_medium']   ?? ''),
                'utm_campaign' => (string) ($chat_row['utm_campaign'] ?? ''),
                'gclid'        => (string) ($chat_row['gclid']        ?? ''),
                'referrer'     => (string) ($chat_row['referrer']     ?? ''),
            );
            $rule = Zen_Cortext_Attribution::resolve($att);
            if ($rule && !empty($rule['survey_id'])) $survey_id = (int) $rule['survey_id'];
        }
        if ($survey_id <= 0) {
            $survey_id = (int) get_option('zen_cortext_default_survey_id', 0);
        }
        if ($survey_id <= 0) return null;

        $survey = method_exists('Zen_Cortext_Surveys', 'get')
            ? Zen_Cortext_Surveys::get($survey_id)
            : null;
        if (!$survey) return null;
        return array(
            'id'    => $survey_id,
            'label' => (string) ($survey['label'] ?? ''),
        );
    }

    /* ---------------- Cursor helpers ---------------- */

    private function encode_cursor($updated_at, $id) {
        $u = is_numeric($updated_at) ? (int) $updated_at : (int) strtotime((string) $updated_at);
        $payload = wp_json_encode(array('u' => $u, 'i' => (int) $id));
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    private function decode_cursor($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') return null;
        $pad = strlen($raw) % 4;
        if ($pad) $raw .= str_repeat('=', 4 - $pad);
        $decoded = base64_decode(strtr($raw, '-_', '+/'), true);
        if ($decoded === false) return null;
        $arr = json_decode($decoded, true);
        if (!is_array($arr) || !isset($arr['u'], $arr['i'])) return null;
        return array('u' => (int) $arr['u'], 'i' => (int) $arr['i']);
    }

    /* ---------------- Misc helpers ---------------- */

    private function parse_date($s) {
        $s = trim((string) $s);
        if ($s === '') return null;
        $ts = strtotime($s);
        if ($ts === false) return null;
        return gmdate('Y-m-d H:i:s', $ts);
    }

    private function bool_param($v) {
        if (is_bool($v)) return $v;
        $v = strtolower(trim((string) $v));
        return in_array($v, array('1','true','yes','y','on'), true);
    }

    private function to_iso8601($mysql_datetime) {
        if (empty($mysql_datetime)) return null;
        $ts = strtotime((string) $mysql_datetime);
        if ($ts === false) return (string) $mysql_datetime;
        return gmdate('c', $ts);
    }

    private function envelope($data, $meta) {
        $response = rest_ensure_response(array('data' => $data, 'meta' => $meta));
        // Hint to caches and proxies that this is sensitive per-key data.
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        return $response;
    }
}
