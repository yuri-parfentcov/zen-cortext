<?php
/**
 * Chat Template Editor — admin page (Template Code + Help panels).
 *
 * CodeMirror editor (via wp_enqueue_code_editor) for the .tpl.html
 * files admins are allowed to edit (chat.tpl.html, chat-page-body.tpl.html,
 * chat.css). In-progress edits stage to a sibling `_preview/` directory
 * and preview via /talk/?zen_cortext_preview=1 — the resolver in
 * Zen_Cortext_Shortcode picks the staged file when the visitor is
 * logged-in with manage_options. Saves create timestamped backups in
 * `_versions/` (last 10 kept per file).
 *
 * Color configuration lives on the dedicated Design page
 * (Zen_Cortext_Design) — moved out so design decisions don't sit
 * inside a code-editor surface. The colors REST endpoint moved with
 * it; only the structural template / CSS endpoints remain here.
 *
 * AI assistance reuses the existing Zen_Cortext_API streaming primitives
 * (stream_chat_via_api / stream_chat_via_cli) — same processor + model
 * pipeline as the admin Brainstorm page.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zen_Cortext_Chat_Editor {

    private static $instance = null;
    private $hook = '';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu',            array($this, 'add_menu'), 20);
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
        add_action('rest_api_init',         array($this, 'register_routes'));
    }

    /* ================================================================
       Allow-list of editable template files
       ================================================================ */

    /**
     * Map of editable template files → their plugin-relative directory.
     * Both `_preview/` and `_versions/` siblings of these directories
     * are used by the editor; the resolver in Zen_Cortext_Shortcode
     * (or the load_page_template filter for the wrapper) picks files
     * from `_preview/` when the preview query param + capability are set.
     */
    /**
     * Editable files come from the template registry — only .tpl.html
     * files are editable now. Raw PHP is no longer accepted because the
     * runtime templates can't contain PHP, and a typo in a PHP file
     * could 500 the public chat.
     */
    public static function editable_files() {
        $out = array();
        foreach (Zen_Cortext_Template_Renderer::registry() as $name => $m) {
            // Honor the registry's per-file mode (text/html for templates,
            // text/css for stylesheets) so CodeMirror picks the right
            // syntax highlighter on file switch.
            $out[$name] = array(
                'label' => $m['label'],
                'mode'  => isset($m['mode']) ? $m['mode'] : 'text/html',
                'kind'  => isset($m['kind']) ? $m['kind'] : 'template',
            );
        }
        return $out;
    }

    public static function file_path($filename) {
        $m = Zen_Cortext_Template_Renderer::meta($filename);
        return $m ? $m['live'] : null;
    }

    public static function versions_dir($filename) {
        $m = Zen_Cortext_Template_Renderer::meta($filename);
        return $m ? $m['versions'] : null;
    }

    public static function factory_path($filename) {
        $m = Zen_Cortext_Template_Renderer::meta($filename);
        return $m ? $m['factory'] : null;
    }

    /* ================================================================
       Admin page
       ================================================================ */

    public function add_menu() {
        $this->hook = add_submenu_page(
            'zen-cortext',
            __('Template Editor', 'zen-cortext'),
            __('Template Editor', 'zen-cortext'),
            'manage_options',
            'zen-cortext-chat-editor',
            array($this, 'render_page')
        );
    }

    public function enqueue($hook) {
        if (empty($this->hook) || $hook !== $this->hook) return;

        wp_enqueue_style(
            'zen-cortext-chat-editor',
            ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/chat-editor.css',
            array(),
            ZEN_CORTEXT_VERSION
        );

        // wp_enqueue_code_editor returns the CodeMirror init config that
        // the editor JS feeds back into wp.codeEditor.initialize() to
        // light up the textarea. The mode is HTML now — templates have
        // no PHP — but the linter still needs to be turned off because
        // CodeMirror's CSSLint/JSHint nag noisily on HTML fragments.
        $cm_settings = wp_enqueue_code_editor(array('type' => 'text/html'));
        wp_enqueue_script('wp-theme-plugin-editor');
        wp_enqueue_style('wp-codemirror');

        wp_enqueue_script(
            'zen-cortext-chat-editor',
            ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/chat-editor.js',
            array(),
            ZEN_CORTEXT_VERSION,
            true
        );

        $files_meta = array();
        foreach (self::editable_files() as $name => $meta) {
            $files_meta[$name] = array(
                'label' => $meta['label'],
                'mode'  => $meta['mode'],
            );
        }

        wp_localize_script('zen-cortext-chat-editor', 'zenCortextChatEditor', array(
            'restRoot'      => esc_url_raw(rest_url('zen-cortext/v1/chat-editor')),
            'restNonce'     => wp_create_nonce('wp_rest'),
            'previewUrl'    => esc_url_raw(home_url('/talk/')),
            'previewParam'  => 'zen_cortext_preview',
            'editableFiles' => $files_meta,
            'codeMirror'    => $cm_settings ? $cm_settings : false,
        ));
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'zen-cortext'));
        }
        include ZEN_CORTEXT_PLUGIN_DIR . 'admin/views/chat-editor-page.php';
    }

    /* ================================================================
       REST routes
       ================================================================ */

    public function register_routes() {
        $admin_only = function () {
            return current_user_can('manage_options');
        };
        $ns = 'zen-cortext/v1';

        register_rest_route($ns, '/chat-editor/source', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_get_source'),
            'permission_callback' => $admin_only,
        ));

        register_rest_route($ns, '/chat-editor/preview-source', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_write_preview'),
            'permission_callback' => $admin_only,
        ));

        register_rest_route($ns, '/chat-editor/preview-source', array(
            'methods'             => 'DELETE',
            'callback'            => array($this, 'rest_delete_preview'),
            'permission_callback' => $admin_only,
        ));

        register_rest_route($ns, '/chat-editor/save', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_save'),
            'permission_callback' => $admin_only,
        ));

        register_rest_route($ns, '/chat-editor/versions', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_list_versions'),
            'permission_callback' => $admin_only,
        ));

        register_rest_route($ns, '/chat-editor/version-content', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_get_version_content'),
            'permission_callback' => $admin_only,
        ));

        register_rest_route($ns, '/chat-editor/restore', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_restore_version'),
            'permission_callback' => $admin_only,
        ));

        register_rest_route($ns, '/chat-editor/reset', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_reset_to_factory'),
            'permission_callback' => $admin_only,
        ));

        register_rest_route($ns, '/chat-editor/ai', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_ai'),
            'permission_callback' => $admin_only,
        ));
    }

    /* ----- Colors ----- */

    /* ----- File source ----- */

    public function rest_get_source($request) {
        $file = (string) $request->get_param('file');
        $path = self::file_path($file);
        if (!$path) {
            return new WP_Error('zen_cortext_chat_editor', 'Unknown template', array('status' => 404));
        }
        // The live file is in wp-content/uploads/ — if it's missing for
        // any reason, fall back to the bundled factory copy so the editor
        // still has source to work with.
        $source = file_exists($path)
            ? file_get_contents($path)
            : (file_exists(self::factory_path($file)) ? file_get_contents(self::factory_path($file)) : '');
        $preview = Zen_Cortext_Template_Renderer::get_preview_source($file);
        return rest_ensure_response(array(
            'file'    => $file,
            'source'  => $source,
            'preview' => $preview,
        ));
    }

    /* ----- Preview staging (transient-backed; no filesystem writes) ----- */

    public function rest_write_preview($request) {
        $file   = (string) $request->get_param('file');
        $source = (string) $request->get_param('source');
        if (!self::file_path($file)) {
            return new WP_Error(
                'zen_cortext_chat_editor',
                sprintf('Unknown template "%s". Try a hard refresh of the editor.', sanitize_text_field($file)),
                array('status' => 400)
            );
        }
        Zen_Cortext_Template_Renderer::set_preview_source($file, $source);
        return rest_ensure_response(array(
            'staged' => true,
            'bytes'  => strlen($source),
        ));
    }

    public function rest_delete_preview($request) {
        $file = (string) $request->get_param('file');
        if (!self::file_path($file)) {
            return new WP_Error('zen_cortext_chat_editor', 'Unknown file', array('status' => 400));
        }
        Zen_Cortext_Template_Renderer::delete_preview_source($file);
        return rest_ensure_response(array('cleared' => true));
    }

    /* ----- Save + versions ----- */

    public function rest_save($request) {
        $file   = (string) $request->get_param('file');
        $source = (string) $request->get_param('source');
        $path   = self::file_path($file);
        if (!$path) {
            return new WP_Error('zen_cortext_chat_editor', 'Unknown file', array('status' => 400));
        }

        // Validate per file kind: templates go through the placeholder
        // engine (no raw PHP, balanced blocks, known directives); CSS
        // files only get the no-PHP check. Both reject obvious breakage
        // before we touch the live file.
        $meta = Zen_Cortext_Template_Renderer::meta($file);
        $kind = ($meta && !empty($meta['kind'])) ? $meta['kind'] : 'template';
        $check = Zen_Cortext_Template_Renderer::validate($source, $kind);
        if ($check !== true) {
            return new WP_Error('zen_cortext_chat_editor_lint', $check, array('status' => 400));
        }

        // Snapshot the current published file before overwriting.
        $snapshot = self::create_version_snapshot($file);
        if (is_wp_error($snapshot)) return $snapshot;

        if (file_put_contents($path, $source) === false) {
            return new WP_Error('zen_cortext_chat_editor', 'Cannot write file', array('status' => 500));
        }

        // The published file is now the saved source — drop the staged
        // draft transient so a refresh shows the canonical version.
        Zen_Cortext_Template_Renderer::delete_preview_source($file);

        // Prune older snapshots beyond the most recent 10.
        self::prune_versions($file, 10);

        return rest_ensure_response(array(
            'saved'   => true,
            'version' => $snapshot,
        ));
    }

    public function rest_list_versions($request) {
        $file = (string) $request->get_param('file');
        if (!self::file_path($file)) {
            return new WP_Error('zen_cortext_chat_editor', 'Unknown file', array('status' => 400));
        }
        return rest_ensure_response(array('versions' => self::list_versions($file)));
    }

    public function rest_get_version_content($request) {
        $file = (string) $request->get_param('file');
        $ts   = (string) $request->get_param('timestamp');
        if (!self::file_path($file) || !preg_match('/^\d{8}-\d{6}(-\d+)?$/', $ts)) {
            return new WP_Error('zen_cortext_chat_editor', 'Bad request', array('status' => 400));
        }
        $path = self::versions_dir($file) . $file . '.' . $ts;
        if (!file_exists($path)) {
            return new WP_Error('zen_cortext_chat_editor', 'Version not found', array('status' => 404));
        }
        return rest_ensure_response(array(
            'file'      => $file,
            'timestamp' => $ts,
            'source'    => file_get_contents($path),
        ));
    }

    public function rest_restore_version($request) {
        $file = (string) $request->get_param('file');
        $ts   = (string) $request->get_param('timestamp');
        if (!self::file_path($file) || !preg_match('/^\d{8}-\d{6}(-\d+)?$/', $ts)) {
            return new WP_Error('zen_cortext_chat_editor', 'Bad request', array('status' => 400));
        }
        $version_path = self::versions_dir($file) . $file . '.' . $ts;
        if (!file_exists($version_path)) {
            return new WP_Error('zen_cortext_chat_editor', 'Version not found', array('status' => 404));
        }
        // Snapshot the current published file before restoring so the
        // restore action itself is reversible — versions are append-only.
        $snapshot = self::create_version_snapshot($file);
        if (is_wp_error($snapshot)) return $snapshot;

        $source = file_get_contents($version_path);
        $meta = Zen_Cortext_Template_Renderer::meta($file);
        $kind = ($meta && !empty($meta['kind'])) ? $meta['kind'] : 'template';
        $check = Zen_Cortext_Template_Renderer::validate($source, $kind);
        if ($check !== true) {
            return new WP_Error('zen_cortext_chat_editor_lint', $check, array('status' => 400));
        }
        if (file_put_contents(self::file_path($file), $source) === false) {
            return new WP_Error('zen_cortext_chat_editor', 'Cannot write file', array('status' => 500));
        }
        // Restore replaces live with a prior version — drop any staged
        // draft so the editor reload shows the version we just restored.
        Zen_Cortext_Template_Renderer::delete_preview_source($file);
        self::prune_versions($file, 10);
        return rest_ensure_response(array('restored' => true, 'version' => $snapshot));
    }

    public function rest_reset_to_factory($request) {
        $file = (string) $request->get_param('file');
        $live    = self::file_path($file);
        $factory = self::factory_path($file);
        if (!$live || !$factory) {
            return new WP_Error('zen_cortext_chat_editor', 'Unknown file', array('status' => 400));
        }
        if (!file_exists($factory)) {
            return new WP_Error('zen_cortext_chat_editor', 'Factory copy is missing for this template', array('status' => 500));
        }
        // Snapshot the current live file BEFORE overwriting, so the reset
        // itself is reversible — the admin can pull their edits back from
        // the version history dropdown if they decide they were better.
        $snapshot = self::create_version_snapshot($file);
        if (is_wp_error($snapshot)) return $snapshot;
        if (!@copy($factory, $live)) {
            return new WP_Error('zen_cortext_chat_editor', 'Cannot copy factory file over live', array('status' => 500));
        }
        // Drop any in-progress draft — the published file is now factory.
        Zen_Cortext_Template_Renderer::delete_preview_source($file);
        self::prune_versions($file, 10);
        return rest_ensure_response(array(
            'reset'   => true,
            'version' => $snapshot,
        ));
    }

    /* ----- AI ----- */

    public function rest_ai($request) {
        $file        = (string) $request->get_param('file');
        $message     = (string) $request->get_param('message');
        $history_in  = (array)  $request->get_param('history');
        $source      = (string) $request->get_param('source'); // current editor contents
        $path        = self::file_path($file);
        if (!$path || trim($message) === '') {
            return new WP_Error('zen_cortext_chat_editor', 'file + message required', array('status' => 400));
        }

        $system_prompt = self::build_ai_system_prompt($file, $source);

        $messages = array();
        foreach ($history_in as $h) {
            if (!is_array($h)) continue;
            $role    = isset($h['role'])    ? (string) $h['role']    : '';
            $content = isset($h['content']) ? (string) $h['content'] : '';
            if ($role === '' || $content === '') continue;
            $messages[] = array(
                'role'    => $role === 'assistant' ? 'assistant' : 'user',
                'content' => substr($content, 0, 8000),
            );
        }
        $messages[] = array('role' => 'user', 'content' => $message);

        // Buffer the streamed assistant text server-side so we can return
        // a single JSON payload to the client. The chat-editor UI doesn't
        // need token-by-token streaming the way a chat does. The streamer
        // hands the callback a JSON STRING per event (the same shape that
        // the brainstorm endpoint forwards as SSE) — we json_decode and
        // accumulate the text deltas.
        $buffer = '';
        $on_event = function ($json) use (&$buffer) {
            $event = json_decode((string) $json, true);
            if (!is_array($event) || empty($event['type'])) return;
            if ($event['type'] === 'content_block_delta' && isset($event['delta']['text'])) {
                $buffer .= (string) $event['delta']['text'];
            }
        };

        $opts = array('max_tokens' => 8000, 'timeout' => 180);
        if (Zen_Cortext_API::processor() === 'cli') {
            $cli_model = (string) get_option('zen_cortext_cli_model', 'sonnet');
            Zen_Cortext_API::stream_chat_via_cli($system_prompt, $messages, $cli_model, $on_event, $opts);
        } else {
            Zen_Cortext_API::stream_chat_via_api($system_prompt, $messages, $on_event, $opts);
        }

        $parsed = self::parse_ai_response($buffer);
        return rest_ensure_response(array(
            'summary' => $parsed['summary'],
            'source'  => $parsed['source'],
            'raw'     => $buffer,
        ));
    }

    /* ================================================================
       Helpers
       ================================================================ */

    private static function create_version_snapshot($file) {
        $path = self::file_path($file);
        if (!$path || !file_exists($path)) return new WP_Error('zen_cortext_chat_editor', 'Source missing', array('status' => 500));
        $vdir = self::versions_dir($file);
        if (!is_dir($vdir) && !wp_mkdir_p($vdir)) return new WP_Error('zen_cortext_chat_editor', 'Cannot create versions dir', array('status' => 500));
        // Two saves in the same second would collide on second-precision
        // timestamps and corrupt the earlier snapshot. Append a counter
        // when needed so each snapshot lives in its own file.
        $base = gmdate('Ymd-His');
        $ts   = $base;
        $i    = 1;
        while (file_exists($vdir . $file . '.' . $ts)) {
            $ts = $base . '-' . $i++;
        }
        $vpath = $vdir . $file . '.' . $ts;
        if (!@copy($path, $vpath)) return new WP_Error('zen_cortext_chat_editor', 'Cannot snapshot version', array('status' => 500));
        return $ts;
    }

    private static function list_versions($file) {
        $vdir = self::versions_dir($file);
        if (!is_dir($vdir)) return array();
        $entries = @scandir($vdir);
        if (!is_array($entries)) return array();
        $out = array();
        $prefix = $file . '.';
        foreach ($entries as $e) {
            if (strpos($e, $prefix) !== 0) continue;
            $ts = substr($e, strlen($prefix));
            if (!preg_match('/^\d{8}-\d{6}(-\d+)?$/', $ts)) continue;
            $out[] = $ts;
        }
        rsort($out);
        return $out;
    }

    private static function prune_versions($file, $keep) {
        $vdir = self::versions_dir($file);
        $list = self::list_versions($file);
        $extra = array_slice($list, max(0, (int) $keep));
        foreach ($extra as $ts) {
            @unlink($vdir . $file . '.' . $ts); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- pruning plugin-managed version snapshot files in the uploads dir.
        }
    }

    /**
     * System prompt for the AI editor. Treats the request like a code-
     * editing pair-programming session: shows the current source, lists
     * the helpers the file already uses, and pins the response format
     * (one SUMMARY line + a single fenced code block). The parser only
     * trusts the fenced block — anything else the model says is meta.
     */
    public static function build_ai_system_prompt($file, $source) {
        $token_lines = array();
        // Color tokens live on the Design page now; the AI still needs
        // to know they exist (so it doesn't suggest hardcoded hex when a
        // variable is available) — reach across for the catalog.
        $tokens_source = class_exists('Zen_Cortext_Design')
            ? Zen_Cortext_Design::color_tokens()
            : array();
        foreach ($tokens_source as $name => $meta) {
            $token_lines[] = '  ' . $name . '   default ' . $meta['default'] . '   ' . $meta['label'];
        }
        $tokens = implode("\n", $token_lines);

        $files_list = array();
        foreach (self::editable_files() as $n => $m) {
            $files_list[] = '  ' . $n . ' — ' . $m['label'];
        }
        $files = implode("\n", $files_list);

        // Available context keys per file. The runtime controllers
        // (chat.php and zen-cortext-chat-page.php) populate these — the
        // template can ONLY reference what the controller passes in.
        $contexts = array(
            'chat.tpl.html' => "  intro.name, intro.role, intro.logo_url, intro.site_url,\n  intro.site_url_or_hash, intro.site_display, intro.body_html (raw),\n  has_logo_or_site (bool), input_placeholder, email_input_placeholder",
            'chat-page-body.tpl.html' => "  rail_buttons_html (raw, pre-rendered team-cards + quick-links),\n  chat_html (raw, the full chat shell),\n  mobile_trigger_icon, quick_actions_label, mobile_open_label",
            'email/chat-transcript.html' => "  site_name, site_url, recipient_email, chat_url, sent_at_human,\n  messages (array — loop with {{ each:messages }} … {{ /each }}),\n  inside the loop each item exposes: role, content,\n  is_user (bool), is_admin (bool), is_assistant (bool),\n  sender (admin display name when role=admin)",
        );
        $ctx_block = isset($contexts[$file]) ? $contexts[$file] : '  (unknown — admin is editing an unregistered template)';

        return implode("\n\n", array(
            "You are the Template Editor AI assistant for the Zen Cortext WordPress plugin. The admin edits a placeholder template (NOT PHP) for the visitor chat. Templates compile with a tiny Mustache-flavoured engine — raw <?php tags are rejected at save time.",
            "EDITABLE TEMPLATES (one at a time):\n" . $files,
            "CURRENT FILE: " . $file,
            "AVAILABLE CONTEXT KEYS (the runtime controller for this template provides these — do NOT reference anything else):\n" . $ctx_block,
            "PLACEHOLDER SYNTAX:\n  {{ key }}            HTML-escaped interpolation\n  {{ raw:key }}        raw HTML (the controller already sanitised it)\n  {{ url:key }}        esc_url\n  {{ attr:key }}       esc_attr (use inside HTML attributes)\n  {{ t:Some string }}  translated string via __()\n  {{ if:key }} … {{ /if }}      truthy block\n  {{ if:!key }} … {{ /if }}     falsy block\n  {{ each:list }} … {{ /each }} loop; inside, item fields are merged into the local context; {{ index0 }} / {{ index1 }} are auto-added.\n  Dot notation works: {{ intro.name }}, {{ url:intro.site_url }}.",
            "AVAILABLE COLOR TOKENS (CSS custom properties — use them in inline style attributes if needed, do not hardcode hex):\n" . $tokens,
            "RULES:\n  1. Output ONLY a one-line SUMMARY followed by ONE fenced code block containing the FULL new template source.\n  2. Templates contain HTML and the placeholder syntax above. NEVER include raw <?php, <?=, or <% — they're forbidden by the validator.\n  3. Each {{ if:… }} must have a matching {{ /if }}; each {{ each:… }} must have a matching {{ /each }} — unbalanced blocks reject at save.\n  4. Preserve element IDs the JS depends on (zc-chat, zc-input, zc-send, zc-typing, zc-chips, zc-share, zc-share-button, zc-delete-button, zc-share-status, zc-intro-card, zen-cortext-root, zcp-modal, zcp-modal-close, zcp-modal-title, zcp-mobile-trigger). Renaming these silently breaks chat.js or the page wrapper.\n  5. Always the complete file — never partial diffs.",
            "FORMAT (verbatim, no extra prose):\nSUMMARY: <one short sentence>\n```html\n<the new full template source>\n```",
            "CURRENT SOURCE:\n```html\n" . $source . "\n```",
        ));
    }

    public static function parse_ai_response($raw) {
        $raw = (string) $raw;
        $summary = '';
        if (preg_match('/SUMMARY:\s*(.+)/i', $raw, $m)) {
            $summary = trim($m[1]);
            // strip up to (but not including) the first newline so a
            // multi-line "summary" doesn't bleed into the body.
            $summary = preg_replace('/\s*\n.*$/s', '', $summary);
        }
        // Prefer the fenced ```php ... ``` block; fall back to any fenced block.
        $source = '';
        if (preg_match('/```(?:php|html)?\s*\n(.*?)\n```/s', $raw, $m)) {
            $source = $m[1];
        }
        return array('summary' => $summary, 'source' => $source);
    }
}
