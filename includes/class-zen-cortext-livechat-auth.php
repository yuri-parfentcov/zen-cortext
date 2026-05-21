<?php
/**
 * Magic-link authentication for the live chat admin page (/zen-livechat/).
 *
 * This is a standalone auth system separate from WP login — the live chat
 * page is a PWA that runs outside wp-admin. Admins authenticate via a
 * magic link sent to their email, which issues a session token stored in
 * the browser's localStorage and passed as Authorization: Bearer on every
 * REST call.
 *
 * Reuses the same patterns as the zen-magic-login plugin (token generation,
 * hashing, single-use consumption, rate limiting) but with separate user
 * meta keys and a different session mechanism (transients, not WP auth cookies).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zen_Cortext_Livechat_Auth {

    const TOKEN_EXPIRY    = 900;    // 15 minutes
    const SESSION_TTL     = 604800; // 7 days
    const RATE_LIMIT_MAX  = 3;      // per hour
    const META_TOKEN      = '_zen_cortext_livechat_token';
    const META_EXPIRY     = '_zen_cortext_livechat_token_expiry';
    const META_RATE       = '_zen_cortext_livechat_rate';
    const SESSION_PREFIX  = 'zen_cortext_livesess_';

    /**
     * Send a magic link to the user's email. Returns true or WP_Error.
     */
    public static function send_magic_link($user_id) {
        $user_id = (int) $user_id;
        $user = get_userdata($user_id);
        if (!$user) return new WP_Error('zen_cortext_auth', 'User not found.');

        // Only invitable users can log in to the live chat page.
        $invitable = (array) get_option('zen_cortext_invitable_users', array());
        if (!in_array($user_id, array_map('intval', $invitable), true)) {
            return new WP_Error('zen_cortext_auth', 'This user is not enabled for live chat.');
        }

        // Rate limit: max 3 requests/hour.
        if (!self::check_rate_limit($user_id)) {
            return new WP_Error('zen_cortext_auth', 'Too many login requests. Please wait and try again.');
        }

        // Generate a 64-char token, store its hash + expiry.
        $token = wp_generate_password(64, false, false);
        $hash  = hash('sha256', $token);

        update_user_meta($user_id, self::META_TOKEN, $hash);
        update_user_meta($user_id, self::META_EXPIRY, time() + self::TOKEN_EXPIRY);

        // Build the magic link URL.
        $livechat_url = home_url('/zen-livechat/');
        $magic_link = add_query_arg(array(
            'auth_token' => $token,
            'user_id'    => $user_id,
        ), $livechat_url);

        // Send the email.
        $site_name = get_bloginfo('name');
        $subject = sprintf('[%s] Your live chat login link', $site_name);
        $expiry_min = (int) ceil(self::TOKEN_EXPIRY / 60);
        $body = '<html><body style="font-family:sans-serif;font-size:14px;color:#333;">'
              . '<h2 style="color:#646B3A;">Live Chat Login</h2>'
              . '<p>Hi ' . esc_html($user->display_name) . ',</p>'
              . '<p>Click the button below to log in to the live chat console. This link expires in ' . $expiry_min . ' minutes and can only be used once.</p>'
              . '<p style="margin:24px 0;"><a href="' . esc_url($magic_link) . '" style="display:inline-block;padding:14px 28px;background:#646B3A;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;font-size:16px;">Open Live Chat</a></p>'
              . '<p style="color:#888;font-size:12px;">Or copy this link: ' . esc_url($magic_link) . '</p>'
              . '<p style="color:#888;font-size:12px;">If you didn\'t request this, ignore this email.</p>'
              . '</body></html>';

        $headers = array('Content-Type: text/html; charset=UTF-8');
        $sent = wp_mail($user->user_email, $subject, $body, $headers);

        if (!$sent) {
            return new WP_Error('zen_cortext_auth', 'Failed to send email. Check your mail configuration.');
        }

        self::record_rate_limit($user_id);
        return true;
    }

    /**
     * Verify a magic link token. Single-use — consumed on success.
     * Returns the WP user_id or WP_Error.
     */
    public static function verify_token($token, $user_id) {
        $user_id = (int) $user_id;
        $token   = trim((string) $token);

        if ($token === '' || $user_id <= 0) {
            return new WP_Error('zen_cortext_auth', 'Invalid token or user.');
        }

        $stored_hash = get_user_meta($user_id, self::META_TOKEN, true);
        $expiry      = (int) get_user_meta($user_id, self::META_EXPIRY, true);

        // Consume immediately to prevent replay.
        delete_user_meta($user_id, self::META_TOKEN);
        delete_user_meta($user_id, self::META_EXPIRY);

        if ($stored_hash === '' || $expiry === 0) {
            return new WP_Error('zen_cortext_auth', 'No pending login. Request a new link.');
        }

        if (time() > $expiry) {
            return new WP_Error('zen_cortext_auth', 'Link expired. Request a new one.');
        }

        // Timing-safe comparison.
        if (!hash_equals($stored_hash, hash('sha256', $token))) {
            return new WP_Error('zen_cortext_auth', 'Invalid token.');
        }

        // Verify user is still invitable.
        $invitable = (array) get_option('zen_cortext_invitable_users', array());
        if (!in_array($user_id, array_map('intval', $invitable), true)) {
            return new WP_Error('zen_cortext_auth', 'This user is no longer enabled for live chat.');
        }

        return $user_id;
    }

    /**
     * Issue a session token for the authenticated user. Stored as a WP
     * transient (hash → user_id) with an 8-hour TTL. The raw token is
     * returned to the client for localStorage storage.
     */
    public static function issue_session($user_id) {
        $user_id = (int) $user_id;
        $token = wp_generate_password(64, false, false);
        $hash  = hash('sha256', $token);
        set_transient(self::SESSION_PREFIX . $hash, $user_id, self::SESSION_TTL);
        return $token;
    }

    /**
     * Validate a session token (passed as Authorization: Bearer <token>).
     * Returns the user_id or false.
     */
    public static function validate_session($session_token) {
        $session_token = trim((string) $session_token);
        if ($session_token === '') return false;

        $hash = hash('sha256', $session_token);
        $user_id = get_transient(self::SESSION_PREFIX . $hash);
        if ($user_id === false) return false;

        // Verify user still exists and is invitable.
        $user = get_userdata((int) $user_id);
        if (!$user) return false;
        $invitable = (array) get_option('zen_cortext_invitable_users', array());
        if (!in_array((int) $user_id, array_map('intval', $invitable), true)) return false;

        return (int) $user_id;
    }

    /**
     * Extract session token from the Authorization header.
     * Returns the raw token string or empty string.
     */
    public static function get_token_from_request() {
        $header = '';
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            // getallheaders is case-insensitive on some servers.
            foreach ($headers as $k => $v) {
                if (strtolower($k) === 'authorization') {
                    $header = $v;
                    break;
                }
            }
        }
        if ($header === '' && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_AUTHORIZATION']));
        }
        if ($header === '' && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = sanitize_text_field(wp_unslash((string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']));
        }

        if (strpos($header, 'Bearer ') === 0) {
            return substr($header, 7);
        }
        return '';
    }

    /**
     * Convenience: validate the current request's session and return user_id or WP_Error.
     */
    public static function authenticate_request() {
        $token = self::get_token_from_request();
        if ($token === '') {
            return new WP_Error('zen_cortext_auth', 'Missing Authorization header.', array('status' => 401));
        }
        $user_id = self::validate_session($token);
        if ($user_id === false) {
            return new WP_Error('zen_cortext_auth', 'Invalid or expired session.', array('status' => 401));
        }
        return $user_id;
    }

    /* ---------- Rate limiting ---------- */

    private static function check_rate_limit($user_id) {
        $timestamps = get_user_meta($user_id, self::META_RATE, true);
        if (!is_array($timestamps)) return true;
        // Keep only timestamps from the last hour.
        $cutoff = time() - 3600;
        $recent = array_filter($timestamps, function ($ts) use ($cutoff) { return $ts > $cutoff; });
        return count($recent) < self::RATE_LIMIT_MAX;
    }

    private static function record_rate_limit($user_id) {
        $timestamps = get_user_meta($user_id, self::META_RATE, true);
        if (!is_array($timestamps)) $timestamps = array();
        $cutoff = time() - 3600;
        $timestamps = array_filter($timestamps, function ($ts) use ($cutoff) { return $ts > $cutoff; });
        $timestamps[] = time();
        update_user_meta($user_id, self::META_RATE, $timestamps);
    }
}
