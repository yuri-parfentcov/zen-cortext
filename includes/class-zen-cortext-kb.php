<?php
/**
 * Knowledge base storage and queries (custom table wp_zen_cortext_kb).
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

class Zen_Cortext_KB {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'zen_cortext_kb';
    }

    /**
     * Insert or update a row by post_id. Resets classification/structured if content changed.
     */
    public static function upsert($post_id, $post_type, $title, $content) {
        global $wpdb;
        $table = self::table();

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, content FROM {$table} WHERE post_id = %d",
            $post_id
        ));

        $now = current_time('mysql');

        if ($existing) {
            $changed = ($existing->content !== $content);
            $data = array(
                'post_type' => $post_type,
                'title'     => $title,
                'content'   => $content,
                'updated_at'=> $now,
            );
            if ($changed) {
                $data['classification'] = null;
                $data['structured'] = null;
                $data['classified_at'] = null;
                $data['structured_at'] = null;
            }
            $wpdb->update($table, $data, array('post_id' => $post_id));
            return $changed ? 'reset' : 'updated';
        }

        $wpdb->insert($table, array(
            'post_id'    => $post_id,
            'post_type'  => $post_type,
            'title'      => $title,
            'content'    => $content,
            'updated_at' => $now,
        ));
        return 'inserted';
    }

    public static function next_unclassified() {
        global $wpdb;
        $table = self::table();
        // updated_at is fetched so the AI loop can pass it back into
        // set_classification() as a concurrency token — if a save_post
        // hook fires between fetch and write, the row's content may
        // already be stale relative to the LLM's input.
        return $wpdb->get_row(
            "SELECT id, post_id, title, content, updated_at FROM {$table}
             WHERE classification IS NULL AND content != '' AND CHAR_LENGTH(content) >= 20
             ORDER BY id ASC LIMIT 1"
        );
    }

    /**
     * Set the AI-derived classification for a row.
     *
     * @param int         $id
     * @param string      $classification
     * @param string|null $expected_updated_at  Optional concurrency token.
     *   If provided and the row's current updated_at no longer matches,
     *   the write is skipped (the row was mutated by a hook mid-call).
     *   Returns false in that case; the queue will pick the row up again
     *   on the next iteration since classification is still NULL.
     * @return bool true on write, false on stale-skip
     */
    public static function set_classification($id, $classification, $expected_updated_at = null) {
        global $wpdb;
        $table = self::table();
        if ($expected_updated_at !== null) {
            $current = $wpdb->get_var($wpdb->prepare(
                "SELECT updated_at FROM {$table} WHERE id = %d",
                $id
            ));
            if ($current !== $expected_updated_at) {
                return false;
            }
        }
        $wpdb->update($table,
            array('classification' => $classification, 'classified_at' => current_time('mysql')),
            array('id' => $id)
        );
        return true;
    }

    /**
     * Mark very-short or empty rows as 'other' so they don't block the queue.
     */
    public static function flush_empty_to_other() {
        global $wpdb;
        $table = self::table();
        return $wpdb->query(
            "UPDATE {$table}
             SET classification = 'other', classified_at = NOW()
             WHERE classification IS NULL AND (content = '' OR CHAR_LENGTH(content) < 20)"
        );
    }

    public static function next_unstructured() {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row(
            "SELECT id, post_id, title, content, classification, updated_at FROM {$table}
             WHERE structured IS NULL
               AND classification IS NOT NULL
               AND classification != 'other'
             ORDER BY id ASC LIMIT 1"
        );
    }

    /**
     * Set the AI-derived structured content for a row. Same
     * concurrency-token semantics as set_classification(): if
     * $expected_updated_at is passed and the row has since been mutated
     * by a hook, the write is skipped (the queue picks it up on the
     * next iteration since structured is still NULL).
     *
     * @return bool true on write, false on stale-skip
     */
    public static function set_structured($id, $structured, $expected_updated_at = null) {
        global $wpdb;
        $table = self::table();
        if ($expected_updated_at !== null) {
            $current = $wpdb->get_var($wpdb->prepare(
                "SELECT updated_at FROM {$table} WHERE id = %d",
                $id
            ));
            if ($current !== $expected_updated_at) {
                return false;
            }
        }
        $wpdb->update($table,
            array('structured' => $structured, 'structured_at' => current_time('mysql')),
            array('id' => $id)
        );
        return true;
    }

    /**
     * Delete the KB row(s) for a given post. Idempotent — silent no-op
     * if no row exists. Called by the post-lifecycle hooks in Extractor
     * when a post moves out of the publish state or is hard-deleted.
     */
    public static function delete_for_post($post_id) {
        global $wpdb;
        $wpdb->delete(self::table(), array('post_id' => (int) $post_id));
    }

    public static function stats() {
        global $wpdb;
        $table = self::table();

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $by_class = $wpdb->get_results(
            "SELECT COALESCE(classification, 'unclassified') AS k, COUNT(*) AS n
             FROM {$table} GROUP BY classification"
        );
        $needs_classify = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE classification IS NULL AND content != '' AND CHAR_LENGTH(content) >= 20"
        );
        $needs_structure = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE structured IS NULL AND classification IS NOT NULL AND classification != 'other'"
        );

        $classes = array();
        foreach ($by_class as $row) {
            $classes[$row->k] = (int) $row->n;
        }

        return array(
            'total'           => $total,
            'by_class'        => $classes,
            'needs_classify'  => $needs_classify,
            'needs_structure' => $needs_structure,
        );
    }

    public static function clear() {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE " . self::table());
        self::flush_cache();
    }

    /**
     * Drop both KB context-block caches so the next render reflects the
     * current DB state. Called from anywhere that mutates KB rows OR
     * artifacts (artifacts feed into the same brainstorm context).
     */
    public static function flush_cache() {
        delete_transient('zen_cortext_kb_cache');
        delete_transient('zen_cortext_kb_brainstorm_cache');
    }

    /**
     * Build the KB context block injected into the system prompt.
     * Cached in a transient for 5 minutes.
     */
    public static function build_context_block() {
        $cached = get_transient('zen_cortext_kb_cache');
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results(
            "SELECT classification, structured FROM {$table}
             WHERE structured IS NOT NULL AND structured != ''"
        );

        if (empty($rows)) {
            $block = "\n\n[Knowledge base empty]\n";
            set_transient('zen_cortext_kb_cache', $block, 300);
            return $block;
        }

        $grouped = array();
        foreach ($rows as $row) {
            $cls = $row->classification ?: 'other';
            if (!isset($grouped[$cls])) $grouped[$cls] = array();
            $grouped[$cls][] = $row->structured;
        }

        // Labels come from the editable content-types option; the option
        // is the single source of truth, replacing the formerly-hardcoded
        // 4-key map. Iteration follows the admin-defined display order.
        $labels = class_exists('Zen_Cortext_KB_Types')
            ? Zen_Cortext_KB_Types::labels()
            : array();

        $block = "\n\n## Knowledge Base\nUse these real cases, articles, and service descriptions to ground your answers with specific details.\n\n";
        $emitted_keys = array();
        foreach ($labels as $key => $label) {
            if (!empty($grouped[$key])) {
                $block .= "### {$label}\n\n";
                foreach ($grouped[$key] as $content) {
                    $block .= $content . "\n\n---\n\n";
                }
                $emitted_keys[$key] = true;
            }
        }
        // Orphan rows: rows whose classification slug is no longer in the
        // types option (e.g. admin deleted the type without resetting the
        // rows). Bucket them under a generic heading rather than silently
        // dropping them. Skip 'other' — those rows are unstructured anyway
        // (next_unstructured() filters them out).
        $orphans = array();
        foreach ($grouped as $key => $bodies) {
            if (isset($emitted_keys[$key])) continue;
            if ($key === 'other') continue;
            $orphans = array_merge($orphans, $bodies);
        }
        if (!empty($orphans)) {
            $block .= "### Other content\n\n";
            foreach ($orphans as $content) {
                $block .= $content . "\n\n---\n\n";
            }
        }

        set_transient('zen_cortext_kb_cache', $block, 300);
        return $block;
    }

    /**
     * Wider context block for the admin Brainstorm page.
     *
     * The visitor-facing build_context_block() only returns rows that have
     * been classified AND restructured (clean, schema-conformant). That's
     * the right policy for the public assistant but useless for brainstorm
     * if the admin hasn't run the pipeline yet.
     *
     * This variant returns ALL synced posts: it prefers the structured
     * version when present, and falls back to the raw post content
     * otherwise — so brainstorming has access to the full site content
     * even when nothing has been classified yet.
     *
     * Cached separately for 5 minutes.
     */
    public static function build_brainstorm_context_block() {
        $cached = get_transient('zen_cortext_kb_brainstorm_cache');
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results(
            "SELECT post_id, post_type, title, content, structured, classification
             FROM {$table}
             ORDER BY post_type ASC, id ASC"
        );

        if (empty($rows)) {
            $block = "\n\n[Knowledge base empty — no posts have been synced.]\n";
            set_transient('zen_cortext_kb_brainstorm_cache', $block, 300);
            return $block;
        }

        // Group by post_type so the model can navigate by content kind
        // (case studies, FAQs, services, blog posts, etc.).
        $grouped = array();
        foreach ($rows as $row) {
            $key = $row->post_type ?: 'other';
            if (!isset($grouped[$key])) $grouped[$key] = array();
            $grouped[$key][] = $row;
        }

        // Resolve site-structure roles once, not per row.
        $site_roles = self::resolve_site_roles();

        $site_host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        if ($site_host === '') $site_host = 'this site';

        $block  = "\n\n## Knowledge Base — Full Site Content\n";
        $block .= "Every published page and post on {$site_host} that has been synced into the KB. Grouped by post type. Each entry is annotated with its URL and any structural role (front page, blog index, shop page, etc.) so you can reason about WHERE in the site each piece of content lives, not just WHAT it says. Where a structured version exists it's used; otherwise the raw post content is included verbatim. Use this freely as ground truth for ideation.\n\n";

        foreach ($grouped as $type => $items) {
            $block .= "### post_type: {$type} (" . count($items) . " items)\n\n";
            foreach ($items as $row) {
                $title = trim((string) $row->title);
                if ($title === '') $title = '(untitled)';
                $block .= "#### {$title}\n";
                $meta = self::row_metadata_lines((int) $row->post_id, $row->classification, $site_roles);
                if ($meta !== '') {
                    $block .= $meta;
                }
                $body = !empty($row->structured) ? $row->structured : (string) $row->content;
                $body = trim($body);
                if ($body !== '') {
                    $block .= "\n" . $body . "\n";
                }
                $block .= "\n---\n\n";
            }
        }

        set_transient('zen_cortext_kb_brainstorm_cache', $block, 300);
        return $block;
    }

    /**
     * Resolve the post IDs that play special structural roles on the site.
     * Looked up once per context-block render so we don't hit get_option()
     * inside a per-row loop.
     */
    private static function resolve_site_roles() {
        $roles = array(
            'front_page' => (int) get_option('page_on_front'),
            'posts_page' => (int) get_option('page_for_posts'),
            'shop_page'  => 0,
            'cart_page'  => 0,
            'checkout_page' => 0,
            'account_page'  => 0,
        );
        if (function_exists('wc_get_page_id')) {
            foreach (array('shop', 'cart', 'checkout', 'myaccount') as $wc_key) {
                $id = (int) wc_get_page_id($wc_key);
                if ($id > 0) {
                    $roles[$wc_key === 'myaccount' ? 'account_page' : $wc_key . '_page'] = $id;
                }
            }
        }
        return $roles;
    }

    /**
     * Render the per-row metadata lines: structural role(s), permalink,
     * classification. Empty string when nothing useful to add.
     */
    private static function row_metadata_lines($post_id, $classification, $site_roles) {
        if ($post_id <= 0) return '';

        $parts = array();

        $role_labels = array();
        if ($post_id === $site_roles['front_page'])    $role_labels[] = 'site front page (home)';
        if ($post_id === $site_roles['posts_page'])    $role_labels[] = 'blog index page';
        if ($post_id === $site_roles['shop_page'])     $role_labels[] = 'WooCommerce shop page';
        if ($post_id === $site_roles['cart_page'])     $role_labels[] = 'WooCommerce cart page';
        if ($post_id === $site_roles['checkout_page']) $role_labels[] = 'WooCommerce checkout page';
        if ($post_id === $site_roles['account_page'])  $role_labels[] = 'WooCommerce my account page';
        if (!empty($role_labels)) {
            $parts[] = '_role: ' . implode(' · ', $role_labels) . '_';
        }

        $url = get_permalink($post_id);
        if ($url) {
            $parts[] = '_url: ' . $url . '_';
        }

        if (!empty($classification)) {
            $parts[] = '_classification: ' . $classification . '_';
        }

        return empty($parts) ? '' : implode("\n", $parts) . "\n";
    }
}
