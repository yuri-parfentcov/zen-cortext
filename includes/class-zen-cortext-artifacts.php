<?php
/**
 * Knowledge Artifacts storage and queries (custom table wp_zen_cortext_artifacts).
 *
 * Artifacts are hand-authored knowledge items (not extracted from WP posts).
 * They live alongside the post-derived KB and feed into the same chat context block.
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

class Zen_Cortext_Artifacts {

    const VALID_SOURCES = array('manual', 'chat');

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'zen_cortext_artifacts';
    }

    /**
     * Valid artifact types are sourced from the admin-managed KB content
     * types option (`zen_cortext_content_types`). Artifact types and KB
     * classifier categories share one taxonomy — the dropdown in the
     * Artifacts tab is rendered from the same list the KB tab edits.
     *
     * Note: 'other' is NOT a valid artifact type — it's the classifier's
     * structural fallback for off-topic content; admins should never
     * hand-author an artifact as 'other'.
     */
    public static function valid_types() {
        return class_exists('Zen_Cortext_KB_Types')
            ? Zen_Cortext_KB_Types::slugs()
            : array();
    }

    public static function valid_type($type) {
        return in_array($type, self::valid_types(), true);
    }

    public static function valid_source($source) {
        return in_array($source, self::VALID_SOURCES, true);
    }

    /**
     * Insert a new artifact. Returns the new row id, or WP_Error on failure.
     */
    public static function create($data) {
        global $wpdb;

        $title     = isset($data['title']) ? trim((string) $data['title']) : '';
        $type      = isset($data['type']) ? (string) $data['type'] : '';
        $raw       = isset($data['raw_content']) ? (string) $data['raw_content'] : '';
        $source    = isset($data['source']) ? (string) $data['source'] : 'manual';
        $author_id = !empty($data['author_id']) ? absint($data['author_id']) : null;

        if ($title === '') {
            return new WP_Error('zen_cortext_artifact', 'Title is required.');
        }
        if (!self::valid_type($type)) {
            return new WP_Error('zen_cortext_artifact', 'Invalid type.');
        }
        if (!self::valid_source($source)) {
            $source = 'manual';
        }

        $now = current_time('mysql');
        $ok = $wpdb->insert(self::table(), array(
            'title'       => $title,
            'type'        => $type,
            'raw_content' => $raw,
            'structured'  => null,
            'source'      => $source,
            'author_id'   => $author_id,
            'created_at'  => $now,
            'updated_at'  => $now,
        ));
        if ($ok === false) {
            return new WP_Error('zen_cortext_artifact', 'DB insert failed: ' . $wpdb->last_error);
        }

        Zen_Cortext_KB::flush_cache();
        return (int) $wpdb->insert_id;
    }

    /**
     * Update an existing artifact. Pass any subset of: title, type, raw_content, structured, source.
     */
    public static function update($id, $data) {
        global $wpdb;
        $id = (int) $id;
        if ($id <= 0) {
            return new WP_Error('zen_cortext_artifact', 'Invalid id.');
        }

        $update = array('updated_at' => current_time('mysql'));

        if (isset($data['title'])) {
            $title = trim((string) $data['title']);
            if ($title === '') {
                return new WP_Error('zen_cortext_artifact', 'Title cannot be empty.');
            }
            $update['title'] = $title;
        }
        if (isset($data['type'])) {
            if (!self::valid_type($data['type'])) {
                return new WP_Error('zen_cortext_artifact', 'Invalid type.');
            }
            $update['type'] = (string) $data['type'];
        }
        if (array_key_exists('raw_content', $data)) {
            $update['raw_content'] = (string) $data['raw_content'];
        }
        if (array_key_exists('structured', $data)) {
            $update['structured'] = $data['structured'] === null ? null : (string) $data['structured'];
        }
        if (isset($data['source']) && self::valid_source($data['source'])) {
            $update['source'] = (string) $data['source'];
        }
        if (array_key_exists('author_id', $data)) {
            $update['author_id'] = !empty($data['author_id']) ? absint($data['author_id']) : null;
        }

        $ok = $wpdb->update(self::table(), $update, array('id' => $id));
        if ($ok === false) {
            return new WP_Error('zen_cortext_artifact', 'DB update failed: ' . $wpdb->last_error);
        }

        Zen_Cortext_KB::flush_cache();
        return true;
    }

    public static function set_structured($id, $structured) {
        return self::update($id, array('structured' => (string) $structured));
    }

    public static function delete($id) {
        global $wpdb;
        $id = (int) $id;
        if ($id <= 0) {
            return new WP_Error('zen_cortext_artifact', 'Invalid id.');
        }
        $ok = $wpdb->delete(self::table(), array('id' => $id), array('%d'));
        if ($ok === false) {
            return new WP_Error('zen_cortext_artifact', 'DB delete failed: ' . $wpdb->last_error);
        }
        Zen_Cortext_KB::flush_cache();
        return true;
    }

    public static function get($id) {
        global $wpdb;
        $id = (int) $id;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d",
            $id
        ), ARRAY_A);
    }

    /**
     * List artifacts. Optional filters: ['type' => '...']
     * Always sorted by updated_at DESC.
     */
    public static function all($filters = array()) {
        global $wpdb;
        $table = self::table();

        $where = '1=1';
        $args  = array();
        if (!empty($filters['type']) && self::valid_type($filters['type'])) {
            $where .= ' AND type = %s';
            $args[] = $filters['type'];
        }

        $sql = "SELECT id, title, type, source, author_id, created_at, updated_at,
                       CHAR_LENGTH(raw_content) AS raw_len,
                       CHAR_LENGTH(COALESCE(structured, '')) AS structured_len
                FROM {$table}
                WHERE {$where}
                ORDER BY updated_at DESC, id DESC";

        if (!empty($args)) {
            $sql = $wpdb->prepare($sql, $args);
        }
        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Lightweight search for the reference-picker UI. Case-insensitive title
     * LIKE match. Returns id/title/type/updated_at — enough for the dropdown.
     * Optional $exclude_id keeps the artifact currently being edited out of
     * its own reference list.
     */
    public static function search($query, $exclude_id = 0, $limit = 20) {
        global $wpdb;
        $table = self::table();

        $query = trim((string) $query);
        $exclude_id = (int) $exclude_id;
        $limit = max(1, min(50, (int) $limit));

        $where  = '1=1';
        $args   = array();

        if ($query !== '') {
            $where .= ' AND title LIKE %s';
            $args[] = '%' . $wpdb->esc_like($query) . '%';
        }
        if ($exclude_id > 0) {
            $where .= ' AND id != %d';
            $args[] = $exclude_id;
        }

        $sql = "SELECT id, title, type, updated_at
                FROM {$table}
                WHERE {$where}
                ORDER BY updated_at DESC, id DESC
                LIMIT %d";
        $args[] = $limit;

        return $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
    }

    /**
     * Fetch multiple artifacts by id, in the order requested. Used to inject
     * reference artifacts into the artifact builder chat / synthesizer.
     */
    public static function get_many(array $ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) return array();

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $table = self::table();
        // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        // $placeholders is a runtime-built list of %d tokens (count matches $ids)
        // for the IN() expansion; $wpdb->prepare receives them all in the
        // second arg. The linter can't see through the variable-length list.
        $sql = $wpdb->prepare(
            "SELECT id, title, type, raw_content, structured FROM {$table} WHERE id IN ({$placeholders})",
            $ids
        );
        // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $rows = $wpdb->get_results($sql, ARRAY_A);

        // Re-order to match requested ID order.
        $by_id = array();
        foreach ($rows as $r) { $by_id[(int) $r['id']] = $r; }
        $ordered = array();
        foreach ($ids as $id) {
            if (isset($by_id[$id])) $ordered[] = $by_id[$id];
        }
        return $ordered;
    }

    /**
     * Build a guardrailed reference block from a list of artifact IDs. The
     * block is intended to be appended to the system prompt of the artifact
     * builder chat or the synthesizer. It explicitly tells the model to use
     * the references as background only, not as source material for the new
     * artifact.
     */
    public static function build_reference_block(array $ids, $exclude_id = 0) {
        $exclude_id = (int) $exclude_id;
        $ids = array_values(array_filter(array_map('intval', $ids), function ($id) use ($exclude_id) {
            return $id > 0 && $id !== $exclude_id;
        }));
        if (empty($ids)) return '';

        $rows = self::get_many($ids);
        if (empty($rows)) return '';

        $block  = "\n\n# Reference artifacts (read-only background)\n\n";
        $block .= "The user has selected the existing artifacts below as background context for this session. Treat them as reference material, not source material.\n\n";
        $block .= "You MAY use them to:\n";
        $block .= "- Match terminology, tone, and structure of existing artifacts\n";
        $block .= "- Detect when the user is creating a duplicate or update of one of these\n";
        $block .= "- Understand the broader context (related work, recurring themes, internal vocabulary)\n\n";
        $block .= "You MUST NOT:\n";
        $block .= "- Add facts from these references to the new artifact unless the user explicitly mentions them in chat\n";
        $block .= "- Treat their content as something the user 'already said' — only the user's actual chat messages count as source material\n";
        $block .= "- Skip questions because 'we probably know this from the references'\n";
        $block .= "- Quote or paraphrase the references back to the user as if they wrote them\n\n";
        $block .= "The new artifact's content comes from the user. References are background only.\n\n---\n\n";

        foreach ($rows as $r) {
            $body = trim((string) $r['structured']);
            if ($body === '') {
                $body = trim((string) $r['raw_content']);
            }
            if ($body === '') continue;
            $block .= "## {$r['title']} (" . $r['type'] . ")\n\n" . $body . "\n\n---\n\n";
        }

        return $block;
    }

    public static function stats() {
        global $wpdb;
        $table = self::table();

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $by_type_rows = $wpdb->get_results("SELECT type, COUNT(*) AS n FROM {$table} GROUP BY type");

        $by_type = array();
        foreach ($by_type_rows as $row) {
            $by_type[$row->type] = (int) $row->n;
        }
        // Ensure all types appear, even with zero counts.
        foreach (self::valid_types() as $t) {
            if (!isset($by_type[$t])) $by_type[$t] = 0;
        }

        return array(
            'total'   => $total,
            'by_type' => $by_type,
        );
    }

    /**
     * Build the artifacts context block to append to the chat system prompt.
     * Mirrors Zen_Cortext_KB::build_context_block() — groups by type, uses
     * the same labels for the four shared types, plus 'general_info' as
     * "Company Information". Falls back to raw_content when structured is empty.
     */
    public static function build_context_block() {
        global $wpdb;
        $table = self::table();

        $rows = $wpdb->get_results(
            "SELECT type, structured, raw_content, title, author_id FROM {$table}
             ORDER BY type ASC, id ASC"
        );

        if (empty($rows)) {
            return '';
        }

        // Cache author lookups (only a few users).
        $author_cache = array();

        $grouped = array();
        foreach ($rows as $row) {
            $body = trim((string) $row->structured);
            if ($body === '') {
                // Artifact not yet restructured — fall back to raw content with title heading.
                $body = "# " . trim((string) $row->title) . "\n\n" . trim((string) $row->raw_content);
            }
            if ($body === '') continue;

            // Prepend author attribution if set.
            if (!empty($row->author_id)) {
                $uid = (int) $row->author_id;
                if (!isset($author_cache[$uid])) {
                    $u = get_userdata($uid);
                    $author_cache[$uid] = $u ? $u->display_name : '';
                }
                if ($author_cache[$uid] !== '') {
                    $body = "**Author: " . $author_cache[$uid] . "**\n\n" . $body;
                }
            }

            $type = $row->type ?: '';
            if ($type === '') continue;
            if (!isset($grouped[$type])) $grouped[$type] = array();
            $grouped[$type][] = $body;
        }

        if (empty($grouped)) {
            return '';
        }

        // Labels come from the unified content types option. The KB tab's
        // editor is the single source of truth. Heading is suffixed with
        // "(curated)" to distinguish artifact-sourced entries from
        // post-derived KB entries in the chat context block.
        $kb_labels = class_exists('Zen_Cortext_KB_Types')
            ? Zen_Cortext_KB_Types::labels()
            : array();

        $block = "\n\n## Knowledge Artifacts\nHand-curated knowledge items maintained by the team. Treat these as authoritative for the topics they cover.\n\n";

        $emitted = array();
        foreach ($kb_labels as $key => $label) {
            if (!empty($grouped[$key])) {
                $block .= "### {$label} (curated)\n\n";
                foreach ($grouped[$key] as $content) {
                    $block .= $content . "\n\n---\n\n";
                }
                $emitted[$key] = true;
            }
        }
        // Orphan artifacts (type no longer in content types — admin
        // removed the slug after artifacts were created). Bucket them so
        // they're not silently dropped from the chat context.
        $orphans = array();
        foreach ($grouped as $key => $bodies) {
            if (isset($emitted[$key])) continue;
            $orphans = array_merge($orphans, $bodies);
        }
        if (!empty($orphans)) {
            $block .= "### Other curated content\n\n";
            foreach ($orphans as $content) {
                $block .= $content . "\n\n---\n\n";
            }
        }

        return $block;
    }
}
