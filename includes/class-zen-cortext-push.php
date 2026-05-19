<?php
/**
 * Web Push notification sender — pure PHP, no Composer dependencies.
 *
 * Uses VAPID (Voluntary Application Server Identification) for auth and
 * RFC 8291 / RFC 8188 for payload encryption. All crypto is handled by
 * PHP's built-in openssl + hash extensions.
 *
 * Requirements: PHP 7.4+, openssl with EC (prime256v1) support,
 * hash_hkdf(), openssl_pkey_derive().
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zen_Cortext_Push {

    const OPTION_PUBLIC  = 'zen_cortext_vapid_public';
    const OPTION_PRIVATE = 'zen_cortext_vapid_private';

    /* ================================================================
       VAPID key management
       ================================================================ */

    /**
     * Get or generate VAPID keys. Returns ['public' => base64url, 'private' => base64url].
     */
    public static function get_vapid_keys() {
        $pub  = get_option(self::OPTION_PUBLIC, '');
        $priv = get_option(self::OPTION_PRIVATE, '');
        if ($pub !== '' && $priv !== '') {
            return array('public' => $pub, 'private' => $priv);
        }
        return self::generate_vapid_keys();
    }

    /**
     * Generate a fresh VAPID key pair, persist to options, return it.
     */
    public static function generate_vapid_keys() {
        $key = openssl_pkey_new(array(
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ));
        if (!$key) {
            return new WP_Error('zen_cortext_push', 'Failed to generate EC key: ' . openssl_error_string());
        }

        $details = openssl_pkey_get_details($key);
        // Extract the raw 32-byte private key (d) and 65-byte uncompressed public key (04 || x || y).
        $d = $details['ec']['d'];
        $x = $details['ec']['x'];
        $y = $details['ec']['y'];

        // Public key = 04 || x || y (uncompressed point, 65 bytes).
        $pub_raw = "\x04" . str_pad($x, 32, "\x00", STR_PAD_LEFT) . str_pad($y, 32, "\x00", STR_PAD_LEFT);
        $priv_raw = str_pad($d, 32, "\x00", STR_PAD_LEFT);

        $pub_b64  = self::base64url_encode($pub_raw);
        $priv_b64 = self::base64url_encode($priv_raw);

        update_option(self::OPTION_PUBLIC, $pub_b64);
        update_option(self::OPTION_PRIVATE, $priv_b64);

        return array('public' => $pub_b64, 'private' => $priv_b64);
    }

    /**
     * Get the public key in the format needed by the browser's
     * pushManager.subscribe({ applicationServerKey: ... }).
     */
    public static function get_public_key_base64url() {
        $keys = self::get_vapid_keys();
        if (is_wp_error($keys)) return '';
        return $keys['public'];
    }

    /* ================================================================
       Subscription management (DB table wp_zen_cortext_push_subscriptions)
       ================================================================ */

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'zen_cortext_push_subscriptions';
    }

    public static function subscribe($user_id, $endpoint, $p256dh, $auth) {
        global $wpdb;
        $user_id  = (int) $user_id;
        $endpoint = esc_url_raw(trim((string) $endpoint));
        $p256dh   = trim((string) $p256dh);
        $auth     = trim((string) $auth);

        if ($user_id <= 0 || $endpoint === '' || $p256dh === '' || $auth === '') {
            return new WP_Error('zen_cortext_push', 'All subscription fields are required.');
        }

        // Upsert by user_id + endpoint.
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::table() . " WHERE user_id = %d AND endpoint = %s",
            $user_id, $endpoint
        ));

        if ($existing) {
            $wpdb->update(self::table(), array(
                'p256dh'     => $p256dh,
                'auth'       => $auth,
                'created_at' => current_time('mysql'),
            ), array('id' => (int) $existing));
            return (int) $existing;
        }

        $wpdb->insert(self::table(), array(
            'user_id'    => $user_id,
            'endpoint'   => $endpoint,
            'p256dh'     => $p256dh,
            'auth'       => $auth,
            'created_at' => current_time('mysql'),
        ));
        return (int) $wpdb->insert_id;
    }

    public static function unsubscribe($user_id, $endpoint) {
        global $wpdb;
        $wpdb->delete(self::table(), array(
            'user_id'  => (int) $user_id,
            'endpoint' => $endpoint,
        ));
    }

    /**
     * Get all push subscriptions for a user.
     */
    public static function get_subscriptions($user_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE user_id = %d",
            (int) $user_id
        ), ARRAY_A);
    }

    /* ================================================================
       Send a push notification to all subscriptions for a user.
       ================================================================ */

    /**
     * Send a push notification. Returns the number of successful deliveries.
     * Never throws — push failure is silent and must not break the caller
     * (e.g. the invite flow where email is the primary notification).
     */
    public static function send($user_id, $title, $body, $url = '', $tag = '') {
        try {
            $subs = self::get_subscriptions($user_id);
            if (empty($subs)) return 0;

            $keys = self::get_vapid_keys();
            if (is_wp_error($keys)) return 0;

            $payload = wp_json_encode(array(
                'title' => (string) $title,
                'body'  => (string) $body,
                'url'   => (string) $url,
                'tag'   => (string) $tag,
            ));

            $success = 0;
            foreach ($subs as $sub) {
                $result = self::send_to_endpoint(
                    $sub['endpoint'],
                    self::base64url_decode($sub['p256dh']),
                    self::base64url_decode($sub['auth']),
                    $payload,
                    $keys
                );
                if ($result === true) {
                    $success++;
                } else {
                    // If the endpoint is gone (410 Gone or 404), remove the subscription.
                    if (is_int($result) && ($result === 404 || $result === 410)) {
                        self::unsubscribe($user_id, $sub['endpoint']);
                    }
                }
            }
            return $success;
        } catch (\Throwable $e) {
            // Push is best-effort — never break the caller.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Zen Cortext Push error: ' . $e->getMessage());
            }
            return 0;
        }
    }

    /* ================================================================
       Low-level: encrypt payload + send via VAPID-signed HTTP POST
       ================================================================ */

    /**
     * Send an encrypted push message to a single endpoint.
     * Returns true on 2xx, HTTP status code on failure.
     */
    private static function send_to_endpoint($endpoint, $client_public_key, $client_auth, $payload, $vapid_keys) {
        // 1. Generate ephemeral ECDH key pair.
        $local_key = openssl_pkey_new(array(
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ));
        if (!$local_key) return false;

        $local_details = openssl_pkey_get_details($local_key);
        $local_pub = "\x04"
            . str_pad($local_details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            . str_pad($local_details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        // 2. Derive shared secret via ECDH.
        // Reconstruct the client's public key as a PEM for openssl_pkey_derive.
        $client_pem = self::ec_public_key_to_pem($client_public_key);
        if (!$client_pem) return false;

        $client_key_res = openssl_pkey_get_public($client_pem);
        if (!$client_key_res) return false;

        // PHP 8.x: openssl_pkey_derive returns the shared secret directly.
        $shared_secret = openssl_pkey_derive($client_key_res, $local_key);
        if ($shared_secret === false || $shared_secret === '') return false;

        // 3. RFC 8291 key derivation (aesgcm content encoding).
        //
        // Step A: Derive the pseudo-random key (PRK) from the ECDH shared secret
        //         and the client's auth secret.
        //   hash_hkdf(algo, IKM, length, info, salt)
        //   IKM  = ecdh shared secret
        //   salt = client auth secret (from push subscription)
        //   info = "Content-Encoding: auth\0"
        $auth_info = "Content-Encoding: auth\x00";
        $prk_key = hash_hkdf('sha256', $shared_secret, 32, $auth_info, $client_auth);

        // Step B: Generate a random 16-byte salt. This salt MUST be used in both
        //         the key derivation AND the Encryption header — using different
        //         values means the browser can't decrypt.
        $salt = random_bytes(16);

        // Step C: Build the context for CEK and nonce derivation.
        //   context = "P-256" || 0x00 || len(client_pub) || client_pub || len(local_pub) || local_pub
        $context = "P-256\x00"
            . pack('n', 65) . $client_public_key
            . pack('n', 65) . $local_pub;

        $cek_info   = "Content-Encoding: aesgcm\x00" . $context;
        $nonce_info = "Content-Encoding: nonce\x00" . $context;

        // Step D: Derive the content encryption key (16 bytes) and nonce (12 bytes).
        //   IKM  = PRK from step A
        //   salt = the random salt from step B
        $cek   = hash_hkdf('sha256', $prk_key, 16, $cek_info, $salt);
        $nonce = hash_hkdf('sha256', $prk_key, 12, $nonce_info, $salt);

        // 4. Encrypt payload with AES-128-GCM.
        // Pad the payload per aesgcm: 2-byte big-endian padding length (0) + content.
        $padded = "\x00\x00" . $payload;
        $tag_out = '';
        $encrypted = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag_out, '', 16);
        if ($encrypted === false) return false;

        $body = $encrypted . $tag_out;

        // 5. Build VAPID Authorization header (JWT signed with the VAPID private key).
        $audience = self::get_audience($endpoint);
        $jwt = self::create_vapid_jwt($audience, $vapid_keys);
        if (!$jwt) return false;

        // 6. Send HTTP POST to the push endpoint.
        $response = wp_remote_post($endpoint, array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type'     => 'application/octet-stream',
                'Content-Encoding' => 'aesgcm',
                'Content-Length'   => strlen($body),
                'Authorization'    => 'vapid t=' . $jwt . ',k=' . $vapid_keys['public'],
                'Crypto-Key'       => 'dh=' . self::base64url_encode($local_pub) . ';p256ecdsa=' . $vapid_keys['public'],
                'Encryption'       => 'salt=' . self::base64url_encode($salt),
                'TTL'              => '86400',
                'Urgency'          => 'high',
            ),
            'body'    => $body,
        ));

        if (is_wp_error($response)) return false;

        $code = (int) wp_remote_retrieve_response_code($response);
        return ($code >= 200 && $code < 300) ? true : $code;
    }

    /**
     * Create a VAPID JWT (ES256 signed) for the given audience (push service origin).
     */
    private static function create_vapid_jwt($audience, $vapid_keys) {
        $header = self::base64url_encode(wp_json_encode(array('typ' => 'JWT', 'alg' => 'ES256')));

        // VAPID 'sub' is the operator contact the push service can reach
        // about misbehaving subscriptions. Derive from site admin_email
        // so push works correctly on any install. Filter override lets
        // hosts pin a specific contact regardless of WP settings.
        $admin_email = trim((string) get_option('admin_email', ''));
        $contact = $admin_email !== ''
            ? 'mailto:' . $admin_email
            : 'mailto:' . get_bloginfo('name') . '@' . (string) parse_url(home_url(), PHP_URL_HOST);
        $contact = (string) apply_filters('zen_cortext_vapid_contact', $contact);

        $payload = self::base64url_encode(wp_json_encode(array(
            'aud' => $audience,
            'exp' => time() + 43200, // 12 hours
            'sub' => $contact,
        )));

        $signing_input = $header . '.' . $payload;

        // Reconstruct the VAPID private key as PEM for signing.
        $priv_raw = self::base64url_decode($vapid_keys['private']);
        $pub_raw  = self::base64url_decode($vapid_keys['public']);
        $pem = self::ec_private_key_to_pem($priv_raw, $pub_raw);
        if (!$pem) return false;

        $key = openssl_pkey_get_private($pem);
        if (!$key) return false;

        $signature = '';
        $ok = openssl_sign($signing_input, $signature, $key, OPENSSL_ALGO_SHA256);
        if (!$ok || $signature === '') return false;

        // openssl_sign returns DER-encoded signature. Convert to raw r||s (64 bytes).
        $raw_sig = self::der_to_raw_ec_signature($signature);
        if (!$raw_sig) return false;

        return $signing_input . '.' . self::base64url_encode($raw_sig);
    }

    /* ================================================================
       Crypto helpers
       ================================================================ */

    /**
     * Convert an uncompressed EC public key (65 bytes: 04 || x || y) to PEM.
     */
    private static function ec_public_key_to_pem($raw) {
        if (strlen($raw) !== 65 || $raw[0] !== "\x04") return false;
        // DER structure for a P-256 public key.
        $der = "\x30\x59"  // SEQUENCE 89 bytes
             . "\x30\x13"  // SEQUENCE 19 bytes (AlgorithmIdentifier)
             . "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"  // OID ecPublicKey
             . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"  // OID prime256v1
             . "\x03\x42\x00" . $raw;  // BIT STRING 66 bytes (0x00 padding + 65 bytes key)
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Convert raw private key (32 bytes d) + raw public key (65 bytes 04||x||y) to PEM.
     */
    private static function ec_private_key_to_pem($priv_raw, $pub_raw) {
        if (strlen($priv_raw) !== 32) return false;
        // DER for EC private key (SEC 1 / RFC 5915).
        $priv_der = "\x04\x20" . $priv_raw;
        $curve_oid = "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // [0] prime256v1
        $pub_bits = "\xa1\x44\x03\x42\x00" . $pub_raw; // [1] BIT STRING

        $inner = "\x02\x01\x01" . $priv_der . $curve_oid . $pub_bits;
        $inner_len = strlen($inner);
        $der = "\x30" . chr($inner_len) . $inner;

        return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    }

    /**
     * Convert DER-encoded ECDSA signature to raw r||s (64 bytes).
     * DER: 30 <len> 02 <rlen> <r> 02 <slen> <s>
     */
    private static function der_to_raw_ec_signature($der) {
        if (strlen($der) < 8) return false;
        $offset = 2; // skip 30 <len>
        if (ord($der[1]) > 127) $offset++; // long form length

        // Read r
        if (ord($der[$offset]) !== 0x02) return false;
        $rlen = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rlen);
        $offset += 2 + $rlen;

        // Read s
        if (ord($der[$offset]) !== 0x02) return false;
        $slen = ord($der[$offset + 1]);
        $s = substr($der, $offset + 2, $slen);

        // Strip leading zeros and pad to 32 bytes.
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");
        $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }

    /**
     * Extract the audience (origin) from a push endpoint URL.
     */
    private static function get_audience($endpoint) {
        $parts = parse_url($endpoint);
        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
    }

    private static function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64url_decode($data) {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
