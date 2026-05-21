<?php
/**
 * Extract published posts from WP and upsert into the KB table.
 * Replaces scripts/extract_and_classify.py extraction step.
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

class Zen_Cortext_Extractor {

    /**
     * Sync all configured post types into the KB table.
     * Returns counts: ['inserted', 'updated', 'reset', 'total', 'orphans_removed'].
     *
     * Also purges orphans — KB rows whose post_id no longer corresponds
     * to a currently-published post of a configured type. This is the
     * self-healing path for state that pre-dates the post-lifecycle hooks
     * (or any edge cases the hooks miss, e.g. direct DB writes by other
     * plugins). With the hooks in place the orphan count should usually
     * be 0 on subsequent syncs.
     */
    public static function sync_all() {
        $post_types = get_option('zen_cortext_post_types', array('post', 'page'));
        if (!is_array($post_types) || empty($post_types)) {
            return array('inserted' => 0, 'updated' => 0, 'reset' => 0, 'total' => 0, 'orphans_removed' => 0);
        }

        $counts = array('inserted' => 0, 'updated' => 0, 'reset' => 0, 'total' => 0, 'orphans_removed' => 0);

        $posts = get_posts(array(
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'numberposts'    => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- KB indexer must read raw post_content without theme/SEO filters mutating it; we re-clean below.
            'suppress_filters' => true,
        ));

        $current_ids = array();
        foreach ($posts as $post) {
            $current_ids[] = (int) $post->ID;
            $content = self::clean_content($post->post_content);
            $result = Zen_Cortext_KB::upsert(
                (int) $post->ID,
                $post->post_type,
                $post->post_title,
                $content
            );
            if (isset($counts[$result])) {
                $counts[$result]++;
            }
            $counts['total']++;
        }

        // Orphan cleanup: rows in the KB whose post_id is no longer the
        // ID of a currently-published post of a tracked type. Catches
        // posts deleted before hooks were wired, posts moved to non-
        // tracked types, and rows left behind by edge cases.
        $counts['orphans_removed'] = self::purge_orphans($current_ids);

        Zen_Cortext_KB::flush_cache();
        return $counts;
    }

    /**
     * Delete KB rows whose post_id isn't in the supplied keep-set.
     * Returns the number of rows removed.
     */
    private static function purge_orphans(array $keep_ids) {
        global $wpdb;
        $table = Zen_Cortext_KB::table();
        if (empty($keep_ids)) {
            // No published posts at all — wipe everything. Aggressive but
            // correct: if the site has zero published content, the KB
            // shouldn't pretend otherwise.
            $deleted = (int) $wpdb->query("DELETE FROM {$table}");
            return $deleted;
        }
        $placeholders = implode(',', array_fill(0, count($keep_ids), '%d'));
        $sql = "DELETE FROM {$table} WHERE post_id NOT IN ({$placeholders})";
        $deleted = (int) $wpdb->query($wpdb->prepare($sql, $keep_ids));
        return $deleted;
    }

    /**
     * Strip shortcodes, HTML, and entities; collapse whitespace.
     * Mirrors strip_html() from scripts/extract_and_classify.py.
     */
    public static function clean_content($html) {
        if (!is_string($html)) return '';
        // Strip shortcodes [foo] and [/foo]
        $text = preg_replace('/\[\/?[^\]]+\]/', ' ', $html);
        // Strip HTML tags
        $text = wp_strip_all_tags($text);
        // Decode common entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /* ---------------------------------------------------------------
     * Post-lifecycle hooks
     * --------------------------------------------------------------- */

    /**
     * Fired from wp_after_insert_post — handles publish/update/status-
     * change for any post type. KB table is kept in sync with the
     * current WP state; AI classification + restructuring are NOT
     * triggered (admin clicks "Rebuild KB" to spend tokens).
     *
     * The existing KB::upsert() logic NULLs classification + structured
     * whenever content changes, so updates automatically re-enter the
     * pipeline queue on the next Rebuild. New posts arrive with NULL
     * classification, picked up the same way.
     */
    public static function on_post_changed($post_id, $post, $update) {
        // Revisions and autosaves never enter the KB. wp_after_insert_post
        // fires for them and we must guard.
        if (!is_object($post)) return;
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!self::is_tracked_post_type($post->post_type)) return;

        if ($post->post_status !== 'publish') {
            // Transition out of publish (draft, pending, future, private,
            // trash) → drop from KB so the visitor chat stops citing it
            // immediately. before_delete_post handles the hard-delete case.
            Zen_Cortext_KB::delete_for_post($post_id);
            Zen_Cortext_KB::flush_cache();
            self::bust_badge_cache();
            return;
        }

        $content = self::clean_content($post->post_content);
        Zen_Cortext_KB::upsert(
            (int) $post_id,
            $post->post_type,
            $post->post_title,
            $content
        );
        Zen_Cortext_KB::flush_cache();
        self::bust_badge_cache();
    }

    /**
     * Fired from before_delete_post — final cleanup for hard deletes.
     * Idempotent: if on_post_changed already pulled the row on a trash
     * transition, this is a silent no-op.
     */
    public static function on_post_deleted($post_id) {
        Zen_Cortext_KB::delete_for_post($post_id);
        Zen_Cortext_KB::flush_cache();
        self::bust_badge_cache();
    }

    /**
     * Whether a post type is configured to be indexed into the KB.
     * Reads the same `zen_cortext_post_types` option as the manual
     * Sync button.
     */
    public static function is_tracked_post_type($post_type) {
        $tracked = (array) get_option('zen_cortext_post_types', array('post', 'page'));
        return in_array($post_type, $tracked, true);
    }

    /**
     * Invalidate the cached "items pending re-processing" count rendered
     * as a badge on the Knowledge Base submenu item. Called from every
     * mutator on this path (hook handlers, manual sync, classify, restructure,
     * clear) so the badge stays in sync without a per-pageload COUNT(*).
     */
    public static function bust_badge_cache() {
        delete_transient('zen_cortext_kb_pending');
    }
}
