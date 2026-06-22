<?php
/**
 * Tiny template engine for the visitor chat templates.
 *
 * Why this exists: the editable templates used to be raw PHP, which meant
 * a typo in the chat editor could 500 the public /talk/ page. By compiling
 * a Mustache-flavoured placeholder syntax instead, admins can break the
 * markup but never the runtime — invalid placeholders fall back to empty,
 * unbalanced blocks are rejected at save time.
 *
 * Supported syntax:
 *
 *   {{ key }}             escape-html interpolation
 *   {{ raw:key }}         raw HTML (caller is responsible for sanitisation)
 *   {{ url:key }}         esc_url
 *   {{ attr:key }}        esc_attr
 *   {{ t:Some string }}   __('Some string', 'zen-cortext') then esc_html
 *
 *   {{ if:key }} … {{ /if }}     show block when key is truthy (non-empty,
 *                                non-zero, non-null)
 *   {{ if:!key }} … {{ /if }}    show block when key is falsy
 *   {{ each:key }} … {{ /each }} loop over an array; inside the loop,
 *                                each item's fields are merged into the
 *                                local context, and `index0` / `index1`
 *                                are added.
 *
 *   Dot notation: {{ intro.name }}, {{ url:intro.site_url }}.
 *
 * Templates must NOT contain raw PHP: a save-time validator rejects any
 * `<?` sequence so a copy-paste from the old templates can't smuggle code
 * back in.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zen_Cortext_Template_Renderer {

    /* ================================================================
       Registry — single source of truth for editable templates
       ================================================================ */

    public static function registry() {
        $base_views  = ZEN_CORTEXT_PLUGIN_DIR . 'public/views/';
        $base_pages  = ZEN_CORTEXT_PLUGIN_DIR . 'public/templates/';
        $base_assets = ZEN_CORTEXT_PLUGIN_DIR . 'public/assets/';
        $writable    = self::writable_root();
        return array(
            'chat.tpl.html' => array(
                'label'    => __('Chat shell (chat.tpl.html)', 'zen-cortext'),
                'factory'  => $base_views . 'factory/chat.tpl.html',
                // Admin-edited "live" source + version history now live in
                // the database (wp_options), not on disk — see
                // get_live_source()/set_live_source(). The factory copy
                // ships read-only inside the plugin and is the fallback.
                // `live`/`versions` paths are retained ONLY so the one-time
                // file→DB migration can find + import + delete pre-2.39.2
                // uploads copies.
                'slug'     => 'chat',
                'live'     => $writable . 'templates/chat.tpl.html',
                'versions' => $writable . 'versions/chat.tpl.html/',
                'kind'     => 'template',
                'mode'     => 'text/html',
            ),
            'chat-page-body.tpl.html' => array(
                'label'    => __('Full page wrapper body (chat-page-body.tpl.html)', 'zen-cortext'),
                'factory'  => $base_pages . 'factory/chat-page-body.tpl.html',
                'slug'     => 'chat_page_body',
                'live'     => $writable . 'templates/chat-page-body.tpl.html',
                'versions' => $writable . 'versions/chat-page-body.tpl.html/',
                'kind'     => 'template',
                'mode'     => 'text/html',
            ),
            // chat.css — the chat's stylesheet. Edited the same way as the
            // templates; the live source lives in the DB. The shortcode +
            // wrapper enqueue the bundled factory CSS file by default, or
            // print the customized source as inline CSS when the admin has
            // saved an edit (see Zen_Cortext_Template_Renderer helpers).
            'chat.css' => array(
                'label'    => __('Chat stylesheet (chat.css)', 'zen-cortext'),
                'factory'  => $base_assets . 'factory/chat.css',
                'slug'     => 'chat_css',
                'live'     => $writable . 'assets/chat.css',
                'versions' => $writable . 'versions/chat.css/',
                'kind'     => 'css',
                'mode'     => 'text/css',
            ),
            // Email transcript template — rendered by the /chat/{uid}/email
            // endpoint into a wp_mail() HTML body when the visitor clicks
            // "Email me a copy". Same Mustache-flavoured DSL as the chat
            // shell; admin can rebrand without touching PHP.
            'email/chat-transcript.html' => array(
                'label'    => __('Email — chat transcript (email/chat-transcript.html)', 'zen-cortext'),
                'factory'  => $base_views . 'factory/email/chat-transcript.html',
                'slug'     => 'email_chat_transcript',
                'live'     => $writable . 'templates/email/chat-transcript.html',
                'versions' => $writable . 'versions/email/chat-transcript.html/',
                'kind'     => 'template',
                'mode'     => 'text/html',
            ),
        );
    }

    /* ================================================================
       Database-backed source storage (replaces on-disk live files)
       ================================================================ */

    const OPT_SRC_PREFIX = 'zen_cortext_src_';   // live source per file
    const OPT_VER_PREFIX = 'zen_cortext_ver_';   // version history per file
    const VERSIONS_KEEP  = 10;

    /** Option key for a file's live source, or null for unknown files. */
    private static function src_option($name) {
        $m = self::meta($name);
        return ($m && !empty($m['slug'])) ? self::OPT_SRC_PREFIX . $m['slug'] : null;
    }

    /** Option key for a file's version history, or null for unknown files. */
    private static function ver_option($name) {
        $m = self::meta($name);
        return ($m && !empty($m['slug'])) ? self::OPT_VER_PREFIX . $m['slug'] : null;
    }

    /**
     * Admin-edited live source for a file, or null when none is stored
     * (the runtime then falls back to the bundled factory copy).
     */
    public static function get_live_source($name) {
        $key = self::src_option($name);
        if (!$key) return null;
        $val = get_option($key, null);
        return is_string($val) ? $val : null;
    }

    /** Store admin-edited live source (autoload off — only read on render). */
    public static function set_live_source($name, $source) {
        $key = self::src_option($name);
        if (!$key) return false;
        return update_option($key, (string) $source, false);
    }

    /** Drop the live source so the runtime falls back to factory. */
    public static function delete_live_source($name) {
        $key = self::src_option($name);
        return $key ? delete_option($key) : false;
    }

    /** Factory (bundled, read-only) source for a file, or '' if missing. */
    public static function get_factory_source($name) {
        $m = self::meta($name);
        if (!$m || empty($m['factory']) || !file_exists($m['factory'])) return '';
        $c = file_get_contents($m['factory']);
        return is_string($c) ? $c : '';
    }

    /**
     * Version history: array of ['ts' => 'Ymd-His', 'source' => '...'],
     * newest first.
     */
    public static function get_versions($name) {
        $key = self::ver_option($name);
        if (!$key) return array();
        $v = get_option($key, array());
        return is_array($v) ? $v : array();
    }

    /** List version timestamps (newest first). */
    public static function list_version_ids($name) {
        $out = array();
        foreach (self::get_versions($name) as $row) {
            if (isset($row['ts'])) $out[] = (string) $row['ts'];
        }
        return $out;
    }

    /** Fetch one version's source by timestamp, or null. */
    public static function get_version_source($name, $ts) {
        foreach (self::get_versions($name) as $row) {
            if (isset($row['ts']) && (string) $row['ts'] === (string) $ts) {
                return isset($row['source']) ? (string) $row['source'] : '';
            }
        }
        return null;
    }

    /**
     * Snapshot the CURRENT live source (or factory when none) into the
     * version history and return the new timestamp. Caps history at
     * VERSIONS_KEEP. Returns '' when there is nothing to snapshot.
     */
    public static function add_version_snapshot($name) {
        $key = self::ver_option($name);
        if (!$key) return '';
        $current = self::get_live_source($name);
        if ($current === null) $current = self::get_factory_source($name);
        if ($current === '') return '';

        $versions = self::get_versions($name);
        // Unique second-precision timestamp (append a counter on collision).
        $base = gmdate('Ymd-His');
        $ts = $base; $i = 1;
        $existing = self::list_version_ids($name);
        while (in_array($ts, $existing, true)) { $ts = $base . '-' . $i++; }

        array_unshift($versions, array('ts' => $ts, 'source' => $current));
        $versions = array_slice($versions, 0, self::VERSIONS_KEEP);
        update_option($key, $versions, false);
        return $ts;
    }

    /**
     * Where the chat editor stores its writable artifacts. Uses the WP
     * uploads dir (filterable via the standard upload_dir filter) so
     * production overrides like S3 plugins still work, and so the path
     * automatically respects WP's WordPress-multisite-aware layout.
     */
    public static function writable_root() {
        $u = wp_upload_dir();
        return trailingslashit($u['basedir']) . 'zen-cortext/';
    }

    public static function writable_url() {
        $u = wp_upload_dir();
        return trailingslashit($u['baseurl']) . 'zen-cortext/';
    }

    /**
     * Public URL for the bundled factory copy of an editable asset (used
     * for chat.css when the admin hasn't customized it — a real file URL is
     * browser-cacheable). Customized source is served as inline CSS instead
     * (see enqueue_chat_css), since it lives in the DB, not on disk.
     */
    public static function factory_url($name) {
        $m = self::meta($name);
        if (!$m || empty($m['factory'])) return '';
        // No ?ver here — wp_register_style() appends the version arg, so
        // returning a bare URL avoids a duplicated cache-buster.
        $rel = ltrim(substr($m['factory'], strlen(ZEN_CORTEXT_PLUGIN_DIR)), '/');
        return ZEN_CORTEXT_PLUGIN_URL . $rel;
    }

    /**
     * Register the chat stylesheet under $handle (does NOT enqueue — callers
     * enqueue when the chat actually renders). When the admin has saved a
     * custom chat.css it is attached as inline CSS (DB-backed, no file), which
     * prints only once the handle is enqueued; otherwise the bundled factory
     * chat.css file is registered so it stays browser-cacheable.
     */
    public static function register_chat_css($handle) {
        $custom = self::get_live_source('chat.css');
        if (is_string($custom) && $custom !== '') {
            wp_register_style($handle, false, array(), ZEN_CORTEXT_VERSION);
            wp_add_inline_style($handle, $custom);
        } else {
            wp_register_style($handle, self::factory_url('chat.css'), array(), ZEN_CORTEXT_VERSION);
        }
    }

    /* ----- preview transient (in-progress edits, not on disk) ----- */

    /**
     * Per-user transient key for the in-progress preview source. Keying
     * by user_id means concurrent admins don't clobber each other's
     * drafts; keying by filename means switching files in the editor
     * doesn't lose the previous file's draft either.
     */
    public static function preview_transient_key($filename, $user_id = null) {
        if ($user_id === null) $user_id = get_current_user_id();
        return 'zen_cortext_preview_' . (int) $user_id . '_' . md5((string) $filename);
    }

    public static function get_preview_source($filename, $user_id = null) {
        $val = get_transient(self::preview_transient_key($filename, $user_id));
        return ($val === false || !is_string($val)) ? null : $val;
    }

    public static function set_preview_source($filename, $source, $user_id = null) {
        // 1-hour TTL — long enough for a multi-step AI edit session, short
        // enough that an admin closing their tab doesn't keep the draft
        // alive forever. Discard / save / restore / reset all clear it
        // explicitly anyway.
        return set_transient(
            self::preview_transient_key($filename, $user_id),
            (string) $source,
            HOUR_IN_SECONDS
        );
    }

    public static function delete_preview_source($filename, $user_id = null) {
        return delete_transient(self::preview_transient_key($filename, $user_id));
    }

    public static function meta($name) {
        $r = self::registry();
        return isset($r[$name]) ? $r[$name] : null;
    }

    /**
     * Returns the source string the runtime should render. Order:
     *   1. Admin preview transient (only when the visitor is the admin
     *      who staged the edit, AND ?zen_cortext_preview=1 is set).
     *   2. The on-disk live copy under wp-content/uploads/zen-cortext/.
     *   3. The bundled factory copy in the plugin tree.
     * Returns null only when none of the above exist (shouldn't happen
     * because ensure_seeded() copies factory→live on first run, but the
     * null branch keeps the renderer from blowing up if the factory
     * file is also somehow missing).
     */
    public static function resolve_source($name, $allow_preview = false) {
        $m = self::meta($name);
        if (!$m) return null;
        if ($allow_preview && self::is_preview_request()) {
            $preview = self::get_preview_source($name);
            if ($preview !== null) return $preview;
        }
        // Admin-edited source lives in the DB; fall back to the bundled
        // factory copy when the admin hasn't customized this file.
        $live = self::get_live_source($name);
        if ($live !== null) return $live;
        if (file_exists($m['factory'])) return file_get_contents($m['factory']);
        return null;
    }

    public static function is_preview_request() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview-mode detector; gated further by is_user_logged_in() + manage_options below.
        if (empty($_GET['zen_cortext_preview'])) return false;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview-mode detector; gated further by is_user_logged_in() + manage_options below.
        if ($_GET['zen_cortext_preview'] !== '1') return false;
        if (!is_user_logged_in()) return false;
        return current_user_can('manage_options');
    }

    /**
     * One-time migration: import any pre-2.39.2 on-disk "live" source and
     * version files from wp-content/uploads/zen-cortext/ into the database,
     * then delete the files (editable source no longer lives in uploads —
     * it belongs in the DB, which isn't publicly readable and survives
     * plugin upgrades). Idempotent + cheap after the first run (one option
     * read). Fresh installs simply have no files and fall back to factory.
     */
    public static function ensure_seeded() {
        if (get_option('zen_cortext_src_db_migrated') === 'done') return;

        foreach (self::registry() as $name => $m) {
            // Import the live source file → DB (only if not already in DB).
            // Skip copies that are byte-identical to the bundled factory file:
            // those are just the old auto-seeded copy, not a real edit, so we
            // let them fall back to factory (keeps the DB clean and chat.css
            // browser-cacheable instead of inlined).
            if (self::get_live_source($name) === null
                && !empty($m['live']) && file_exists($m['live'])) {
                $c = file_get_contents($m['live']);
                if (is_string($c) && $c !== '' && $c !== self::get_factory_source($name)) {
                    self::set_live_source($name, $c);
                }
            }

            // Import version snapshot files → DB version history (newest first).
            if (!empty($m['versions']) && is_dir($m['versions'])) {
                $entries = @scandir($m['versions']);
                $rows = array();
                if (is_array($entries)) {
                    $prefix = basename($name) . '.'; // files are "<file>.<ts>"
                    $fname  = basename($name);
                    foreach ($entries as $e) {
                        if (strpos($e, $fname . '.') !== 0) continue;
                        $ts = substr($e, strlen($fname) + 1);
                        if (!preg_match('/^\d{8}-\d{6}(-\d+)?$/', $ts)) continue;
                        $src = @file_get_contents($m['versions'] . $e);
                        if (is_string($src)) $rows[] = array('ts' => $ts, 'source' => $src);
                    }
                }
                if ($rows) {
                    usort($rows, function ($a, $b) { return strcmp($b['ts'], $a['ts']); });
                    $rows = array_slice($rows, 0, self::VERSIONS_KEEP);
                    $key = self::ver_option($name);
                    if ($key && get_option($key, null) === null) {
                        update_option($key, $rows, false);
                    }
                }
            }

            // Delete the now-migrated on-disk source + version files so no
            // editable source remains in the uploads folder.
            if (!empty($m['live']) && file_exists($m['live'])) {
                @unlink($m['live']); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing migrated source file from uploads after import to DB.
            }
            if (!empty($m['versions']) && is_dir($m['versions'])) {
                $entries = @scandir($m['versions']);
                if (is_array($entries)) {
                    foreach ($entries as $e) {
                        if ($e === '.' || $e === '..') continue;
                        @unlink($m['versions'] . $e); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing migrated version snapshot from uploads after import to DB.
                    }
                }
                @rmdir($m['versions']); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the now-empty migrated version-snapshot dir from the plugin's own uploads folder; one-time migration, WP_Filesystem credentials aren't available in this path.
            }
        }

        update_option('zen_cortext_src_db_migrated', 'done', false);
    }

    /* ================================================================
       Public render entry points
       ================================================================ */

    /**
     * Render a registered template. $context is a nested associative
     * array; preview gating is honored automatically.
     */
    public static function render($name, $context = array()) {
        $source = self::resolve_source($name, true);
        if ($source === null) return '';
        return self::render_string($source, $context);
    }

    /**
     * Render a free-form template string. Useful for tests and for the
     * editor's "preview the in-progress source without saving" flow.
     */
    public static function render_string($template, $context = array()) {
        $template = self::render_blocks((string) $template, (array) $context);
        return self::render_vars($template, $context);
    }

    /* ================================================================
       Engine internals
       ================================================================ */

    private static function render_blocks($template, $context) {
        // Match the OUTERMOST balanced block first by scanning, since
        // nested blocks inside loops need the parent's iteration to
        // happen first (so `each:items` substitutes its inner block
        // once per item before that item's nested `if:foo` is evaluated).
        $blocks_re = '/\{\{\s*(each|if):(!?[\w.\-]+)\s*\}\}(.*?)\{\{\s*\/\1\s*\}\}/s';

        // Iterate until no more outermost matches. Each pass shrinks the
        // template by one balanced pair. The `?` non-greedy quantifier
        // + cycling ensures correct handling of sibling and nested
        // blocks of the same type.
        $guard = 0;
        while ($guard++ < 200 && preg_match($blocks_re, $template)) {
            $next = preg_replace_callback($blocks_re, function ($m) use ($context) {
                $type = $m[1]; $key = $m[2]; $inner = $m[3];
                if ($type === 'each') {
                    $arr = self::resolve($key, $context);
                    if (!is_array($arr) || empty($arr)) return '';
                    $i = 0;
                    $out = '';
                    foreach ($arr as $item) {
                        $local = is_array($item) ? array_merge($context, $item) : $context;
                        $local['index0'] = $i;
                        $local['index1'] = $i + 1;
                        $i++;
                        // Recurse so nested blocks inside the loop get
                        // resolved against the merged-local context.
                        $out .= self::render_string($inner, $local);
                    }
                    return $out;
                }
                // if branch
                $negate = strpos($key, '!') === 0;
                if ($negate) $key = substr($key, 1);
                $val = self::resolve($key, $context);
                $truthy = !empty($val);
                if ($negate ? !$truthy : $truthy) {
                    return self::render_string($inner, $context);
                }
                return '';
            }, $template, 1);
            if ($next === null || $next === $template) break; // safety
            $template = $next;
        }
        return $template;
    }

    private static function render_vars($template, $context) {
        return preg_replace_callback(
            '/\{\{\s*(?:(raw|url|attr|t):)?\s*([!\w.\- ]+?)\s*\}\}/',
            function ($m) use ($context) {
                $modifier = isset($m[1]) ? $m[1] : '';
                $key      = $m[2];

                if ($modifier === 't') {
                    // Template `{{ t:Some Key }}` placeholders are dynamic
                    // strings unknown to gettext extraction at build time.
                    // Templates ship the English text as the key itself, so
                    // a no-translation render returns the key verbatim.
                    return esc_html(trim($key));
                }
                $val = self::resolve($key, $context);
                if ($val === null) return '';
                $val = is_scalar($val) ? (string) $val : '';
                switch ($modifier) {
                    case 'raw':  return $val;
                    case 'url':  return esc_url($val);
                    case 'attr': return esc_attr($val);
                    default:     return esc_html($val);
                }
            },
            $template
        );
    }

    /**
     * Walk a dotted key (e.g. "intro.name") through the context map.
     * Returns null when any segment is missing, which the renderer
     * coerces to an empty string at render time.
     */
    private static function resolve($key, $context) {
        $parts = explode('.', $key);
        $cur = $context;
        foreach ($parts as $p) {
            $p = trim($p);
            if (is_array($cur) && array_key_exists($p, $cur)) {
                $cur = $cur[$p];
            } else {
                return null;
            }
        }
        return $cur;
    }

    /* ================================================================
       Save-time validation
       ================================================================ */

    /**
     * Reject obvious template breakage at save time. Returns true on
     * success or a human-readable error string on failure. The editor
     * surfaces the error and refuses the save instead of writing a
     * busted file (which would render `/talk/` blank).
     */
    public static function validate($source, $kind = 'template') {
        $source = (string) $source;
        if (preg_match('/<\?(?:php|=)?/i', $source)) {
            return __('Source must not contain raw PHP.', 'zen-cortext');
        }
        // CSS source has its own grammar — no placeholder/block syntax to
        // balance, no Mustache directives. We reject raw PHP (above) and
        // call it good; CSS parser errors are the browser's problem and
        // surface visually on the next preview.
        if ($kind === 'css') return true;
        $opens_each  = preg_match_all('/\{\{\s*each:[\w.\-]+\s*\}\}/', $source);
        $closes_each = preg_match_all('/\{\{\s*\/each\s*\}\}/', $source);
        if ($opens_each !== $closes_each) {
            return sprintf(
                /* translators: %1$d open count, %2$d close count */
                __('Unbalanced {{ each }} … {{ /each }} blocks (%1$d open vs %2$d close).', 'zen-cortext'),
                $opens_each, $closes_each
            );
        }
        $opens_if  = preg_match_all('/\{\{\s*if:!?[\w.\-]+\s*\}\}/', $source);
        $closes_if = preg_match_all('/\{\{\s*\/if\s*\}\}/', $source);
        if ($opens_if !== $closes_if) {
            return sprintf(
                /* translators: %1$d open count, %2$d close count */
                __('Unbalanced {{ if }} … {{ /if }} blocks (%1$d open vs %2$d close).', 'zen-cortext'),
                $opens_if, $closes_if
            );
        }
        // Catch typos like {{ rawx:foo }} or {{ if foo }} (missing colon)
        // before they confuse admins. Each `{{ ... }}` must match either
        // a known modifier:key form, a plain key, a t:string, or a
        // block open/close keyword.
        if (preg_match_all('/\{\{(.*?)\}\}/s', $source, $m)) {
            foreach ($m[1] as $expr) {
                $e = trim($expr);
                if ($e === '') continue;
                if ($e === '/if' || $e === '/each') continue;
                if (preg_match('/^each:[\w.\-]+$/', $e)) continue;
                if (preg_match('/^if:!?[\w.\-]+$/', $e))  continue;
                if (preg_match('/^t:.+$/', $e))           continue;
                if (preg_match('/^(raw|url|attr):[\w.\-]+$/', $e)) continue;
                if (preg_match('/^[\w.\-]+$/', $e)) continue;
                return sprintf(
                    /* translators: %s offending placeholder */
                    __('Unrecognized placeholder: {{ %s }}', 'zen-cortext'),
                    $e
                );
            }
        }
        return true;
    }
}
