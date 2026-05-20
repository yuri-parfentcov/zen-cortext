<?php
/**
 * Zen Cortext — Design admin page.
 *
 * Owns the visual design surface for the visitor chat. Today that's
 * the color configurator (--zc-* token pickers + mini-chat preview),
 * moved here from the Chat Template Editor's "Colors" tab so design
 * decisions don't live inside a code-editor surface.
 *
 * Stored as the `zen_cortext_chat_colors` WP option — the same option
 * the public render reads via Zen_Cortext_Shortcode::build_color_override_style(),
 * so the option name and value shape MUST match what the old editor
 * wrote. No data migration needed.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zen_Cortext_Design {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Design moved into the Settings page as a tab (?page=zen-cortext&tab=design).
        // No own add_menu — assets are enqueued conditionally on the
        // Settings page when ?tab=design is selected.
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
        add_action('rest_api_init',         array($this, 'register_routes'));

        // admin-post.php handler for the "Quick-create chat page" button.
        // Stays out of REST because creating a draft page + redirecting
        // to its WP editor is a classic POST-redirect-GET flow that
        // admin-post.php already handles cleanly.
        add_action('admin_post_zen_cortext_create_chat_page', array($this, 'handle_create_chat_page'));

        // Float button — renders at wp_footer on every public page,
        // skipping admin and the chat pages themselves (where the
        // button would be redundant). Cheap to gate at the action layer
        // so wp_footer doesn't waste a call to the renderer when the
        // feature is off.
        add_action('wp_footer', array($this, 'maybe_render_float_button'), 30);
    }

    /**
     * Editable color tokens — name, label, default value. Defaults
     * mirror the WordPress admin styling palette (see /wp-admin/css/
     * colors/_admin.scss). Keep these in sync with the chat.css :root
     * block — the public chat reads from chat.css when no override
     * option is saved, while this method drives the Reset button in
     * the admin Design tab.
     */
    public static function color_tokens() {
        return array(
            '--zc-accent'        => array('label' => 'Accent',               'default' => '#2271b1'),
            '--zc-accent-hover'  => array('label' => 'Accent (hover)',       'default' => '#135e96'),
            '--zc-primary'       => array('label' => 'Primary',              'default' => '#2271b1'),
            '--zc-primary-hover' => array('label' => 'Primary (hover)',      'default' => '#135e96'),
            '--zc-bg'            => array('label' => 'Page background',     'default' => '#ffffff'),
            '--zc-surface'       => array('label' => 'Surface background',  'default' => '#ffffff'),
            '--zc-text'          => array('label' => 'Body text',           'default' => '#3c434a'),
            '--zc-text-strong'   => array('label' => 'Strong text',         'default' => '#1d2327'),
            '--zc-text-muted'    => array('label' => 'Muted text',          'default' => '#646970'),
            '--zc-border'        => array('label' => 'Border',              'default' => '#c3c4c7'),
            '--zc-user-bg'       => array('label' => 'User bubble bg',      'default' => '#2271b1'),
            '--zc-user-text'     => array('label' => 'User bubble text',    'default' => '#ffffff'),
            '--zc-assistant-bg'  => array('label' => 'Assistant bubble bg', 'default' => '#f0f0f1'),
            // Chip text is a dedicated token (not derived from --zc-accent
            // or --zc-user-text) because palettes differ wildly in chip-
            // background luminance — a pale-lime accent needs dark text,
            // a medium-blue accent needs white. Decoupling lets admins
            // pick the right contrast per palette without it leaking
            // into the user bubble.
            '--zc-chip-text'     => array('label' => 'Chip text',           'default' => '#ffffff'),
        );
    }

    /* ================================================================
       Admin page (now a tab on the Settings page)
       ================================================================ */

    public function enqueue($hook) {
        // Only the Settings top-level page hook is relevant. The tab
        // gate is on $_GET['tab'] since the tab strip routes inside the
        // same hook_suffix.
        if ($hook !== 'toplevel_page_zen-cortext') return;
        if (!isset($_GET['tab']) || $_GET['tab'] !== 'design') return;

        // Reuse the chat-editor stylesheet — it owns the .zce-color-row
        // and .zce-mini-chat selectors. Unused code-panel rules are
        // inert (no matching markup on this page); keeps a single
        // source of truth instead of forking the CSS.
        wp_enqueue_style(
            'zen-cortext-chat-editor',
            ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/chat-editor.css',
            array(),
            ZEN_CORTEXT_VERSION
        );

        // Enqueue the public chat stylesheet too so the Live preview
        // block uses the same selectors that ship to visitors. Without
        // this, intro card / chip / share button styles would be invisible
        // in the preview (chat.css is not loaded in admin by default).
        // The .zce-mini-chat overrides in chat-editor.css constrain the
        // size so we still get a compact preview, not the full 760px
        // chat layout.
        $chat_css_url = Zen_Cortext_Template_Renderer::asset_url('chat.css');
        wp_enqueue_style(
            'zen-cortext-chat-public',
            $chat_css_url,
            array(),
            null // version already baked into the URL via mtime / plugin version
        );

        // WP media uploader for the float-button icon picker — opens
        // the standard media library modal so admins can pick any
        // uploaded image without typing a URL.
        wp_enqueue_media();

        wp_enqueue_script(
            'zen-cortext-design',
            ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/design.js',
            array(),
            ZEN_CORTEXT_VERSION,
            true
        );

        // Shape the chat-page list down to id+title for the target-page
        // dropdown — full row objects aren't needed client-side.
        $page_options = array();
        foreach (self::list_chat_pages() as $p) {
            $page_options[] = array(
                'id'    => (int) $p['id'],
                'title' => (string) $p['title'],
            );
        }

        wp_localize_script('zen-cortext-design', 'zenCortextDesign', array(
            'restRoot'        => esc_url_raw(rest_url('zen-cortext/v1/design')),
            'restNonce'       => wp_create_nonce('wp_rest'),
            'colorTokens'     => self::color_tokens(),
            'savedColors'     => (object) (array) get_option('zen_cortext_chat_colors', array()),
            'floatButton'     => self::get_float_button(),
            'floatDefaults'   => self::float_button_defaults(),
            'chatPageOptions' => $page_options,
        ));
    }

    /* ================================================================
       REST: POST /zen-cortext/v1/design/colors
       ================================================================ */

    public function register_routes() {
        $admin_only = function () {
            return current_user_can('manage_options');
        };
        register_rest_route('zen-cortext/v1', '/design/colors', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_save_colors'),
            'permission_callback' => $admin_only,
        ));
        register_rest_route('zen-cortext/v1', '/design/float-button', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_save_float_button'),
            'permission_callback' => $admin_only,
        ));
    }

    /**
     * Persist the float-button settings. Validates each field against
     * its allowed shape — defensive because these values land in
     * inline CSS / HTML on every public page.
     */
    public function rest_save_float_button($request) {
        $raw = (array) $request->get_param('settings');

        $vertical   = isset($raw['vertical'])   ? (string) $raw['vertical']   : 'bottom';
        $horizontal = isset($raw['horizontal']) ? (string) $raw['horizontal'] : 'right';
        if (!in_array($vertical,   array('top','middle','bottom'), true)) $vertical   = 'bottom';
        if (!in_array($horizontal, array('left','right'), true))          $horizontal = 'right';

        // Validate the hex color server-side; only accept #RGB / #RRGGBB /
        // #RRGGBBAA. A bad value falls back to white so storage stays
        // safe to drop straight into inline CSS at render time.
        $button_color = isset($raw['button_color']) ? trim((string) $raw['button_color']) : '#ffffff';
        if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $button_color)) $button_color = '#ffffff';

        $clean = array(
            'enabled'        => !empty($raw['enabled']) ? 1 : 0,
            'vertical'       => $vertical,
            'horizontal'     => $horizontal,
            'padding'        => max(0, min(200, (int) ($raw['padding'] ?? 24))),
            'button_color'   => $button_color,
            'icon_url'       => isset($raw['icon_url'])   ? esc_url_raw((string) $raw['icon_url'])         : '',
            'hover_text'     => isset($raw['hover_text']) ? sanitize_text_field((string) $raw['hover_text']) : '',
            'target_page_id' => max(0, (int) ($raw['target_page_id'] ?? 0)),
        );
        update_option(self::FLOAT_OPTION, $clean);
        return rest_ensure_response(array(
            'saved'    => true,
            'settings' => $clean,
        ));
    }

    /**
     * Persist the picked colors to the zen_cortext_chat_colors option.
     * Validates each token against the catalog and runs each value
     * through Zen_Cortext_Shortcode::sanitize_token_value so the public
     * render path (which trusts this option) can't inject arbitrary CSS.
     */
    /* ================================================================
       Chat pages — detection + quick-create
       ================================================================ */

    /**
     * Map of plugin-provided page templates that host the chat. Slugs
     * match the Template Name values set in the template file headers
     * under public/templates/, picked via the WP "Page Attributes →
     * Template" dropdown. Used to detect pages whose hosting mechanism
     * isn't the shortcode but the full-page template.
     */
    public static function chat_page_templates() {
        return array(
            'zen-cortext-chat-page.php'     => array(
                'label' => __('Visitor chat (full page)',  'zen-cortext'),
                'role'  => 'visitor',
            ),
            'zen-cortext-livechat-page.php' => array(
                'label' => __('Admin live-chat (full page)', 'zen-cortext'),
                'role'  => 'admin',
            ),
        );
    }

    /**
     * Return every WP page that hosts the visitor or admin chat.
     * Detects two mechanisms:
     *   - Pages with `[zen_cortext]` shortcode in post_content
     *   - Pages whose `_wp_page_template` meta matches one of the
     *     plugin's chat page templates
     * Results are deduped by page id and ordered by post_title.
     */
    public static function list_chat_pages() {
        global $wpdb;
        $by_id = array();

        // 1) Shortcode-bearing pages. LIKE is fine here — chat sites
        // typically have a handful of pages total; this isn't hot path.
        $shortcode_like = '%[zen_cortext%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title, post_status FROM {$wpdb->posts}
             WHERE post_type = 'page'
               AND post_status IN ('publish','draft','private','pending')
               AND post_content LIKE %s",
            $shortcode_like
        ));
        foreach ($rows as $r) {
            $by_id[(int) $r->ID] = array(
                'id'          => (int) $r->ID,
                'title'       => (string) $r->post_title,
                'status'      => (string) $r->post_status,
                'mechanisms'  => array('shortcode'),
            );
        }

        // 2) Pages assigned the plugin's page templates. Pulled in a
        // single IN() query against postmeta so we don't loop call WP.
        $templates = array_keys(self::chat_page_templates());
        if (!empty($templates)) {
            $placeholders = implode(',', array_fill(0, count($templates), '%s'));
            $sql = "SELECT p.ID, p.post_title, p.post_status, m.meta_value AS template
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                    WHERE p.post_type = 'page'
                      AND p.post_status IN ('publish','draft','private','pending')
                      AND m.meta_key = '_wp_page_template'
                      AND m.meta_value IN ({$placeholders})";
            $rows = $wpdb->get_results($wpdb->prepare($sql, $templates));
            foreach ($rows as $r) {
                $id = (int) $r->ID;
                if (isset($by_id[$id])) {
                    $by_id[$id]['mechanisms'][] = 'template:' . $r->template;
                } else {
                    $by_id[$id] = array(
                        'id'          => $id,
                        'title'       => (string) $r->post_title,
                        'status'      => (string) $r->post_status,
                        'mechanisms'  => array('template:' . $r->template),
                    );
                }
            }
        }

        // Sort by status (published first), then by title.
        usort($by_id, function ($a, $b) {
            $status_rank = function ($s) {
                return $s === 'publish' ? 0 : ($s === 'draft' ? 1 : 2);
            };
            $cmp = $status_rank($a['status']) - $status_rank($b['status']);
            if ($cmp !== 0) return $cmp;
            return strcasecmp($a['title'], $b['title']);
        });
        return $by_id;
    }

    /**
     * Build a human-friendly label for the mechanism(s) hosting the
     * chat on a given page. Knows the plugin's template slugs and
     * collapses raw paths to the registered template labels.
     */
    public static function describe_mechanisms($mechanisms) {
        $templates = self::chat_page_templates();
        $out = array();
        foreach ((array) $mechanisms as $m) {
            if ($m === 'shortcode') {
                $out[] = __('Shortcode', 'zen-cortext');
            } elseif (strpos($m, 'template:') === 0) {
                $slug = substr($m, strlen('template:'));
                $out[] = isset($templates[$slug])
                    ? $templates[$slug]['label']
                    : sprintf(__('Template (%s)', 'zen-cortext'), $slug);
            }
        }
        return implode(' + ', $out);
    }

    /**
     * admin-post.php handler for the "Create chat page" button.
     * Creates a draft page with the [zen_cortext] shortcode and an
     * obvious starter title, then redirects to its WP editor so the
     * admin can rename/publish in one click. Cap-checked + nonce-
     * checked, classic POST-redirect-GET; no AJAX needed.
     */
    public function handle_create_chat_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'zen-cortext'));
        }
        check_admin_referer('zen_cortext_create_chat_page');

        $id = wp_insert_post(array(
            'post_type'    => 'page',
            'post_status'  => 'draft',
            'post_title'   => __('New chat page', 'zen-cortext'),
            'post_content' => "<!-- wp:shortcode -->\n[zen_cortext]\n<!-- /wp:shortcode -->",
        ), true);
        if (is_wp_error($id) || !$id) {
            // Send the admin back to the same Chat pages tab they
            // clicked the button on — the form lives in the Settings
            // page's `?tab=pages` branch.
            wp_safe_redirect(add_query_arg(
                array('page' => 'zen-cortext', 'tab' => 'pages', 'created' => 'fail'),
                admin_url('admin.php')
            ));
            exit;
        }
        // Send the admin straight to the page editor — they almost
        // always want to rename/customize the new page right away.
        wp_safe_redirect(get_edit_post_link((int) $id, 'redirect'));
        exit;
    }

    public function rest_save_colors($request) {
        $raw    = (array) $request->get_param('colors');
        $tokens = self::color_tokens();
        $clean  = array();
        foreach ($raw as $name => $value) {
            if (!isset($tokens[$name])) continue;
            $name  = Zen_Cortext_Shortcode::sanitize_token_name($name);
            $value = Zen_Cortext_Shortcode::sanitize_token_value($value);
            if ($name === '' || $value === '') continue;
            $clean[$name] = $value;
        }
        update_option('zen_cortext_chat_colors', $clean);
        return rest_ensure_response(array(
            'saved'  => true,
            'colors' => (object) $clean,
        ));
    }

    /* ================================================================
       Float button — sticky CTA that links visitors to the chat
       ================================================================ */

    const FLOAT_OPTION = 'zen_cortext_float_button';

    /**
     * Default float-button settings. The icon URL points to chat.png
     * shipped inside the plugin's public/assets directory so a fresh
     * install works on any docroot without requiring the admin to
     * upload an icon to /chat.png at the site root. Admins can still
     * override with any media-library URL. target_page_id=0 means
     * "auto-detect the first detected visitor chat page at render time".
     */
    public static function float_button_defaults() {
        return array(
            'enabled'        => 0,
            'vertical'       => 'bottom',    // top | middle | bottom
            'horizontal'     => 'right',     // left | right
            'padding'        => 24,           // px from the edge (0..200)
            'button_color'   => '#ffffff',    // circle background, hex
            'icon_url'       => ZEN_CORTEXT_PLUGIN_URL . 'public/assets/chat.png',
            'hover_text'     => __('Talk to our AI consultant', 'zen-cortext'),
            'target_page_id' => 0,
        );
    }

    /**
     * Saved settings merged over the defaults.
     *
     * Empty-string values in the saved option are intentionally treated
     * as "not set" — they fall through to the default. Without this,
     * a Save click with the icon URL or hover text field blank would
     * permanently override the bundled defaults (`/chat.png` and
     * "Talk to our AI consultant") with empty strings, making the
     * button invisible/unlabeled until the admin retyped them.
     * Non-empty saved values still win.
     */
    public static function get_float_button() {
        $saved = get_option(self::FLOAT_OPTION, array());
        if (!is_array($saved)) $saved = array();
        $saved = array_filter($saved, static function ($v) {
            return !($v === '' || $v === null);
        });
        return array_merge(self::float_button_defaults(), $saved);
    }

    /**
     * Resolve the target URL the button should link to. Order of
     * preference: explicit target_page_id → first detected visitor
     * chat page → first detected chat page of any kind → home_url.
     * Returning home_url instead of '' keeps the button clickable
     * even on a fresh install where nothing is configured yet.
     */
    public static function float_button_target_url($settings = null) {
        if ($settings === null) $settings = self::get_float_button();

        $page_id = (int) ($settings['target_page_id'] ?? 0);
        if ($page_id > 0) {
            $url = get_permalink($page_id);
            if ($url) return $url;
        }
        $pages = self::list_chat_pages();
        foreach ($pages as $p) {
            // Prefer visitor pages — admin live-chat is the wrong target.
            foreach ($p['mechanisms'] as $m) {
                if ($m === 'shortcode' || $m === 'template:zen-cortext-chat-page.php') {
                    $url = get_permalink($p['id']);
                    if ($url) return $url;
                }
            }
        }
        // No visitor page detected — fall back to the first detected
        // page (likely the admin one). Better than nothing; admin will
        // notice and set target_page_id explicitly.
        if (!empty($pages)) {
            $url = get_permalink($pages[0]['id']);
            if ($url) return $url;
        }
        return home_url('/');
    }

    /**
     * wp_footer hook — renders the button when enabled, skipping the
     * admin side, feed requests, and the chat pages themselves so the
     * button doesn't shadow the actual chat on its own page.
     */
    public function maybe_render_float_button() {
        if (is_admin() || is_feed() || is_robots()) return;
        $settings = self::get_float_button();
        if (empty($settings['enabled'])) return;

        // Skip on the chat pages themselves — the button pointing at
        // the page you're already on is just noise.
        if (is_singular('page')) {
            $current_id = get_queried_object_id();
            $pages      = self::list_chat_pages();
            foreach ($pages as $p) {
                if ((int) $p['id'] === (int) $current_id) return;
            }
        }

        echo self::build_float_button_html($settings);
    }

    /**
     * Render the float-button HTML + scoped CSS. Position values are
     * already validated at save time, so we can drop them straight into
     * the inline style block without re-sanitizing. Icon URL went
     * through esc_url_raw at save; we re-escape on output as
     * defense-in-depth.
     */
    public static function build_float_button_html($settings) {
        $vertical   = in_array($settings['vertical'], array('top','middle','bottom'), true) ? $settings['vertical'] : 'bottom';
        $horizontal = in_array($settings['horizontal'], array('left','right'), true)        ? $settings['horizontal'] : 'right';
        $padding    = max(0, min(200, (int) $settings['padding']));
        $icon_url   = esc_url((string) $settings['icon_url']);
        if ($icon_url === '') $icon_url = esc_url(ZEN_CORTEXT_PLUGIN_URL . 'public/assets/chat.png');
        $hover_text = trim((string) $settings['hover_text']);
        if ($hover_text === '') $hover_text = __('Talk to our AI consultant', 'zen-cortext');
        $target_url = esc_url(self::float_button_target_url($settings));
        // Re-validate hex on render so a bad value in storage never lands
        // inline-CSS as something exploitable. White fallback matches the
        // default and keeps the button visible with any icon.
        $button_color = (string) ($settings['button_color'] ?? '');
        if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $button_color)) $button_color = '#ffffff';

        // Position rules — `middle` is centered vertically via translate.
        $pos_v = $vertical === 'top'    ? 'top: '    . $padding . 'px;'
               : ($vertical === 'bottom' ? 'bottom: ' . $padding . 'px;'
               : 'top: 50%; transform: translateY(-50%);');
        $pos_h = $horizontal === 'left' ? 'left: '  . $padding . 'px;'
                                        : 'right: ' . $padding . 'px;';
        $tooltip_origin = $horizontal === 'left' ? 'left: calc(100% + 12px);' : 'right: calc(100% + 12px);';
        $tooltip_arrow_side = $horizontal === 'left' ? 'right: 100%;' : 'left: 100%;';

        ob_start();
        ?>
        <style id="zcfb-style">
        .zcfb-wrap { position: fixed; z-index: 2147483600; <?php echo $pos_v . ' ' . $pos_h; ?> }
        .zcfb-btn {
            display: flex; align-items: center; justify-content: center;
            width: 64px; height: 64px; border-radius: 50%;
            background: <?php echo $button_color; ?>;
            box-shadow: 0 4px 16px rgba(0,0,0,0.18);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            text-decoration: none;
        }
        .zcfb-btn:hover, .zcfb-btn:focus-visible {
            transform: scale(1.06); box-shadow: 0 6px 22px rgba(0,0,0,0.24); outline: none;
        }
        /* Icon sits centered at 60% of the circle so it doesn't touch
           the edge. `contain` keeps non-square uploads from being
           cropped — the inset gives them room to breathe. */
        .zcfb-btn img { width: 60%; height: 60%; display: block; object-fit: contain; }
        .zcfb-tip {
            position: absolute; top: 50%; transform: translateY(-50%);
            <?php echo $tooltip_origin; ?>
            background: #1d2327; color: #fff; padding: 8px 12px; border-radius: 6px;
            font-size: 13px; line-height: 1.2; white-space: nowrap;
            opacity: 0; pointer-events: none; transition: opacity 0.15s ease;
        }
        .zcfb-tip::after {
            content: ''; position: absolute; top: 50%; transform: translateY(-50%);
            <?php echo $tooltip_arrow_side; ?>
            border: 6px solid transparent;
            <?php echo $horizontal === 'left'
                ? 'border-right-color: #1d2327;'
                : 'border-left-color: #1d2327;'; ?>
        }
        .zcfb-wrap:hover .zcfb-tip,
        .zcfb-wrap:focus-within .zcfb-tip { opacity: 1; }
        @media (max-width: 480px) {
            .zcfb-btn { width: 56px; height: 56px; }
            .zcfb-tip { display: none; }  /* tap-only on mobile — no hover */
        }
        </style>
        <div class="zcfb-wrap" id="zcfb-wrap">
            <a href="<?php echo $target_url; ?>" class="zcfb-btn"
               aria-label="<?php echo esc_attr($hover_text); ?>"
               title="<?php echo esc_attr($hover_text); ?>">
                <img src="<?php echo $icon_url; ?>" alt="" />
            </a>
            <span class="zcfb-tip" role="tooltip"><?php echo esc_html($hover_text); ?></span>
        </div>
        <?php
        return ob_get_clean();
    }
}
