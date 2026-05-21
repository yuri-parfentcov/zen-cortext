<?php
/**
 * Survey/Interview storage (custom table wp_zen_cortext_surveys).
 *
 * Each survey is an admin-defined interview script. The raw `script` column
 * holds the editor source; `parsed_json` is a cached structural form so the
 * AI prompt builder doesn't re-parse on every chat turn.
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

class Zen_Cortext_Surveys {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'zen_cortext_surveys';
    }

    /**
     * Insert. Returns the new row id, or WP_Error on validation failure.
     * The caller passes the raw editor source under 'script'; we parse it
     * to validate + cache the parsed form.
     */
    public static function create($data) {
        global $wpdb;

        $label   = isset($data['label']) ? trim((string) $data['label']) : '';
        $desc    = isset($data['description']) ? (string) $data['description'] : '';
        $script  = isset($data['script']) ? (string) $data['script'] : '';
        $outcome = isset($data['outcome_instructions']) ? (string) $data['outcome_instructions'] : '';
        $enabled = !empty($data['enabled']) ? 1 : 0;

        if ($label === '') {
            return new WP_Error('zen_cortext_survey', 'Label is required.');
        }

        $parsed = Zen_Cortext_Survey_Parser::parse($script);
        if (is_wp_error($parsed)) {
            return $parsed;
        }

        $now = current_time('mysql');
        $ok = $wpdb->insert(self::table(), array(
            'label'                => $label,
            'description'          => $desc,
            'script'               => $script,
            'parsed_json'          => wp_json_encode($parsed),
            'outcome_instructions' => $outcome,
            'enabled'              => $enabled,
            'created_at'           => $now,
            'updated_at'           => $now,
        ));
        if ($ok === false) {
            return new WP_Error('zen_cortext_survey', 'DB insert failed: ' . $wpdb->last_error);
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Update. Pass any subset of: label, description, script, enabled.
     * Re-parses + re-caches whenever script changes.
     */
    public static function update($id, $data) {
        global $wpdb;
        $id = (int) $id;
        if ($id <= 0) {
            return new WP_Error('zen_cortext_survey', 'Invalid id.');
        }

        $update = array('updated_at' => current_time('mysql'));

        if (isset($data['label'])) {
            $label = trim((string) $data['label']);
            if ($label === '') {
                return new WP_Error('zen_cortext_survey', 'Label cannot be empty.');
            }
            $update['label'] = $label;
        }
        if (array_key_exists('description', $data)) {
            $update['description'] = (string) $data['description'];
        }
        if (array_key_exists('script', $data)) {
            $script = (string) $data['script'];
            $parsed = Zen_Cortext_Survey_Parser::parse($script);
            if (is_wp_error($parsed)) {
                return $parsed;
            }
            $update['script']      = $script;
            $update['parsed_json'] = wp_json_encode($parsed);
        }
        if (array_key_exists('outcome_instructions', $data)) {
            $update['outcome_instructions'] = (string) $data['outcome_instructions'];
        }
        if (array_key_exists('enabled', $data)) {
            $update['enabled'] = !empty($data['enabled']) ? 1 : 0;
        }

        $ok = $wpdb->update(self::table(), $update, array('id' => $id));
        if ($ok === false) {
            return new WP_Error('zen_cortext_survey', 'DB update failed: ' . $wpdb->last_error);
        }
        return true;
    }

    public static function delete($id) {
        global $wpdb;
        $id = (int) $id;
        if ($id <= 0) {
            return new WP_Error('zen_cortext_survey', 'Invalid id.');
        }
        $ok = $wpdb->delete(self::table(), array('id' => $id), array('%d'));
        if ($ok === false) {
            return new WP_Error('zen_cortext_survey', 'DB delete failed: ' . $wpdb->last_error);
        }
        return true;
    }

    public static function get($id) {
        global $wpdb;
        $id = (int) $id;
        if ($id <= 0) return null;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d",
            $id
        ), ARRAY_A);
    }

    /**
     * Light-weight list for admin pickers + the Surveys page table. Returns
     * id/label/description/enabled/updated_at + a question_count derived
     * from parsed_json. No raw script in the result — keep it small.
     */
    public static function all($filters = array()) {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, label, description, parsed_json, enabled, created_at, updated_at
             FROM " . self::table() . "
             ORDER BY enabled DESC, label ASC, id ASC",
            ARRAY_A
        );
        if (!is_array($rows)) return array();

        $only_enabled = !empty($filters['only_enabled']);
        $out = array();
        foreach ($rows as $row) {
            if ($only_enabled && empty($row['enabled'])) continue;
            $count = 0;
            if (!empty($row['parsed_json'])) {
                $decoded = json_decode((string) $row['parsed_json'], true);
                if (is_array($decoded) && !empty($decoded['questions']) && is_array($decoded['questions'])) {
                    $count = count($decoded['questions']);
                }
            }
            $out[] = array(
                'id'             => (int) $row['id'],
                'label'          => (string) $row['label'],
                'description'    => (string) $row['description'],
                'question_count' => $count,
                'enabled'        => (int) $row['enabled'],
                'created_at'     => (string) $row['created_at'],
                'updated_at'     => (string) $row['updated_at'],
            );
        }
        return $out;
    }

    /**
     * Decode a row's parsed_json back into the structured array. Falls back
     * to re-parsing the script when the cache is empty/corrupt — defensive
     * but cheap because each chat turn does this at most once. Outcome
     * instructions live in their own column (free-text, not parsed) and
     * are merged into the returned struct under 'outcome_instructions' so
     * the prompt builder gets everything in one shape.
     */
    public static function get_parsed($id) {
        $row = self::get($id);
        if (!$row) return null;
        if (empty($row['enabled'])) return null;

        $parsed = null;
        $cached = !empty($row['parsed_json']) ? json_decode((string) $row['parsed_json'], true) : null;
        if (is_array($cached) && isset($cached['questions'])) {
            $parsed = $cached;
        } else {
            // Cache miss / legacy row — reparse on the fly.
            $parsed = Zen_Cortext_Survey_Parser::parse((string) $row['script']);
            if (is_wp_error($parsed)) return null;
        }
        $parsed['outcome_instructions'] = isset($row['outcome_instructions'])
            ? (string) $row['outcome_instructions']
            : '';
        return $parsed;
    }
}
