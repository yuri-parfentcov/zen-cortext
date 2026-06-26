<?php
/**
 * Admin: settings page, register_setting, AJAX job runners.
 */


/*
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 * phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
 *
 * Justification:
 * - SQL: this file is a data-access surface for plugin-owned tables
 *   (wp_zen_cortext_*). Each query is built around a $wpdb->prefix .
 *   'zen_cortext_…' table name, which cannot be passed via a %s placeholder
 *   ($wpdb->prepare does not bind identifiers). Every user-controlled value
 *   in WHERE / VALUES / SET clauses goes through $wpdb->prepare(). Admin
 *   analytics aggregates (SUM / COUNT / CASE) are real-time and not
 *   candidates for the WP_Object_Cache.
 * - Nonce: every AJAX entry point in this class begins with
 *   $this->check_request(), which calls current_user_can('manage_options')
 *   followed by check_ajax_referer('zen_cortext_admin', 'nonce') before
 *   reading any superglobal. The linter cannot trace nonce checks across
 *   helper methods.
 * - Sanitize/Unslash: NOT blanket-disabled. Every $_GET/$_POST read is
 *   unslashed + sanitized at its point of use with a context-appropriate
 *   function (sanitize_key/(int)/sanitize_text_field/esc_url_raw/array_map).
 *   The few exceptions carry a TARGETED, justified phpcs:ignore on their own
 *   line: JSON payloads (types/messages/chips_json/intro_card_json) are
 *   json_decoded and then sanitized field-by-field in their save() layer with
 *   the right function per field (sanitize_text_field, sanitize_textarea_field,
 *   wp_kses_post for HTML bodies, esc_url_raw for URLs); AI-prompt/script
 *   textareas go through sanitize_textarea() which unslashes + validates UTF-8
 *   + strips control chars while preserving the angle-bracket tokens those
 *   prompts require.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zen_Cortext_Admin {

    private static $instance = null;
    private $init_hook = '';
    private $kb_hook = '';
    private $chat_hook = '';
    private $chats_hook = '';
    private $brainstorm_hook = '';
    private $attribution_hook = '';
    private $sessions_hook = '';
    private $ads_sync_hook = '';
    private $surveys_hook = '';
    private $webhooks_hook = '';
    private $api_hook = '';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
        // Runs after every plugin file has registered its submenus
        // (chat-editor.php hooks at priority 20). Renames the auto-generated
        // parent entry to "Settings", drops any duplicate copy that WP 6.7+
        // creates when add_submenu_page is called with the parent's slug,
        // and reshuffles the submenu order by slug.
        add_action('admin_menu', array($this, 'reorder_menu'), 999);
        add_action('admin_init', array($this, 'register_settings'));
        // Redirect handler hooks `init` (not `admin_init`) so it fires
        // before wp-admin/includes/menu.php's user_can_access_admin_page()
        // check (line 380), which 403s on unknown page slugs like the
        // removed `zen-cortext-artifacts`.
        add_action('init', array($this, 'redirect_legacy_kb_tab'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));

        // AJAX
        add_action('wp_ajax_zen_cortext_test_connection',  array($this, 'ajax_test_connection'));
        add_action('wp_ajax_zen_cortext_sync',             array($this, 'ajax_sync'));
        add_action('wp_ajax_zen_cortext_classify_next',    array($this, 'ajax_classify_next'));
        add_action('wp_ajax_zen_cortext_restructure_next', array($this, 'ajax_restructure_next'));
        add_action('wp_ajax_zen_cortext_clear',            array($this, 'ajax_clear'));
        add_action('wp_ajax_zen_cortext_stats',            array($this, 'ajax_stats'));

        // Knowledge Artifacts AJAX
        add_action('wp_ajax_zen_cortext_artifact_list',                 array($this, 'ajax_artifact_list'));
        add_action('wp_ajax_zen_cortext_artifact_get',                  array($this, 'ajax_artifact_get'));
        add_action('wp_ajax_zen_cortext_artifact_save',                 array($this, 'ajax_artifact_save'));
        add_action('wp_ajax_zen_cortext_artifact_delete',               array($this, 'ajax_artifact_delete'));
        add_action('wp_ajax_zen_cortext_artifact_search',               array($this, 'ajax_artifact_search'));
        add_action('wp_ajax_zen_cortext_artifact_synthesize_from_chat', array($this, 'ajax_artifact_synthesize_from_chat'));

        // Saved Chats AJAX
        add_action('wp_ajax_zen_cortext_chat_delete',  array($this, 'ajax_chat_delete'));
        add_action('wp_ajax_zen_cortext_chat_restore', array($this, 'ajax_chat_restore'));

        // Attribution Context AJAX
        add_action('wp_ajax_zen_cortext_attribution_list',   array($this, 'ajax_attribution_list'));
        add_action('wp_ajax_zen_cortext_attribution_get',    array($this, 'ajax_attribution_get'));
        add_action('wp_ajax_zen_cortext_attribution_save',   array($this, 'ajax_attribution_save'));
        add_action('wp_ajax_zen_cortext_attribution_delete', array($this, 'ajax_attribution_delete'));

        // User Sessions AJAX — read-only expand on the User Sessions
        // admin view. Returns the session row + all attached chats.
        add_action('wp_ajax_zen_cortext_session_get',    array($this, 'ajax_session_get'));
        add_action('wp_ajax_zen_cortext_session_delete', array($this, 'ajax_session_delete'));

        // Ads Sync AJAX
        add_action('wp_ajax_zen_cortext_apps_script_key_regenerate', array($this, 'ajax_apps_script_key_regenerate'));
        add_action('wp_ajax_zen_cortext_ads_campaigns_list',         array($this, 'ajax_ads_campaigns_list'));
        add_action('wp_ajax_zen_cortext_ads_campaigns_clear',        array($this, 'ajax_ads_campaigns_clear'));

        // Surveys AJAX
        add_action('wp_ajax_zen_cortext_surveys_list',   array($this, 'ajax_surveys_list'));
        add_action('wp_ajax_zen_cortext_surveys_get',    array($this, 'ajax_surveys_get'));
        add_action('wp_ajax_zen_cortext_surveys_save',   array($this, 'ajax_surveys_save'));
        add_action('wp_ajax_zen_cortext_surveys_delete', array($this, 'ajax_surveys_delete'));

        // Webhooks AJAX (admin-only). Endpoint list lives in a WP option;
        // CRUD goes through these handlers so the JS editor mirrors the
        // attribution page pattern.
        add_action('wp_ajax_zen_cortext_webhooks_list',   array($this, 'ajax_webhooks_list'));
        add_action('wp_ajax_zen_cortext_webhooks_save',   array($this, 'ajax_webhooks_save'));
        add_action('wp_ajax_zen_cortext_webhooks_delete', array($this, 'ajax_webhooks_delete'));
        add_action('wp_ajax_zen_cortext_webhooks_test',   array($this, 'ajax_webhooks_test'));

        // API Keys AJAX (admin-only). Multi-key auth for the external
        // read API (zc/v1). Create returns the raw token exactly once;
        // revoke is one-way (no un-revoke — create a new key).
        add_action('wp_ajax_zen_cortext_api_keys_list',   array($this, 'ajax_api_keys_list'));
        add_action('wp_ajax_zen_cortext_api_keys_create', array($this, 'ajax_api_keys_create'));
        add_action('wp_ajax_zen_cortext_api_keys_revoke', array($this, 'ajax_api_keys_revoke'));

        // KB content types editor — full-list save and per-type delete.
        // Both go through Zen_Cortext_KB_Types for validation + legacy-
        // option mirroring + cache busting.
        add_action('wp_ajax_zen_cortext_types_save',   array($this, 'ajax_types_save'));
        add_action('wp_ajax_zen_cortext_types_delete', array($this, 'ajax_types_delete'));
    }

    public function add_menu() {
        add_menu_page(
            __('Zen Cortext', 'zen-cortext'),
            __('Zen Cortext', 'zen-cortext'),
            'manage_options',
            'zen-cortext',
            array($this, 'render_page'),
            'dashicons-format-chat',
            58
        );

        // Getting Started — onboarding page. Slug `zen-cortext-init` keeps
        // it separate from the parent slug, so `?page=zen-cortext` keeps
        // routing to Settings and every existing deep link
        // (?page=zen-cortext&tab=connection, &tab=design, etc.) keeps
        // working unchanged. Display order is handled by reorder_menu().
        $this->init_hook = add_submenu_page(
            'zen-cortext',
            __('Getting Started with Zen Cortext', 'zen-cortext'),
            __('Getting Started', 'zen-cortext'),
            'manage_options',
            'zen-cortext-init',
            array($this, 'render_initialization_page')
        );

        // The auto-generated parent-slug submenu (still labelled "Zen Cortext")
        // is renamed to "Settings" in reorder_menu() — calling add_submenu_page
        // with the parent's slug used to rename it pre-6.7 but now produces a
        // second, duplicate entry, so we mutate $submenu directly instead.

        // Knowledge Base — sync + classify + restructure pipeline for indexed
        // WordPress content. Moved out of the Settings tab strip so the
        // pipeline UI (long-running sync/classify/restructure jobs and their
        // log output) has room to breathe and so admins land directly on it
        // without hunting through tabs.
        //
        // The menu title includes a count-bubble badge showing rows that
        // need (re-)processing. Count is cached in a 60s transient and
        // busted from every KB write path (post hooks + manual pipeline
        // + Clear), so the badge stays current without 4 COUNT queries
        // on every admin pageload.
        $pending = self::kb_pending_count();
        $kb_menu_title = __('Knowledge Base', 'zen-cortext');
        if ($pending > 0) {
            $kb_menu_title .= ' <span class="awaiting-mod count-' . (int) $pending . '"><span class="pending-count">' . (int) $pending . '</span></span>';
        }
        $this->kb_hook = add_submenu_page(
            'zen-cortext',
            __('Knowledge Base', 'zen-cortext'),
            $kb_menu_title,
            'manage_options',
            'zen-cortext-kb',
            array($this, 'render_kb_page')
        );

        // Chat settings — public-chat persona + behaviour: system prompt,
        // welcome message, default chips, intro card, default survey,
        // live takeover. Previously a tab on the Settings screen; broken
        // out so the long form (incl. the Adapt-to-KB modal) has room.
        $this->chat_hook = add_submenu_page(
            'zen-cortext',
            __('Chat settings', 'zen-cortext'),
            __('Chat settings', 'zen-cortext'),
            'manage_options',
            'zen-cortext-chat',
            array($this, 'render_chat_page')
        );

        // Attribution Context — admin-curated rules that key off UTM tags,
        // referrer host, or gclid presence. Each rule customizes the chat
        // welcome message, starter chips, and a system-prompt block so the
        // AI knows what offer/landing brought the visitor here.
        // Positioned right under Chat settings — both shape how the
        // visitor chat behaves, so they belong adjacent in the sidebar.
        $this->attribution_hook = add_submenu_page(
            'zen-cortext',
            __('Attribution Context', 'zen-cortext'),
            __('Attribution Context', 'zen-cortext'),
            'manage_options',
            'zen-cortext-attribution',
            array($this, 'render_attribution_page')
        );

        // Saved client chats — every public chat session is automatically
        // persisted with attribution data; this page is the read-only browser.
        $this->chats_hook = add_submenu_page(
            'zen-cortext',
            __('Saved Chats', 'zen-cortext'),
            __('Saved Chats', 'zen-cortext'),
            'manage_options',
            'zen-cortext-chats',
            array($this, 'render_chats_page')
        );

        // Admin-only brainstorm chat. Same KB / Artifacts / Team Expertise
        // context as the visitor chat, but on Opus 4.6 with extended thinking
        // and prompt caching — used for ideation and content drafting.
        $this->brainstorm_hook = add_submenu_page(
            'zen-cortext',
            __('Brainstorm', 'zen-cortext'),
            __('Brainstorm', 'zen-cortext'),
            'manage_options',
            'zen-cortext-brainstorm',
            array($this, 'render_brainstorm_page')
        );

        // User Sessions — one row per visitor browser-visit (GA-style:
        // 30-min inactivity OR attribution change starts a new session).
        // Shows enriched-with-attribution sessions by default and lets
        // admins expand any row to see the full attribution map plus all
        // chats that were started during that session.
        $this->sessions_hook = add_submenu_page(
            'zen-cortext',
            __('User Sessions', 'zen-cortext'),
            __('User Sessions', 'zen-cortext'),
            'manage_options',
            'zen-cortext-sessions',
            array($this, 'render_sessions_page')
        );

        // Surveys — admin-defined interview scripts. Attached either globally
        // via the Default Survey field on the Chat tab, or per-rule on an
        // Attribution Context entry. Injected into the AI system prompt as
        // guidance — not a hard form, no per-chat state.
        $this->surveys_hook = add_submenu_page(
            'zen-cortext',
            __('Surveys', 'zen-cortext'),
            __('Surveys', 'zen-cortext'),
            'manage_options',
            'zen-cortext-surveys',
            array($this, 'render_surveys_page')
        );

        // Webhooks — outbound JSON POSTs to admin-configured endpoints on
        // lead.captured / invite.sent / admin.joined / admin.left /
        // chat.started. Storage is a single WP option; delivery is
        // fire-and-forget (no queue, no retry, no signing).
        $this->webhooks_hook = add_submenu_page(
            'zen-cortext',
            __('Webhooks', 'zen-cortext'),
            __('Webhooks', 'zen-cortext'),
            'manage_options',
            'zen-cortext-webhooks',
            array($this, 'render_webhooks_page')
        );

        // API — single submenu hosting two tabs:
        //   ?tab=keys  → multi-key bearer-token auth for wp-json/zc/v1/*
        //   ?tab=docs  → static reference for the same API
        // Both share the same hook so asset enqueue + capability checks
        // converge; the render callback dispatches on $_GET['tab']. Slug
        // kept as `zen-cortext-api-keys` so existing bookmarks and the
        // cross-links from API Docs don't 404.
        $this->api_hook = add_submenu_page(
            'zen-cortext',
            __('API', 'zen-cortext'),
            __('API', 'zen-cortext'),
            'manage_options',
            'zen-cortext-api-keys',
            array($this, 'render_api_page')
        );

        // Ads Sync — Apps Script API key + read-only view of the campaigns
        // synced from Google Ads. Synced data joins to attribution rules at
        // lookup time on utm_campaign ↔ campaign_name.
        $this->ads_sync_hook = add_submenu_page(
            'zen-cortext',
            __('Google Ads Sync', 'zen-cortext'),
            __('Google Ads Sync', 'zen-cortext'),
            'manage_options',
            'zen-cortext-ads-sync',
            array($this, 'render_ads_sync_page')
        );
    }

    /**
     * Renames the auto-generated "Zen Cortext" submenu entry to "Settings",
     * drops any duplicate copy WP 6.7+ creates, and reorders the submenu by
     * usage frequency (most-checked screens first, setup screens last).
     *
     * Hooked at admin_menu priority 999 — after admin.php (priority 10) and
     * chat-editor.php (priority 20) have registered their entries.
     */
    public function reorder_menu() {
        global $submenu;
        if (empty($submenu['zen-cortext']) || !is_array($submenu['zen-cortext'])) return;

        // Pass 1: rename the auto-generated parent-slug entry to "Settings"
        // and drop any duplicates (WP 6.7+ side-effect).
        $seen_parent = false;
        foreach ($submenu['zen-cortext'] as $key => $entry) {
            $slug = isset($entry[2]) ? $entry[2] : '';
            if ($slug !== 'zen-cortext') continue;
            if ($seen_parent) {
                unset($submenu['zen-cortext'][$key]);
                continue;
            }
            $submenu['zen-cortext'][$key][0] = __('Settings', 'zen-cortext');
            $submenu['zen-cortext'][$key][3] = __('Zen Cortext Settings', 'zen-cortext');
            $seen_parent = true;
        }

        // Pass 2: reorder by slug. Slugs not listed here keep their original
        // order at the bottom, so a future feature that adds a submenu still
        // shows up without code changes here.
        $desired = array(
            'zen-cortext-chats',        // Saved Chats
            'zen-cortext-sessions',     // User Sessions
            'zen-cortext-brainstorm',   // Brainstorm
            'zen-cortext-chat',         // Chat settings
            'zen-cortext-kb',           // Knowledge Base
            'zen-cortext-attribution',  // Attribution Context
            'zen-cortext-surveys',      // Surveys
            'zen-cortext-chat-editor',  // Template Editor
            'zen-cortext-webhooks',     // Webhooks
            'zen-cortext-api-keys',     // API
            'zen-cortext-ads-sync',     // Google Ads Sync
            'zen-cortext',              // Settings (parent slug)
            'zen-cortext-init',         // Getting Started
        );

        $by_slug = array();
        foreach ($submenu['zen-cortext'] as $entry) {
            $slug = isset($entry[2]) ? $entry[2] : '';
            if ($slug === '') continue;
            $by_slug[$slug] = $entry;
        }

        $ordered = array();
        foreach ($desired as $slug) {
            if (isset($by_slug[$slug])) {
                $ordered[] = $by_slug[$slug];
                unset($by_slug[$slug]);
            }
        }
        foreach ($by_slug as $entry) $ordered[] = $entry;

        $submenu['zen-cortext'] = array_values($ordered);
    }

    public function register_settings() {
        // One option group per tab. Saving any tab's form runs sanitizers
        // ONLY for options registered in that tab's group, so unrelated tabs'
        // values can't get blanked when a different tab is saved (which is
        // what happens with WP's register_setting + missing-POST-field
        // semantics if every option lives in one shared group).

        // Connection tab — backend choice + credentials + model settings.
        $g = 'zen_cortext_connection';
        register_setting($g, 'zen_cortext_api_key',         array('sanitize_callback' => array($this, 'sanitize_api_key')));
        // The Claude Code CLI processor (proc_open) is not part of this
        // plugin — it ships as a separate companion plugin which registers
        // its own zen_cortext_processor / cli_path / cli_model settings.
        register_setting($g, 'zen_cortext_model',           array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($g, 'zen_cortext_classify_model',  array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($g, 'zen_cortext_max_tokens',      array('sanitize_callback' => array($this, 'sanitize_max_tokens')));

        // Knowledge Base tab — what to index, classify prompt template.
        // `zen_cortext_content_types` is NOT registered here: its editor
        // is AJAX-only (zen_cortext_types_save handler), not part of the
        // options.php form. Registering a sanitize callback that called
        // save() created infinite recursion because save() itself runs
        // update_option, which triggers the sanitize filter.
        $g = 'zen_cortext_kb';
        register_setting($g, 'zen_cortext_post_types',      array('sanitize_callback' => array($this, 'sanitize_post_types')));
        register_setting($g, 'zen_cortext_classify_prompt', array('sanitize_callback' => array($this, 'sanitize_textarea')));

        // Chat tab — public-chat UI strings + takeover config + team expertise.
        $g = 'zen_cortext_chat';
        // The Chat settings page is split into three tabs (basic / rail /
        // prompts). Each tab posts its own form to options.php, so each
        // group must be SEPARATE — otherwise saving on one tab would
        // blank fields that belong to other tabs (same gotcha solved
        // for connection/voice/sessions in settings-page.php).
        //
        // Basic tab — visitor-facing chrome (intro card, welcome line,
        // chips) + default survey selector (which survey to run is a
        // content decision; the prompt template that wraps it lives on
        // the Prompts tab).
        $g = 'zen_cortext_chat_basic';
        register_setting($g, 'zen_cortext_welcome_message',     array('sanitize_callback' => 'sanitize_textarea_field'));
        register_setting($g, 'zen_cortext_intro_card',          array('sanitize_callback' => array($this, 'sanitize_intro_card')));
        register_setting($g, 'zen_cortext_default_chips',       array('sanitize_callback' => array($this, 'sanitize_default_chips')));
        register_setting($g, 'zen_cortext_default_survey_id',   array('sanitize_callback' => 'absint'));

        // Rail tab — side rail / mobile menu (quick links, takeover, team).
        $g = 'zen_cortext_chat_rail';
        register_setting($g, 'zen_cortext_quick_links',         array('sanitize_callback' => array($this, 'sanitize_quick_links')));
        register_setting($g, 'zen_cortext_show_invite_buttons', array('sanitize_callback' => 'rest_sanitize_boolean'));
        register_setting($g, 'zen_cortext_invite_label',        array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($g, 'zen_cortext_invitable_users',     array('sanitize_callback' => array($this, 'sanitize_invitable_users')));
        register_setting($g, 'zen_cortext_team_expertise',      array('sanitize_callback' => array($this, 'sanitize_team_expertise')));

        // Prompts tab — LLM-facing scaffolding (system prompt + Adapt
        // modal, survey framing template, read-only gatekeeper preview).
        $g = 'zen_cortext_chat_prompts';
        register_setting($g, 'zen_cortext_system_prompt',          array('sanitize_callback' => array($this, 'sanitize_textarea')));
        register_setting($g, 'zen_cortext_survey_prompt_template', array('sanitize_callback' => array($this, 'sanitize_textarea')));

        // Voice tab — Groq Whisper transcription with optional OpenAI
        // Whisper fallback. Same bring-your-own-key model as the
        // Anthropic credentials above, so the agency never proxies or
        // marks up provider usage.
        $g = 'zen_cortext_voice';
        register_setting($g, 'zen_cortext_voice_enabled',     array('sanitize_callback' => 'rest_sanitize_boolean'));
        register_setting($g, 'zen_cortext_groq_api_key',      array('sanitize_callback' => array($this, 'sanitize_api_key')));
        register_setting($g, 'zen_cortext_openai_api_key',    array('sanitize_callback' => array($this, 'sanitize_api_key')));

        // User sessions tab — visitor-session tracking on/off + an
        // optional GDPR mode that gates the beacon on Google Consent
        // Mode v2 (`analytics_storage` must be granted before sendBeacon
        // fires). The renderer in Zen_Cortext_Shortcode::print_session_beacon
        // reads both options and prints / wraps / suppresses the JS
        // accordingly.
        $g = 'zen_cortext_sessions';
        register_setting($g, 'zen_cortext_sessions_enabled',         array('sanitize_callback' => 'rest_sanitize_boolean'));
        register_setting($g, 'zen_cortext_sessions_gdpr_compliant',  array('sanitize_callback' => 'rest_sanitize_boolean'));

        // Custom Header / Body / Footer code injected into the standalone chat
        // templates (which skip wp_head()/wp_footer()). Raw markup by design;
        // capability-gated in sanitize_custom_code().
        register_setting($g, 'zen_cortext_header_code', array('sanitize_callback' => array($this, 'sanitize_custom_code')));
        register_setting($g, 'zen_cortext_body_code',   array('sanitize_callback' => array($this, 'sanitize_custom_code')));
        register_setting($g, 'zen_cortext_footer_code', array('sanitize_callback' => array($this, 'sanitize_custom_code')));
    }

    /**
     * Sanitize the custom Header/Body/Footer code fields.
     *
     * These intentionally hold raw markup (GTM/GA4/Pixel <script> tags, the GTM
     * <noscript> iframe, verification <meta> tags). Stored verbatim ONLY for
     * users who hold `unfiltered_html` (administrators on single-site, super
     * admins on multisite) — the same capability core requires to save the
     * Custom HTML widget, and the standard model used by header/footer-code
     * plugins. For anyone else the value is filtered through wp_kses_post(),
     * which strips <script>/<iframe> and dangerous attributes.
     */
    public function sanitize_custom_code($value) {
        $value = is_string($value) ? wp_unslash($value) : '';
        if (current_user_can('unfiltered_html')) {
            return $value;
        }
        return wp_kses_post($value);
    }

    /**
     * Default starter chips — admin-curated. No fallback: empty list
     * means no chips on the chat page. The front-end picks 4 at random
     * from the saved pool on every page load.
     *
     * Accepts either a textarea string (one chip per line, pipe-separated)
     * or an array of {emoji,label,message} chips (programmatic / legacy
     * callers). Storage shape is always an array of normalized chips.
     */
    public function sanitize_default_chips($value) {
        if (is_string($value)) {
            return self::parse_chips_textarea(wp_unslash($value));
        }
        if (!is_array($value)) return array();
        $clean = array();
        foreach ($value as $chip) {
            if (!is_array($chip)) continue;
            $emoji   = isset($chip['emoji'])   ? sanitize_text_field((string) $chip['emoji'])   : '';
            $label   = isset($chip['label'])   ? sanitize_text_field((string) $chip['label'])   : '';
            $message = isset($chip['message']) ? sanitize_text_field((string) $chip['message']) : '';
            if ($label === '' && $message === '') continue;
            if ($message === '') $message = $label;
            if ($label === '')   $label   = $message;
            $clean[] = array('emoji' => $emoji, 'label' => $label, 'message' => $message);
        }
        return $clean;
    }

    /**
     * Sanitize the `zen_cortext_quick_links` option.
     *
     * Accepts either:
     *  - A pipe-delimited textarea string (one row per line: emoji/icon
     *    | prefix | label | url) — what the new editor posts.
     *  - An array of `{icon, label, url, prefix, target}` rows — legacy
     *    callers / migrations.
     *
     * Target defaults to `_blank` for absolute http(s) URLs and stays
     * empty (same tab) for relative URLs — the admin doesn't have to
     * spell it out per row.
     */
    public function sanitize_quick_links($value) {
        if (is_string($value)) {
            return self::parse_quick_links_textarea(wp_unslash($value));
        }
        if (!is_array($value)) return array();
        $clean = array();
        foreach ($value as $row) {
            if (!is_array($row)) continue;
            $icon   = isset($row['icon'])   ? sanitize_text_field((string) $row['icon'])   : '';
            $label  = isset($row['label'])  ? sanitize_text_field((string) $row['label'])  : '';
            $url    = isset($row['url'])    ? esc_url_raw((string) $row['url'])            : '';
            $prefix = isset($row['prefix']) ? sanitize_text_field((string) $row['prefix']) : '';
            $target = isset($row['target']) ? (string) $row['target']                      : '';
            $target = ($target === '_blank' || $target === '1') ? '_blank' : '';
            if ($label === '' && $url === '') continue;
            $clean[] = array(
                'icon'   => $icon,
                'label'  => $label,
                'url'    => $url,
                'prefix' => $prefix,
                'target' => $target,
            );
        }
        return $clean;
    }

    /**
     * Parse the quick-links textarea into the option's canonical array
     * shape. Each non-empty line becomes one row:
     *
     *   icon | prefix | label | url
     *
     * with empty trailing pieces tolerated. A 3-piece line is treated
     * as `icon | label | url` (no prefix). External http(s) URLs auto-
     * default to target=_blank; relative URLs stay same-tab.
     */
    private static function parse_quick_links_textarea($text) {
        $clean = array();
        $lines = preg_split('/\r\n|\r|\n/', (string) $text);
        foreach ((array) $lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = array_map('trim', explode('|', $line));

            // Pad up to 4 pieces for predictable destructuring.
            $parts = array_pad($parts, 4, '');

            if (count(array_filter($parts, 'strlen')) >= 3 && trim($parts[3]) === '' && trim($parts[2]) === '') {
                // Single-piece line is unusable; skip.
                continue;
            }

            // Two layouts allowed: 4-piece `icon|prefix|label|url`,
            // 3-piece `icon|label|url` (no prefix). Detect by looking at
            // the last non-empty piece — it should be the URL.
            if ($parts[3] === '' && $parts[2] !== '') {
                // 3-piece form. Shift so url ends up at index 3.
                $parts = array($parts[0], '', $parts[1], $parts[2]);
            }
            $icon   = sanitize_text_field($parts[0]);
            $prefix = sanitize_text_field($parts[1]);
            $label  = sanitize_text_field($parts[2]);
            // Only accept well-formed URL shapes. esc_url_raw silently
            // prepends `http://` to anything that isn't a scheme'd URL
            // or a relative path — so a single stray character (e.g.
            // an Option-C typo `ç` on macOS) becomes `http://ç`. Gate
            // first; reject malformed entries to an empty url so the
            // row gets dropped by the empty-row filter below instead
            // of corrupted into nonsense.
            $raw_url = trim($parts[3]);
            $url     = '';
            if ($raw_url !== '') {
                if (preg_match('#^(https?|mailto|tel):#i', $raw_url)
                    || $raw_url[0] === '/'
                    || $raw_url[0] === '#') {
                    $url = esc_url_raw($raw_url);
                }
                // else: leave $url empty — the row will be dropped below.
            }

            if ($label === '' && $url === '') continue;

            $clean[] = array(
                'icon'   => $icon,
                'label'  => $label,
                'url'    => $url,
                'prefix' => $prefix,
                // All rail cards open in a new tab so the visitor stays
                // anchored on the chat page; the rail is meant to LEAVE
                // the conversation, not navigate away from it.
                'target' => '_blank',
            );
        }
        return $clean;
    }

    /**
     * Serialize the saved quick-links array back to the pipe-delimited
     * textarea shape the editor displays. Drops the auto-computed
     * `target` field — that's recomputed from URL on next save.
     */
    public static function quick_links_to_textarea($links) {
        if (!is_array($links)) return '';
        $lines = array();
        foreach ($links as $ql) {
            if (!is_array($ql)) continue;
            $icon   = isset($ql['icon'])   ? (string) $ql['icon']   : '';
            $prefix = isset($ql['prefix']) ? (string) $ql['prefix'] : '';
            $label  = isset($ql['label'])  ? (string) $ql['label']  : '';
            $url    = isset($ql['url'])    ? (string) $ql['url']    : '';
            if ($label === '' && $url === '') continue;
            // Keep all four columns even when prefix is empty so the
            // admin sees a consistent shape — easier to edit by hand.
            $lines[] = $icon . ' | ' . $prefix . ' | ' . $label . ' | ' . $url;
        }
        return implode("\n", $lines);
    }

    /**
     * Parse the textarea into a chips array. Each non-empty line is one
     * chip. Pipe-separated, with the first piece treated as the emoji
     * ONLY when it's emoji-shaped (no ASCII alphanumerics) AND there's
     * at least one more piece. Everything after the first non-emoji piece
     * collapses into the message — so "Label | foo | bar" gives
     * label="Label" and message="foo | bar", which is what an admin
     * pasting a list intuitively expects.
     */
    private static function parse_chips_textarea($text) {
        $clean = array();
        $lines = preg_split('/\r\n|\r|\n/', (string) $text);
        foreach ((array) $lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = array_map('trim', explode('|', $line));

            $emoji = '';
            // Strip the emoji off the front when present so the rest is
            // unambiguously [label, message-fragments...].
            if (count($parts) >= 2 && self::looks_like_emoji($parts[0])) {
                $emoji = array_shift($parts);
            }

            $label   = isset($parts[0]) ? $parts[0] : '';
            $message = count($parts) >= 2
                ? trim(implode(' | ', array_slice($parts, 1)))
                : '';

            if ($label === '' && $message === '') continue;
            if ($message === '') $message = $label;
            if ($label === '')   $label   = $message;

            $clean[] = array(
                'emoji'   => sanitize_text_field($emoji),
                'label'   => sanitize_text_field($label),
                'message' => sanitize_text_field($message),
            );
        }
        return $clean;
    }

    /**
     * Heuristic: "📦" or "🏪💎" → true, "Office" → false. We rely on the
     * absence of ASCII letters/digits rather than trying to enumerate
     * Unicode emoji ranges (which is a moving target).
     */
    private static function looks_like_emoji($s) {
        $s = trim((string) $s);
        if ($s === '' || strlen($s) > 16) return false;
        return !preg_match('/[A-Za-z0-9]/', $s);
    }

    /**
     * Render a chips array back to textarea format, choosing the most
     * compact representation that the parser can round-trip exactly.
     */
    public static function chips_to_textarea($chips) {
        if (!is_array($chips)) return '';
        $lines = array();
        foreach ($chips as $chip) {
            if (!is_array($chip)) continue;
            $emoji   = trim((string) ($chip['emoji']   ?? ''));
            $label   = trim((string) ($chip['label']   ?? ''));
            $message = trim((string) ($chip['message'] ?? ''));
            if ($label === '' && $message === '') continue;
            if ($emoji === '' && $label === $message) {
                $lines[] = $label;
            } elseif ($emoji === '') {
                $lines[] = $label . ' | ' . $message;
            } elseif ($label === $message) {
                $lines[] = $emoji . ' | ' . $label;
            } else {
                $lines[] = $emoji . ' | ' . $label . ' | ' . $message;
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Map a tab key to its WP settings group. Used by the Settings template
     * so each tab's <form> only saves its own options.
     */
    public static function settings_group_for_tab($tab) {
        $map = array(
            'connection' => 'zen_cortext_connection',
            'voice'      => 'zen_cortext_voice',
            'sessions'   => 'zen_cortext_sessions',
        );
        return isset($map[$tab]) ? $map[$tab] : 'zen_cortext_connection';
    }

    public function sanitize_invitable_users($value) {
        if (!is_array($value)) return array();
        return array_values(array_unique(array_filter(array_map('absint', $value))));
    }

    public function sanitize_team_expertise($value) {
        if (!is_array($value)) return array();
        $clean = array();
        foreach ($value as $uid => $text) {
            $uid = (int) $uid;
            if ($uid > 0) {
                $clean[$uid] = wp_unslash(sanitize_textarea_field((string) $text));
            }
        }
        return $clean;
    }

    private static function get_invitable_users_for_js() {
        $user_ids = get_option('zen_cortext_invitable_users', array());
        if (!is_array($user_ids) || empty($user_ids)) return array();
        $result = array();
        foreach ($user_ids as $uid) {
            $u = get_userdata((int) $uid);
            if ($u) {
                $result[] = array('id' => (int) $u->ID, 'display_name' => $u->display_name);
            }
        }
        return $result;
    }

    public function sanitize_api_key($value) {
        $value = trim((string) $value);
        // Allow only printable ASCII (Anthropic keys are sk-ant-...).
        return preg_replace('/[^\x20-\x7E]/', '', $value);
    }

    /**
     * Sanitize an AI prompt / template field. These carry custom
     * angle-bracket template tokens the model relies on (e.g.
     * <active_interview_script> in the survey template, <<categories>> in
     * the classify prompt) and are sent to the AI as JSON-encoded text —
     * they are never echoed as HTML (the admin form renders them through
     * esc_textarea()). So we sanitize WITHOUT the tag-stripping that
     * sanitize_textarea_field() would apply (which would corrupt the
     * tokens): unslash, drop invalid UTF-8, and strip control characters
     * (keeping tab / newline / carriage return).
     */
    public function sanitize_textarea($value) {
        $value = wp_unslash((string) $value);
        $value = wp_check_invalid_utf8($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        return (string) $value;
    }

    /**
     * Clamp max_tokens to the form's declared bounds [64, 8192]. Anything
     * out of range — including 0 from a blanked field — falls back to the
     * documented default of 2048 so a bad save can't silently break chat.
     */
    public function sanitize_max_tokens($value) {
        $n = (int) $value;
        if ($n < 64 || $n > 8192) return 2048;
        return $n;
    }

    public function sanitize_post_types($value) {
        if (!is_array($value)) return array();
        $clean = array();
        foreach ($value as $pt) {
            $pt = sanitize_key($pt);
            if ($pt) $clean[] = $pt;
        }
        return array_values(array_unique($clean));
    }

    public function sanitize_intro_card($value) {
        if (!is_array($value)) return Zen_Cortext_Defaults::intro_card();
        return array(
            'name'     => sanitize_text_field($value['name'] ?? ''),
            'role'     => sanitize_text_field($value['role'] ?? ''),
            // The intro-card body is rendered as HTML in the chat (its
            // body_html is injected via innerHTML), so it must be filtered
            // to the safe post-content tag set on input.
            'body'     => wp_kses_post(wp_unslash((string)($value['body'] ?? ''))),
            'logo_url' => esc_url_raw($value['logo_url'] ?? ''),
            'site_url' => esc_url_raw($value['site_url'] ?? ''),
        );
    }

    public function enqueue($hook) {
        // Getting Started — onboarding guide. Pure HTML with inline
        // styles; just needs the shared admin.css for WP-styled buttons
        // and matching neutral palette. No JS (uses native <details>).
        if (!empty($this->init_hook) && $hook === $this->init_hook) {
            wp_enqueue_style('zen-cortext-admin', ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/admin.css', array(), ZEN_CORTEXT_VERSION);
            wp_enqueue_style('zen-cortext-getting-started', ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/getting-started.css', array('zen-cortext-admin'), ZEN_CORTEXT_VERSION);
            return;
        }

        // Brainstorm page has its own dedicated bundle — no jQuery dependency,
        // no shared state with the legacy admin pages.
        if (!empty($this->brainstorm_hook) && $hook === $this->brainstorm_hook) {
            wp_enqueue_style('zen-cortext-admin', ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/admin.css', array(), ZEN_CORTEXT_VERSION);
            wp_enqueue_script('zen-cortext-brainstorm', ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/brainstorm.js', array(), ZEN_CORTEXT_VERSION, true);
            wp_localize_script('zen-cortext-brainstorm', 'zenCortextBrainstorm', array(
                'restUrl'   => esc_url_raw(rest_url('zen-cortext/v1/admin-brainstorm')),
                'restNonce' => wp_create_nonce('wp_rest'),
            ));
            return;
        }

        // Settings page (top-level), Knowledge Artifacts, Saved Chats,
        // Attribution Context, and Ads Sync all share the same admin assets
        // — admin.js + attribution.js guard each section by checking for
        // its root element.
        $allowed = array('toplevel_page_zen-cortext');
        if (!empty($this->kb_hook))          $allowed[] = $this->kb_hook;
        if (!empty($this->chat_hook))        $allowed[] = $this->chat_hook;
        if (!empty($this->chats_hook))       $allowed[] = $this->chats_hook;
        if (!empty($this->attribution_hook)) $allowed[] = $this->attribution_hook;
        if (!empty($this->sessions_hook))    $allowed[] = $this->sessions_hook;
        if (!empty($this->ads_sync_hook))    $allowed[] = $this->ads_sync_hook;
        if (!empty($this->surveys_hook))     $allowed[] = $this->surveys_hook;
        if (!empty($this->webhooks_hook))    $allowed[] = $this->webhooks_hook;
        if (!empty($this->api_hook))         $allowed[] = $this->api_hook;
        if (!in_array($hook, $allowed, true)) return;
        wp_enqueue_style('zen-cortext-admin', ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/admin.css', array(), ZEN_CORTEXT_VERSION);
        wp_enqueue_script('zen-cortext-admin', ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/admin.js', array('jquery'), ZEN_CORTEXT_VERSION, true);
        wp_localize_script('zen-cortext-admin', 'zenCortextAdmin', array(
            'ajaxUrl'             => admin_url('admin-ajax.php'),
            'nonce'               => wp_create_nonce('zen_cortext_admin'),
            'artifactChatRestUrl' => rest_url('zen-cortext/v1/artifact-chat'),
            'restNonce'           => wp_create_nonce('wp_rest'),
            // Base admin URL for a single chat view — sessions.js appends
            // the chat id to build "View" links into the Saved Chats page.
            'chatBaseUrl'         => admin_url('admin.php?page=zen-cortext-chats&action=view&id='),
            // REST endpoint for the "Adapt system prompt" modal (chat-prompts.js).
            'adaptSystemPromptUrl'=> esc_url_raw(rest_url('zen-cortext/v1/admin/adapt-system-prompt')),
            // Localized for the artifacts editor JS. Unified with KB
            // content types — admin edits the list on the Knowledge Base
            // tab, the artifact dropdown reflects it on next page load.
            'artifactTypes'       => Zen_Cortext_KB_Types::labels(),
            'invitableUsers'      => self::get_invitable_users_for_js(),
        ));

        // Per-page CSS/JS that used to live in inline <style>/<script>
        // blocks inside the view files. All depend on the shared admin
        // bundle (admin.css for styling, zenCortextAdmin for ajaxUrl/nonce).

        // Saved Chats — list view vs single chat detail (same hook).
        if ($hook === $this->chats_hook) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check to pick which asset bundle to enqueue.
            $is_detail = isset($_GET['action']) && $_GET['action'] === 'view';
            if ($is_detail) {
                wp_enqueue_style('zen-cortext-chat-detail', ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/chat-detail.css', array('zen-cortext-admin'), ZEN_CORTEXT_VERSION);
                wp_enqueue_script('zen-cortext-chat-detail', ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/chat-detail.js', array('zen-cortext-admin'), ZEN_CORTEXT_VERSION, true);
            } else {
                wp_enqueue_style('zen-cortext-chats', ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/chats.css', array('zen-cortext-admin'), ZEN_CORTEXT_VERSION);
                wp_enqueue_script('zen-cortext-chats', ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/chats.js', array('zen-cortext-admin'), ZEN_CORTEXT_VERSION, true);
            }
        }

        // User Sessions list.
        if ($hook === $this->sessions_hook) {
            wp_enqueue_style('zen-cortext-sessions', ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/sessions.css', array('zen-cortext-admin'), ZEN_CORTEXT_VERSION);
            wp_enqueue_script('zen-cortext-sessions', ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/sessions.js', array('zen-cortext-admin'), ZEN_CORTEXT_VERSION, true);
        }

        // Chat editor settings page — rail + prompts tab behaviors.
        if ($hook === $this->chat_hook) {
            wp_enqueue_script('zen-cortext-chat-rail', ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/chat-rail.js', array('zen-cortext-admin'), ZEN_CORTEXT_VERSION, true);
            wp_enqueue_script('zen-cortext-chat-prompts', ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/chat-prompts.js', array('zen-cortext-admin'), ZEN_CORTEXT_VERSION, true);
        }

        // Attribution Context page styles.
        if ($hook === $this->attribution_hook) {
            wp_enqueue_style('zen-cortext-attribution', ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/attribution.css', array('zen-cortext-admin'), ZEN_CORTEXT_VERSION);
        }

        // Ads Sync page styles.
        if ($hook === $this->ads_sync_hook) {
            wp_enqueue_style('zen-cortext-ads-sync', ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/ads-sync.css', array('zen-cortext-admin'), ZEN_CORTEXT_VERSION);
        }

        // API page — Docs tab styles (the docs tab is otherwise JS-free).
        if ($hook === $this->api_hook) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab check to scope a stylesheet.
            $api_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'keys';
            if ($api_tab === 'docs') {
                wp_enqueue_style('zen-cortext-api-docs', ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/api-docs.css', array('zen-cortext-admin'), ZEN_CORTEXT_VERSION);
            }
        }


        // Dedicated bundle for Attribution Context + Ads Sync pages.
        // Loaded after admin.js so it can rely on jQuery + zenCortextAdmin.
        // Attribution editor also needs the surveys list to populate its
        // per-rule Survey dropdown — same handler powers both pages.
        if ($hook === $this->attribution_hook || $hook === $this->ads_sync_hook) {
            wp_enqueue_script(
                'zen-cortext-attribution',
                ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/attribution.js',
                array('jquery', 'zen-cortext-admin'),
                ZEN_CORTEXT_VERSION,
                true
            );
            // Hand the global intro card to attribution.js so the "Prefill
            // from global intro card" button on the rule editor can seed
            // the per-rule override fields without an extra AJAX round-trip.
            wp_localize_script(
                'zen-cortext-attribution',
                'zenCortextAttribution',
                array(
                    'globalIntroCard' => get_option(
                        'zen_cortext_intro_card',
                        Zen_Cortext_Defaults::intro_card()
                    ),
                )
            );
        }

        // Dedicated bundle for the Surveys page (list + editor).
        if ($hook === $this->surveys_hook) {
            wp_enqueue_style(
                'zen-cortext-surveys',
                ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/surveys.css',
                array('zen-cortext-admin'),
                ZEN_CORTEXT_VERSION
            );
            wp_enqueue_script(
                'zen-cortext-surveys',
                ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/surveys.js',
                array('jquery', 'zen-cortext-admin'),
                ZEN_CORTEXT_VERSION,
                true
            );
        }

        // Webhooks page bundle. Localizes the public event catalog
        // (label + description per event) so the editor can render
        // the subscription checkboxes from a single source of truth
        // owned by Zen_Cortext_Webhooks::event_catalog().
        if ($hook === $this->webhooks_hook) {
            wp_enqueue_script(
                'zen-cortext-webhooks',
                ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/webhooks.js',
                array('jquery', 'zen-cortext-admin'),
                ZEN_CORTEXT_VERSION,
                true
            );
            wp_localize_script(
                'zen-cortext-webhooks',
                'zenCortextWebhooks',
                array(
                    'events' => Zen_Cortext_Webhooks::event_catalog(),
                )
            );
        }

        // API page bundle. Loaded only on the Keys tab (the Docs tab
        // is JS-free and reading the docs shouldn't pull a CRUD bundle
        // it doesn't need). Ships the scope catalog so the create-key
        // form can render checkboxes from a single source of truth.
        if ($hook === $this->api_hook) {
            $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'keys';
            if ($tab !== 'docs') {
                wp_enqueue_script(
                    'zen-cortext-api-keys',
                    ZEN_CORTEXT_PLUGIN_URL . 'admin/assets/api-keys.js',
                    array('jquery', 'zen-cortext-admin'),
                    ZEN_CORTEXT_VERSION,
                    true
                );
                wp_localize_script(
                    'zen-cortext-api-keys',
                    'zenCortextApiKeys',
                    array(
                        'scopes'   => Zen_Cortext_Api_Keys::scope_catalog(),
                        'apiBase'  => esc_url_raw(rest_url('zc/v1')),
                    )
                );
            }
        }
    }

    /**
     * Bounce legacy URLs to their new homes:
     *  - `?page=zen-cortext&tab=kb`   → ?page=zen-cortext-kb
     *  - `?page=zen-cortext&tab=chat` → ?page=zen-cortext-chat
     *  - `?page=zen-cortext-artifacts` → ?page=zen-cortext-kb&tab=artifacts
     *
     * Hooks `init` (not `admin_init`) so this fires before
     * wp-admin/includes/menu.php's user_can_access_admin_page() check
     * which 403s on unknown page slugs. For tab redirects on known slugs
     * the timing matters less but we keep them on the same hook for
     * uniformity.
     */
    public function redirect_legacy_kb_tab() {
        if (!is_admin()) return;
        if (!isset($_GET['page'])) return;
        if (!current_user_can('manage_options')) return;

        // Former: Settings → KB tab.
        if ($_GET['page'] === 'zen-cortext' && isset($_GET['tab']) && $_GET['tab'] === 'kb') {
            wp_safe_redirect(admin_url('admin.php?page=zen-cortext-kb'));
            exit;
        }

        // Former: Settings → Chat tab.
        if ($_GET['page'] === 'zen-cortext' && isset($_GET['tab']) && $_GET['tab'] === 'chat') {
            wp_safe_redirect(admin_url('admin.php?page=zen-cortext-chat'));
            exit;
        }

        // Former: standalone Knowledge Artifacts submenu.
        if ($_GET['page'] === 'zen-cortext-artifacts') {
            wp_safe_redirect(admin_url('admin.php?page=zen-cortext-kb&tab=artifacts'));
            exit;
        }

        // Former: standalone Design submenu (now a Settings tab).
        if ($_GET['page'] === 'zen-cortext-design') {
            wp_safe_redirect(admin_url('admin.php?page=zen-cortext&tab=design'));
            exit;
        }
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'zen-cortext'));
        }

        // Unknown tabs (e.g. bookmarked legacy `?tab=help` from before the
        // Help tab was removed) fall back to Connection so the admin
        // lands somewhere coherent instead of an empty page.
        $allowed = array('connection', 'voice', 'sessions', 'pages', 'design');
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'connection';
        if (!in_array($tab, $allowed, true)) $tab = 'connection';
        include ZEN_CORTEXT_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    /**
     * Getting Started — onboarding guide rendered as collapsible
     * `<details>` cards, one per setup step. Pending steps start
     * expanded with a ⚠ marker; completed steps collapse to a one-line
     * ✓ summary so a returning admin sees their progress at a glance.
     *
     * The view ../admin/views/_getting-started.php pulls all of its
     * "is step done?" signals from Zen_Cortext_Setup_State::summary()
     * so the queries live in one place.
     */
    public function render_initialization_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'zen-cortext'));
        }
        if (!class_exists('Zen_Cortext_Setup_State')) {
            require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-setup-state.php';
        }
        include ZEN_CORTEXT_PLUGIN_DIR . 'admin/views/_getting-started.php';
    }

    public function render_kb_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'zen-cortext'));
        }
        // Two-tab page: kb (default) and artifacts. Unknown values fall
        // back to kb. The redirect handler in admin_init sends the
        // legacy ?page=zen-cortext-artifacts URL here with ?tab=artifacts.
        $tab = isset($_GET['tab']) && $_GET['tab'] === 'artifacts' ? 'artifacts' : 'kb';
        $stats = Zen_Cortext_KB::stats();
        include ZEN_CORTEXT_PLUGIN_DIR . 'admin/views/kb-page.php';
    }

    public function render_chat_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'zen-cortext'));
        }
        // Three-tab page: basic (default) | rail | prompts. Unknown
        // values fall back to basic so a bookmarked legacy URL lands
        // on a sensible default instead of an empty page.
        $allowed = array('basic', 'rail', 'prompts');
        $req_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        $tab = in_array($req_tab, $allowed, true) ? $req_tab : 'basic';
        include ZEN_CORTEXT_PLUGIN_DIR . 'admin/views/chat-page.php';
    }

    public function render_brainstorm_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'zen-cortext'));
        }
        include ZEN_CORTEXT_PLUGIN_DIR . 'admin/views/brainstorm-page.php';
    }

    public function render_attribution_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'zen-cortext'));
        }
        include ZEN_CORTEXT_PLUGIN_DIR . 'admin/views/attribution-page.php';
    }

    public function render_sessions_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'zen-cortext'));
        }
        $page          = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $search        = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        // Default to enriched-only because non-enriched (direct/no-UTM)
        // sessions are the noisy long tail. Admins can flip the checkbox
        // off to inspect the full firehose.
        $enriched_only = !isset($_GET['enriched']) || $_GET['enriched'] === '1';
        $has_chats     = isset($_GET['has_chats']) && $_GET['has_chats'] === '1';
        $rule_id       = isset($_GET['rule_id']) ? (int) $_GET['rule_id'] : 0;

        $result = Zen_Cortext_Sessions::paged(array(
            'page'          => $page,
            'per_page'      => 25,
            'search'        => $search,
            'enriched_only' => $enriched_only,
            'has_chats'     => $has_chats,
            'rule_id'       => $rule_id,
        ));
        $stats = Zen_Cortext_Sessions::stats();
        $rules = class_exists('Zen_Cortext_Attribution') ? Zen_Cortext_Attribution::list_all() : array();
        include ZEN_CORTEXT_PLUGIN_DIR . 'admin/views/sessions-page.php';
    }

    public function render_surveys_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'zen-cortext'));
        }
        include ZEN_CORTEXT_PLUGIN_DIR . 'admin/views/surveys-page.php';
    }

    public function render_webhooks_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'zen-cortext'));
        }
        include ZEN_CORTEXT_PLUGIN_DIR . 'admin/views/webhooks-page.php';
    }

    /**
     * Unified API submenu. Dispatches on ?tab=keys|docs (default keys).
     * The wrapper view emits the wrap + h1 + nav-tab-wrapper and includes
     * the active tab's sub-view, so each sub-view holds only its tab
     * content (no outer chrome).
     */
    public function render_api_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'zen-cortext'));
        }
        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'keys';
        if (!in_array($tab, array('keys', 'docs'), true)) $tab = 'keys';
        include ZEN_CORTEXT_PLUGIN_DIR . 'admin/views/api-page.php';
    }

    public function render_ads_sync_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'zen-cortext'));
        }
        $key_info  = Zen_Cortext_ApiKey_Auth::info();
        $sync_ts   = Zen_Cortext_Ads_Campaigns::last_sync_timestamp();
        $sync_count = Zen_Cortext_Ads_Campaigns::count_all();
        include ZEN_CORTEXT_PLUGIN_DIR . 'admin/views/ads-sync-page.php';
    }

    public function render_chats_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'zen-cortext'));
        }
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
        if ($action === 'view' && isset($_GET['id'])) {
            $chat = Zen_Cortext_Chats::get((int) $_GET['id']);
            if (!$chat) {
                echo '<div class="wrap"><h1>' . esc_html__('Saved Chat', 'zen-cortext') . '</h1><p>' . esc_html__('Not found.', 'zen-cortext') . '</p></div>';
                return;
            }
            // Resolve the WordPress admin who took over the chat (if any)
            // and the parent session row stamped on the chat. Both null when
            // the chat predates the takeover/sessions features.
            $admin_user = null;
            if (!empty($chat['admin_user_id'])) {
                $u = get_userdata((int) $chat['admin_user_id']);
                if ($u) {
                    $admin_user = array(
                        'id'           => (int) $u->ID,
                        'display_name' => (string) $u->display_name,
                        'user_email'   => (string) $u->user_email,
                        'user_login'   => (string) $u->user_login,
                    );
                }
            }
            $session         = null;
            $related_sessions = array();
            if (class_exists('Zen_Cortext_Sessions') && !empty($chat['session_uid'])) {
                $session = Zen_Cortext_Sessions::get_by_uid((string) $chat['session_uid']);
            }
            // Other visits from the same browser (matched by ip_hash, the
            // only persistent visitor identifier we keep). Always queried —
            // even if the current chat has no session, the ip_hash on the
            // chat row itself can surface returning-visitor activity.
            if (class_exists('Zen_Cortext_Sessions') && !empty($chat['ip_hash'])) {
                $exclude = !empty($chat['session_uid']) ? (string) $chat['session_uid'] : '';
                $related_sessions = Zen_Cortext_Sessions::for_ip_hash((string) $chat['ip_hash'], $exclude, 20);
            }
            include ZEN_CORTEXT_PLUGIN_DIR . 'admin/views/chat-detail-page.php';
            return;
        }
        $page         = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $search       = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $has_utm      = isset($_GET['has_utm']) && $_GET['has_utm'] === '1';
        $hide_deleted = isset($_GET['hide_deleted']) && $_GET['hide_deleted'] === '1';
        $result  = Zen_Cortext_Chats::paged(array(
            'page'         => $page,
            'per_page'     => 25,
            'search'       => $search,
            'has_utm'      => $has_utm,
            'hide_deleted' => $hide_deleted,
        ));
        $stats = Zen_Cortext_Chats::stats();
        include ZEN_CORTEXT_PLUGIN_DIR . 'admin/views/chats-page.php';
    }

    public function ajax_chat_delete() {
        $this->check_request();
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $result = Zen_Cortext_Chats::delete($id);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        wp_send_json_success();
    }

    public function ajax_chat_restore() {
        $this->check_request();
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $result = Zen_Cortext_Chats::restore($id);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        wp_send_json_success();
    }

    /* ---------------- AJAX handlers ---------------- */

    private function check_request() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'), 403);
        }
        check_ajax_referer('zen_cortext_admin', 'nonce');
    }

    public function ajax_test_connection() {
        $this->check_request();
        // Accept the API key currently typed on the Settings page so the user
        // can validate it BEFORE saving. (A companion CLI plugin handles its
        // own backend test via the zen_cortext_test_connection filter, and
        // may read additional $_POST overrides it forwards itself.)
        $overrides = array();
        if (isset($_POST['api_key'])) $overrides['api_key'] = sanitize_text_field(wp_unslash((string) $_POST['api_key']));
        $result = Zen_Cortext_API::test_connection($overrides);
        wp_send_json($result);
    }

    public function ajax_sync() {
        $this->check_request();
        $counts = Zen_Cortext_Extractor::sync_all();
        Zen_Cortext_KB::flush_empty_to_other();
        Zen_Cortext_Extractor::bust_badge_cache();
        wp_send_json_success(array(
            'counts' => $counts,
            'stats'  => Zen_Cortext_KB::stats(),
        ));
    }

    public function ajax_classify_next() {
        $this->check_request();
        $row = Zen_Cortext_KB::next_unclassified();
        if (!$row) {
            wp_send_json_success(array('done' => true, 'stats' => Zen_Cortext_KB::stats()));
        }

        $result = Zen_Cortext_API::classify($row->title, $row->content);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        // Pass the row's updated_at as a concurrency token. If a
        // wp_after_insert_post hook re-upserted this row between our
        // fetch and write (because the post was edited mid-Rebuild),
        // the setter skips the write and the row stays NULL — picked
        // up again on the next iteration against the fresh content.
        $written = Zen_Cortext_KB::set_classification($row->id, $result, $row->updated_at);
        Zen_Cortext_KB::flush_cache();
        Zen_Cortext_Extractor::bust_badge_cache();

        wp_send_json_success(array(
            'done'           => false,
            'last_id'        => (int) $row->id,
            'last_title'     => $row->title,
            'last_category'  => $result,
            'stale_skipped'  => !$written,
            'stats'          => Zen_Cortext_KB::stats(),
        ));
    }

    public function ajax_restructure_next() {
        $this->check_request();
        $row = Zen_Cortext_KB::next_unstructured();
        if (!$row) {
            wp_send_json_success(array('done' => true, 'stats' => Zen_Cortext_KB::stats()));
        }

        $result = Zen_Cortext_API::restructure($row->title, $row->content, $row->classification);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        $written = Zen_Cortext_KB::set_structured($row->id, $result, $row->updated_at);
        Zen_Cortext_KB::flush_cache();
        Zen_Cortext_Extractor::bust_badge_cache();

        wp_send_json_success(array(
            'done'          => false,
            'last_id'       => (int) $row->id,
            'last_title'    => $row->title,
            'chars'         => strlen($result),
            'stale_skipped' => !$written,
            'stats'         => Zen_Cortext_KB::stats(),
        ));
    }

    public function ajax_clear() {
        $this->check_request();
        Zen_Cortext_KB::clear();
        Zen_Cortext_Extractor::bust_badge_cache();
        wp_send_json_success(array('stats' => Zen_Cortext_KB::stats()));
    }

    /**
     * Cached pending-rows count for the menu badge. Cheap query but
     * runs on every admin pageload via add_menu(), so cache it.
     */
    public static function kb_pending_count() {
        $cached = get_transient('zen_cortext_kb_pending');
        if ($cached !== false) return (int) $cached;
        $stats = Zen_Cortext_KB::stats();
        $pending = (int) $stats['needs_classify'] + (int) $stats['needs_structure'];
        set_transient('zen_cortext_kb_pending', $pending, 60);
        return $pending;
    }

    /**
     * AJAX: save the full content types list. Posted as a JSON-encoded
     * array of {slug,label,description,restructure_prompt} objects.
     */
    public function ajax_types_save() {
        $this->check_request();
        // $_POST['types'] is a JSON string: json_decoded just below and every
        // field validated + sanitized in Zen_Cortext_KB_Types::save()
        // (sanitize_key on slugs, sanitize_text_field on labels,
        // sanitize_textarea_field on descriptions). Never echoed raw.
        $raw = isset($_POST['types']) ? wp_unslash((string) $_POST['types']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload, decoded + per-field sanitized in KB_Types::save() (see note above).
        $types = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($types)) {
            wp_send_json_error(array('message' => __('Invalid types payload.', 'zen-cortext')));
        }
        // json_decode() does not sanitize. Zen_Cortext_KB_Types::save() is the
        // sanitization + validation layer: it runs sanitize_key() on slugs,
        // sanitize_text_field()/sanitize_textarea_field() on label/description,
        // and rejects malformed rows — so every decoded field is cleaned there.
        $result = Zen_Cortext_KB_Types::save($types);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        Zen_Cortext_Extractor::bust_badge_cache();
        wp_send_json_success(array(
            'types' => Zen_Cortext_KB_Types::all(),
        ));
    }

    /**
     * AJAX: delete a content type. First call returns kb_rows_affected
     * count for confirmation; second call with force=1 actually deletes
     * (resetting affected rows to NULL classification + structured).
     */
    public function ajax_types_delete() {
        $this->check_request();
        $slug  = isset($_POST['slug'])  ? sanitize_key(wp_unslash($_POST['slug'])) : '';
        $force = isset($_POST['force']) && $_POST['force'] === '1';
        $result = Zen_Cortext_KB_Types::delete($slug, $force);
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $payload = array('message' => $result->get_error_message(), 'code' => $result->get_error_code());
            if (is_array($data) && isset($data['kb_rows_affected'])) {
                $payload['kb_rows_affected'] = (int) $data['kb_rows_affected'];
            }
            wp_send_json_error($payload);
        }
        Zen_Cortext_Extractor::bust_badge_cache();
        wp_send_json_success(array(
            'kb_rows_affected' => (int) $result['kb_rows_affected'],
            'types'            => Zen_Cortext_KB_Types::all(),
        ));
    }

    public function ajax_stats() {
        $this->check_request();
        wp_send_json_success(array('stats' => Zen_Cortext_KB::stats()));
    }

    /* ---------------- Knowledge Artifacts ---------------- */

    public function ajax_artifact_list() {
        $this->check_request();
        $type = isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : '';
        $filters = array();
        if ($type !== '' && Zen_Cortext_Artifacts::valid_type($type)) {
            $filters['type'] = $type;
        }
        wp_send_json_success(array(
            'rows'  => Zen_Cortext_Artifacts::all($filters),
            'stats' => Zen_Cortext_Artifacts::stats(),
        ));
    }

    public function ajax_artifact_get() {
        $this->check_request();
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $row = Zen_Cortext_Artifacts::get($id);
        if (!$row) {
            wp_send_json_error(array('message' => 'Artifact not found.'));
        }
        wp_send_json_success(array('row' => $row));
    }

    public function ajax_artifact_save() {
        $this->check_request();

        $id        = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $title     = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $type      = isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : '';
        // Artifact source is admin-authored long-form text (often technical,
        // may contain literal <> in code/config snippets) that is fed to the
        // AI restructurer and re-escaped on output. sanitize_textarea()
        // validates UTF-8 + strips control chars without lossy tag-stripping.
        $raw       = isset($_POST['raw_content']) ? $this->sanitize_textarea($_POST['raw_content']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitize_textarea() unslashes + validates UTF-8 + strips control chars while preserving angle-bracket prompt tokens the AI restructurer needs.
        $source    = isset($_POST['source']) ? sanitize_key(wp_unslash($_POST['source'])) : 'manual';
        $author_id = isset($_POST['author_id']) && $_POST['author_id'] !== '' ? absint($_POST['author_id']) : null;
        // Defaults to TRUE for back-compat with anything still hitting the
        // endpoint without the flag. The two-button UI sends it explicitly:
        //   "Save"                → restructure=0  (metadata-only edit)
        //   "Save and Restructure" → restructure=1
        $restructure = !isset($_POST['restructure']) || $_POST['restructure'] !== '0';

        if ($title === '') {
            wp_send_json_error(array('message' => 'Title is required.'));
        }
        if (!Zen_Cortext_Artifacts::valid_type($type)) {
            wp_send_json_error(array('message' => 'Invalid artifact type.'));
        }
        if (trim($raw) === '') {
            wp_send_json_error(array('message' => 'Body cannot be empty.'));
        }

        // Run the AI restructure first so we don't write a half-saved row on failure.
        $structured = null;
        if ($restructure) {
            $structured = Zen_Cortext_API::restructure_artifact($title, $type, $raw);
            if (is_wp_error($structured)) {
                wp_send_json_error(array('message' => 'Restructure failed: ' . $structured->get_error_message()));
            }
        }

        if ($id > 0) {
            $existing = Zen_Cortext_Artifacts::get($id);
            if (!$existing) {
                wp_send_json_error(array('message' => 'Artifact not found.'));
            }
            $data = array(
                'title'       => $title,
                'type'        => $type,
                'raw_content' => $raw,
                'source'      => $source,
                'author_id'   => $author_id,
            );
            // Only overwrite structured when restructure was requested.
            // Metadata-only saves leave the existing structured intact —
            // the chat context keeps using the prior restructured output
            // until a future Save and Restructure regenerates it.
            if ($restructure) {
                $data['structured'] = $structured;
            }
            $result = Zen_Cortext_Artifacts::update($id, $data);
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
            }
        } else {
            $new_id = Zen_Cortext_Artifacts::create(array(
                'title'       => $title,
                'type'        => $type,
                'raw_content' => $raw,
                'source'      => $source,
                'author_id'   => $author_id,
            ));
            if (is_wp_error($new_id)) {
                wp_send_json_error(array('message' => $new_id->get_error_message()));
            }
            $id = (int) $new_id;
            // New rows: persist the structured output we already generated
            // when restructure was requested. Without restructure, the row
            // exists with NULL structured — it won't show in the visitor
            // chat context until the admin runs Save and Restructure later.
            if ($restructure) {
                Zen_Cortext_Artifacts::set_structured($id, $structured);
            }
        }

        wp_send_json_success(array(
            'row'   => Zen_Cortext_Artifacts::get($id),
            'stats' => Zen_Cortext_Artifacts::stats(),
        ));
    }

    public function ajax_artifact_delete() {
        $this->check_request();
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $result = Zen_Cortext_Artifacts::delete($id);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        wp_send_json_success(array('stats' => Zen_Cortext_Artifacts::stats()));
    }

    public function ajax_artifact_search() {
        $this->check_request();
        $q          = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        $exclude_id = isset($_POST['exclude_id']) ? (int) $_POST['exclude_id'] : 0;
        $rows = Zen_Cortext_Artifacts::search($q, $exclude_id, 20);
        wp_send_json_success(array('rows' => $rows));
    }

    public function ajax_artifact_synthesize_from_chat() {
        $this->check_request();

        $messages_raw  = isset($_POST['messages']) ? wp_unslash((string) $_POST['messages']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON; decoded below and each message sanitized (sanitize_key role, sanitize_textarea_field content) before use.
        $type          = isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : '';
        $title         = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $exclude_id    = isset($_POST['exclude_id']) ? (int) $_POST['exclude_id'] : 0;
        $reference_ids = isset($_POST['reference_artifacts']) && is_array($_POST['reference_artifacts'])
            ? array_values(array_filter(array_map('intval', wp_unslash($_POST['reference_artifacts']))))
            : array();

        if (!Zen_Cortext_Artifacts::valid_type($type)) {
            wp_send_json_error(array('message' => 'Invalid artifact type.'));
        }
        $messages = json_decode($messages_raw, true);
        if (!is_array($messages) || empty($messages)) {
            wp_send_json_error(array('message' => 'No conversation to synthesize. Have a chat first.'));
        }
        // json_decode does not sanitize — clean each decoded message before
        // it's used (role → enum-ish key, content → UTF-8/control-stripped).
        $messages = array_values(array_filter(array_map(function ($m) {
            if (!is_array($m)) return null;
            return array(
                'role'    => isset($m['role'])    ? sanitize_key((string) $m['role'])              : '',
                // Already unslashed before json_decode, so don't unslash again.
                'content' => isset($m['content']) ? sanitize_textarea_field((string) $m['content']) : '',
            );
        }, $messages)));
        if (empty($messages)) {
            wp_send_json_error(array('message' => 'No conversation to synthesize. Have a chat first.'));
        }

        $draft = Zen_Cortext_API::synthesize_artifact_from_chat($messages, $type, $title, $reference_ids, $exclude_id);
        if (is_wp_error($draft)) {
            wp_send_json_error(array('message' => 'Synthesis failed: ' . $draft->get_error_message()));
        }

        wp_send_json_success(array('draft' => $draft));
    }

    /* ---------------- Attribution Context ---------------- */

    public function ajax_attribution_list() {
        $this->check_request();
        wp_send_json_success(array(
            'rows' => Zen_Cortext_Attribution::list_all(),
        ));
    }

    public function ajax_attribution_get() {
        $this->check_request();
        $id  = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $row = Zen_Cortext_Attribution::get($id);
        if (!$row) {
            wp_send_json_error(array('message' => 'Row not found.'));
        }
        // Decode chips and the optional intro-card override to structured
        // arrays for the editor. intro_card stays null when no override
        // is stored so the editor knows to leave the override checkbox off.
        $row['chips']      = Zen_Cortext_Attribution::decode_chips((string) ($row['chips_json'] ?? ''));
        $row['intro_card'] = Zen_Cortext_Attribution::decode_intro_card((string) ($row['intro_card_json'] ?? ''));
        wp_send_json_success(array('row' => $row));
    }

    public function ajax_attribution_save() {
        $this->check_request();

        $id   = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $data = array(
            'label'               => isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : '',
            'match_utm_source'    => isset($_POST['match_utm_source'])    ? sanitize_text_field(wp_unslash($_POST['match_utm_source']))    : '',
            'match_utm_medium'    => isset($_POST['match_utm_medium'])    ? sanitize_text_field(wp_unslash($_POST['match_utm_medium']))    : '',
            'match_utm_campaign'  => isset($_POST['match_utm_campaign'])  ? sanitize_text_field(wp_unslash($_POST['match_utm_campaign']))  : '',
            'match_referrer_host' => isset($_POST['match_referrer_host']) ? sanitize_text_field(wp_unslash($_POST['match_referrer_host'])) : '',
            'match_gclid_present' => !empty($_POST['match_gclid_present']) ? 1 : 0,
            'priority'            => isset($_POST['priority']) ? (int) $_POST['priority'] : 0,
            'enabled'             => !empty($_POST['enabled']) ? 1 : 0,
            'context_text'        => isset($_POST['context_text'])   ? sanitize_textarea_field(wp_unslash((string) $_POST['context_text']))   : '',
            'invite_message'      => isset($_POST['invite_message']) ? sanitize_textarea_field(wp_unslash((string) $_POST['invite_message'])) : '',
            // Chips arrive as a JSON string from the editor.
            'chips_json'          => isset($_POST['chips_json'])     ? wp_unslash((string) $_POST['chips_json'])     : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON; decoded + every field sanitize_text_field'd in Zen_Cortext_Attribution::normalize_chips_json().
            // Intro-card override: JSON string with the 5 fields, or '' when
            // the override checkbox in the editor is off. normalize_intro_card_json
            // collapses an all-blank-fields object back to '' so the rule
            // falls back to the global intro card payload.
            'intro_card_json'     => isset($_POST['intro_card_json']) ? wp_unslash((string) $_POST['intro_card_json']) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON; decoded + field-by-field sanitized (sanitize_text_field name/role, wp_kses_post body, esc_url_raw urls) in Zen_Cortext_Attribution::normalize_intro_card_json().
            'survey_id'           => isset($_POST['survey_id']) ? (int) $_POST['survey_id'] : 0,
        );

        $result = Zen_Cortext_Attribution::save($id, $data);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        $saved = Zen_Cortext_Attribution::get((int) $result);
        if ($saved) {
            $saved['chips']      = Zen_Cortext_Attribution::decode_chips((string) ($saved['chips_json'] ?? ''));
            $saved['intro_card'] = Zen_Cortext_Attribution::decode_intro_card((string) ($saved['intro_card_json'] ?? ''));
        }
        wp_send_json_success(array('row' => $saved));
    }

    public function ajax_attribution_delete() {
        $this->check_request();
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $result = Zen_Cortext_Attribution::delete($id);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        wp_send_json_success();
    }

    /* ---------------- User Sessions ---------------- */

    public function ajax_session_get() {
        $this->check_request();
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $row = Zen_Cortext_Sessions::get($id);
        if (!$row) {
            wp_send_json_error(array('message' => 'Session not found.'));
        }
        $chats = Zen_Cortext_Sessions::chats_for((string) $row['session_uid']);
        // Trim each chat row down to what the expand panel needs — full
        // messages payloads can be huge and the panel only renders the
        // first user message + meta.
        $chat_summaries = array();
        foreach ($chats as $c) {
            $first_user = '';
            $msgs = json_decode((string) ($c['messages'] ?? ''), true);
            if (is_array($msgs)) {
                foreach ($msgs as $m) {
                    if (isset($m['role'], $m['content']) && $m['role'] === 'user') {
                        $first_user = (string) $m['content'];
                        break;
                    }
                }
            }
            $chat_summaries[] = array(
                'id'                => (int) $c['id'],
                'chat_uid'          => (string) $c['chat_uid'],
                'message_count'     => (int) $c['message_count'],
                'lead_name'         => (string) $c['lead_name'],
                'lead_email'        => (string) $c['lead_email'],
                'lead_submitted_at' => (string) $c['lead_submitted_at'],
                'admin_user_id'     => (int) $c['admin_user_id'],
                'created_at'        => (string) $c['created_at'],
                'updated_at'        => (string) $c['updated_at'],
                'deleted_at'        => (string) $c['deleted_at'],
                'first_user_msg'    => $first_user,
            );
        }
        // Decode the pageview journey for display. Bounded by the
        // PAGEVIEWS_CAP on the writer so this stays small.
        $journey = array();
        if (!empty($row['pageviews_json'])) {
            $decoded = json_decode((string) $row['pageviews_json'], true);
            if (is_array($decoded)) $journey = $decoded;
        }
        unset($row['pageviews_json']);
        $row['journey'] = $journey;
        wp_send_json_success(array(
            'session' => $row,
            'chats'   => $chat_summaries,
        ));
    }

    public function ajax_session_delete() {
        $this->check_request();
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $result = Zen_Cortext_Sessions::delete($id);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        wp_send_json_success();
    }

    /* ---------------- Surveys ---------------- */

    public function ajax_surveys_list() {
        $this->check_request();
        $only_enabled = !empty($_POST['only_enabled']);
        wp_send_json_success(array(
            'rows' => Zen_Cortext_Surveys::all(array('only_enabled' => $only_enabled)),
        ));
    }

    public function ajax_surveys_get() {
        $this->check_request();
        $id  = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $row = Zen_Cortext_Surveys::get($id);
        if (!$row) {
            wp_send_json_error(array('message' => 'Survey not found.'));
        }
        $parsed = null;
        if (!empty($row['parsed_json'])) {
            $decoded = json_decode((string) $row['parsed_json'], true);
            if (is_array($decoded)) $parsed = $decoded;
        }
        wp_send_json_success(array(
            'row' => array(
                'id'                   => (int) $row['id'],
                'label'                => (string) $row['label'],
                'description'          => (string) $row['description'],
                'script'               => (string) $row['script'],
                'parsed'               => $parsed,
                'outcome_instructions' => (string) ($row['outcome_instructions'] ?? ''),
                'enabled'              => (int) $row['enabled'],
                'created_at'           => (string) $row['created_at'],
                'updated_at'           => (string) $row['updated_at'],
            ),
        ));
    }

    public function ajax_surveys_save() {
        $this->check_request();

        $id   = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $data = array(
            'label'                => isset($_POST['label'])                ? sanitize_text_field(wp_unslash($_POST['label']))      : '',
            'description'          => isset($_POST['description'])          ? sanitize_textarea_field(wp_unslash((string) $_POST['description']))          : '',
            // The interview script is parsed by Zen_Cortext_Survey_Parser and
            // fed to the AI; sanitize_textarea() validates UTF-8 + strips
            // control chars while preserving the script's structure markers.
            'script'               => isset($_POST['script'])               ? $this->sanitize_textarea($_POST['script'])                                  : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitize_textarea() unslashes + validates UTF-8 + strips control chars while preserving angle-bracket prompt tokens.
            'outcome_instructions' => isset($_POST['outcome_instructions']) ? sanitize_textarea_field(wp_unslash((string) $_POST['outcome_instructions'])) : '',
            'enabled'              => !empty($_POST['enabled']) ? 1 : 0,
        );

        if ($id > 0) {
            $result = Zen_Cortext_Surveys::update($id, $data);
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
            }
            $saved_id = $id;
        } else {
            $saved_id = Zen_Cortext_Surveys::create($data);
            if (is_wp_error($saved_id)) {
                wp_send_json_error(array('message' => $saved_id->get_error_message()));
            }
        }

        $row = Zen_Cortext_Surveys::get((int) $saved_id);
        $parsed = null;
        if ($row && !empty($row['parsed_json'])) {
            $decoded = json_decode((string) $row['parsed_json'], true);
            if (is_array($decoded)) $parsed = $decoded;
        }
        wp_send_json_success(array(
            'row' => array(
                'id'                   => (int) ($row['id'] ?? 0),
                'label'                => (string) ($row['label'] ?? ''),
                'description'          => (string) ($row['description'] ?? ''),
                'script'               => (string) ($row['script'] ?? ''),
                'parsed'               => $parsed,
                'outcome_instructions' => (string) ($row['outcome_instructions'] ?? ''),
                'enabled'              => (int) ($row['enabled'] ?? 0),
                'created_at'           => (string) ($row['created_at'] ?? ''),
                'updated_at'           => (string) ($row['updated_at'] ?? ''),
            ),
        ));
    }

    public function ajax_surveys_delete() {
        $this->check_request();
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

        // Defensive: detach this survey from any references before deleting.
        global $wpdb;
        $attr_table = $wpdb->prefix . 'zen_cortext_attribution_contexts';
        $wpdb->update($attr_table, array('survey_id' => null), array('survey_id' => $id));
        if ((int) get_option('zen_cortext_default_survey_id', 0) === (int) $id) {
            update_option('zen_cortext_default_survey_id', 0);
        }

        $result = Zen_Cortext_Surveys::delete($id);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        wp_send_json_success();
    }

    /* ---------------- Webhooks ---------------- */

    public function ajax_webhooks_list() {
        $this->check_request();
        wp_send_json_success(array(
            'rows'   => Zen_Cortext_Webhooks::list_all(),
            'events' => Zen_Cortext_Webhooks::event_catalog(),
        ));
    }

    public function ajax_webhooks_save() {
        $this->check_request();
        $id     = isset($_POST['id']) ? sanitize_text_field(wp_unslash((string) $_POST['id'])) : '';
        $events = isset($_POST['events']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['events'])) : array();
        $data = array(
            'label'   => isset($_POST['label'])   ? sanitize_text_field(wp_unslash((string) $_POST['label']))   : '',
            'url'     => isset($_POST['url'])     ? esc_url_raw(wp_unslash((string) $_POST['url']))             : '',
            'events'  => $events,
            'enabled' => !empty($_POST['enabled']) ? 1 : 0,
        );
        $result = Zen_Cortext_Webhooks::save($id, $data);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        wp_send_json_success(array('row' => $result));
    }

    public function ajax_webhooks_delete() {
        $this->check_request();
        $id = isset($_POST['id']) ? sanitize_text_field(wp_unslash((string) $_POST['id'])) : '';
        if ($id === '') wp_send_json_error(array('message' => 'Missing id'));
        Zen_Cortext_Webhooks::delete($id);
        wp_send_json_success();
    }

    public function ajax_webhooks_test() {
        $this->check_request();
        $id = isset($_POST['id']) ? sanitize_text_field(wp_unslash((string) $_POST['id'])) : '';
        if ($id === '') wp_send_json_error(array('message' => 'Missing id'));
        $result = Zen_Cortext_Webhooks::send_test($id);
        if (!empty($result['ok'])) {
            wp_send_json_success($result);
        }
        wp_send_json_error($result);
    }

    /* ---------------- API Keys (external read API) ---------------- */

    public function ajax_api_keys_list() {
        $this->check_request();
        wp_send_json_success(array(
            'rows'   => Zen_Cortext_Api_Keys::list_all(),
            'scopes' => Zen_Cortext_Api_Keys::scope_catalog(),
        ));
    }

    public function ajax_api_keys_create() {
        $this->check_request();
        $scopes = isset($_POST['scopes']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['scopes'])) : array();
        $label  = isset($_POST['label']) ? sanitize_text_field(wp_unslash((string) $_POST['label'])) : '';
        $rpm    = isset($_POST['rate_per_min'])  ? (int) $_POST['rate_per_min']  : 60;
        $rph    = isset($_POST['rate_per_hour']) ? (int) $_POST['rate_per_hour'] : 3000;

        $result = Zen_Cortext_Api_Keys::create($label, $scopes, $rpm, $rph, get_current_user_id());
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        // The raw token is returned EXACTLY once — the admin UI shows it
        // in a one-time panel and warns the user to copy it. Server never
        // stores or returns it again after this response.
        wp_send_json_success(array(
            'row'   => $result['row'],
            'token' => $result['token'],
        ));
    }

    public function ajax_api_keys_revoke() {
        $this->check_request();
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id <= 0) wp_send_json_error(array('message' => 'Missing id'));
        $row = Zen_Cortext_Api_Keys::revoke($id);
        wp_send_json_success(array('row' => $row));
    }

    /* ---------------- Apps Script key + ads sync ---------------- */

    /**
     * Returns the new raw key exactly once. Old key is invalidated by the
     * hash overwrite — there's no recovery path, by design.
     */
    public function ajax_apps_script_key_regenerate() {
        $this->check_request();
        $raw = Zen_Cortext_ApiKey_Auth::generate();
        wp_send_json_success(array(
            'key'  => $raw,
            'info' => Zen_Cortext_ApiKey_Auth::info(),
        ));
    }

    public function ajax_ads_campaigns_list() {
        $this->check_request();
        wp_send_json_success(array(
            'rows'        => Zen_Cortext_Ads_Campaigns::list_all(),
            'last_synced' => Zen_Cortext_Ads_Campaigns::last_sync_timestamp(),
            'count'       => Zen_Cortext_Ads_Campaigns::count_all(),
        ));
    }

    /**
     * Truncate the synced Google Ads campaigns table. Attribution rules
     * are untouched — they keep matching on raw UTMs; they just stop
     * showing the joined campaign metadata (headlines, keywords, budget)
     * until the next sync run repopulates the table.
     */
    public function ajax_ads_campaigns_clear() {
        $this->check_request();
        $deleted = Zen_Cortext_Ads_Campaigns::clear_all();
        wp_send_json_success(array(
            'deleted' => (int) $deleted,
        ));
    }

    public static function render_stats_inline($stats) {
        ob_start();
        ?>
        <table class="widefat striped" style="max-width:560px;">
            <tbody>
                <tr><th><?php esc_html_e('Total rows', 'zen-cortext'); ?></th><td><strong><?php echo (int) $stats['total']; ?></strong></td></tr>
                <tr><th><?php esc_html_e('Needs classify', 'zen-cortext'); ?></th><td><?php echo (int) $stats['needs_classify']; ?></td></tr>
                <tr><th><?php esc_html_e('Needs restructure', 'zen-cortext'); ?></th><td><?php echo (int) $stats['needs_structure']; ?></td></tr>
                <?php foreach ($stats['by_class'] as $key => $count): ?>
                    <tr><th><?php echo esc_html($key); ?></th><td><?php echo (int) $count; ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    }
}
