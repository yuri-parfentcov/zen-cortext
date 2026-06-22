<?php
/**
 * Synced Google Ads campaign metadata (custom table
 * wp_zen_cortext_ads_campaigns).
 *
 * Wholesale-replace per campaign_id from a Google Apps Script POSTing into
 * /wp-json/zen-cortext/v1/ingest/ads-campaigns. Apps Script does the
 * rollup (campaign → ad groups → RSA assets → keywords) and sends a flat
 * payload — we don't model the full GADS schema. Manual context lives in
 * a separate table (wp_zen_cortext_attribution_contexts) so this sync
 * structurally cannot clobber human-written fields.
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

class Zen_Cortext_Ads_Campaigns {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'zen_cortext_ads_campaigns';
    }

    /**
     * Bulk upsert keyed on campaign_id. Campaign rows absent from the
     * payload are left alone unless $delete_missing is true. Returns
     * array(upserted, deleted, errors).
     */
    public static function upsert_bulk($campaigns, $delete_missing = false) {
        global $wpdb;
        $upserted = 0;
        $errors   = array();
        $seen_row_ids = array();

        if (!is_array($campaigns)) {
            return array('upserted' => 0, 'deleted' => 0, 'errors' => array('campaigns must be an array'));
        }

        $now = current_time('mysql');
        $table = self::table();

        foreach ($campaigns as $i => $c) {
            if (!is_array($c)) {
                $errors[] = "row {$i}: not an object";
                continue;
            }
            $type          = self::str($c['type'] ?? 'campaign', 16);
            $type          = ($type === 'group') ? 'group' : 'campaign';
            $campaign_id   = self::str($c['campaign_id']   ?? '', 32);
            $campaign_name = self::str($c['campaign_name'] ?? '', 191);
            $ad_group_id   = self::str($c['ad_group_id']   ?? '', 32);
            $ad_group_name = self::str($c['ad_group_name'] ?? '', 191);
            if ($campaign_id === '' || $campaign_name === '') {
                $errors[] = "row {$i}: campaign_id and campaign_name required";
                continue;
            }
            if ($type === 'group' && ($ad_group_id === '' || $ad_group_name === '')) {
                $errors[] = "row {$i}: ad_group_id and ad_group_name required for group rows";
                continue;
            }

            $row = array(
                'type'          => $type,
                'campaign_id'   => $campaign_id,
                'campaign_name' => $campaign_name,
                'ad_group_id'   => $ad_group_id,
                'ad_group_name' => $ad_group_name,
                'status'        => self::str($c['status'] ?? '', 16),
                'budget_micros' => isset($c['budget_micros']) && $c['budget_micros'] !== '' ? (int) $c['budget_micros'] : null,
                'top_headlines' => self::encode_string_list($c['top_headlines'] ?? array()),
                'top_keywords'  => self::encode_string_list($c['top_keywords']  ?? array()),
                'synced_at'     => $now,
            );

            $existing_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE type = %s AND campaign_id = %s AND ad_group_id = %s",
                $type, $campaign_id, $ad_group_id
            ));

            if ($existing_id > 0) {
                $ok = $wpdb->update($table, $row, array('id' => $existing_id));
                $row_id = $existing_id;
            } else {
                $ok = $wpdb->insert($table, $row);
                $row_id = (int) $wpdb->insert_id;
            }

            if ($ok === false) {
                $errors[] = "row {$i}: db error: " . $wpdb->last_error;
                continue;
            }
            $seen_row_ids[] = $row_id;
            $upserted++;
        }

        $deleted = 0;
        if ($delete_missing && !empty($seen_row_ids)) {
            $placeholders = implode(',', array_fill(0, count($seen_row_ids), '%d'));
            $sql = "DELETE FROM {$table} WHERE id NOT IN ({$placeholders})";
            $deleted = (int) $wpdb->query($wpdb->prepare($sql, $seen_row_ids));
        } elseif ($delete_missing && empty($seen_row_ids)) {
            $deleted = (int) $wpdb->query("DELETE FROM {$table}");
        }

        return array('upserted' => $upserted, 'deleted' => $deleted, 'errors' => $errors);
    }

    /**
     * Find a synced campaign by name (case-insensitive). Returns
     * associative array or null.
     */
    public static function find_by_name($name) {
        global $wpdb;
        $name = trim((string) $name);
        if ($name === '') return null;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . "
             WHERE (type = 'group' AND LOWER(ad_group_name) = LOWER(%s))
                OR (type = 'campaign' AND LOWER(campaign_name) = LOWER(%s))
             ORDER BY (type = 'group') DESC, id DESC
             LIMIT 1",
            $name, $name
        ), ARRAY_A);
    }

    public static function find_by_id($entity_id) {
        global $wpdb;
        $entity_id = trim((string) $entity_id);
        if ($entity_id === '') return null;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . "
             WHERE (type = 'group' AND ad_group_id = %s)
                OR (type = 'campaign' AND campaign_id = %s)
             ORDER BY (type = 'group') DESC, id DESC
             LIMIT 1",
            $entity_id, $entity_id
        ), ARRAY_A);
    }

    /**
     * Resolve a value that could be EITHER a campaign_id or a
     * campaign_name. Tries ID first (exact match — IDs are numeric and
     * unique), then name (case-insensitive). UTM tags in the wild often
     * carry the campaign ID rather than the human-readable name, so the
     * matcher needs to accept both shapes.
     */
    public static function find_by_id_or_name($value) {
        $value = trim((string) $value);
        if ($value === '') return null;
        $row = self::find_by_id($value);
        return $row ? $row : self::find_by_name($value);
    }

    public static function list_all($limit = 200) {
        global $wpdb;
        $limit = max(1, min(1000, (int) $limit));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, type, campaign_id, campaign_name, ad_group_id, ad_group_name,
                    status, budget_micros, top_headlines, top_keywords, synced_at
             FROM " . self::table() . "
             ORDER BY synced_at DESC, id DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);
    }

    public static function last_sync_timestamp() {
        global $wpdb;
        $ts = $wpdb->get_var("SELECT MAX(synced_at) FROM " . self::table());
        return $ts ?: null;
    }

    public static function count_all() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table());
    }

    public static function counts_by_type() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT type, COUNT(*) AS n FROM " . self::table() . " GROUP BY type", ARRAY_A);
        $out = array('campaign' => 0, 'group' => 0);
        foreach ((array) $rows as $r) {
            $t = (isset($r['type']) && $r['type'] === 'group') ? 'group' : 'campaign';
            $out[$t] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Wipe every synced campaign row. Used by the admin "Clear synced
     * data" button. Doesn't touch attribution rules — rules can still
     * match incoming visitors on raw UTMs; they just lose the joined
     * Google Ads metadata (headlines, keywords, budget) until the next
     * sync run. Returns the number of rows deleted.
     */
    public static function clear_all() {
        global $wpdb;
        $before = self::count_all();
        $wpdb->query("TRUNCATE TABLE " . self::table());
        return $before;
    }

    /* ---------------- helpers ---------------- */

    private static function str($v, $maxlen) {
        $v = sanitize_text_field((string) $v);
        if (function_exists('mb_substr') && function_exists('mb_strlen')) {
            if (mb_strlen($v) > $maxlen) $v = mb_substr($v, 0, $maxlen);
        } else {
            if (strlen($v) > $maxlen) $v = substr($v, 0, $maxlen);
        }
        return $v;
    }

    private static function encode_string_list($value) {
        if (!is_array($value)) return '';
        $clean = array();
        foreach ($value as $v) {
            if (!is_string($v)) {
                if (is_scalar($v)) $v = (string) $v;
                else continue;
            }
            $v = trim($v);
            if ($v === '') continue;
            $clean[] = $v;
        }
        return $clean ? wp_json_encode(array_values($clean)) : '';
    }
}
