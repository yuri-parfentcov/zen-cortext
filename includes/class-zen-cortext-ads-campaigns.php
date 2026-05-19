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
        $seen_ids = array();

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
            $campaign_id   = self::str($c['campaign_id']   ?? '', 32);
            $campaign_name = self::str($c['campaign_name'] ?? '', 191);
            if ($campaign_id === '' || $campaign_name === '') {
                $errors[] = "row {$i}: campaign_id and campaign_name required";
                continue;
            }

            $row = array(
                'campaign_id'   => $campaign_id,
                'campaign_name' => $campaign_name,
                'status'        => self::str($c['status'] ?? '', 16),
                'budget_micros' => isset($c['budget_micros']) && $c['budget_micros'] !== '' ? (int) $c['budget_micros'] : null,
                'top_headlines' => self::encode_string_list($c['top_headlines'] ?? array()),
                'top_keywords'  => self::encode_string_list($c['top_keywords']  ?? array()),
                'synced_at'     => $now,
            );

            $existing_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE campaign_id = %s",
                $campaign_id
            ));

            if ($existing_id > 0) {
                $ok = $wpdb->update($table, $row, array('id' => $existing_id));
            } else {
                $ok = $wpdb->insert($table, $row);
            }

            if ($ok === false) {
                $errors[] = "row {$i}: db error: " . $wpdb->last_error;
                continue;
            }
            $seen_ids[] = $campaign_id;
            $upserted++;
        }

        $deleted = 0;
        if ($delete_missing && !empty($seen_ids)) {
            $placeholders = implode(',', array_fill(0, count($seen_ids), '%s'));
            $sql = "DELETE FROM {$table} WHERE campaign_id NOT IN ({$placeholders})";
            $deleted = (int) $wpdb->query($wpdb->prepare($sql, $seen_ids));
        } elseif ($delete_missing && empty($seen_ids)) {
            // Caller explicitly asked for full-replace with an empty payload.
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
            "SELECT * FROM " . self::table() . " WHERE LOWER(campaign_name) = LOWER(%s) LIMIT 1",
            $name
        ), ARRAY_A);
    }

    public static function find_by_id($campaign_id) {
        global $wpdb;
        $campaign_id = trim((string) $campaign_id);
        if ($campaign_id === '') return null;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE campaign_id = %s LIMIT 1",
            $campaign_id
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
            "SELECT id, campaign_id, campaign_name, status, budget_micros,
                    top_headlines, top_keywords, synced_at
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
