<?php
/**
 * Zen Cortext — KB Content Types helper.
 *
 * Single source of truth for the editable list of content types the KB
 * classifier+restructurer works with. Replaces the hardcoded const +
 * inline-in-prompt category list that used to live across api.php,
 * defaults.php, kb.php, kb-page.php, and admin.php.
 *
 * The 'other' bucket is NOT user-editable — it's the structural fallback
 * the classifier returns when nothing matches, baked in here.
 *
 * Artifacts continue reading the legacy `zen_cortext_restructure_prompts`
 * option; they're fully decoupled from this list.
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

class Zen_Cortext_KB_Types {

    const OPTION_KEY      = 'zen_cortext_content_types';
    const RESERVED_OTHER  = 'other';
    const PLACEHOLDER     = '<<categories>>'; // strtr-safe; admins are unlikely to write the literal '<<categories>>' in a prompt.

    /**
     * Slugs that may never be used by admin-defined types. 'other' is
     * structural; 'general_info' is the artifact-only key in the legacy
     * option; 'unclassified' is the placeholder key in KB::stats().
     */
    const RESERVED_SLUGS  = array('other', 'general_info', 'unclassified');

    /**
     * Return the ordered list of admin-defined content types.
     *
     * @return array<int,array{slug:string,label:string,description:string,restructure_prompt:string}>
     */
    public static function all() {
        $raw = get_option(self::OPTION_KEY, null);
        if (!is_array($raw)) {
            // Activation usually seeds this; if the option somehow doesn't
            // exist (manual delete, partial install), fall back to the
            // bundled defaults so the classifier still runs.
            return Zen_Cortext_Defaults::content_types();
        }
        $clean = array();
        foreach ($raw as $t) {
            if (!is_array($t)) continue;
            $slug = isset($t['slug']) ? (string) $t['slug'] : '';
            if ($slug === '') continue;
            $clean[] = array(
                'slug'               => $slug,
                'label'              => isset($t['label']) ? (string) $t['label'] : $slug,
                'description'        => isset($t['description']) ? (string) $t['description'] : '',
                'restructure_prompt' => isset($t['restructure_prompt']) ? (string) $t['restructure_prompt'] : '',
            );
        }
        return $clean;
    }

    public static function slugs() {
        return array_map(static function ($t) { return $t['slug']; }, self::all());
    }

    /**
     * Slugs the classifier may legitimately return. Includes the
     * structural 'other' bucket as the last entry — admins never see
     * it in the editor but it lives in DB rows for off-topic content.
     */
    public static function valid_for_classifier() {
        $s = self::slugs();
        $s[] = self::RESERVED_OTHER;
        return $s;
    }

    public static function get($slug) {
        foreach (self::all() as $t) {
            if ($t['slug'] === $slug) return $t;
        }
        return null;
    }

    /**
     * slug => label map. Used by KB::build_context_block() for chat
     * context group headings.
     */
    public static function labels() {
        $out = array();
        foreach (self::all() as $t) {
            $out[$t['slug']] = $t['label'] !== '' ? $t['label'] : $t['slug'];
        }
        return $out;
    }

    /**
     * slug => restructure prompt map. Used by API::restructure() per row.
     */
    public static function restructure_prompts() {
        $out = array();
        foreach (self::all() as $t) {
            $out[$t['slug']] = $t['restructure_prompt'];
        }
        return $out;
    }

    /**
     * Assemble the bullet-list categories block that gets substituted
     * into the classify prompt template. Always ends with the
     * structural 'other' bucket so the LLM has somewhere to land
     * unclassifiable content.
     */
    public static function assemble_categories_block() {
        $lines = array();
        foreach (self::all() as $t) {
            $desc = trim($t['description']) !== '' ? $t['description'] : $t['label'];
            $lines[] = '- ' . $t['slug'] . ': ' . $desc;
        }
        $lines[] = '- other: Anything that does not fit any category above (legal pages, contact, etc.)';
        return implode("\n", $lines);
    }

    /**
     * Validate + persist a full types array. Idempotent: re-saving the
     * same list is a no-op. On success also writes the legacy
     * `zen_cortext_restructure_prompts` so artifact code that still reads
     * that option continues to find prompts for shared slugs — but the
     * artifact-only `general_info` key is preserved verbatim from the
     * existing option (we never clobber it).
     *
     * @param array $types  Raw input — same shape as all()
     * @return true|WP_Error
     */
    public static function save(array $types) {
        $clean = array();
        $seen_slugs = array();

        foreach ($types as $i => $t) {
            if (!is_array($t)) {
                return new WP_Error('zen_cortext_types', "Row {$i} is not an object.");
            }

            $slug  = isset($t['slug'])  ? strtolower(trim((string) $t['slug'])) : '';
            $label = isset($t['label']) ? trim((string) $t['label']) : '';
            $desc  = isset($t['description']) ? trim((string) $t['description']) : '';
            $prompt = isset($t['restructure_prompt']) ? (string) $t['restructure_prompt'] : '';

            $err = self::validate_slug($slug);
            if (is_wp_error($err)) {
                return new WP_Error('zen_cortext_types', "Row {$i}: " . $err->get_error_message());
            }

            // Duplicate detection (case-insensitive — slugs are already lowercased).
            if (isset($seen_slugs[$slug])) {
                return new WP_Error('zen_cortext_types', "Duplicate slug: {$slug}");
            }

            // Prefix collision check — keeps the classifier's longest-first
            // fuzzy match deterministic. If 'case' and 'case_study' both
            // exist, the LLM could return either and we couldn't reliably
            // pick.
            foreach ($seen_slugs as $existing => $_) {
                if (strpos($existing, $slug) === 0 || strpos($slug, $existing) === 0) {
                    if ($existing !== $slug) {
                        return new WP_Error('zen_cortext_types', "Slug '{$slug}' collides with existing slug '{$existing}' (one is a prefix of the other).");
                    }
                }
            }

            if ($label === '') {
                return new WP_Error('zen_cortext_types', "Row {$i} ({$slug}): label is required.");
            }

            $clean[] = array(
                'slug'               => $slug,
                'label'              => sanitize_text_field($label),
                'description'        => sanitize_textarea_field($desc),
                'restructure_prompt' => wp_unslash($prompt), // textareas; preserve newlines + free-form punctuation.
            );
            $seen_slugs[$slug] = true;
        }

        update_option(self::OPTION_KEY, $clean);

        // The artifact-editor's `zen_cortext_restructure_prompts` option
        // is independent from this point forward. We do NOT mirror into
        // it on save: a previous design did, but it meant editing one
        // KB type clobbered all 5 keys on the artifact side any time the
        // KB list was shorter than the artifact list. Artifacts now keep
        // whatever the migration seeded plus any edits made via the
        // (unchanged) artifact editor — fully decoupled from KB types.

        // Bust caches so live render reflects new labels/prompts.
        if (class_exists('Zen_Cortext_KB')) {
            Zen_Cortext_KB::flush_cache();
        }

        return true;
    }

    /**
     * Delete a content type. Two classes of consumer reference the slug:
     *
     *  - **KB rows** (post-derived, AI-classified): on $force=true these
     *    are reset to NULL classification + structured, requeuing them
     *    for the next Rebuild against whatever type fits best.
     *  - **Artifacts** (hand-authored): these CANNOT be safely auto-reset
     *    because their type was a deliberate author choice and the body
     *    text is hand-curated. If artifacts use this slug, delete is
     *    BLOCKED with a clear instruction to re-type them first.
     *
     * Return values:
     *  - WP_Error('zen_cortext_types_artifacts_in_use')  blocked by artifacts
     *  - WP_Error('zen_cortext_types_in_use')             blocked by KB rows (force=false)
     *  - array{kb_rows_affected:int}                      success
     *
     * @return array{kb_rows_affected:int}|WP_Error
     */
    public static function delete($slug, $force = false) {
        $err = self::validate_slug($slug);
        if (is_wp_error($err)) return $err;

        $types = self::all();
        $index = null;
        foreach ($types as $i => $t) {
            if ($t['slug'] === $slug) { $index = $i; break; }
        }
        if ($index === null) {
            return new WP_Error('zen_cortext_types', "Slug not found: {$slug}");
        }

        global $wpdb;

        // Hard block on artifact usage — artifacts are hand-curated and
        // can't be auto-reset; admin must re-type them via the Artifacts
        // tab before this slug can go.
        if (class_exists('Zen_Cortext_Artifacts')) {
            $artifact_table = Zen_Cortext_Artifacts::table();
            $artifact_rows = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$artifact_table} WHERE type = %s",
                $slug
            ));
            if ($artifact_rows > 0) {
                return new WP_Error(
                    'zen_cortext_types_artifacts_in_use',
                    sprintf(
                        /* translators: %d is the number of Knowledge Artifacts using this content type. */
                        _n(
                            '%d artifact uses this type. Open the Knowledge Artifacts tab and re-type it before deleting this content type.',
                            '%d artifacts use this type. Open the Knowledge Artifacts tab and re-type them before deleting this content type.',
                            $artifact_rows,
                            'zen-cortext'
                        ),
                        $artifact_rows
                    ),
                    array('artifact_rows_affected' => $artifact_rows)
                );
            }
        }

        // KB rows: confirm + reset on force=true.
        $kb_table = Zen_Cortext_KB::table();
        $rows = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$kb_table} WHERE classification = %s",
            $slug
        ));

        if ($rows > 0 && !$force) {
            return new WP_Error(
                'zen_cortext_types_in_use',
                /* translators: %d is the number of Knowledge Base rows currently classified with this content type. */
                sprintf(_n('%d KB row uses this type — reset and delete?', '%d KB rows use this type — reset and delete?', $rows, 'zen-cortext'), $rows),
                array('kb_rows_affected' => $rows)
            );
        }

        if ($rows > 0) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$kb_table}
                 SET classification = NULL,
                     structured = NULL,
                     classified_at = NULL,
                     structured_at = NULL,
                     updated_at = %s
                 WHERE classification = %s",
                current_time('mysql'),
                $slug
            ));
        }

        // Remove the type from the option array, re-index, save.
        array_splice($types, $index, 1);
        $result = self::save($types);
        if (is_wp_error($result)) return $result;

        Zen_Cortext_KB::flush_cache();
        return array('kb_rows_affected' => $rows);
    }

    /**
     * Slug rules: lowercase, starts with letter, 2-31 chars total, only
     * a-z 0-9 _. Reserved slugs forbidden.
     */
    public static function validate_slug($slug) {
        if (!is_string($slug) || $slug === '') {
            return new WP_Error('zen_cortext_slug', __('Slug is required.', 'zen-cortext'));
        }
        if (!preg_match('/^[a-z][a-z0-9_]{1,30}$/', $slug)) {
            return new WP_Error('zen_cortext_slug', __('Slug must be lowercase letters, digits, or underscores; start with a letter; 2-31 characters.', 'zen-cortext'));
        }
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            /* translators: %s is the reserved slug the admin attempted to use. */
            return new WP_Error('zen_cortext_slug', sprintf(__('Slug "%s" is reserved.', 'zen-cortext'), $slug));
        }
        return true;
    }
}
