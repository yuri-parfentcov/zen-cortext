<?php
/**
 * API-key auth for the Apps Script ingestion endpoints.
 *
 * The Google Apps Script that syncs Google Ads data into this plugin can't
 * use WordPress cookie auth, so we issue a long-lived bearer token. The
 * raw token is shown to the admin exactly once at generation time; only
 * the SHA-256 hash is persisted, so a DB leak doesn't expose the key.
 *
 * Tokens are prefixed `zcas_` ("zen-cortext apps script") so they're
 * recognizable in logs and config files.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zen_Cortext_ApiKey_Auth {

    const OPT_HASH    = 'zen_cortext_apps_script_key_hash';
    const OPT_LAST4   = 'zen_cortext_apps_script_key_last4';
    const OPT_ROTATED = 'zen_cortext_apps_script_key_rotated_at';
    const TOKEN_PREFIX = 'zcas_';

    /**
     * Generate a new key, store its hash, return the raw token (caller
     * shows it to the admin once and then loses it).
     */
    public static function generate() {
        $raw  = self::TOKEN_PREFIX . wp_generate_password(48, false, false);
        $hash = hash('sha256', $raw);
        update_option(self::OPT_HASH,    $hash, false);
        update_option(self::OPT_LAST4,   substr($raw, -4), false);
        update_option(self::OPT_ROTATED, current_time('mysql'), false);
        return $raw;
    }

    public static function is_set() {
        return (string) get_option(self::OPT_HASH, '') !== '';
    }

    /**
     * Public-facing info for the admin "Ads Sync" panel — never exposes
     * anything that could be used to log in.
     */
    public static function info() {
        return array(
            'is_set'     => self::is_set(),
            'last4'      => (string) get_option(self::OPT_LAST4, ''),
            'rotated_at' => (string) get_option(self::OPT_ROTATED, ''),
        );
    }

    /**
     * Validate an incoming request. Returns true on success or WP_Error
     * with a 401 status. Use as a REST permission_callback.
     */
    public static function authenticate_apps_script($request = null) {
        if (!self::is_set()) {
            return new WP_Error('zen_cortext_apikey', 'Apps Script key not configured.', array('status' => 401));
        }
        $token = self::token_from_request();
        if ($token === '') {
            return new WP_Error('zen_cortext_apikey', 'Missing Authorization header.', array('status' => 401));
        }
        $expected = (string) get_option(self::OPT_HASH, '');
        $actual   = hash('sha256', $token);
        if ($expected === '' || !hash_equals($expected, $actual)) {
            return new WP_Error('zen_cortext_apikey', 'Invalid API key.', array('status' => 401));
        }
        return true;
    }

    /**
     * Read the bearer token from the Authorization header. Supports the
     * common server quirks (REDIRECT_HTTP_AUTHORIZATION on suexec, missing
     * getallheaders on some FastCGI setups).
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
