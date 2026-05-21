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
                // Live + versions live under wp-content/uploads/ — the
                // plugin tree is read-only on this host (FrankenPHP runs
                // under systemd ProtectSystem=strict with only uploads/
                // and cache/ in ReadWritePaths). Uploads is the standard
                // WP "writable from PHP" location.
                'live'     => $writable . 'templates/chat.tpl.html',
                'versions' => $writable . 'versions/chat.tpl.html/',
                'kind'     => 'template',
                'mode'     => 'text/html',
            ),
            'chat-page-body.tpl.html' => array(
                'label'    => __('Full page wrapper body (chat-page-body.tpl.html)', 'zen-cortext'),
                'factory'  => $base_pages . 'factory/chat-page-body.tpl.html',
                'live'     => $writable . 'templates/chat-page-body.tpl.html',
                'versions' => $writable . 'versions/chat-page-body.tpl.html/',
                'kind'     => 'template',
                'mode'     => 'text/html',
            ),
            // chat.css — the chat's stylesheet. Edited the same way as
            // templates (live in uploads, factory in plugin tree, versions
            // dir for snapshots) but skips the placeholder/PHP validator
            // because CSS source has its own grammar. The shortcode +
            // wrapper enqueue from the live URL when present, falling
            // back to the bundled factory CSS otherwise.
            'chat.css' => array(
                'label'    => __('Chat stylesheet (chat.css)', 'zen-cortext'),
                'factory'  => $base_assets . 'factory/chat.css',
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
                'live'     => $writable . 'templates/email/chat-transcript.html',
                'versions' => $writable . 'versions/email/chat-transcript.html/',
                'kind'     => 'template',
                'mode'     => 'text/html',
            ),
        );
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
     * Public URL for an editable asset — uploads URL if a live copy
     * exists (admin has saved at least once), otherwise the bundled
     * factory copy in the plugin tree. Appends a cache-buster keyed
     * to the live file's mtime so saves invalidate the browser cache
     * without bumping the global plugin version.
     */
    public static function asset_url($name) {
        $m = self::meta($name);
        if (!$m) return '';
        if (file_exists($m['live'])) {
            $rel = ltrim(substr($m['live'], strlen(self::writable_root())), '/');
            return self::writable_url() . $rel . '?ver=' . filemtime($m['live']);
        }
        // Factory fallback — use the plugin URL.
        $factory = $m['factory'];
        $rel     = ltrim(substr($factory, strlen(ZEN_CORTEXT_PLUGIN_DIR)), '/');
        return ZEN_CORTEXT_PLUGIN_URL . $rel . '?ver=' . ZEN_CORTEXT_VERSION;
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
        return 'zce_preview_' . (int) $user_id . '_' . md5((string) $filename);
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
        if (file_exists($m['live']))    return file_get_contents($m['live']);
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
     * Seed live/<file> from factory/<file> when the live copy is missing,
     * and create the writable directories under wp-content/uploads/ so
     * the chat editor's first save doesn't have to mkdir on the hot path.
     */
    public static function ensure_seeded() {
        foreach (self::registry() as $name => $m) {
            $live_dir    = dirname($m['live']);
            $versions    = $m['versions'];
            if (!is_dir($live_dir)) wp_mkdir_p($live_dir);
            if (!is_dir($versions)) wp_mkdir_p($versions);

            if (!file_exists($m['live']) && file_exists($m['factory'])) {
                @copy($m['factory'], $m['live']);
            }
        }
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
