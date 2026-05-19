<?php
/**
 * Multi-key API authentication for the external read API (zc/v1 namespace).
 *
 * Each row in wp_zen_cortext_api_keys is one labeled key with scoped read
 * permissions and per-key rate limits. SHA-256 hash only — the raw token
 * is shown once at creation, never persisted. Bearer header auth, mirrors
 * the existing single-key Zen_Cortext_ApiKey_Auth pattern but supports
 * many labeled keys with scopes (read:chats, read:leads, etc.) instead of
 * one global key with full access.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zen_Cortext_Api_Keys {

    const TOKEN_PREFIX  = 'zcpa_';
    const PREFIX_DISPLAY_LEN = 12;          // first 12 chars shown in the admin list (e.g. "zcpa_a1b2c3d…")
    const TOUCH_THROTTLE_SEC = 60;          // skip writes to last_used_at within this window per key

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'zen_cortext_api_keys';
    }

    /**
     * Canonical scope catalog. Each scope unlocks one endpoint family.
     * Used by the admin UI to render checkboxes and by authenticate() to
     * validate that a key has the scope required by the route being hit.
     */
    public static function scope_catalog() {
        return array(
            'read:chats'       => array(
                'label'       => __('Read chats', 'zen-cortext'),
                'description' => __('GET /chats list and /chats/{id} detail with full transcript.', 'zen-cortext'),
            ),
            'read:leads'       => array(
                'label'       => __('Read leads', 'zen-cortext'),
                'description' => __('GET /leads — chats where the visitor submitted the contact form.', 'zen-cortext'),
            ),
            'read:stats'       => array(
                'label'       => __('Read stats', 'zen-cortext'),
                'description' => __('GET /chats/stats — aggregated counts by attribution + outcome + day.', 'zen-cortext'),
            ),
            'read:attribution' => array(
                'label'       => __('Read attribution rules', 'zen-cortext'),
                'description' => __('GET /attribution-rules — current campaign-rule configuration.', 'zen-cortext'),
            ),
            'read:knowledge'   => array(
                'label'       => __('Read knowledge base', 'zen-cortext'),
                'description' => __('GET /knowledge — KB item metadata (title, classification, source).', 'zen-cortext'),
            ),
            'read:sessions'    => array(
                'label'       => __('Read user sessions', 'zen-cortext'),
                'description' => __('GET /sessions list and /sessions/{id} detail — visitor sessions with full attribution map and attached chats.', 'zen-cortext'),
            ),
        );
    }

    /* ---------------- CRUD ---------------- */

    /**
     * Create a new key. Returns array {row: <stored row>, token: <raw token>}.
     * The raw token is only returned here — the admin must copy it before
     * dismissing the create-response panel. After that it cannot be recovered.
     */
    public static function create($label, $scopes, $rate_per_min, $rate_per_hour, $created_by_user_id = null) {
        global $wpdb;

        $label = trim(sanitize_text_field((string) $label));
        if ($label === '') {
            return new WP_Error('zen_cortext_api_keys', __('Label is required.', 'zen-cortext'));
        }

        $catalog = self::scope_catalog();
        $scopes  = is_array($scopes) ? $scopes : array();
        $clean_scopes = array();
        foreach ($scopes as $s) {
            $s = (string) $s;
            if (isset($catalog[$s])) $clean_scopes[] = $s;
        }
        $clean_scopes = array_values(array_unique($clean_scopes));
        if (empty($clean_scopes)) {
            return new WP_Error('zen_cortext_api_keys', __('At least one scope must be selected.', 'zen-cortext'));
        }

        $rate_per_min  = max(1, min(10000,  (int) $rate_per_min));
        $rate_per_hour = max(1, min(1000000,(int) $rate_per_hour));

        $raw    = self::TOKEN_PREFIX . wp_generate_password(48, false, false);
        $hash   = hash('sha256', $raw);
        $prefix = substr($raw, 0, self::PREFIX_DISPLAY_LEN);

        $now = current_time('mysql');
        $ok = $wpdb->insert(self::table(), array(
            'label'              => $label,
            'key_hash'           => $hash,
            'key_prefix'         => $prefix,
            'scopes'             => wp_json_encode($clean_scopes),
            'rate_per_min'       => $rate_per_min,
            'rate_per_hour'      => $rate_per_hour,
            'created_at'         => $now,
            'created_by_user_id' => $created_by_user_id !== null ? (int) $created_by_user_id : null,
        ));
        if ($ok === false) {
            return new WP_Error('zen_cortext_api_keys', 'DB insert failed: ' . $wpdb->last_error);
        }
        $id  = (int) $wpdb->insert_id;
        $row = self::get($id);
        return array(
            'row'   => $row,
            'token' => $raw,
        );
    }

    public static function get($id) {
        global $wpdb;
        $id = (int) $id;
        if ($id <= 0) return null;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d", $id
        ), ARRAY_A);
        return $row ? self::shape_row($row) : null;
    }

    /** Returns every key (active + revoked) for the admin list. */
    public static function list_all() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM " . self::table() . " ORDER BY revoked_at IS NULL DESC, created_at DESC, id DESC",
            ARRAY_A
        );
        return array_map(array(__CLASS__, 'shape_row'), $rows ?: array());
    }

    public static function revoke($id) {
        global $wpdb;
        $id = (int) $id;
        if ($id <= 0) return new WP_Error('zen_cortext_api_keys', 'Invalid id');
        $now = current_time('mysql');
        $wpdb->update(self::table(),
            array('revoked_at' => $now),
            array('id' => $id, 'revoked_at' => null) // only if not already revoked
        );
        return self::get($id);
    }

    /**
     * Decode the stored JSON scopes column into a plain array. Defensive
     * against legacy / malformed values — returns [] rather than throwing.
     */
    private static function shape_row($row) {
        if (!is_array($row)) return null;
        $scopes = array();
        if (!empty($row['scopes'])) {
            $decoded = json_decode((string) $row['scopes'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $s) $scopes[] = (string) $s;
            }
        }
        $row['id']                 = (int) $row['id'];
        $row['scopes']             = $scopes;
        $row['rate_per_min']       = (int) $row['rate_per_min'];
        $row['rate_per_hour']      = (int) $row['rate_per_hour'];
        $row['created_by_user_id'] = $row['created_by_user_id'] !== null ? (int) $row['created_by_user_id'] : null;
        // Never expose the hash in API responses or admin UI.
        unset($row['key_hash']);
        return $row;
    }

    /* ---------------- Auth ---------------- */

    /**
     * Validate the incoming request against the given required scope.
     * Returns true on success or WP_Error with an appropriate HTTP status.
     * Side effects on success: records rate-limit consumption, touches
     * last_used_at (throttled).
     *
     * Designed to be used inside REST permission_callback closures.
     */
    public static function authenticate($required_scope) {
        $token = self::token_from_request();
        if ($token === '') {
            return new WP_Error('zc_unauthorized', __('Missing Authorization header (Bearer).', 'zen-cortext'), array('status' => 401));
        }
        if (strpos($token, self::TOKEN_PREFIX) !== 0) {
            return new WP_Error('zc_unauthorized', __('Invalid token format.', 'zen-cortext'), array('status' => 401));
        }

        global $wpdb;
        $hash = hash('sha256', $token);
        $row  = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE key_hash = %s LIMIT 1",
            $hash
        ), ARRAY_A);
        if (!$row) {
            return new WP_Error('zc_unauthorized', __('Unknown API key.', 'zen-cortext'), array('status' => 401));
        }
        if (!empty($row['revoked_at'])) {
            return new WP_Error('zc_unauthorized', __('API key has been revoked.', 'zen-cortext'), array('status' => 401));
        }

        $row = self::shape_row($row);

        if ($required_scope !== '' && !in_array($required_scope, $row['scopes'], true)) {
            return new WP_Error(
                'zc_forbidden_scope',
                sprintf(
                    /* translators: %s: scope name like read:chats */
                    __('This key lacks the required scope: %s', 'zen-cortext'),
                    $required_scope
                ),
                array('status' => 403)
            );
        }

        $rl = self::check_rate_limit($row);
        if ($rl !== true) {
            return new WP_Error('zc_rate_limited', __('Rate limit exceeded.', 'zen-cortext'), array(
                'status'      => 429,
                'retry_after' => $rl,
            ));
        }

        self::touch_last_used((int) $row['id']);

        // Stash the authed key on the global request scope so handlers
        // can read row metadata (label, scopes) without re-authenticating.
        $GLOBALS['zen_cortext_api_key_row'] = $row;
        return true;
    }

    /**
     * Sliding-window rate limit on TWO horizons (per minute + per hour)
     * via WP transients. Returns true on allow, or the integer seconds
     * the caller should wait (= Retry-After).
     */
    private static function check_rate_limit($row) {
        $now = time();
        $id  = (int) $row['id'];

        $check = function ($window_sec, $max) use ($id, $now) {
            $key = 'zc_apirl_' . $id . '_' . $window_sec;
            $cutoff = $now - $window_sec;
            $timestamps = get_transient($key);
            if (!is_array($timestamps)) $timestamps = array();
            $timestamps = array_values(array_filter(
                $timestamps,
                function ($ts) use ($cutoff) { return (int) $ts > $cutoff; }
            ));
            if (count($timestamps) >= $max) {
                set_transient($key, $timestamps, $window_sec);
                $oldest = (int) $timestamps[0];
                return max(1, ($oldest + $window_sec) - $now);
            }
            $timestamps[] = $now;
            set_transient($key, $timestamps, $window_sec);
            return true;
        };

        $minute_check = $check(60,   (int) $row['rate_per_min']);
        if ($minute_check !== true) return $minute_check;
        $hour_check   = $check(3600, (int) $row['rate_per_hour']);
        if ($hour_check !== true)   return $hour_check;
        return true;
    }

    /**
     * Update last_used_at for the key, throttled per-key to avoid a
     * write storm under high QPS. The 60s window matches typical "this
     * key is in use" granularity an admin cares about.
     */
    private static function touch_last_used($id) {
        $id = (int) $id;
        if ($id <= 0) return;
        $tk = 'zc_apitouch_' . $id;
        if (get_transient($tk)) return;
        set_transient($tk, 1, self::TOUCH_THROTTLE_SEC);
        global $wpdb;
        $wpdb->update(self::table(), array('last_used_at' => current_time('mysql')), array('id' => $id));
    }

    /**
     * Bearer token extraction with the FastCGI / suexec quirks the
     * existing Apps Script auth class already documented. Inlined here
     * rather than depending on Zen_Cortext_ApiKey_Auth so the two key
     * systems stay independently maintainable.
     */
    public static function token_from_request() {
        $header = '';
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $k => $v) {
                    if (strtolower((string) $k) === 'authorization') {
                        $header = (string) $v;
                        break;
                    }
                }
            }
        }
        if ($header === '' && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = (string) $_SERVER['HTTP_AUTHORIZATION'];
        }
        if ($header === '' && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (stripos($header, 'Bearer ') === 0) {
            return trim(substr($header, 7));
        }
        return '';
    }
}
