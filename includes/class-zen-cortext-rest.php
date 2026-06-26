<?php
/**
 * REST endpoint for Zen Cortext streaming.
 * POST /wp-json/zen-cortext/v1/send  → SSE stream from Anthropic.
 */


/*
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 * phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
 *
 * Justification:
 * - SQL: plugin-owned tables interpolated as identifiers; user values go
 *   through $wpdb->prepare(). Same rationale as the data-layer classes.
 * - Nonce: these are REST endpoints registered via register_rest_route()
 *   with an explicit permission_callback that gates on capability + own
 *   apikey/livechat auth (or a verified WP REST nonce on public routes)
 *   before the handler runs; the linter does not recognise that path.
 * - Sanitize: NOT blanket-disabled. Request params come through
 *   $request->get_param(), the WP-recommended boundary. The one direct
 *   superglobal read ($_FILES['audio'] in handle_transcribe) validates and
 *   sanitizes each field individually at the boundary (is_uploaded_file on
 *   the temp path, (int) on size, sanitize_mime_type on the MIME,
 *   sanitize_file_name on the name); only the PHP-generated temp path itself
 *   carries a targeted phpcs:ignore since it is validated, not sanitizable.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zen_Cortext_Rest {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        register_rest_route('zen-cortext/v1', '/send', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_send'),
            'permission_callback' => '__return_true',
        ));

        // Public read of a saved chat session by its public uid. Used by the
        // chat page to replay a conversation when the visitor opens a share
        // link like /talk/?chat=abc123. Returns just the messages array; no
        // PII (no user_agent, no ip_hash, no attribution). Soft-deleted
        // chats return 404.
        register_rest_route('zen-cortext/v1', '/chat/(?P<uid>[a-zA-Z0-9_-]{8,64})', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_chat_replay'),
            'permission_callback' => '__return_true',
        ));

        // Public soft-delete of a saved chat by its uid. The visitor's
        // share link is the only credential — anyone with the uid can
        // delete it. Soft-delete only: the row stays in admin, marked
        // deleted, but the public read endpoint stops serving it.
        register_rest_route('zen-cortext/v1', '/chat/(?P<uid>[a-zA-Z0-9_-]{8,64})/delete', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_chat_delete'),
            'permission_callback' => $this->owner_token_permission_cb('Only the original visitor can delete this conversation.'),
        ));

        // Public lead capture from the in-chat contact form. No nonce —
        // visitors don't carry WP cookies — but the chat_uid acts as the
        // implicit credential (an attacker would need the visitor's uid
        // to poison their lead, which only matters to the visitor).
        register_rest_route('zen-cortext/v1', '/chat/(?P<uid>[a-zA-Z0-9_-]{8,64})/lead', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_chat_lead'),
            'permission_callback' => $this->owner_token_permission_cb('Only the original visitor can attach a lead to this conversation.'),
        ));

        // Visitor self-archive: email a copy of the conversation transcript
        // to the visitor. Owner-token gated like /delete — only the original
        // visitor can trigger sends from their own chat. Has its own rate
        // limit window so it can't be used as a free relay.
        register_rest_route('zen-cortext/v1', '/chat/(?P<uid>[a-zA-Z0-9_-]{8,64})/email', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_chat_email_transcript'),
            'permission_callback' => $this->owner_token_permission_cb('Only the original visitor can email this conversation.'),
        ));

        // Admin-only builder chat: streams a focused interview to help an admin
        // build a Knowledge Artifact. Auth via current_user_can + WP REST nonce.
        register_rest_route('zen-cortext/v1', '/artifact-chat', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_artifact_chat'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        // Admin-only Brainstorm chat. Same KB / Artifacts / Team Expertise
        // context as the visitor chat, but on Opus 4.6 with extended thinking
        // and prompt caching on the static system context. Persisted per-user
        // in wp_zen_cortext_brainstorm_chats so admins can revisit past
        // brainstorms.
        $admin_only_cb = function () {
            return current_user_can('manage_options');
        };
        register_rest_route('zen-cortext/v1', '/admin-brainstorm', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_admin_brainstorm'),
            'permission_callback' => $admin_only_cb,
        ));
        register_rest_route('zen-cortext/v1', '/admin-brainstorm/chats', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_admin_brainstorm_list'),
            'permission_callback' => $admin_only_cb,
        ));
        register_rest_route('zen-cortext/v1', '/admin-brainstorm/chats/(?P<uid>[a-zA-Z0-9_-]{8,64})', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_admin_brainstorm_get'),
            'permission_callback' => $admin_only_cb,
        ));
        register_rest_route('zen-cortext/v1', '/admin-brainstorm/chats/(?P<uid>[a-zA-Z0-9_-]{8,64})', array(
            'methods'             => 'DELETE',
            'callback'            => array($this, 'handle_admin_brainstorm_delete'),
            'permission_callback' => $admin_only_cb,
        ));

        /* ---- Live Chat Takeover: public endpoints ---- */

        register_rest_route('zen-cortext/v1', '/chat/(?P<uid>[a-zA-Z0-9_-]{8,64})/status', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_chat_status'),
            // Exposes the live takeover state (admin attachment + invitable
            // team availability) for a specific conversation, so it is gated
            // by the per-chat owner token — the same credential /send,
            // /invite, /delete use. Legacy/new chats with no stored token
            // stay unenforced (check_owner_token returns ok/new).
            'permission_callback' => $this->owner_token_permission_cb('Only the original visitor can read this conversation\'s status.'),
        ));

        register_rest_route('zen-cortext/v1', '/chat/(?P<uid>[a-zA-Z0-9_-]{8,64})/invite', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_chat_invite'),
            'permission_callback' => $this->owner_token_permission_cb('Only the original visitor can invite a team member into this conversation.'),
        ));

        register_rest_route('zen-cortext/v1', '/chat/(?P<uid>[a-zA-Z0-9_-]{8,64})/poll', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_chat_poll'),
            // Returns live conversation events (admin replies during a
            // takeover) for a specific chat — gated by the per-chat owner
            // token so only the originating visitor can read their own
            // conversation. Legacy/new chats stay unenforced.
            'permission_callback' => $this->owner_token_permission_cb('Only the original visitor can read this conversation.'),
        ));

        /* ---- Live Chat Takeover: admin endpoints (Bearer session auth) ---- */

        register_rest_route('zen-cortext/v1', '/livechat/auth/request', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_livechat_auth_request'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('zen-cortext/v1', '/livechat/auth/verify', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_livechat_auth_verify'),
            'permission_callback' => '__return_true',
        ));

        $livechat_auth_cb = function () {
            return !is_wp_error(Zen_Cortext_Livechat_Auth::authenticate_request());
        };

        register_rest_route('zen-cortext/v1', '/livechat/chats', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_livechat_chats'),
            'permission_callback' => $livechat_auth_cb,
        ));

        register_rest_route('zen-cortext/v1', '/livechat/chat/(?P<uid>[a-zA-Z0-9_-]{8,64})', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_livechat_chat_detail'),
            'permission_callback' => $livechat_auth_cb,
        ));

        register_rest_route('zen-cortext/v1', '/livechat/chat/(?P<uid>[a-zA-Z0-9_-]{8,64})/attach', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_livechat_attach'),
            'permission_callback' => $livechat_auth_cb,
        ));

        register_rest_route('zen-cortext/v1', '/livechat/chat/(?P<uid>[a-zA-Z0-9_-]{8,64})/detach', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_livechat_detach'),
            'permission_callback' => $livechat_auth_cb,
        ));

        register_rest_route('zen-cortext/v1', '/livechat/chat/(?P<uid>[a-zA-Z0-9_-]{8,64})/send', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_livechat_send'),
            'permission_callback' => $livechat_auth_cb,
        ));

        register_rest_route('zen-cortext/v1', '/livechat/chat/(?P<uid>[a-zA-Z0-9_-]{8,64})/poll', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_livechat_poll'),
            'permission_callback' => $livechat_auth_cb,
        ));

        register_rest_route('zen-cortext/v1', '/livechat/ai-helper', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_livechat_ai_helper'),
            'permission_callback' => $livechat_auth_cb,
        ));

        register_rest_route('zen-cortext/v1', '/livechat/status', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_livechat_set_status'),
            'permission_callback' => $livechat_auth_cb,
        ));

        register_rest_route('zen-cortext/v1', '/livechat/status', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_livechat_get_status'),
            'permission_callback' => $livechat_auth_cb,
        ));

        register_rest_route('zen-cortext/v1', '/livechat/schedule', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_livechat_get_schedule'),
            'permission_callback' => $livechat_auth_cb,
        ));

        register_rest_route('zen-cortext/v1', '/livechat/schedule', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_livechat_set_schedule'),
            'permission_callback' => $livechat_auth_cb,
        ));

        register_rest_route('zen-cortext/v1', '/livechat/push/subscribe', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_push_subscribe'),
            'permission_callback' => $livechat_auth_cb,
        ));

        register_rest_route('zen-cortext/v1', '/livechat/push/vapid-public-key', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_push_vapid_key'),
            'permission_callback' => '__return_true',
        ));

        /* ---- Adaptive attribution: visitor invite/chips lookup (public) ---- */

        register_rest_route('zen-cortext/v1', '/attribution-context', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_attribution_context'),
            'permission_callback' => '__return_true',
        ));

        /* ---- Visitor session beacon (public). Fires on every page load
               to either extend the active session or mint a new one
               (GA-style: new session after 30-min inactivity OR attribution
               change). Response carries the session_uid that chat.js then
               echoes back on every /send so chats can be stamped with it. */

        register_rest_route('zen-cortext/v1', '/session/beacon', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_session_beacon'),
            'permission_callback' => '__return_true',
        ));

        /* ---- Voice transcription (public; visitor uploads audio blob) ---- */

        register_rest_route('zen-cortext/v1', '/transcribe', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_transcribe'),
            // Public (logged-out visitors use voice input), but the request
            // must carry a valid WP REST nonce minted into the chat config on
            // page render, proving it originated from a page this site served.
            'permission_callback' => array($this, 'verify_rest_nonce'),
        ));

        /* ---- Apps Script ingestion (Bearer API key, separate from livechat) ---- */

        $apikey_cb = function () {
            $check = Zen_Cortext_ApiKey_Auth::authenticate_apps_script();
            return $check === true;
        };

        register_rest_route('zen-cortext/v1', '/ingest/ping', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_ingest_ping'),
            'permission_callback' => $apikey_cb,
        ));
        register_rest_route('zen-cortext/v1', '/ingest/ads-campaigns', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_ingest_ads_campaigns'),
            'permission_callback' => $apikey_cb,
        ));

        // Admin-only: regenerate the assistant's system prompt by reading
        // the live Knowledge Base. Returns a proposed prompt for review;
        // the admin Applies or Discards from the settings UI.
        register_rest_route('zen-cortext/v1', '/admin/adapt-system-prompt', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_adapt_system_prompt'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));
    }

    /**
     * POST /session/beacon — public arrival beacon. Body:
     *   {
     *     "attribution": { utm_source, utm_medium, ..., referrer, landing_page },
     *     "session_uid": "<client's current uid or null>"
     *   }
     * Resolves to either an extension of the existing session (within the
     * 30-min inactivity window AND attribution unchanged) or a fresh
     * session. Returns { session_uid, action: "created"|"extended" }.
     * Must NOT be cached — the response is visitor-specific.
     */
    public function handle_session_beacon($request) {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        header('Surrogate-Control: no-store');

        $attribution = (array) $request->get_param('attribution');
        $client_session_uid = (string) $request->get_param('session_uid');
        $ip = $this->client_ip();
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        $result = Zen_Cortext_Sessions::beacon($attribution, $client_session_uid, $ip, $ua);
        if (is_wp_error($result)) {
            return new WP_Error(
                $result->get_error_code(),
                $result->get_error_message(),
                array('status' => 500)
            );
        }
        return rest_ensure_response($result);
    }

    /**
     * GET /attribution-context — public lookup. Receives the same UTM/
     * gclid/referrer fields that chat.js captures, returns the matched
     * invite_message + chips. Must NOT be cached: response varies per
     * visitor by query string.
     */
    public function handle_attribution_context($request) {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        // Surrogate-Control is what reverse proxies (Varnish) honor when
        // they don't want to obey browser-facing Cache-Control. no-store
        // tells the edge to never cache this response, even if the URL
        // pattern looks cacheable (e.g. /wp-json/...?_=<ts>).
        header('Surrogate-Control: no-store');
        header('Vary: Cookie');

        $attribution = $this->sanitize_attribution(array(
            'referrer'     => (string) $request->get_param('referrer'),
            'landing_page' => (string) $request->get_param('landing_page'),
            'utm_source'   => (string) $request->get_param('utm_source'),
            'utm_medium'   => (string) $request->get_param('utm_medium'),
            'utm_campaign' => (string) $request->get_param('utm_campaign'),
            'utm_term'     => (string) $request->get_param('utm_term'),
            'utm_content'  => (string) $request->get_param('utm_content'),
            'gclid'        => (string) $request->get_param('gclid'),
            'msclkid'      => (string) $request->get_param('msclkid'),
            'fbc'          => (string) $request->get_param('fbc'),
            'fbp'          => (string) $request->get_param('fbp'),
        ));

        $payload = Zen_Cortext_Attribution::get_invite_payload($attribution);

        // Resolve the active survey for this visitor — matched rule's
        // survey_id wins, falls back to the global default. When present,
        // surface the intro + first question so chat.js can kick the
        // interview off on the very first load (replacing welcome message
        // + starter chips).
        $survey_id = (int) Zen_Cortext_Attribution::active_survey_id($attribution);
        if ($survey_id <= 0) {
            $survey_id = (int) get_option('zen_cortext_default_survey_id', 0);
        }
        if ($survey_id > 0 && class_exists('Zen_Cortext_Surveys')) {
            $parsed = Zen_Cortext_Surveys::get_parsed($survey_id);
            if (is_array($parsed) && !empty($parsed['questions'])) {
                $first = $parsed['questions'][0];
                $payload['survey'] = array(
                    'id'             => $survey_id,
                    'intro'          => isset($parsed['intro']) ? (string) $parsed['intro'] : '',
                    'first_question' => isset($first['text']) ? (string) $first['text'] : '',
                    'first_type'     => isset($first['type']) ? (string) $first['type'] : 'open',
                    'first_options'  => isset($first['options']) && is_array($first['options'])
                        ? array_values(array_map('strval', $first['options']))
                        : array(),
                );
            }
        }

        return rest_ensure_response($payload);
    }

    /**
     * POST /transcribe — public voice-transcription endpoint. Visitor
     * browser uploads an audio blob (multipart/form-data, field name
     * `audio`); we forward it to Groq Whisper Large v3 Turbo with an
     * optional OpenAI Whisper fallback (see Zen_Cortext_Transcribe).
     *
     * Per-IP rate limited (20/min). Disabled unless an admin has
     * flipped the master toggle on the Voice settings tab — the chat
     * client gates the mic button on the same flag so a non-toggled
     * site never even exposes the button.
     */
    /**
     * Permission callback for public visitor-facing endpoints that read
     * request input directly (e.g. the multipart audio upload). These are
     * intentionally reachable by logged-out visitors, so we cannot gate on a
     * capability; instead we require a valid WP REST nonce (`wp_rest`),
     * supplied via the X-WP-Nonce header or a _wpnonce param. The nonce is
     * minted into the chat config (Zen_Cortext_Shortcode::chat_config_payload)
     * when the page is rendered. For logged-out visitors the `wp_rest` nonce
     * is stable within its lifetime window, so it still validates on
     * full-page-cached (e.g. Varnish) sites. This proves the request came
     * from a page this site served rather than a blind cross-origin POST.
     */
    public function verify_rest_nonce($request) {
        $nonce = $request->get_header('X-WP-Nonce');
        if (empty($nonce)) {
            $nonce = $request->get_param('_wpnonce');
        }
        if (empty($nonce) || !wp_verify_nonce((string) $nonce, 'wp_rest')) {
            return new WP_Error(
                'zc_bad_nonce',
                'Invalid or expired security token. Reload the page and try again.',
                array('status' => 403)
            );
        }
        return true;
    }

    public function handle_transcribe($request) {
        if (!get_option('zen_cortext_voice_enabled', false)) {
            return new WP_Error('zc_voice_disabled', 'Voice transcription is not enabled.', array('status' => 503));
        }

        // Per-IP sliding 60s window. Separate bucket from the chat-send
        // rate limit so a chatty visitor isn't also throttled here.
        $ip = $this->client_ip();
        if (!self::check_and_record_transcribe_rate($ip, 20, 60)) {
            $resp = new WP_REST_Response(array(
                'code'    => 'zc_rate_limited',
                'message' => 'Too many transcription requests. Wait a minute and try again.',
            ), 429);
            $resp->header('Retry-After', '60');
            return $resp;
        }

        // Expect $_FILES['audio'] from FormData. Each field is validated and
        // sanitized at this boundary before use: the upload is confirmed to be
        // a genuine PHP upload (is_uploaded_file), size is range-checked, the
        // MIME type goes through sanitize_mime_type() + an audio/* allow-check,
        // and the filename through sanitize_file_name(). The transcribe class
        // sanitizes the name/mime again before forwarding (defense in depth).
        if (empty($_FILES['audio']) || !is_array($_FILES['audio'])) {
            return new WP_Error('zc_no_audio', 'Missing audio upload field.', array('status' => 400));
        }
        // Pull only the specific fields we use, each individually sanitized;
        // we never consume the raw $_FILES array wholesale.
        $error    = isset($_FILES['audio']['error'])    ? (int) $_FILES['audio']['error'] : -1;
        $tmp_name = isset($_FILES['audio']['tmp_name']) ? (string) $_FILES['audio']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP-generated upload temp path, validated via is_uploaded_file() below; not user-controlled text to sanitize.
        $size     = isset($_FILES['audio']['size'])     ? (int) $_FILES['audio']['size'] : 0;
        $mime     = isset($_FILES['audio']['type'])     ? sanitize_mime_type(wp_unslash((string) $_FILES['audio']['type'])) : 'audio/webm';
        $name     = isset($_FILES['audio']['name'])     ? sanitize_file_name(wp_unslash((string) $_FILES['audio']['name'])) : 'audio.webm';

        if ($error !== UPLOAD_ERR_OK) {
            return new WP_Error('zc_upload_error', 'Audio upload failed.', array('status' => 400, 'detail' => $error));
        }
        if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
            return new WP_Error('zc_upload_invalid', 'Audio upload is not a valid uploaded file.', array('status' => 400));
        }
        if ($size <= 0) {
            return new WP_Error('zc_upload_empty', 'Audio upload is empty.', array('status' => 400));
        }
        if ($size > 10 * 1024 * 1024) {
            return new WP_Error('zc_upload_too_large', 'Audio upload exceeds the 10 MB limit.', array('status' => 413));
        }
        if ($mime === '' || stripos($mime, 'audio/') !== 0) {
            return new WP_Error('zc_bad_mime', 'Upload is not an audio file.', array('status' => 400));
        }
        if ($name === '') {
            $name = 'audio.webm';
        }

        $result = Zen_Cortext_Transcribe::transcribe($tmp_name, $mime, $name);
        if (is_wp_error($result)) {
            $status = 502; // upstream provider error
            $code   = $result->get_error_code();
            if ($code === 'zc_no_provider') $status = 503;
            return new WP_Error($code, $result->get_error_message(), array('status' => $status));
        }

        return rest_ensure_response(array(
            'text'     => $result['text'],
            'provider' => $result['provider'],
        ));
    }

    /**
     * Per-IP rate limit dedicated to /transcribe. Sliding-window over
     * `$window_sec` seconds — distinct from check_and_record_rate_limit()
     * which is locked to a 1-hour bucket for chat-send. Bucket key
     * hashed so we never persist a raw IP.
     */
    private static function check_and_record_transcribe_rate($ip, $max, $window_sec) {
        $key    = 'zen_cortext_rl_xc_' . md5((string) $ip);
        $now    = time();
        $cutoff = $now - $window_sec;
        $ts     = get_transient($key);
        if (!is_array($ts)) $ts = array();
        $ts = array_values(array_filter($ts, function ($t) use ($cutoff) { return (int) $t > $cutoff; }));
        if (count($ts) >= $max) {
            set_transient($key, $ts, $window_sec);
            return false;
        }
        $ts[] = $now;
        set_transient($key, $ts, $window_sec);
        return true;
    }

    /**
     * POST /ingest/ping — health check for the Apps Script. Confirms
     * auth works without mutating data.
     */
    public function handle_ingest_ping($request) {
        return rest_ensure_response(array(
            'ok'          => true,
            'server_time' => current_time('mysql'),
            'version'     => ZEN_CORTEXT_VERSION,
        ));
    }

    /**
     * POST /ingest/ads-campaigns — wholesale upsert of Google Ads
     * campaign data from the Apps Script. Body:
     *   { "delete_missing": false, "campaigns": [ ...rows... ] }
     * delete_missing defaults to false — partial syncs don't accidentally
     * remove rows that the script forgot to send.
     */
    public function handle_ingest_ads_campaigns($request) {
        $campaigns      = $request->get_param('campaigns');
        $delete_missing = (bool) $request->get_param('delete_missing');
        if (!is_array($campaigns)) {
            return new WP_Error('zen_cortext_bad_request', 'campaigns array required', array('status' => 400));
        }
        $result = Zen_Cortext_Ads_Campaigns::upsert_bulk($campaigns, $delete_missing);
        return rest_ensure_response($result);
    }

    public function handle_send($request) {
        $messages = $request->get_param('messages');
        if (!is_array($messages)) {
            return new WP_Error('zen_cortext_bad_request', 'messages array required', array('status' => 400));
        }

        // Optional persistence + attribution captured client-side.
        $chat_uid = (string) $request->get_param('chat_uid');
        $chat_uid = preg_replace('/[^a-zA-Z0-9_-]/', '', $chat_uid);
        if (strlen($chat_uid) > 64) {
            $chat_uid = substr($chat_uid, 0, 64);
        }

        // Owner token: stored only in the original visitor's localStorage,
        // sent with every /send call. Third parties opening the share link
        // (?chat=…) don't have the token, so the chat row's stored hash
        // won't match and we refuse to write. First-message-of-a-chat is
        // the 'new' case — we accept and persist the token then.
        $owner_token = (string) $request->get_param('owner_token');
        $owner_token = preg_replace('/[^a-zA-Z0-9_-]/', '', $owner_token);
        if (strlen($owner_token) > 128) {
            $owner_token = substr($owner_token, 0, 128);
        }
        if ($chat_uid !== '') {
            $owner_check = Zen_Cortext_Chats::check_owner_token($chat_uid, $owner_token);
            if ($owner_check === 'mismatch') {
                return new WP_Error(
                    'zen_cortext_forbidden',
                    'This conversation is read-only — only the original visitor can post here.',
                    array('status' => 403)
                );
            }
        }

        $attribution = (array) $request->get_param('attribution');
        $attribution = $this->sanitize_attribution($attribution);
        // Server-side fields (don't trust client). The User-Agent header is
        // attacker-controlled, so sanitize it like any other input.
        $attribution['user_agent'] = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $attribution['ip']         = $this->client_ip();

        // Visitor session id from chat.js (minted by the /session/beacon
        // endpoint on page load). Used to attach this chat to its parent
        // session row after the chat upsert. Empty string when the
        // visitor's browser hasn't fired the beacon yet — attach is a
        // no-op in that case.
        $session_uid = (string) $request->get_param('session_uid');
        $session_uid = preg_replace('/[^a-zA-Z0-9_-]/', '', $session_uid);
        if (strlen($session_uid) > 64) {
            $session_uid = substr($session_uid, 0, 64);
        }

        // Check if an admin has taken over this chat. If so, save the
        // visitor's message to the DB + fire an event for the admin's poller,
        // but do NOT call Anthropic. Send a single SSE event back so the
        // visitor's JS knows to switch to polling mode.
        if ($chat_uid !== '' && Zen_Cortext_Takeover::is_attached($chat_uid) !== null) {
            // Save the visitor's message to the chat row.
            $upsert_data = array_merge($attribution, array(
                'chat_uid'    => $chat_uid,
                'messages'    => $messages,
                'owner_token' => $owner_token,
            ));
            Zen_Cortext_Chats::upsert($upsert_data);
            if ($session_uid !== '') {
                Zen_Cortext_Sessions::attach_chat($session_uid, $chat_uid);
            }

            // Notify the admin's poller.
            $last_user_msg = '';
            foreach (array_reverse($messages) as $m) {
                if (isset($m['role'], $m['content']) && $m['role'] === 'user') {
                    $last_user_msg = (string) $m['content'];
                    break;
                }
            }
            Zen_Cortext_Takeover::record_visitor_message($chat_uid, $last_user_msg);

            // Return a lightweight SSE response so the client knows.
            nocache_headers();
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
            while (ob_get_level() > 0) { ob_end_flush(); }

            echo "data: " . wp_json_encode(array(
                'type'    => 'admin_mode',
                'message' => 'Your message has been sent to the team.',
            )) . "\n\n";
            @flush();
            exit;
        }

        // Rate limit (Stage A — flat baseline). 30 messages / hour per
        // chat_uid, sliding window. Sits BEFORE the Haiku classifier so
        // throttled traffic doesn't spend Haiku budget either. Stage B
        // (enrichment-driven escalation) is a future change that tightens
        // this cap when recent messages carry scraping/repetitive intent.
        $rate_limit = (int) apply_filters('zen_cortext_rate_limit_per_hour', 30);
        if ($chat_uid !== '' && $rate_limit > 0
            && !self::check_and_record_rate_limit($chat_uid, $rate_limit)
        ) {
            $this->emit_rate_limited_response($chat_uid);
            exit;
        }

        // Input filter: two layers (regex + Haiku classifier) stop off-
        // topic / prompt-injection / abuse messages BEFORE we spend the
        // ~$0.025 Sonnet call with full context. Returns a templated
        // response that stays in the conversation history, so the visitor
        // gets a friendly redirect rather than a silent drop.
        // Find the latest user message AND the most recent assistant
        // turn before it that ISN'T one of our templated refusals. The
        // classifier needs that real prior question as context so answers
        // like "printed pictures site" (on-topic responses to "what's the
        // site about?") don't get mis-tagged off_topic. Skipping past a
        // refusal in history is what breaks the self-reinforcing loop
        // when a visitor retries the same message after being blocked.
        $last_user_msg       = '';
        $prior_assistant_msg = '';
        $saw_user            = false;
        foreach (array_reverse($messages) as $m) {
            if (empty($m['role']) || !isset($m['content'])) continue;
            if (!$saw_user) {
                if ($m['role'] === 'user') {
                    $last_user_msg = (string) $m['content'];
                    $saw_user = true;
                }
                continue;
            }
            if ($m['role'] === 'assistant') {
                $content = (string) $m['content'];
                if (Zen_Cortext_Filter::is_template_refusal($content)) {
                    continue; // skip refusal, walk further back
                }
                $prior_assistant_msg = $content;
                break;
            }
        }
        if ($last_user_msg !== '') {
            // Pass the active survey's label + description to the filter.
            // When set, it relaxes the off-topic gate (in both layers) for
            // the duration of the interview — so an admin-defined survey
            // outside the agency's usual scope (e.g. a wine survey) can
            // run without on-topic answers getting templated-refused. The
            // injection_attempt / abuse gates still apply unconditionally.
            $survey_id = (int) Zen_Cortext_Attribution::active_survey_id($attribution);
            if ($survey_id <= 0) {
                $survey_id = (int) get_option('zen_cortext_default_survey_id', 0);
            }
            $survey_context = '';
            if ($survey_id > 0 && class_exists('Zen_Cortext_Surveys')) {
                $survey_row = Zen_Cortext_Surveys::get($survey_id);
                if (is_array($survey_row) && !empty($survey_row['enabled'])) {
                    $parts = array();
                    if (!empty($survey_row['label']))       $parts[] = (string) $survey_row['label'];
                    if (!empty($survey_row['description'])) $parts[] = (string) $survey_row['description'];
                    $survey_context = trim(implode("\n\n", $parts));
                }
            }

            $result     = Zen_Cortext_Filter::should_block($last_user_msg, $prior_assistant_msg, $survey_context, $attribution);
            $decision   = isset($result['decision'])   ? $result['decision']   : null;
            $enrichment = isset($result['enrichment']) ? $result['enrichment'] : null;

            // Attach enrichment to the last user-role message in $messages
            // before any save path so both blocked and allowed branches
            // carry the telemetry into the DB unchanged.
            if (is_array($enrichment) && !empty($enrichment)) {
                self::attach_enrichment_to_last_user($messages, $enrichment);
            }

            if ($decision !== null) {
                $this->emit_blocked_response($decision, $messages, $chat_uid, $attribution, $last_user_msg, $owner_token, $session_uid);
                exit;
            }
        }

        // Normal AI mode: stream from Anthropic.
        nocache_headers();
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        // Drop output buffering so each chunk reaches the client immediately.
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        Zen_Cortext_API::stream_chat($messages, $chat_uid, $attribution, $owner_token);

        // Stamp the chat row with its parent session uid. stream_chat()'s
        // upsert has run by this point, so the chat row exists. attach_chat
        // guards on the chat row's session_uid being empty (idempotent).
        if ($chat_uid !== '' && $session_uid !== '') {
            Zen_Cortext_Sessions::attach_chat($session_uid, $chat_uid);
        }

        // We've already written the entire response. Tell WP to stop.
        exit;
    }

    /**
     * Persist the blocked exchange + fire a 'blocked' chat_event (so
     * admins can tune patterns from log data), then emit a single SSE
     * content_block_delta frame with the templated response. chat.js
     * accumulates it exactly like a normal assistant message.
     */
    private function emit_blocked_response($block, $messages, $chat_uid, $attribution, $raw_user_msg, $owner_token = '', $session_uid = '') {
        $response_text = (string) $block['response'];

        if ($chat_uid !== '') {
            $final_messages = array();
            foreach ($messages as $m) {
                if (empty($m['role']) || empty($m['content'])) continue;
                $role  = $m['role'] === 'assistant' ? 'assistant' : 'user';
                $entry = array(
                    'role'    => $role,
                    'content' => (string) $m['content'],
                );
                if ($role === 'user' && !empty($m['enrichment']) && is_array($m['enrichment'])) {
                    $entry['enrichment'] = $m['enrichment'];
                }
                $final_messages[] = $entry;
            }
            $final_messages[] = array('role' => 'assistant', 'content' => $response_text);

            $upsert_data = array_merge(
                is_array($attribution) ? $attribution : array(),
                array(
                    'chat_uid'    => $chat_uid,
                    'messages'    => $final_messages,
                    'owner_token' => $owner_token,
                )
            );
            Zen_Cortext_Chats::upsert($upsert_data);
            if ($session_uid !== '') {
                Zen_Cortext_Sessions::attach_chat($session_uid, $chat_uid);
            }

            // Log the block so admin can review and tune patterns.
            if (class_exists('Zen_Cortext_Chat_Events')) {
                $snippet = function_exists('mb_substr')
                    ? mb_substr($raw_user_msg, 0, 500)
                    : substr($raw_user_msg, 0, 500);
                Zen_Cortext_Chat_Events::insert(
                    $chat_uid,
                    'blocked',
                    array(
                        'category' => $block['category'],
                        'reason'   => $block['reason'],
                        'snippet'  => $snippet,
                    ),
                    'system'
                );
            }
        }

        nocache_headers();
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) { ob_end_flush(); }

        echo "data: " . wp_json_encode(array(
            'type'  => 'content_block_delta',
            'delta' => array('text' => $response_text),
        )) . "\n\n";
        @flush();
    }

    public function handle_chat_replay($request) {
        // Per-chat data must never be cached by Varnish or browsers — a chat
        // can be deleted or updated at any time, and a stale cached copy
        // would expose deleted content.
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $uid = (string) $request->get_param('uid');
        $uid = preg_replace('/[^a-zA-Z0-9_-]/', '', $uid);
        if ($uid === '') {
            return new WP_Error('zen_cortext_not_found', 'Chat not found', array('status' => 404));
        }

        // get_by_uid() filters soft-deleted rows by default — exactly what
        // we want for the public replay endpoint.
        $row = Zen_Cortext_Chats::get_by_uid($uid);
        if (!$row) {
            return new WP_Error('zen_cortext_not_found', 'Chat not found', array('status' => 404));
        }

        $messages = json_decode($row['messages'], true);
        if (!is_array($messages)) $messages = array();

        // Strip everything except the conversation itself — no PII, no attribution.
        // lead_submitted is a boolean flag (no PII) so chat.js can suppress
        // the contact-form render after a page reload — the `leadSubmitted`
        // JS flag otherwise resets to false on fresh page loads.
        return rest_ensure_response(array(
            'chat_uid'       => $row['chat_uid'],
            'messages'       => $messages,
            'created'        => $row['created_at'],
            'updated'        => $row['updated_at'],
            'lead_submitted' => !empty($row['lead_submitted_at']),
            // Exposed so the "Email me a copy" form can prefill — the chat
            // uid is already the implicit credential, so no new exposure.
            'lead_email'     => (string) ($row['lead_email'] ?? ''),
        ));
    }

    /**
     * POST /chat/{uid}/lead — visitor submits the in-chat contact form.
     * Stores name/email/whatsapp on the chat row, fires an email to every
     * invitable team member, and records a chat_event so the livechat
     * PWA's poller surfaces a notification. Idempotent-ish: a second
     * submission overwrites the first (visitor correcting a typo).
     */
    public function handle_chat_lead($request) {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $uid = (string) $request->get_param('uid');
        $uid = preg_replace('/[^a-zA-Z0-9_-]/', '', $uid);
        if ($uid === '') {
            return new WP_Error('zen_cortext_lead', 'Chat not found', array('status' => 404));
        }

        // Owner-token gate enforced at registration (owner_token_permission_cb)
        // so a third party on the share link can't poison the lead capture.

        $name     = (string) $request->get_param('name');
        $email    = (string) $request->get_param('email');
        $whatsapp = (string) $request->get_param('whatsapp');

        $result = Zen_Cortext_Chats::save_lead($uid, $name, $email, $whatsapp);
        if (is_wp_error($result)) {
            return new WP_Error('zen_cortext_lead', $result->get_error_message(), array('status' => 400));
        }

        // Fan out notifications. Email is the guaranteed delivery path;
        // chat_event lets the PWA show an in-app badge when the admin is
        // already logged in.
        $this->notify_lead_captured($result);

        return rest_ensure_response(array(
            'saved'        => true,
            'submitted_at' => $result['lead_submitted_at'] ?? current_time('mysql'),
        ));
    }

    /**
     * Email every invitable user + record a chat_event for pollers. Any
     * mail failure is swallowed — we already saved the lead, so losing
     * a notification shouldn't 500 the visitor.
     */
    private function notify_lead_captured($chat_row) {
        $invitable = (array) get_option('zen_cortext_invitable_users', array());
        $invitable = array_values(array_filter(array_map('intval', $invitable)));

        $name     = (string) ($chat_row['lead_name']     ?? '');
        $email    = (string) ($chat_row['lead_email']    ?? '');
        $whatsapp = (string) ($chat_row['lead_whatsapp'] ?? '');
        $chat_uid = (string) ($chat_row['chat_uid']      ?? '');

        // Build a compact context block from the chat for the email body.
        $messages = array();
        if (!empty($chat_row['messages'])) {
            $decoded = json_decode($chat_row['messages'], true);
            if (is_array($decoded)) $messages = $decoded;
        }
        $transcript = '';
        foreach (array_slice($messages, -6) as $m) {
            if (!isset($m['role'], $m['content'])) continue;
            $role = $m['role'] === 'assistant' ? 'AI' : ($m['role'] === 'admin' ? 'Admin' : 'Visitor');
            $transcript .= $role . ': ' . $m['content'] . "\n\n";
        }

        $admin_link = admin_url('admin.php?page=zen-cortext-chats&action=view&id=' . (int) $chat_row['id']);

        $subject = sprintf('[%s] New lead: %s', get_bloginfo('name'), $name !== '' ? $name : $email);
        $body  = '<html><body style="font-family:sans-serif;font-size:14px;color:#333;">';
        $body .= '<h2 style="color:#646B3A;margin:0 0 12px 0;">New lead captured</h2>';
        $body .= '<p><strong>Name:</strong> ' . esc_html($name) . '<br>';
        $body .= '<strong>Email:</strong> <a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
        if ($whatsapp !== '') {
            $wa_num = preg_replace('/[^0-9+]/', '', $whatsapp);
            $body .= '<br><strong>WhatsApp:</strong> <a href="https://wa.me/' . esc_attr(ltrim($wa_num, '+')) . '">' . esc_html($whatsapp) . '</a>';
        }
        $body .= '</p>';

        if (!empty($chat_row['utm_campaign']) || !empty($chat_row['utm_source'])) {
            $body .= '<p style="color:#666;font-size:12px;"><strong>Traffic source:</strong> '
                   . esc_html(trim(($chat_row['utm_source'] ?? '') . ' / ' . ($chat_row['utm_medium'] ?? ''), ' /'))
                   . ($chat_row['utm_campaign'] ? ' — campaign ' . esc_html($chat_row['utm_campaign']) : '')
                   . '</p>';
        }

        if ($transcript !== '') {
            $body .= '<h3 style="margin:20px 0 6px 0;">Last exchanges</h3>';
            $body .= '<pre style="background:#f6f7f7;padding:12px;border-radius:6px;white-space:pre-wrap;font-family:inherit;">'
                   . esc_html($transcript) . '</pre>';
        }

        $body .= '<p style="margin-top:20px;"><a href="' . esc_url($admin_link) . '" style="display:inline-block;padding:10px 18px;background:#646B3A;color:#fff;text-decoration:none;border-radius:6px;">Open the full chat</a></p>';
        $body .= '</body></html>';

        $headers = array('Content-Type: text/html; charset=UTF-8');
        foreach ($invitable as $uid) {
            $u = get_userdata($uid);
            if (!$u || empty($u->user_email)) continue;
            @wp_mail($u->user_email, $subject, $body, $headers);
        }

        // Fire a chat event so admins already in the PWA see it live.
        if ($chat_uid !== '' && class_exists('Zen_Cortext_Chat_Events')) {
            Zen_Cortext_Chat_Events::insert(
                $chat_uid,
                'lead_captured',
                array('name' => $name, 'email' => $email, 'whatsapp' => $whatsapp),
                'visitor'
            );
        }
    }

    public function handle_chat_delete($request) {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $uid = (string) $request->get_param('uid');
        $uid = preg_replace('/[^a-zA-Z0-9_-]/', '', $uid);
        if ($uid === '') {
            return new WP_Error('zen_cortext_not_found', 'Chat not found', array('status' => 404));
        }

        // Owner-token gate is enforced at route registration via
        // owner_token_permission_cb() — a third party with the share link
        // can't soft-delete someone else's conversation. Legacy rows (no
        // stored hash) stay unenforced.

        // Idempotent: if the chat is already deleted (or never existed),
        // we still return success. The visitor's intent ("make this gone
        // for me") is satisfied either way.
        Zen_Cortext_Chats::soft_delete_by_uid($uid);

        // Tell Varnish to purge any cached replay response for this uid.
        // We can't ban from PHP without admin secret, so we issue a same-host
        // PURGE request — Varnish only allows it from 127.0.0.1, which is
        // exactly what we are.
        $this->purge_replay_cache($uid);

        return rest_ensure_response(array('deleted' => true));
    }

    /**
     * POST /chat/{uid}/email — visitor self-archive. Renders the conversation
     * via the editable email/chat-transcript.html template and emails it to
     * the address the visitor provided. Owner-token gated like /delete and
     * rate-limited on its own window so a single chat can't be used to mail
     * arbitrary addresses repeatedly.
     */
    public function handle_chat_email_transcript($request) {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $uid = (string) $request->get_param('uid');
        $uid = preg_replace('/[^a-zA-Z0-9_-]/', '', $uid);
        if ($uid === '') {
            return new WP_Error('zen_cortext_not_found', 'Chat not found', array('status' => 404));
        }

        // Owner-token gate enforced at registration (owner_token_permission_cb).

        $email = trim((string) $request->get_param('email'));
        if ($email === '' || !is_email($email)) {
            return new WP_Error(
                'zen_cortext_email_invalid',
                'A valid email address is required.',
                array('status' => 400)
            );
        }

        // Separate rate-limit window (5/hour). Keying on $uid . ':email' so
        // sending a transcript doesn't burn the chat's normal /send budget.
        if (!self::check_and_record_rate_limit($uid . ':email', 5)) {
            return new WP_Error(
                'zen_cortext_rate_limited',
                "You've requested a few transcripts in a short window — give it a minute and try again.",
                array('status' => 429)
            );
        }

        $row = Zen_Cortext_Chats::get_by_uid($uid);
        if (!$row) {
            return new WP_Error('zen_cortext_not_found', 'Chat not found', array('status' => 404));
        }

        $decoded = json_decode($row['messages'], true);
        if (!is_array($decoded)) $decoded = array();

        $site_name = trim((string) get_bloginfo('name'));
        if ($site_name === '') $site_name = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        // Reshape messages for the template — adds is_user / is_admin booleans
        // so the template can branch with {{ if:is_user }} without exposing
        // string-equality logic to template authors.
        $rendered_messages = array();
        foreach ($decoded as $m) {
            if (!is_array($m) || empty($m['role']) || empty($m['content'])) continue;
            $role = (string) $m['role'];
            $rendered_messages[] = array(
                'role'         => $role,
                'content'      => (string) $m['content'],
                'is_user'      => $role === 'user',
                'is_admin'     => $role === 'admin',
                'is_assistant' => $role === 'assistant',
                'sender'       => isset($m['admin_name']) ? (string) $m['admin_name'] : '',
            );
        }

        $chat_url = add_query_arg('chat', $uid, home_url('/talk/'));

        $context = array(
            'site_name'       => $site_name,
            'site_url'        => home_url('/'),
            'recipient_email' => $email,
            'chat_url'        => $chat_url,
            'sent_at_human'   => date_i18n(
                get_option('date_format') . ' ' . get_option('time_format'),
                current_time('timestamp')
            ),
            'messages'        => $rendered_messages,
        );

        $body = Zen_Cortext_Template_Renderer::render('email/chat-transcript.html', $context);
        if (!is_string($body) || $body === '') {
            return new WP_Error(
                'zen_cortext_email_render',
                'Could not render the transcript email.',
                array('status' => 500)
            );
        }

        $subject = sprintf(
            /* translators: %s: site name */
            __('Your conversation with %s', 'zen-cortext'),
            $site_name
        );

        // Without explicit From/Reply-To headers wp_mail() synthesizes
        //   "WordPress <wordpress@<host>>"
        // which looks like spam to most clients. Use the site name + admin
        // email so the visitor recognises the sender and can reply.
        $from_name  = $site_name;
        $from_email = (string) get_option('admin_email', '');
        if (!is_email($from_email)) {
            $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
            $host = preg_replace('/^www\./', '', $host);
            $from_email = $host !== '' ? ('no-reply@' . $host) : '';
        }

        $headers = array('Content-Type: text/html; charset=UTF-8');
        if ($from_email !== '') {
            $headers[] = sprintf('From: %s <%s>', $from_name, $from_email);
            $headers[] = sprintf('Reply-To: %s <%s>', $from_name, $from_email);
        }

        $sent = @wp_mail($email, $subject, $body, $headers);

        if (!$sent) {
            return new WP_Error(
                'zen_cortext_email_failed',
                'Could not deliver the email — please try again later.',
                array('status' => 502)
            );
        }

        // Best-effort: remember this address so the form prefills next time.
        // No-op when the chat already has a lead_email from a contact form.
        Zen_Cortext_Chats::set_lead_email_if_empty($uid, $email);

        if (class_exists('Zen_Cortext_Chat_Events')) {
            Zen_Cortext_Chat_Events::insert(
                $uid,
                'transcript_emailed',
                array('email' => $email),
                'visitor'
            );
        }

        return rest_ensure_response(array(
            'sent'  => true,
            'email' => $email,
        ));
    }

    /**
     * Owner-token gate for visitor write/action endpoints. Reads
     * `owner_token` from the request body, runs the same check used
     * by /send + /delete, and returns a 403 WP_Error on mismatch
     * (or null when the caller may proceed). Legacy rows with no
     * stored hash stay unenforced — Zen_Cortext_Chats::check_owner_token
     * returns 'ok' for those.
     */
    /**
     * Build the permission_callback for a visitor write endpoint
     * (delete / lead / email / invite). Declares the owner-token gate at
     * route-registration time instead of inside the handler. Allows the
     * request when the owner_token matches the stored hash for {uid}, when
     * the row is legacy (no stored hash — unenforced), or when it doesn't
     * exist yet ('new') — the handlers are idempotent / create-on-demand for
     * those cases. Denies a genuine mismatch with a 403. $forbidden_message
     * tailors the visitor-facing copy per action.
     */
    public function owner_token_permission_cb($forbidden_message = 'Only the original visitor can perform this action.') {
        return function ($request) use ($forbidden_message) {
            $uid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $request->get_param('uid'));
            // Empty/garbage uid: let the handler return its own 404 shape.
            if ($uid === '') {
                return true;
            }
            $token = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $request->get_param('owner_token'));
            if (strlen($token) > 128) {
                $token = substr($token, 0, 128);
            }
            if (Zen_Cortext_Chats::check_owner_token($uid, $token) === 'mismatch') {
                return new WP_Error('zen_cortext_forbidden', $forbidden_message, array('status' => 403));
            }
            return true;
        };
    }

    /**
     * Authenticate + touch last_seen in one call. Used by all admin
     * endpoints so the status heartbeat piggybacks on existing traffic.
     */
    private function auth_and_touch() {
        $user_id = Zen_Cortext_Livechat_Auth::authenticate_request();
        if (!is_wp_error($user_id)) {
            Zen_Cortext_Takeover::touch_last_seen($user_id);
        }
        return $user_id;
    }

    /**
     * Lightweight touch of visitor_last_seen on the chat row.
     * Called from visitor-facing endpoints (status, poll, send).
     */
    /**
     * Sliding-window rate limiter keyed by chat_uid. Records a
     * timestamp on admission; returns false once the chat has used
     * $max entries in the last hour. Pruned lazily on each call so
     * the transient array stays bounded. The transient is rewritten
     * on every admission and every refusal so an idle hour decays the
     * window naturally via WP's own transient GC.
     */
    private static function check_and_record_rate_limit($chat_uid, $max) {
        $key = 'zen_cortext_rl_' . md5((string) $chat_uid);
        $now = time();
        $cutoff = $now - 3600;
        $timestamps = get_transient($key);
        if (!is_array($timestamps)) $timestamps = array();
        $timestamps = array_values(array_filter(
            $timestamps,
            function ($ts) use ($cutoff) { return (int) $ts > $cutoff; }
        ));
        if (count($timestamps) >= $max) {
            set_transient($key, $timestamps, 3600);
            return false;
        }
        $timestamps[] = $now;
        set_transient($key, $timestamps, 3600);
        return true;
    }

    /**
     * Emit a friendly throttle message as a single SSE content_block
     * delta so chat.js renders it inline the same way as a normal
     * assistant turn. Persists the exchange + logs a chat_event so
     * admins can spot abusive chat_uids from the events log.
     */
    private function emit_rate_limited_response($chat_uid) {
        $text = "You've sent a lot of messages in a short time — give it a minute and I'll pick back up with you."
              . Zen_Cortext_Filter::REFUSAL_SENTINEL;

        if ($chat_uid !== '' && class_exists('Zen_Cortext_Chat_Events')) {
            Zen_Cortext_Chat_Events::insert(
                $chat_uid,
                'rate_limited',
                array('stage' => 'A', 'window' => '1h'),
                'system'
            );
        }

        nocache_headers();
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) { ob_end_flush(); }

        echo "data: " . wp_json_encode(array(
            'type'  => 'content_block_delta',
            'delta' => array('type' => 'text_delta', 'text' => $text),
        )) . "\n\n";
        echo "data: " . wp_json_encode(array('type' => 'message_stop')) . "\n\n";
        echo "data: [DONE]\n\n";
        @flush();
    }

    /**
     * Walk $messages backward and attach $enrichment to the most recent
     * user-role entry. Silent no-op if there is no user message yet.
     * Mutates by reference so callers can keep passing the same array.
     */
    public static function attach_enrichment_to_last_user(&$messages, $enrichment) {
        if (!is_array($messages) || empty($messages)) return;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (!isset($messages[$i]['role'])) continue;
            if ($messages[$i]['role'] === 'user') {
                $messages[$i]['enrichment'] = $enrichment;
                return;
            }
        }
    }

    private static function touch_visitor_last_seen($chat_uid) {
        global $wpdb;
        $table = $wpdb->prefix . 'zen_cortext_chats';
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET visitor_last_seen = %s WHERE chat_uid = %s AND deleted_at IS NULL",
            current_time('mysql'), $chat_uid
        ));
    }

    /**
     * Compute visitor status from visitor_last_seen timestamp.
     * Returns 'online', 'away', or 'offline'.
     */
    private static function visitor_status($visitor_last_seen) {
        if (empty($visitor_last_seen)) return 'offline';
        $age = time() - strtotime($visitor_last_seen);
        if ($age < 60)  return 'online';
        if ($age < 300) return 'away';
        return 'offline';
    }

    /**
     * Compute chat status fields shared by both list and detail endpoints.
     * Single source of truth for visitor_status and is_invited.
     */
    private static function chat_status_fields($row, $admin_user_id) {
        $visitor_status = self::visitor_status($row['visitor_last_seen'] ?? null);
        $invite_stale = (strtotime($row['updated_at']) < (time() - 1800));
        $is_invited = !$invite_stale
            && Zen_Cortext_Chat_Events::has_pending_invite($row['chat_uid'], $admin_user_id);
        return array(
            'visitor_status' => $visitor_status,
            'is_invited'     => $is_invited,
        );
    }

    private function purge_replay_cache($uid) {
        $url = rest_url('zen-cortext/v1/chat/' . $uid);
        wp_remote_request($url, array(
            'method'  => 'PURGE',
            'timeout' => 2,
            'blocking' => false,
        ));
    }

    private function sanitize_attribution($attribution) {
        $allowed = array(
            'referrer', 'landing_page',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'gclid', 'msclkid', 'fbc', 'fbp',
            // a-metrics first-party visitor id (from the `_amv_js` mirror cookie),
            // carried on the /send attribution payload to stitch the chat to the
            // visitor's a-metrics journey on ingest.
            'amv',
        );
        $clean = array();
        foreach ($allowed as $k) {
            if (isset($attribution[$k])) {
                $clean[$k] = sanitize_text_field((string) $attribution[$k]);
            }
        }
        return $clean;
    }

    private function client_ip() {
        // Honor common proxy headers but only the first hop, and only when present.
        foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$key]));
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '';
    }

    public function handle_artifact_chat($request) {
        $messages = $request->get_param('messages');
        if (!is_array($messages)) {
            return new WP_Error('zen_cortext_bad_request', 'messages array required', array('status' => 400));
        }

        $reference_ids = (array) $request->get_param('reference_artifacts');
        $reference_ids = array_values(array_filter(array_map('intval', $reference_ids)));
        $exclude_id    = (int) $request->get_param('exclude_id');
        $type          = trim((string) $request->get_param('type'));
        $title         = trim((string) $request->get_param('title'));

        nocache_headers();
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        Zen_Cortext_API::stream_artifact_chat($messages, $reference_ids, $exclude_id, $type, $title);

        exit;
    }

    /* ================================================================
       Live Chat Takeover — Public endpoints
       ================================================================ */

    public function handle_chat_status($request) {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $uid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $request->get_param('uid'));
        if ($uid === '') {
            return new WP_Error('zen_cortext_not_found', 'Chat not found', array('status' => 404));
        }

        // Touch visitor_last_seen so admin can see the visitor is still on the page.
        self::touch_visitor_last_seen($uid);

        $admin_id = Zen_Cortext_Takeover::is_attached($uid);
        $admin_name = null;
        if ($admin_id) {
            $admin_user = get_userdata($admin_id);
            if ($admin_user) $admin_name = $admin_user->display_name;
        }

        $invitable = array();
        if (get_option('zen_cortext_show_invite_buttons', false)) {
            $users = Zen_Cortext_Takeover::get_invitable_users();
            foreach ($users as $u) {
                $invitable[] = array(
                    'id'           => (int) $u->ID,
                    'display_name' => $u->display_name,
                    'status'       => Zen_Cortext_Takeover::get_effective_status($u->ID),
                );
            }
        }

        return rest_ensure_response(array(
            'admin_attached'  => $admin_id !== null,
            'admin_name'      => $admin_name,
            'invitable_users' => $invitable,
            'last_event_id'   => (int) Zen_Cortext_Chat_Events::latest_id($uid),
        ));
    }

    public function handle_chat_invite($request) {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $uid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $request->get_param('uid'));
        $user_id = (int) $request->get_param('user_id');

        if ($uid === '' || $user_id <= 0) {
            return new WP_Error('zen_cortext_bad_request', 'uid and user_id required', array('status' => 400));
        }

        // Owner-token gate enforced at registration (owner_token_permission_cb)
        // so a third party on a share link can't spam-page the team into
        // someone else's conversation.

        $result = Zen_Cortext_Takeover::invite($uid, $user_id);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array('invited' => true));
    }

    public function handle_chat_poll($request) {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $uid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $request->get_param('uid'));
        $since_id = (int) $request->get_param('since_id');

        if ($uid === '') {
            return new WP_Error('zen_cortext_not_found', 'Chat not found', array('status' => 404));
        }

        self::touch_visitor_last_seen($uid);

        // Auto-detach stale admin before returning events.
        Zen_Cortext_Takeover::auto_detach_stale($uid);

        $events = Zen_Cortext_Chat_Events::poll($uid, $since_id);

        // Decode JSON payloads for the client.
        foreach ($events as &$e) {
            $decoded = json_decode($e['payload'], true);
            $e['payload'] = is_array($decoded) ? $decoded : array();
        }
        unset($e);

        return rest_ensure_response(array('events' => $events));
    }

    /* ================================================================
       Live Chat Takeover — Admin auth endpoints
       ================================================================ */

    public function handle_livechat_auth_request($request) {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $email = sanitize_email((string) $request->get_param('email'));
        if ($email === '') {
            return new WP_Error('zen_cortext_bad_request', 'Email required', array('status' => 400));
        }

        $user = get_user_by('email', $email);
        if (!$user) {
            // Timing-safe: don't reveal whether the email exists.
            return rest_ensure_response(array('sent' => true));
        }

        $result = Zen_Cortext_Livechat_Auth::send_magic_link($user->ID);
        // Always return success — don't reveal whether the email worked.
        return rest_ensure_response(array('sent' => true));
    }

    public function handle_livechat_auth_verify($request) {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $token   = (string) $request->get_param('token');
        $user_id = (int) $request->get_param('user_id');

        $result = Zen_Cortext_Livechat_Auth::verify_token($token, $user_id);
        if (is_wp_error($result)) {
            return $result;
        }

        $session_token = Zen_Cortext_Livechat_Auth::issue_session($result);
        $user = get_userdata($result);

        // Auto-set status to online on login.
        Zen_Cortext_Takeover::set_user_status($result, 'online');

        return rest_ensure_response(array(
            'session_token' => $session_token,
            'user' => array(
                'id'           => (int) $user->ID,
                'display_name' => $user->display_name,
                'email'        => $user->user_email,
            ),
        ));
    }

    /* ================================================================
       Live Chat Takeover — Admin-authenticated endpoints
       ================================================================ */

    public function handle_livechat_chats($request) {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $user_id = $this->auth_and_touch();
        if (is_wp_error($user_id)) return $user_id;

        $result = Zen_Cortext_Chats::paged(array(
            'per_page'     => 50,
            'page'         => 1,
            'hide_deleted' => true,
        ));

        $chats = array();
        foreach ($result['rows'] as $r) {
            $msgs = json_decode($r['messages'], true);
            $first_msg = '';
            if (is_array($msgs)) {
                foreach ($msgs as $m) {
                    if (isset($m['role'], $m['content']) && $m['role'] === 'user') {
                        $first_msg = mb_substr((string) $m['content'], 0, 120);
                        break;
                    }
                }
            }
            $status = self::chat_status_fields($r, $user_id);

            $chats[] = array(
                'chat_uid'         => $r['chat_uid'],
                'message_count'    => (int) $r['message_count'],
                'first_message'    => $first_msg,
                'admin_user_id'    => $r['admin_user_id'] !== null ? (int) $r['admin_user_id'] : null,
                'admin_attached_at'=> $r['admin_attached_at'],
                'is_invited'       => $status['is_invited'],
                'visitor_status'   => $status['visitor_status'],
                'created_at'       => $r['created_at'],
                'updated_at'       => $r['updated_at'],
            );
        }

        return rest_ensure_response(array('chats' => $chats, 'total' => $result['total']));
    }

    public function handle_livechat_chat_detail($request) {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $user_id = $this->auth_and_touch();
        if (is_wp_error($user_id)) return $user_id;

        $uid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $request->get_param('uid'));
        $row = Zen_Cortext_Chats::get_by_uid($uid, true); // include_deleted for admin
        if (!$row) {
            return new WP_Error('zen_cortext_not_found', 'Chat not found', array('status' => 404));
        }

        $messages = json_decode($row['messages'], true);
        if (!is_array($messages)) $messages = array();

        $status = self::chat_status_fields($row, $user_id);

        // Return the latest event ID so the poll starts from here, not from 0.
        $last_event_id = (int) Zen_Cortext_Chat_Events::latest_id($row['chat_uid']);

        return rest_ensure_response(array(
            'chat_uid'         => $row['chat_uid'],
            'messages'         => $messages,
            'message_count'    => (int) $row['message_count'],
            'admin_user_id'    => $row['admin_user_id'] !== null ? (int) $row['admin_user_id'] : null,
            'admin_attached_at'=> $row['admin_attached_at'],
            'was_invited'      => $status['is_invited'],
            'visitor_status'   => $status['visitor_status'],
            'last_event_id'    => $last_event_id,
            'referrer'         => $row['referrer'],
            'landing_page'     => $row['landing_page'],
            'utm_source'       => $row['utm_source'],
            'utm_campaign'     => $row['utm_campaign'],
            'created_at'       => $row['created_at'],
            'updated_at'       => $row['updated_at'],
        ));
    }

    public function handle_livechat_attach($request) {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $user_id = $this->auth_and_touch();
        if (is_wp_error($user_id)) return $user_id;

        $uid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $request->get_param('uid'));
        $result = Zen_Cortext_Takeover::attach($uid, $user_id);
        if (is_wp_error($result)) return $result;

        return rest_ensure_response(array('attached' => true));
    }

    public function handle_livechat_detach($request) {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $user_id = $this->auth_and_touch();
        if (is_wp_error($user_id)) return $user_id;

        $uid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $request->get_param('uid'));
        Zen_Cortext_Takeover::detach($uid, $user_id);

        return rest_ensure_response(array('detached' => true));
    }

    public function handle_livechat_send($request) {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $user_id = $this->auth_and_touch();
        if (is_wp_error($user_id)) return $user_id;

        $uid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $request->get_param('uid'));
        $content = trim((string) $request->get_param('content'));
        if ($content === '') {
            return new WP_Error('zen_cortext_bad_request', 'Message content required', array('status' => 400));
        }

        $result = Zen_Cortext_Takeover::send_admin_message($uid, $user_id, $content);
        if (is_wp_error($result)) return $result;

        // Insert a heartbeat event so auto-detach knows the admin is active.
        Zen_Cortext_Chat_Events::insert($uid, 'heartbeat', array(), 'admin', $user_id);

        return rest_ensure_response(array('sent' => true));
    }

    public function handle_livechat_poll($request) {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $user_id = $this->auth_and_touch();
        if (is_wp_error($user_id)) return $user_id;

        $uid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $request->get_param('uid'));
        $since_id = (int) $request->get_param('since_id');

        // Record heartbeat only when this admin is attached to this chat.
        // Previously heartbeats fired on every poll even when just viewing,
        // which kept the auto-detach timer alive indefinitely.
        $chat = Zen_Cortext_Chats::get_by_uid($uid, true);
        if ($chat && (int) ($chat['admin_user_id'] ?? 0) === $user_id) {
            Zen_Cortext_Chat_Events::insert($uid, 'heartbeat', array(), 'admin', $user_id);
        }

        $events = Zen_Cortext_Chat_Events::poll($uid, $since_id);
        foreach ($events as &$e) {
            $decoded = json_decode($e['payload'], true);
            $e['payload'] = is_array($decoded) ? $decoded : array();
        }
        unset($e);

        return rest_ensure_response(array('events' => $events));
    }

    public function handle_livechat_set_status($request) {
        nocache_headers();
        $user_id = $this->auth_and_touch();
        if (is_wp_error($user_id)) return $user_id;

        $status = sanitize_key((string) $request->get_param('status'));
        Zen_Cortext_Takeover::set_user_status($user_id, $status);

        return rest_ensure_response(array(
            'status' => Zen_Cortext_Takeover::get_effective_status($user_id),
        ));
    }

    public function handle_livechat_get_status($request) {
        nocache_headers();
        $user_id = $this->auth_and_touch();
        if (is_wp_error($user_id)) return $user_id;

        // Touch last_seen on every status check (acts as heartbeat).
        Zen_Cortext_Takeover::touch_last_seen($user_id);

        // Return all invitable users with their statuses.
        $users = Zen_Cortext_Takeover::get_invitable_users();
        $result = array();
        foreach ($users as $u) {
            $result[] = array(
                'id'           => (int) $u->ID,
                'display_name' => $u->display_name,
                'status'       => Zen_Cortext_Takeover::get_effective_status($u->ID),
                'is_me'        => ((int) $u->ID === $user_id),
            );
        }

        return rest_ensure_response(array(
            'users'     => $result,
            'my_status' => Zen_Cortext_Takeover::get_effective_status($user_id),
        ));
    }

    public function handle_livechat_get_schedule($request) {
        nocache_headers();
        $user_id = $this->auth_and_touch();
        if (is_wp_error($user_id)) return $user_id;

        $raw = get_user_meta($user_id, Zen_Cortext_Takeover::META_SCHEDULE, true);

        return rest_ensure_response(array(
            'schedule'         => Zen_Cortext_Takeover::get_schedule($user_id),
            'available_zones'  => timezone_identifiers_list(),
            'is_configured'    => is_array($raw) && !empty($raw),
        ));
    }

    public function handle_livechat_set_schedule($request) {
        nocache_headers();
        $user_id = $this->auth_and_touch();
        if (is_wp_error($user_id)) return $user_id;

        $payload = $request->get_json_params();
        if (!is_array($payload)) $payload = $request->get_params();

        $saved = Zen_Cortext_Takeover::set_schedule($user_id, $payload);
        if (is_wp_error($saved)) return $saved;

        return rest_ensure_response(array(
            'schedule' => $saved,
            'status'   => Zen_Cortext_Takeover::get_effective_status($user_id),
        ));
    }

    public function handle_push_subscribe($request) {
        nocache_headers();
        $user_id = $this->auth_and_touch();
        if (is_wp_error($user_id)) return $user_id;

        $endpoint = esc_url_raw(trim((string) $request->get_param('endpoint')));
        $p256dh   = trim((string) $request->get_param('p256dh'));
        $auth     = trim((string) $request->get_param('auth'));

        $result = Zen_Cortext_Push::subscribe($user_id, $endpoint, $p256dh, $auth);
        if (is_wp_error($result)) return $result;

        return rest_ensure_response(array('subscribed' => true));
    }

    public function handle_push_vapid_key($request) {
        nocache_headers();
        $key = Zen_Cortext_Push::get_public_key_base64url();
        return rest_ensure_response(array('publicKey' => $key));
    }

    public function handle_livechat_ai_helper($request) {
        $user_id = $this->auth_and_touch();
        if (is_wp_error($user_id)) return $user_id;

        $chat_uid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $request->get_param('chat_uid'));
        $question = trim((string) $request->get_param('question'));

        if ($chat_uid === '' || $question === '') {
            return new WP_Error('zen_cortext_bad_request', 'chat_uid and question required', array('status' => 400));
        }

        $chat = Zen_Cortext_Chats::get_by_uid($chat_uid, true);
        if (!$chat) {
            return new WP_Error('zen_cortext_not_found', 'Chat not found', array('status' => 404));
        }

        $messages = json_decode($chat['messages'], true);
        if (!is_array($messages)) $messages = array();

        // Build the system prompt with full context (same as the public chat).
        $system = get_option('zen_cortext_system_prompt', Zen_Cortext_Defaults::system_prompt());
        $system .= Zen_Cortext_KB::build_context_block();
        $system .= Zen_Cortext_Artifacts::build_context_block();
        $system .= Zen_Cortext_API::build_team_expertise_block();
        $system .= "\n\n## INTERNAL CONTEXT — AI HELPER MODE (DRAFT REPLY)\n\n"
                 . "You are drafting a reply that the admin will send to the visitor. The admin will click 'Copy to response' and send your output verbatim (or lightly edited).\n\n"
                 . "STRICT OUTPUT RULES:\n"
                 . "- Output ONLY the message text to send to the visitor. Nothing else.\n"
                 . "- Write in first person, as the admin speaking directly to the visitor.\n"
                 . "- No meta-commentary, no preamble, no 'Here is a suggested reply', no analysis of what the visitor asked, no 'For the admin's reference', no breakdowns of possible follow-ups, no section headers, no bullet lists explaining categories of issues, no horizontal rules (---), no references to the admin by name.\n"
                 . "- If the admin's question is itself a directive (e.g. 'prepare the answer', 'draft a reply', 'respond to this'), treat it as 'write the reply now' — do not describe what you would do, just do it.\n"
                 . "- Keep it concise and in the engineering register defined above. A live chat reply, not a blog post.\n"
                 . "- If you genuinely need clarification from the admin before you can draft, ask ONE short question and stop. Otherwise, draft.\n";

        // Compose the transcript + admin question into a single user message.
        $transcript = "Current conversation:\n\n";
        foreach ($messages as $m) {
            $label = 'VISITOR';
            if (isset($m['role'])) {
                if ($m['role'] === 'assistant') $label = 'AI';
                elseif ($m['role'] === 'admin') $label = 'ADMIN' . (!empty($m['admin_name']) ? ' (' . $m['admin_name'] . ')' : '');
            }
            $transcript .= "[{$label}]: " . ($m['content'] ?? '') . "\n\n";
        }
        $transcript .= "---\n\nAdmin's question: " . $question;

        // Stream SSE via Claude Code CLI (Max-subscription path, no API tokens).
        nocache_headers();
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $on_event = function ($json) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $json is wp_json_encode output emitted as SSE; escaping would corrupt the SSE protocol.
            echo "data: " . $json . "\n\n";
            if (ob_get_level()) { @ob_flush(); }
            @flush();
        };
        $helper_messages = array(array('role' => 'user', 'content' => $transcript));
        Zen_Cortext_API::stream_internal(
            $system,
            $helper_messages,
            $on_event,
            array('timeout' => 180)
        );
        exit;
    }

    /**
     * Admin Brainstorm — streams Claude Opus 4.6 with extended thinking and
     * prompt caching on the static system context. The conversation is
     * persisted in wp_zen_cortext_brainstorm_chats so admins can come back
     * to past brainstorms. Auth is the route's permission_callback
     * (manage_options) plus the WP REST nonce on the client.
     */
    public function handle_admin_brainstorm($request) {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return new WP_Error('zen_cortext_unauthorized', 'Not logged in', array('status' => 401));
        }

        $messages_in = $request->get_param('messages');
        if (!is_array($messages_in) || empty($messages_in)) {
            return new WP_Error('zen_cortext_bad_request', 'messages array required', array('status' => 400));
        }

        $messages = self::sanitize_brainstorm_messages($messages_in);
        if (empty($messages)) {
            return new WP_Error('zen_cortext_bad_request', 'no valid messages', array('status' => 400));
        }

        // Resolve the chat_uid: client may supply one (continuing an existing
        // brainstorm), or we mint a new one. Existing uids are ownership-
        // checked — a stale or foreign uid silently becomes a new chat.
        $chat_uid_in = (string) $request->get_param('chat_uid');
        $chat_uid    = Zen_Cortext_Brainstorm_Chats::sanitize_uid($chat_uid_in);
        if ($chat_uid !== '') {
            $existing = Zen_Cortext_Brainstorm_Chats::get_for_user($chat_uid, $user_id);
            if (!$existing) {
                $chat_uid = ''; // not ours / not found — fall through to mint
            }
        }
        if ($chat_uid === '') {
            $chat_uid = Zen_Cortext_Brainstorm_Chats::generate_uid();
        }

        // Use the brainstorm-specific KB block (raw fallback + role/URL
        // metadata) — see class-zen-cortext-kb.php for the difference vs
        // the strict visitor-chat block.
        $brainstorm_prompt = Zen_Cortext_Defaults::brainstorm_system_prompt();
        $static_context    = Zen_Cortext_KB::build_brainstorm_context_block()
                           . Zen_Cortext_Artifacts::build_context_block()
                           . Zen_Cortext_API::build_team_expertise_block();

        $system = array(
            array(
                'type' => 'text',
                'text' => $brainstorm_prompt,
            ),
            array(
                'type'          => 'text',
                'text'          => $static_context,
                'cache_control' => array('type' => 'ephemeral'),
            ),
        );

        nocache_headers();
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        // Tell the client which uid this conversation has — the first event
        // out the door so even an immediate API error still gets the uid
        // recorded client-side. The frontend remembers this for follow-up
        // turns and for highlighting the active item in the sidebar.
        echo "data: " . wp_json_encode(array(
            'type'     => 'chat_meta',
            'chat_uid' => $chat_uid,
        )) . "\n\n";
        @flush();

        // Flatten the system blocks into a single string. The API path will
        // re-wrap with cache_control via the helper's cache_static option;
        // the CLI path takes plain text (CLI handles caching automatically).
        $system_combined = '';
        foreach ($system as $block) {
            if (isset($block['text'])) $system_combined .= $block['text'];
        }

        $on_event = function ($json) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $json is wp_json_encode output emitted as SSE; escaping would corrupt the SSE protocol.
            echo "data: " . $json . "\n\n";
            if (ob_get_level()) { @ob_flush(); }
            @flush();
        };

        // Resolve the model the admin picked in the UI. Anything not in the
        // allow-list falls back to BRAINSTORM_MODEL (Opus 4.6), so a missing
        // or spoofed value can never select something unexpected.
        $model_cfg = Zen_Cortext_API::brainstorm_model_config(
            (string) $request->get_param('model')
        );

        // Built-in backend is the streaming HTTP API; a companion CLI plugin
        // may intercept via the zen_cortext_stream_internal filter. The API
        // path uses the full model id (key-billed); the CLI companion reads
        // the model's alias from opts['cli_model'] (subscription-billed).
        $opts = array(
            'model'        => $model_cfg['id'],
            'max_tokens'   => $model_cfg['max_tokens'],
            'cache_static' => true,
            'timeout'      => 600,
            'cli_model'    => $model_cfg['cli'],
        );
        // Only request extended thinking for models that support it —
        // passing a thinking budget to a non-thinking model errors out.
        if (!empty($model_cfg['thinking'])) {
            $opts['thinking_budget'] = Zen_Cortext_API::BRAINSTORM_THINKING_BUDGET;
        }
        $result = Zen_Cortext_API::stream_internal(
            $system_combined,
            $messages,
            $on_event,
            $opts
        );

        // Persist the conversation if we got an actual response. Skip saving
        // on error so a failed turn doesn't replace working state.
        if ($result['ok'] && $result['text'] !== '') {
            $final   = $messages;
            $final[] = array('role' => 'assistant', 'content' => $result['text']);
            $save    = Zen_Cortext_Brainstorm_Chats::upsert($chat_uid, $user_id, $final);
            if (is_wp_error($save)) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic only; gated on operational error paths to land in the WP debug.log when WP_DEBUG_LOG is on.
                error_log('Zen Cortext brainstorm save failed: ' . $save->get_error_message());
            }
        }
        exit;
    }

    /**
     * List the current admin's saved brainstorm chats. Lightweight rows
     * for the sidebar — no messages payload, just titles + counts + dates.
     */
    public function handle_admin_brainstorm_list($request) {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return new WP_Error('zen_cortext_unauthorized', 'Not logged in', array('status' => 401));
        }
        $rows = Zen_Cortext_Brainstorm_Chats::list_for_user($user_id, 50);
        return rest_ensure_response(array('chats' => $rows));
    }

    /**
     * Load a single brainstorm chat by uid. 404 if missing or owned by
     * another user — same response either way, no information leak.
     */
    public function handle_admin_brainstorm_get($request) {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return new WP_Error('zen_cortext_unauthorized', 'Not logged in', array('status' => 401));
        }
        $uid = (string) $request->get_param('uid');
        $row = Zen_Cortext_Brainstorm_Chats::get_for_user($uid, $user_id);
        if (!$row) {
            return new WP_Error('zen_cortext_not_found', 'Chat not found', array('status' => 404));
        }
        $messages = json_decode($row['messages'], true);
        if (!is_array($messages)) $messages = array();
        return rest_ensure_response(array(
            'uid'           => $row['uid'],
            'title'         => $row['title'],
            'message_count' => (int) $row['message_count'],
            'created_at'    => $row['created_at'],
            'updated_at'    => $row['updated_at'],
            'messages'      => $messages,
        ));
    }

    /**
     * Hard-delete a brainstorm chat by uid. Idempotent: deleting a chat
     * that's already gone returns 200. Foreign-owned uids return 403.
     */
    public function handle_admin_brainstorm_delete($request) {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return new WP_Error('zen_cortext_unauthorized', 'Not logged in', array('status' => 401));
        }
        $uid = (string) $request->get_param('uid');
        $result = Zen_Cortext_Brainstorm_Chats::delete_for_user($uid, $user_id);
        if (is_wp_error($result)) {
            return new WP_Error('zen_cortext_forbidden', $result->get_error_message(), array('status' => 403));
        }
        return rest_ensure_response(array('deleted' => true));
    }

    /**
     * Strip incoming brainstorm messages to the shape Anthropic accepts:
     * only role=user|assistant, content as string. No length cap — brainstorm
     * is an internal admin-only surface; the model context window is the only
     * limit that matters here, and capping inputs silently truncated long
     * pasted briefs mid-sentence.
     */
    private static function sanitize_brainstorm_messages($messages_in) {
        $clean = array();
        foreach ($messages_in as $m) {
            if (!is_array($m)) continue;
            $role    = isset($m['role']) ? (string) $m['role'] : '';
            $content = isset($m['content']) ? (string) $m['content'] : '';
            if ($content === '') continue;
            if ($role !== 'user' && $role !== 'assistant') continue;
            $clean[] = array(
                'role'    => $role,
                'content' => $content,
            );
        }
        return $clean;
    }

    /**
     * Admin-only: regenerate the assistant system prompt by reading the
     * site's Knowledge Base. Non-streaming — returns the full proposed
     * prompt in a single JSON response that the admin UI shows in a
     * preview modal. The admin clicks Apply (writes to
     * zen_cortext_system_prompt) or Discard (closes modal, no change).
     *
     * Why non-streaming: this is a one-shot, ≤2k output rewrite the
     * admin sees once. Streaming adds complexity for no UX gain — the
     * admin watches a spinner for 5-15s either way.
     */
    public function handle_adapt_system_prompt($request) {
        $api_key = Zen_Cortext_API::api_key();
        if ($api_key === '') {
            return new WP_Error(
                'zc_no_api_key',
                'No API key configured. Set one on Settings → Connection before adapting prompts.',
                array('status' => 400)
            );
        }

        // Knowledge Base — use the brainstorm builder so Artifacts +
        // KB are both included. The adapter needs everything the
        // assistant will eventually see at runtime.
        if (!class_exists('Zen_Cortext_KB')) {
            return new WP_Error('zc_no_kb', 'Knowledge Base unavailable.', array('status' => 500));
        }
        $kb_block = Zen_Cortext_KB::build_brainstorm_context_block();
        if (trim((string) $kb_block) === '') {
            return new WP_Error(
                'zc_kb_empty',
                'Knowledge Base is empty. Sync at least a few pages or posts on Settings → Knowledge Base before adapting prompts.',
                array('status' => 400)
            );
        }

        $site_name = trim((string) get_bloginfo('name'));
        $site_desc = trim((string) get_bloginfo('description'));
        $site_url  = home_url('/');
        $current   = (string) get_option('zen_cortext_system_prompt', '');

        // Cap the KB block so we don't exceed Sonnet's context window on
        // a huge site. 80k chars ≈ 20k tokens — leaves headroom for the
        // meta-prompt + response.
        $kb_capped = self::cap_chars($kb_block, 80000);

        $meta_prompt = self::adapter_meta_prompt($site_name, $site_desc, $site_url);

        $user_block = "<knowledge_base>\n" . $kb_capped . "\n</knowledge_base>\n\n"
                    . "Generate the new system prompt now. Output ONLY the prompt text — no preamble, no markdown code fence, no commentary.";

        $payload = wp_json_encode(array(
            'model'      => (string) apply_filters(
                'zen_cortext_adapter_model',
                trim((string) get_option('zen_cortext_model', 'claude-sonnet-4-6'))
            ),
            'max_tokens' => 4096,
            'system'     => $meta_prompt,
            'messages'   => array(
                array('role' => 'user', 'content' => $user_block),
            ),
        ));

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'timeout' => 120,
            'headers' => array(
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
            ),
            'body'    => $payload,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('zc_api_error', 'API request failed: ' . $response->get_error_message(), array('status' => 502));
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code !== 200) {
            return new WP_Error('zc_api_status', sprintf('API returned %d: %s', $code, substr($body, 0, 500)), array('status' => 502));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || empty($decoded['content'])) {
            return new WP_Error('zc_api_parse', 'API response was not parseable.', array('status' => 502));
        }

        $proposed = '';
        foreach ((array) $decoded['content'] as $block) {
            if (isset($block['type'], $block['text']) && $block['type'] === 'text') {
                $proposed .= (string) $block['text'];
            }
        }
        $proposed = trim($proposed);

        // Strip a leading code fence if the model added one despite the
        // instruction. Belt-and-suspenders.
        $proposed = preg_replace('/^```[a-zA-Z]*\R/', '', $proposed);
        $proposed = preg_replace('/\R```\s*$/', '', $proposed);
        $proposed = trim($proposed);

        if ($proposed === '') {
            return new WP_Error('zc_empty_output', 'Adapter returned empty text.', array('status' => 502));
        }

        $usage = isset($decoded['usage']) && is_array($decoded['usage']) ? $decoded['usage'] : array();

        return rest_ensure_response(array(
            'current'      => $current,
            'proposed'     => $proposed,
            'site_name'    => $site_name,
            'kb_chars'     => strlen($kb_capped),
            'tokens_in'    => isset($usage['input_tokens'])  ? (int) $usage['input_tokens']  : null,
            'tokens_out'   => isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null,
            'model'        => isset($decoded['model']) ? (string) $decoded['model'] : '',
        ));
    }

    /**
     * The meta-prompt that turns the Knowledge Base into a new system
     * prompt. Output must follow the structure the plugin's runtime
     * expects (## headings, [chip] / [invite] / [contact_form] marker
     * syntax) — those are functional, not stylistic.
     */
    private static function adapter_meta_prompt($site_name, $site_desc, $site_url) {
        $site_name = $site_name !== '' ? $site_name : 'this site';
        $tagline_line = $site_desc !== '' ? "- Tagline: {$site_desc}\n" : '';

        return "You are rewriting the system prompt for a pre-sales chat consultant deployed on a specific WordPress site. Your output becomes the live system prompt the assistant uses for every visitor conversation.\n\n"
             . "SITE IDENTITY:\n"
             . "- Site name: {$site_name}\n"
             . $tagline_line
             . "- URL: {$site_url}\n\n"
             . "The visitor's `<knowledge_base>` block (in the user message) contains the site's published pages, posts, case studies, FAQs, and any internal knowledge artefacts. Read it carefully. Every claim about what {$site_name} does, who they serve, their stack/methodology/principles, and what's in or out of scope MUST be grounded in that block. Do not invent. Do not generalize. If the KB says it, you can say it. If the KB doesn't say it, leave it out.\n\n"
             . "Produce a complete new system prompt following EXACTLY this structure. The section headings are required — the plugin's runtime expects them. Do not invent new sections, do not skip required ones.\n\n"
             . "OPENING PARAGRAPH (no heading, 2-3 sentences):\n"
             . "Start with: \"You are a pre-sales consultant for {$site_name} — [one-line description of what they do, derived from the KB]. You were built on top of {$site_name}'s own published content using Claude. The Knowledge Base block appended below contains the site's pages, posts, case studies, FAQs, and any internal knowledge artefacts. Treat that block as your primary source of truth.\"\n\n"
             . "## Who you talk to\n"
             . "Describe the audience this business actually serves based on the KB. Be specific about who lands on the site, what they typically want, what their language register tends to be.\n\n"
             . "## Your job\n"
             . "What the assistant is here to do, framed as helping the visitor decide whether {$site_name} fits their situation. Honest, not sales.\n\n"
             . "## How you answer\n"
             . "Bullet list: tone, length, register, when to be technical vs plain, citing the KB. Match the vocabulary the KB itself uses.\n\n"
             . "## Framing\n"
             . "Why visitors should prefer the assistant's answer over generic AI advice (because it knows THIS specific business's approach).\n\n"
             . "## Hard rules\n"
             . "Bullet list. Standard rules: never quote prices/timelines unless explicitly in the KB, never promise outcomes, honest about scope boundaries (use what the KB says is in/out of scope), never invent visitor-specific facts, never reference being an AI beyond a brief first-message acknowledgement, never hardcode external contact methods.\n\n"
             . "## Follow-up chips\n"
             . "PRESERVE THIS SYNTAX EXACTLY (the plugin parses it):\n"
             . "End-of-message chips written as `[chip] Short specific thing` on their own lines, after a blank line, 2-4 per message, under 60 chars each, distinct paths not variations. Include 3 example chips that are specific to {$site_name} based on the KB (real likely visitor questions, not generic placeholders).\n\n"
             . "## Handing off to a real person\n"
             . "PRESERVE THESE MARKERS EXACTLY (the plugin parses them):\n"
             . "- `[invite: Firstname]` — live invite (push notification to team member; auto-falls-back to contact form if offline)\n"
             . "- `[contact_form]` or `[contact_form: Firstname]` — async lead-capture form\n"
             . "Explain when to use each. Use first names only. Do not bake specific team member names into rules — there's a separate team_expertise block appended at runtime that lists who's available.\n\n"
             . "## Closing the conversation\n"
             . "When to wrap up vs keep talking. Frame hand-offs as natural next steps, never as sales closes.\n\n"
             . "CONSTRAINTS:\n"
             . "- Output ONLY the system prompt text. No preamble, no explanation, no markdown code fence around the whole output.\n"
             . "- Use \"{$site_name}\" verbatim where you reference the business — do not paraphrase it.\n"
             . "- Ground EVERY claim about what the business does in the KB. If the KB says they specialise in X for Y audience, say that. If it doesn't say something, don't invent it.\n"
             . "- Do not reference specific team-member names in rules — use \"Firstname\" as a placeholder. The plugin appends a separate team list at runtime.\n"
             . "- 2500-4500 chars total. Comprehensive but not bloated.\n"
             . "- If the KB is sparse and you can't write substantive sections (especially \"Who you talk to\" or \"Hard rules\"), keep those sections brief and note in the section what's missing rather than padding with generic copy.\n";
    }

    /**
     * Trim a string to N characters at a word boundary if possible.
     * Used to bound the KB block sent into the adapter so very large
     * sites don't blow the context window.
     */
    private static function cap_chars($s, $max) {
        $s = (string) $s;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($s) <= $max) return $s;
            $cut = mb_substr($s, 0, $max);
        } else {
            if (strlen($s) <= $max) return $s;
            $cut = substr($s, 0, $max);
        }
        // Don't cut mid-sentence if there's a natural break in the last 200 chars.
        $tail = function_exists('mb_substr') ? mb_substr($cut, -200) : substr($cut, -200);
        $last_break = strrpos($tail, "\n\n");
        if ($last_break !== false) {
            $cut = function_exists('mb_substr')
                ? mb_substr($cut, 0, mb_strlen($cut) - 200 + $last_break)
                : substr($cut, 0, strlen($cut) - 200 + $last_break);
        }
        return $cut . "\n\n[... knowledge base truncated for adapter call ...]";
    }
}
