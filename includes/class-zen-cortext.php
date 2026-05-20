<?php
/**
 * Main Zen Cortext plugin class.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zen_Cortext {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies() {
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-defaults.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-kb.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-kb-types.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-author-bio.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-artifacts.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-chats.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-sessions.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-brainstorm-chats.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-attribution.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-survey-parser.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-surveys.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-ads-campaigns.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-apikey-auth.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-filter.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-chat-events.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-webhooks.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-takeover.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-livechat-auth.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-push.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-extractor.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-api.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-admin.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-template-renderer.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-shortcode.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-rest.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-chat-editor.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-design.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-api-keys.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-public-api.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-transcribe.php';
    }

    private function init_hooks() {
        add_action('init', array($this, 'init'));
        Zen_Cortext_Admin::get_instance();
        Zen_Cortext_Shortcode::get_instance();
        Zen_Cortext_Rest::get_instance();
        Zen_Cortext_Chat_Editor::get_instance();
        Zen_Cortext_Design::get_instance();
        Zen_Cortext_Public_API::get_instance();
        // Author bio (absorbed from the zen-author-bio mu-plugin).
        // Registers [zen_author_bio] / [zen_author_posts_heading]
        // shortcodes + the_content filter that styles inline "Author:"
        // lines on single posts.
        Zen_Cortext_Author_Bio::get_instance();
        self::register_sw_hooks();

        // Outbound webhook fan-out. Subscribes to the central event
        // log's fire-on-insert action so all configured endpoints
        // receive lead.captured / invite.sent / admin.joined / admin.left
        // / chat.started without each call site having to know about them.
        add_action('zen_cortext_chat_event', array('Zen_Cortext_Webhooks', 'on_chat_event'), 10, 5);

        // Session-started webhook fan-out. Fires once per new session
        // row minted by Zen_Cortext_Sessions::beacon(). Decoupled from
        // chat events because a visitor may arrive (and be attributed)
        // without ever opening chat — CRMs that care about every
        // attributed visit subscribe here.
        add_action('zen_cortext_session_started', array('Zen_Cortext_Webhooks', 'on_session_started'), 10, 4);

        // Daily cron: purge stale chat events (48h) to keep the table small.
        add_action('zen_cortext_cleanup_events', array(__CLASS__, 'run_event_cleanup'));
        if (!wp_next_scheduled('zen_cortext_cleanup_events')) {
            wp_schedule_event(time(), 'daily', 'zen_cortext_cleanup_events');
        }

        // KB ↔ WP content lifecycle. wp_after_insert_post covers publish,
        // update, and status transitions in one hook (it fires after every
        // wp_insert_post / wp_update_post call, including those triggered
        // by trash/untrash). before_delete_post handles the hard-delete
        // case. These hooks ONLY update the KB table — they never call
        // the LLM; admin clicks "Rebuild KB" to spend tokens on the new
        // rows. Without these, the KB drifts silently from WP content
        // until someone remembers to click Sync.
        add_action('wp_after_insert_post', array('Zen_Cortext_Extractor', 'on_post_changed'), 10, 3);
        add_action('before_delete_post',   array('Zen_Cortext_Extractor', 'on_post_deleted'));

        // Invalidate the Haiku classifier's cached assistant_context when
        // any source option changes. Build_assistant_context() also has a
        // 1h TTL fallback for hosts that bypass update_option (CLI scripts,
        // direct DB writes), but these hooks make admin edits feel instant.
        foreach (array(
            'zen_cortext_welcome_message',
            'zen_cortext_default_chips',
            'zen_cortext_system_prompt',
            'blogname',
            'blogdescription',
        ) as $opt) {
            add_action('update_option_' . $opt, array('Zen_Cortext_Filter', 'invalidate_assistant_context_cache'));
            add_action('add_option_'    . $opt, array('Zen_Cortext_Filter', 'invalidate_assistant_context_cache'));
        }
    }

    public static function run_event_cleanup() {
        global $wpdb;
        Zen_Cortext_Chat_Events::cleanup(48);
        // Clear stale invites (older than 1 hour with no admin attached).
        $wpdb->query(
            "UPDATE " . Zen_Cortext_Chats::table() . "
             SET invited_user_ids = ''
             WHERE invited_user_ids != ''
               AND admin_user_id IS NULL
               AND updated_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
    }

    public function init() {
        // Lazy schema upgrade if option lags behind constant.
        if (get_option('zen_cortext_db_version') !== ZEN_CORTEXT_DB_VERSION) {
            self::create_tables();
            update_option('zen_cortext_db_version', ZEN_CORTEXT_DB_VERSION);
        }

        // First-run / self-heal: copy any missing live template files from
        // their bundled factory siblings. This keeps the runtime functional
        // even if the live copy was deleted by hand.
        if (class_exists('Zen_Cortext_Template_Renderer')) {
            Zen_Cortext_Template_Renderer::ensure_seeded();
        }

        // Old one-time migrations removed in 2.34.7:
        //   - zen_cortext_chat_colors_migrated_v2_19_9 (renamed legacy
        //     --zc-lime / --zc-olive tokens to --zc-accent / --zc-primary)
        //   - zen_cortext_chip_rules_migrated_v2 (stripped inline chip-rules
        //     section from customized system prompts)
        // Both have shipped for many minor versions; the new uninstall.php
        // wildcard-sweeps the leftover `*_migrated_*` option keys so the
        // gating flags don't accumulate.

        // One-time font-family migration: pre-2.34.5 installs hardcoded
        // 'Yanone Kaffeesatz' in chat.css. New default is the WP-native
        // system stack, but existing sites that already render with
        // Yanone should keep it — sniff the writable chat.css for the
        // font name and preserve the user's font on upgrade.
        //
        // Fresh installs have no writable chat.css yet (it's seeded from
        // the bundled file on first request) OR it was just seeded with
        // the new system-stack value; either way the Yanone sniff fails
        // and the system-stack default sticks.
        if (get_option('zen_cortext_font_migrated_v2_34_5', '') !== 'done') {
            $existing = (string) get_option('zen_cortext_font_family', '');
            if ($existing === '') {
                $had_yanone = false;
                if (class_exists('Zen_Cortext_Template_Renderer')) {
                    $live_css = Zen_Cortext_Template_Renderer::writable_root() . 'assets/chat.css';
                    if (file_exists($live_css)) {
                        $contents = @file_get_contents($live_css);
                        if (is_string($contents) && stripos($contents, 'Yanone Kaffeesatz') !== false) {
                            $had_yanone = true;
                        }
                    }
                }
                if ($had_yanone) {
                    update_option('zen_cortext_font_family', "'Yanone Kaffeesatz', Arial, Helvetica, sans-serif");
                }
            }
            update_option('zen_cortext_font_migrated_v2_34_5', 'done', false);
        }

        // Rewrite rule to serve the livechat service worker from root scope.
        // Without this, the SW file under /wp-content/plugins/ can't control /zen-livechat/.
        add_rewrite_rule('^livechat-sw\.js$', 'index.php?zen_cortext_sw=1', 'top');
    }

    /**
     * Register the service worker query var and serve the file.
     */
    public static function register_sw_hooks() {
        add_filter('query_vars', function ($vars) {
            $vars[] = 'zen_cortext_sw';
            return $vars;
        });
        // Prevent WP canonical redirect from adding a trailing slash to /livechat-sw.js.
        add_filter('redirect_canonical', function ($redirect_url, $requested_url) {
            if (strpos($requested_url, 'livechat-sw.js') !== false) {
                return false;
            }
            return $redirect_url;
        }, 10, 2);
        add_action('template_redirect', function () {
            if (!get_query_var('zen_cortext_sw')) return;
            header('Content-Type: application/javascript');
            header('Service-Worker-Allowed: /');
            header('Cache-Control: no-cache, must-revalidate');
            readfile(ZEN_CORTEXT_PLUGIN_DIR . 'public/assets/livechat-sw.js');
            exit;
        });
    }

    /**
     * Activation: create table and seed default options.
     */
    public static function activate() {
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-defaults.php';
        require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext-kb-types.php';

        self::create_tables();

        // Seed defaults only if not already set.
        $defaults = Zen_Cortext_Defaults::all();
        foreach ($defaults as $key => $value) {
            if (get_option($key, null) === null) {
                add_option($key, $value);
            }
        }

        // Seed the data-driven content types list for fresh installs.
        if (get_option(Zen_Cortext_KB_Types::OPTION_KEY, null) === null) {
            add_option(Zen_Cortext_KB_Types::OPTION_KEY, Zen_Cortext_Defaults::content_types());
        }

        update_option('zen_cortext_db_version', ZEN_CORTEXT_DB_VERSION);
    }

    public static function deactivate() {
        Zen_Cortext_KB::flush_cache();
        // In-progress chat-editor drafts are short-lived working state —
        // there's no value in keeping them across a deactivation/upgrade
        // cycle, and they'd confuse the editor on next reload.
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_zce\\_preview\\_%' OR option_name LIKE '\\_transient\\_timeout\\_zce\\_preview\\_%'");
    }

    public static function create_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $kb_table = $wpdb->prefix . 'zen_cortext_kb';
        $kb_sql = "CREATE TABLE {$kb_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            post_type VARCHAR(32) NOT NULL,
            title TEXT NOT NULL,
            content LONGTEXT NOT NULL,
            classification VARCHAR(32) DEFAULT NULL,
            structured LONGTEXT DEFAULT NULL,
            classified_at DATETIME DEFAULT NULL,
            structured_at DATETIME DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY post_id (post_id),
            KEY classification (classification)
        ) {$charset_collate};";
        dbDelta($kb_sql);

        $artifacts_table = $wpdb->prefix . 'zen_cortext_artifacts';
        $artifacts_sql = "CREATE TABLE {$artifacts_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            type VARCHAR(32) NOT NULL,
            raw_content LONGTEXT NOT NULL,
            structured LONGTEXT DEFAULT NULL,
            source VARCHAR(16) NOT NULL,
            author_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY type (type),
            KEY author_id (author_id)
        ) {$charset_collate};";
        dbDelta($artifacts_sql);

        $chats_table = $wpdb->prefix . 'zen_cortext_chats';
        $chats_sql = "CREATE TABLE {$chats_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            chat_uid VARCHAR(64) NOT NULL,
            owner_token_hash VARCHAR(64) NOT NULL DEFAULT '',
            messages LONGTEXT NOT NULL,
            message_count INT UNSIGNED NOT NULL DEFAULT 0,
            referrer VARCHAR(2048) NOT NULL DEFAULT '',
            landing_page VARCHAR(2048) NOT NULL DEFAULT '',
            user_agent VARCHAR(1024) NOT NULL DEFAULT '',
            ip_hash VARCHAR(64) NOT NULL DEFAULT '',
            admin_user_id BIGINT UNSIGNED DEFAULT NULL,
            admin_attached_at DATETIME DEFAULT NULL,
            admin_detached_at DATETIME DEFAULT NULL,
            invited_user_ids TEXT NOT NULL DEFAULT '',
            utm_source VARCHAR(255) NOT NULL DEFAULT '',
            utm_medium VARCHAR(255) NOT NULL DEFAULT '',
            utm_campaign VARCHAR(255) NOT NULL DEFAULT '',
            utm_term VARCHAR(255) NOT NULL DEFAULT '',
            utm_content VARCHAR(255) NOT NULL DEFAULT '',
            gclid VARCHAR(255) NOT NULL DEFAULT '',
            msclkid VARCHAR(255) NOT NULL DEFAULT '',
            fbc VARCHAR(255) NOT NULL DEFAULT '',
            fbp VARCHAR(255) NOT NULL DEFAULT '',
            visitor_last_seen DATETIME DEFAULT NULL,
            lead_name VARCHAR(191) NOT NULL DEFAULT '',
            lead_email VARCHAR(191) NOT NULL DEFAULT '',
            lead_whatsapp VARCHAR(64) NOT NULL DEFAULT '',
            lead_submitted_at DATETIME DEFAULT NULL,
            session_uid VARCHAR(64) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY chat_uid (chat_uid),
            KEY updated_at (updated_at),
            KEY deleted_at (deleted_at),
            KEY admin_user_id (admin_user_id),
            KEY utm_source (utm_source),
            KEY gclid (gclid),
            KEY msclkid (msclkid),
            KEY lead_submitted_at (lead_submitted_at),
            KEY session_uid (session_uid)
        ) {$charset_collate};";
        dbDelta($chats_sql);

        // Visitor sessions — one row per browser visit, GA-style (new
        // session after 30-min inactivity OR attribution change). Attribution
        // columns mirror wp_zen_cortext_chats so the same UTM/click-id/
        // referrer fields are available in both layers. Chats are stamped
        // with session_uid at /send time (see Zen_Cortext_Sessions::attach_chat).
        $sessions_table = $wpdb->prefix . 'zen_cortext_sessions';
        $sessions_sql = "CREATE TABLE {$sessions_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_uid VARCHAR(64) NOT NULL,
            utm_source VARCHAR(255) NOT NULL DEFAULT '',
            utm_medium VARCHAR(255) NOT NULL DEFAULT '',
            utm_campaign VARCHAR(255) NOT NULL DEFAULT '',
            utm_term VARCHAR(255) NOT NULL DEFAULT '',
            utm_content VARCHAR(255) NOT NULL DEFAULT '',
            gclid VARCHAR(255) NOT NULL DEFAULT '',
            msclkid VARCHAR(255) NOT NULL DEFAULT '',
            fbc VARCHAR(255) NOT NULL DEFAULT '',
            fbp VARCHAR(255) NOT NULL DEFAULT '',
            referrer VARCHAR(2048) NOT NULL DEFAULT '',
            landing_page VARCHAR(2048) NOT NULL DEFAULT '',
            user_agent VARCHAR(1024) NOT NULL DEFAULT '',
            ip_hash VARCHAR(64) NOT NULL DEFAULT '',
            rule_id BIGINT UNSIGNED DEFAULT NULL,
            pageviews_json LONGTEXT NULL DEFAULT NULL,
            chat_count INT UNSIGNED NOT NULL DEFAULT 0,
            first_seen_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            enriched TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY session_uid (session_uid),
            KEY enriched_last_seen (enriched, last_seen_at),
            KEY utm_source (utm_source),
            KEY utm_campaign (utm_campaign),
            KEY gclid (gclid),
            KEY ip_hash (ip_hash),
            KEY rule_id (rule_id)
        ) {$charset_collate};";
        dbDelta($sessions_sql);

        $events_table = $wpdb->prefix . 'zen_cortext_chat_events';
        $events_sql = "CREATE TABLE {$events_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            chat_uid VARCHAR(64) NOT NULL,
            event_type VARCHAR(32) NOT NULL,
            payload LONGTEXT NOT NULL DEFAULT '',
            sender_type VARCHAR(16) NOT NULL DEFAULT '',
            sender_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY chat_uid_id (chat_uid, id),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($events_sql);

        $push_table = $wpdb->prefix . 'zen_cortext_push_subscriptions';
        $push_sql = "CREATE TABLE {$push_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            endpoint TEXT NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id)
        ) {$charset_collate};";
        dbDelta($push_sql);

        // Admin-owned Brainstorm sessions. Distinct from visitor chats:
        // owned by a single WordPress user, no attribution, hard delete only.
        $brainstorm_table = $wpdb->prefix . 'zen_cortext_brainstorm_chats';
        $brainstorm_sql = "CREATE TABLE {$brainstorm_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uid VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL DEFAULT '',
            messages LONGTEXT NOT NULL,
            message_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uid (uid),
            KEY user_id_updated (user_id, updated_at)
        ) {$charset_collate};";
        dbDelta($brainstorm_sql);

        // Manual attribution-context rules. Sync NEVER touches this table —
        // that's the structural guarantee behind "manual fields can't be
        // overwritten by Apps Script". Joined to ads_campaigns at lookup
        // time on match_utm_campaign ↔ campaign_name.
        $attr_table = $wpdb->prefix . 'zen_cortext_attribution_contexts';
        // match_utm_* + match_referrer_host all widened in schema v14 to
        // accept comma-separated lists. utm fields used to be 64/191; now
        // 255 across the board so an admin can list a handful of sources/
        // mediums/campaigns in one rule. match_referrer_host bumped to
        // 1024 because it now accepts URL/path patterns too (was bare host
        // only); a list of those plus internal-traffic patterns can run
        // long. Index `match_lookup` continues to cover the leading bytes
        // for fast filter-rules-by-campaign queries even with the wider
        // columns; the matcher does the substring/CSV work in PHP.
        $attr_sql = "CREATE TABLE {$attr_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            label VARCHAR(191) NOT NULL,
            match_utm_source VARCHAR(255) DEFAULT NULL,
            match_utm_medium VARCHAR(255) DEFAULT NULL,
            match_utm_campaign VARCHAR(255) DEFAULT NULL,
            match_referrer_host VARCHAR(1024) DEFAULT NULL,
            match_gclid_present TINYINT(1) NOT NULL DEFAULT 0,
            priority SMALLINT NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            context_text LONGTEXT NOT NULL,
            invite_message TEXT NOT NULL,
            chips_json TEXT NOT NULL,
            intro_card_json TEXT NULL DEFAULT NULL,
            survey_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY match_lookup (enabled, match_utm_campaign(64), match_utm_source(64), match_utm_medium(64)),
            KEY priority (priority),
            KEY survey_id (survey_id)
        ) {$charset_collate};";
        dbDelta($attr_sql);

        // Admin-defined survey/interview scripts. Attached either globally
        // via the zen_cortext_default_survey_id option or per-attribution-rule
        // via attribution_contexts.survey_id. Pure prompt-only — no per-chat
        // state column needed; the AI uses conversation context for tracking.
        $surveys_table = $wpdb->prefix . 'zen_cortext_surveys';
        $surveys_sql = "CREATE TABLE {$surveys_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            label VARCHAR(191) NOT NULL,
            description TEXT NOT NULL,
            script LONGTEXT NOT NULL,
            parsed_json LONGTEXT NOT NULL,
            outcome_instructions LONGTEXT NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY enabled (enabled)
        ) {$charset_collate};";
        dbDelta($surveys_sql);

        // Apps-Script-synced Google Ads campaign metadata. Wholesale-replace
        // per campaign_id. Joined to attribution_contexts at lookup time so
        // chat prompts can include live ad copy / keywords.
        $ads_table = $wpdb->prefix . 'zen_cortext_ads_campaigns';
        $ads_sql = "CREATE TABLE {$ads_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id VARCHAR(32) NOT NULL,
            campaign_name VARCHAR(191) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT '',
            budget_micros BIGINT DEFAULT NULL,
            top_headlines TEXT NOT NULL,
            top_keywords TEXT NOT NULL,
            synced_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY campaign_id (campaign_id),
            KEY campaign_name (campaign_name)
        ) {$charset_collate};";
        dbDelta($ads_sql);

        // Multi-key API authentication for the external read API (zc/v1
        // namespace). Each row is one labeled key with scoped read
        // permissions and per-key rate limits. SHA-256 hash only — the raw
        // token is shown to the admin exactly once at creation time and
        // then unrecoverable, matching the GitHub PAT model. Revoked
        // keys keep their row for audit; re-issuing means creating a new
        // row, not flipping a flag.
        $api_keys_table = $wpdb->prefix . 'zen_cortext_api_keys';
        $api_keys_sql = "CREATE TABLE {$api_keys_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            label VARCHAR(191) NOT NULL,
            key_hash CHAR(64) NOT NULL,
            key_prefix VARCHAR(16) NOT NULL,
            scopes TEXT NOT NULL,
            rate_per_min SMALLINT UNSIGNED NOT NULL DEFAULT 60,
            rate_per_hour MEDIUMINT UNSIGNED NOT NULL DEFAULT 3000,
            created_at DATETIME NOT NULL,
            last_used_at DATETIME DEFAULT NULL,
            revoked_at DATETIME DEFAULT NULL,
            created_by_user_id BIGINT UNSIGNED DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY key_hash (key_hash),
            KEY revoked_at (revoked_at)
        ) {$charset_collate};";
        dbDelta($api_keys_sql);
    }
}
