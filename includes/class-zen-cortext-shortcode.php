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
        // Resolve chat.css from the editable-asset registry: live URL
        // (uploads/zen-cortext/assets/chat.css) when the admin has saved
        // an edited copy, factory URL inside the plugin tree otherwise.
        // The mtime cache-buster baked into asset_url() means saves
        // invalidate the browser cache without bumping plugin version.
        $chat_css_url = Zen_Cortext_Template_Renderer::asset_url('chat.css');
        wp_register_style(
            'zen-cortext-public',
            $chat_css_url,
            array(),
            null  // version baked into asset_url() — passing null avoids ?ver=… being appended twice.
        );
        wp_register_style(
            'zen-cortext-yanone',
            'https://fonts.googleapis.com/css2?family=Yanone+Kaffeesatz:wght@400;500;600;700&display=swap',
            array(),
            null
        );
        wp_register_script(
            'zen-cortext-public',
            ZEN_CORTEXT_PLUGIN_URL . 'public/assets/chat.js',
            array(),
            ZEN_CORTEXT_VERSION,
            true
        );
    }

    public function render($atts = array()) {
        $atts = shortcode_atts(array(), $atts, 'zen_cortext');

        if (!$this->assets_enqueued) {
            wp_enqueue_style('zen-cortext-yanone');
            wp_enqueue_style('zen-cortext-public');
            wp_enqueue_script('zen-cortext-public');
            $default_chips = (array) get_option('zen_cortext_default_chips', array());
            wp_localize_script('zen-cortext-public', 'zenCortextConfig', array(
                'restUrl'               => esc_url_raw(rest_url('zen-cortext/v1/send')),
                'restRoot'              => esc_url_raw(rest_url('zen-cortext/v1')),
                'attributionContextUrl' => esc_url_raw(rest_url('zen-cortext/v1/attribution-context')),
                'transcribeUrl'         => esc_url_raw(rest_url('zen-cortext/v1/transcribe')),
                'voiceEnabled'          => (bool) get_option('zen_cortext_voice_enabled', false),
                'voiceMaxSec'           => 60,
                'introCard'             => get_option('zen_cortext_intro_card', Zen_Cortext_Defaults::intro_card()),
                'welcomeMessage'        => get_option('zen_cortext_welcome_message', Zen_Cortext_Defaults::welcome_message()),
                'defaultChips'          => array_values($default_chips),
            ));
            $this->assets_enqueued = true;
        }

        $intro = get_option('zen_cortext_intro_card', Zen_Cortext_Defaults::intro_card());

        $color_override = self::build_color_override_style();

        ob_start();
        if ($color_override !== '') echo $color_override;
        // chat.php is a thin controller that calls the template renderer.
        // Preview-file routing is handled inside the renderer, not here.
        include ZEN_CORTEXT_PLUGIN_DIR . 'public/views/chat.php';
        return ob_get_clean();
    }

    /**
     * Build the inline <style> tag that overrides --zc-* tokens from the
     * zen_cortext_chat_colors option AND the typography options
     * (zen_cortext_font_family / zen_cortext_font_size). Returns an
     * empty string when nothing is configured so the published chat.css
     * defaults stand alone.
     */
    public static function build_color_override_style() {
        $rules = array();

        // Color tokens — one --zc-* rule per saved palette pick.
        $colors = (array) get_option('zen_cortext_chat_colors', array());
        foreach ($colors as $token => $value) {
            $token = self::sanitize_token_name($token);
            $value = self::sanitize_token_value($value);
            if ($token === '' || $value === '') continue;
            $rules[] = $token . ':' . $value . ';';
        }

        // Typography — emit --zc-font-family / --zc-font-size only when
        // the admin has picked something. Empty option = inherit from
        // host theme; the chat.css default rules (var(--zc-font-*, inherit))
        // do the right thing on their own.
        $font_family = trim((string) get_option('zen_cortext_font_family', ''));
        if ($font_family !== '') {
            $rules[] = '--zc-font-family:' . self::sanitize_font_family($font_family) . ';';
        }
        $font_size = (int) get_option('zen_cortext_font_size', 0);
        if ($font_size > 0 && $font_size <= 64) {
            $rules[] = '--zc-font-size:' . $font_size . 'px;';
        }

        if (!$rules) return '';
        // Emit at :root AND .zen-cortext-root so tokens cascade to chrome
        // that sits OUTSIDE the chat scope — the standalone chat page's
        // left rail (.zcp-rail), body (.zcp-body), and main wrapper
        // (.zcp-main) all reference --zc-* tokens but are siblings of
        // .zen-cortext-root, not children. The .zen-cortext-root rule
        // stays for backwards-compat with embeds that depend on it.
        // Vars are namespaced with --zc- so emitting at :root cannot
        // collide with host-theme styles.
        return '<style id="zen-cortext-color-overrides">:root,.zen-cortext-root{' . implode('', $rules) . '}</style>';
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
        ?>
<script>
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
          fbp:          ck('_fbp')
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
</script>
        <?php
    }
}
