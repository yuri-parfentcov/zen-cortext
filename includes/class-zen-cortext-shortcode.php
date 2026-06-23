<?php
/**
 * [zen_cortext] shortcode — renders the chat UI on a WP page.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zen_Cortext_Shortcode {

    private static $instance = null;
    private $assets_enqueued = false;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Custom code injection for the standalone chat templates.
     *
     * The full-page chat (/talk/) owns the whole document and deliberately
     * skips wp_head()/wp_footer(), so a site's normal header/footer injectors
     * (theme, GTM4WP, "Insert Headers and Footers", analytics plugins) never
     * fire there. These three options let the admin paste ANY markup — GTM,
     * GA4, Meta Pixel, verification meta tags, etc. — into the head, after the
     * opening <body>, and before </body> respectively. Theme-independent.
     *
     * The stored code is echoed verbatim by design (it is markup/script). It is
     * sanitized on save in Zen_Cortext_Admin::sanitize_custom_code(): stored raw
     * only for users with the unfiltered_html capability, otherwise filtered
     * through wp_kses_post() — the same model core uses for the Custom HTML
     * widget and that the header/footer-code plugins use.
     */
    public static function print_header_code() {
        $code = (string) get_option('zen_cortext_header_code', '');
        if (trim($code) === '') return;
        echo "\n";
        echo $code; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-authored custom code, capability-gated by unfiltered_html on save; escaping would defeat the feature.
        echo "\n";
    }

    /** Custom code printed immediately after the opening <body> tag. */
    public static function print_body_code() {
        $code = (string) get_option('zen_cortext_body_code', '');
        if (trim($code) === '') return;
        echo "\n";
        echo $code; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-authored custom code, capability-gated by unfiltered_html on save; escaping would defeat the feature.
        echo "\n";
    }

    /** Custom code printed just before the closing </body> tag. */
    public static function print_footer_code() {
        $code = (string) get_option('zen_cortext_footer_code', '');
        if (trim($code) === '') return;
        echo "\n";
        echo $code; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-authored custom code, capability-gated by unfiltered_html on save; escaping would defeat the feature.
        echo "\n";
    }

    const PAGE_TEMPLATE_SLUG = 'zen-cortext-chat-page.php';

    private function __construct() {
        add_shortcode('zen_cortext', array($this, 'render'));
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));

        // Register a custom page template for a full-page client chat that
        // bypasses the theme chrome and renders the same layout as the
        // standalone /chat/index.php prototype.
        add_filter('theme_page_templates', array($this, 'register_page_template'));
        add_filter('template_include', array($this, 'load_page_template'));

        // Site-wide session beacon. Inline in the footer (no extra HTTP
        // request) so every front-end page load registers / extends a
        // visitor session — not just the chat page. Guests only; admins/
        // logged-in users are excluded to avoid noise. Uses sendBeacon
        // for fire-and-forget reliability on bouncing visitors.
        add_action('wp_footer', array($this, 'print_session_beacon'), 99);
    }

    const LIVECHAT_TEMPLATE_SLUG = 'zen-cortext-livechat-page.php';

    public function register_page_template($templates) {
        $templates[self::PAGE_TEMPLATE_SLUG] = __('Zen Cortext — Full-page client chat', 'zen-cortext');
        $templates[self::LIVECHAT_TEMPLATE_SLUG] = __('Zen Cortext — Live Chat Admin (PWA)', 'zen-cortext');
        return $templates;
    }

    public function load_page_template($template) {
        if (!is_singular('page')) {
            return $template;
        }
        $selected = get_page_template_slug(get_queried_object_id());

        $map = array(
            self::PAGE_TEMPLATE_SLUG     => 'zen-cortext-chat-page.php',
            self::LIVECHAT_TEMPLATE_SLUG => 'zen-cortext-livechat-page.php',
        );
        if (!isset($map[$selected])) {
            return $template;
        }
        // Preview routing for the editable body template lives entirely
        // inside Zen_Cortext_Template_Renderer — no wrapper-PHP swap needed.
        $custom = ZEN_CORTEXT_PLUGIN_DIR . 'public/templates/' . $map[$selected];
        if (file_exists($custom)) {
            return $custom;
        }
        return $template;
    }

    public function register_assets() {
        // chat.css now lives in the DB (editable via the Template Editor).
        // register_chat_css() enqueues the bundled factory file when the
        // admin hasn't customized it (browser-cacheable), or attaches the
        // saved source as inline CSS that prints once the handle is enqueued.
        Zen_Cortext_Template_Renderer::register_chat_css('zen-cortext-public');
        wp_register_script(
            'zen-cortext-public',
            ZEN_CORTEXT_PLUGIN_URL . 'public/assets/chat.js',
            array(),
            ZEN_CORTEXT_VERSION,
            true
        );

        // Standalone full-page chat template chrome (the page layout/rail/
        // modal CSS + the small modal-interaction JS). Registered here so
        // the template can enqueue + print them through the core pipeline
        // instead of hand-writing <link>/<script> tags.
        wp_register_style(
            'zen-cortext-chat-page',
            ZEN_CORTEXT_PLUGIN_URL . 'public/assets/chat-page.css',
            array('zen-cortext-public'),
            ZEN_CORTEXT_VERSION
        );
        wp_register_script(
            'zen-cortext-chat-page',
            ZEN_CORTEXT_PLUGIN_URL . 'public/assets/chat-page.js',
            array(),
            ZEN_CORTEXT_VERSION,
            true
        );

        // Standalone live-chat admin (PWA) template assets.
        wp_register_style(
            'zen-cortext-livechat',
            ZEN_CORTEXT_PLUGIN_URL . 'public/assets/livechat.css',
            array(),
            ZEN_CORTEXT_VERSION
        );
        wp_register_script(
            'zen-cortext-livechat',
            ZEN_CORTEXT_PLUGIN_URL . 'public/assets/livechat.js',
            array(),
            ZEN_CORTEXT_VERSION,
            true
        );
    }

    /**
     * Build the zenCortextConfig payload localized onto the chat script.
     * Shared by the shortcode embed path and the standalone chat-page
     * template so both agree on the runtime config shape.
     */
    public static function chat_config_payload() {
        return array(
            'restUrl'               => esc_url_raw(rest_url('zen-cortext/v1/send')),
            'restRoot'              => esc_url_raw(rest_url('zen-cortext/v1')),
            'attributionContextUrl' => esc_url_raw(rest_url('zen-cortext/v1/attribution-context')),
            'transcribeUrl'         => esc_url_raw(rest_url('zen-cortext/v1/transcribe')),
            'voiceEnabled'          => (bool) get_option('zen_cortext_voice_enabled', false),
            'voiceMaxSec'           => 60,
            'introCard'             => get_option('zen_cortext_intro_card', Zen_Cortext_Defaults::intro_card()),
            'welcomeMessage'        => get_option('zen_cortext_welcome_message', Zen_Cortext_Defaults::welcome_message()),
            'defaultChips'          => array_values((array) get_option('zen_cortext_default_chips', array())),
            // Analytics data layer: when true, the chat pushes semantic
            // events (chat_started, message_sent, lead_submitted, …) to
            // window.dataLayer (GTM/GA4) and mirrors them as DOM
            // CustomEvents. No PII is ever emitted — only ids, counts and
            // flags. Site owners can disable via the filter below.
            'dataLayer'             => (bool) apply_filters('zen_cortext_data_layer_enabled', true),
        );
    }

    public function render($atts = array()) {
        $atts = shortcode_atts(array(), $atts, 'zen_cortext');

        if (!$this->assets_enqueued) {
            wp_enqueue_style('zen-cortext-public');
            wp_enqueue_script('zen-cortext-public');
            wp_localize_script('zen-cortext-public', 'zenCortextConfig', self::chat_config_payload());

            // Per-site design-settings overrides (--zc-* color tokens +
            // font-family / font-size) attached AFTER chat.css so the
            // cascade beats the published defaults. Empty when nothing is
            // configured, so this is a no-op on default sites.
            $override_css = self::build_color_override_css();
            if ($override_css !== '') {
                wp_add_inline_style('zen-cortext-public', $override_css);
            }
            $this->assets_enqueued = true;
        }

        $intro = get_option('zen_cortext_intro_card', Zen_Cortext_Defaults::intro_card());

        ob_start();
        // chat.php is a thin controller that calls the template renderer.
        // Preview-file routing is handled inside the renderer, not here.
        include ZEN_CORTEXT_PLUGIN_DIR . 'public/views/chat.php';
        return ob_get_clean();
    }

    /**
     * Build the raw CSS that overrides --zc-* tokens from the
     * zen_cortext_chat_colors option AND the typography options
     * (zen_cortext_font_family / zen_cortext_font_size). Returns an
     * empty string when nothing is configured so the published chat.css
     * defaults stand alone. This is the "design settings" output — it is
     * attached via wp_add_inline_style(), never echoed as a raw <style>.
     */
    public static function build_color_override_css() {
        $token_rules = array();
        $direct_rules = '';

        // Color tokens — one --zc-* rule per saved palette pick.
        $colors = (array) get_option('zen_cortext_chat_colors', array());
        foreach ($colors as $token => $value) {
            $token = self::sanitize_token_name($token);
            $value = self::sanitize_token_value($value);
            if ($token === '' || $value === '') continue;
            $token_rules[] = $token . ':' . $value . ';';
        }

        // Typography — emit BOTH the CSS variable AND direct font-* rules
        // on the elements that need to follow the picker. The variable
        // is the modern path (new factory chat.css uses var() refs); the
        // direct rules cover sites with a stale writable chat.css that
        // still has hardcoded sizes from pre-2.34.7. Direct rules sit in
        // an inline <style> printed AFTER the chat.css <link>, so they
        // win on identical specificity without needing !important.
        $font_family = trim((string) get_option('zen_cortext_font_family', ''));
        if ($font_family !== '') {
            $ff = self::sanitize_font_family($font_family);
            $token_rules[] = '--zc-font-family:' . $ff . ';';
            $direct_rules .= '.zen-cortext-root{font-family:' . $ff . ';}';
        }
        $font_size = (int) get_option('zen_cortext_font_size', 0);
        if ($font_size > 0 && $font_size <= 64) {
            $token_rules[] = '--zc-font-size:' . $font_size . 'px;';
            // chat.css uses em throughout for chat-internal elements
            // (post-2.34.9 refactor). Setting font-size on .zen-cortext-root
            // is enough — every child scales proportionally via em. This
            // rule sits in the override <style> printed after chat.css,
            // so it wins on cascade order at the same specificity.
            $direct_rules .= '.zen-cortext-root{font-size:' . $font_size . 'px;}';
        }

        if (!$token_rules && $direct_rules === '') return '';
        // Token rules go on `:root, .zen-cortext-root` so colors cascade
        // to chrome outside the chat scope (left rail, body, main wrapper).
        // Direct typography rules use the more specific .zen-cortext-root
        // selectors so they override chat.css at the same or higher
        // specificity. Vars are --zc-prefixed; safe to emit at :root.
        $css = '';
        if ($token_rules) {
            $css .= ':root,.zen-cortext-root{' . implode('', $token_rules) . '}';
        }
        $css .= $direct_rules;
        return $css;
    }

    /**
     * Sanitize a CSS font-family value before echoing into a <style>.
     * Strips anything that could break out of the property value:
     * semicolons, closing braces, angle brackets, newlines, backslashes.
     * Whitelisted set keeps font names with quotes/commas/spaces.
     */
    public static function sanitize_font_family($value) {
        $value = (string) $value;
        $value = preg_replace('/[;}<>\\\\\r\n]/', '', $value);
        return trim($value);
    }

    /**
     * Tokens must look like `--zc-foo` — the editor only ever emits
     * those, but option payloads come from anywhere so the runtime
     * verifies before echoing into a <style> tag.
     */
    public static function sanitize_token_name($name) {
        $name = (string) $name;
        return preg_match('/^--zc-[a-z][a-z0-9-]{0,40}$/', $name) ? $name : '';
    }

    /**
     * Allow #rgb / #rrggbb / #rrggbbaa hex; reject anything that could
     * close the <style> block or smuggle CSS from a malicious option
     * value (e.g. saved through SQL).
     */
    public static function sanitize_token_value($value) {
        $value = trim((string) $value);
        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) ? $value : '';
    }

    /**
     * Print the site-wide session-beacon snippet in <wp_footer>. Fires
     * navigator.sendBeacon to /session/beacon on every guest page load so
     * arrivals are recorded even when the visitor never opens the chat
     * widget. Skipped for logged-in users, admin/login pages, and feed
     * requests where there's no JS runtime anyway.
     *
     * Inline (no enqueue) on purpose: the script body is identical for
     * every guest so Varnish caching it inside the HTML page is fine;
     * the POST it fires bypasses the cache via the existing VCL rule
     * (POST → pass). sendBeacon can't read the response, so we mint the
     * session_uid client-side (crypto-random, 32 chars — same shape as
     * server-minted) and the server honours it on creation.
     */
    public function print_session_beacon() {
        if (is_user_logged_in()) return;
        if (is_admin())          return;
        if (is_feed())           return;

        // Master kill-switch from Settings → User sessions.
        // Default true so a fresh install starts tracking immediately.
        if (!get_option('zen_cortext_sessions_enabled', true)) return;

        $beacon_url = esc_url_raw(rest_url('zen-cortext/v1/session/beacon'));
        if ($beacon_url === '') return;
        $gdpr = (bool) get_option('zen_cortext_sessions_gdpr_compliant', false);

        // Capture the (identical-for-every-guest) beacon JS and print it
        // through wp_print_inline_script_tag() — the core inline-script
        // printer (WP 5.7+) — instead of a hand-written <script> tag.
        // Kept inline (no enqueued file) on purpose: the body never varies,
        // so Varnish caching it inside the HTML page avoids an extra request.
        ob_start();
        ?>
(function(){
  try {
    var BEACON = <?php echo wp_json_encode($beacon_url); ?>;
    var GDPR = <?php echo $gdpr ? 'true' : 'false'; ?>;
    var KEY = 'zenCortextSession';
    var NOW = Date.now();

    function readSession(){
      try { var raw = localStorage.getItem(KEY); if(!raw) return null;
            var o = JSON.parse(raw);
            return (o && typeof o.uid === 'string' && /^[a-zA-Z0-9_-]{16,64}$/.test(o.uid)) ? o : null;
      } catch(e){ return null; }
    }
    function writeSession(uid){
      try { localStorage.setItem(KEY, JSON.stringify({ uid: uid, last_seen: NOW })); } catch(e){}
    }
    function genUid(){
      var arr = new Uint8Array(24); (window.crypto||window.msCrypto).getRandomValues(arr);
      var s = ''; for (var i=0;i<arr.length;i++) s += (arr[i]%36).toString(36);
      return s.slice(0,32);
    }
    function qp(n){ try { return new URL(location.href).searchParams.get(n) || ''; } catch(e){ return ''; } }
    function ck(n){
      var m = document.cookie.match(new RegExp('(?:^|;\\s*)'+n.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+'=([^;]*)'));
      return m ? decodeURIComponent(m[1]) : '';
    }

    // Google Consent Mode v2 — when GDPR mode is on, the beacon only
    // fires after `analytics_storage` has been granted. We check the
    // gtag-maintained internal consent state (`google_tag_data.ics`)
    // which is populated by `gtag('consent', 'default'|'update', ...)`
    // calls. The state survives async loading of gtag — we just poll
    // briefly because consent may arrive after pageload (cookie banner
    // click), then time out so we don't keep checking forever.
    function hasAnalyticsConsent(){
      try {
        var gtd = window.google_tag_data;
        if (!gtd || !gtd.ics) return false;
        // ics.getConsentState returns 1 for granted, 2 for denied.
        if (typeof gtd.ics.getConsentState === 'function') {
          return gtd.ics.getConsentState('analytics_storage') === 1;
        }
        // Older variants expose an `.entries` map.
        var entries = gtd.ics.entries;
        return !!(entries && entries.analytics_storage && entries.analytics_storage.granted);
      } catch(e){ return false; }
    }

    function fireBeacon(){
      var stored = readSession();
      var uid = stored ? stored.uid : genUid();
      // Persist (or refresh last_seen) before sending so the chat widget
      // on /talk/ can pick it up even if the network request is in flight.
      writeSession(uid);

      var body = JSON.stringify({
        session_uid: uid,
        attribution: {
          referrer:     document.referrer || '',
          landing_page: location.href || '',
          utm_source:   qp('utm_source'),
          utm_medium:   qp('utm_medium'),
          utm_campaign: qp('utm_campaign'),
          utm_term:     qp('utm_term'),
          utm_content:  qp('utm_content'),
          gclid:        qp('gclid'),
          msclkid:      qp('msclkid'),
          fbc:          ck('_fbc'),
          fbp:          ck('_fbp'),
          amv:          ck('_amv_js')
        }
      });

      // sendBeacon is fire-and-forget and survives page unload (useful
      // for bouncing visitors). Falls back to fetch keepalive on browsers
      // missing the API (very old). Neither reads the response — that's
      // why the client mints the uid above.
      var sent = false;
      if (navigator.sendBeacon) {
        try {
          var blob = new Blob([body], { type: 'application/json' });
          sent = navigator.sendBeacon(BEACON, blob);
        } catch(e){}
      }
      if (!sent) {
        try {
          fetch(BEACON, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: body,
            keepalive: true,
            credentials: 'omit'
          });
        } catch(e){}
      }
    }

    if (!GDPR) {
      fireBeacon();
      return;
    }

    // GDPR mode: fire only if/when analytics_storage is granted.
    // Poll for up to ~10 seconds so late-arriving consent (after the
    // visitor clicks Accept on a cookie banner) still gets caught,
    // then give up to avoid pegging the runtime.
    if (hasAnalyticsConsent()) { fireBeacon(); return; }
    var attempts = 0;
    var poll = setInterval(function(){
      attempts++;
      if (hasAnalyticsConsent()) { clearInterval(poll); fireBeacon(); return; }
      if (attempts > 20) { clearInterval(poll); }
    }, 500);
  } catch(e){}
})();
        <?php
        $js = ob_get_clean();
        wp_print_inline_script_tag($js);
    }
}
