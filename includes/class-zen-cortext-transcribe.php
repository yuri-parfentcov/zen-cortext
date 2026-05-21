<?php
/**
 * Voice transcription bridge — Groq Whisper Large v3 Turbo (primary)
 * with optional OpenAI Whisper fallback. Both providers accept the same
 * file upload shape (OpenAI-compatible multipart/form-data), so the
 * only per-provider differences are the endpoint URL, the model name,
 * and the bearer-token header.
 *
 * Called from the /transcribe REST endpoint with the path of an uploaded
 * audio blob. Returns ['text' => '...', 'provider' => 'groq'|'openai']
 * on success or a WP_Error on failure. The REST handler decides how to
 * surface that to the client.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zen_Cortext_Transcribe {

    const GROQ_URL   = 'https://api.groq.com/openai/v1/audio/transcriptions';
    const OPENAI_URL = 'https://api.openai.com/v1/audio/transcriptions';
    const GROQ_MODEL   = 'whisper-large-v3-turbo';
    const OPENAI_MODEL = 'whisper-1';
    const TIMEOUT_SEC  = 30;

    /**
     * Try Groq first; if it errors (network or non-2xx) and an OpenAI
     * key is configured, retry against OpenAI. Either provider alone is
     * enough — only erroring out when *both* paths are unavailable.
     */
    public static function transcribe($tmp_path, $mime, $original_name) {
        if (!is_string($tmp_path) || $tmp_path === '' || !file_exists($tmp_path)) {
            return new WP_Error('zc_transcribe_no_file', 'Audio file missing.');
        }

        $groq_key   = trim((string) get_option('zen_cortext_groq_api_key', ''));
        $openai_key = trim((string) get_option('zen_cortext_openai_api_key', ''));

        if ($groq_key === '' && $openai_key === '') {
            return new WP_Error('zc_no_provider', 'No transcription provider is configured.');
        }

        // Groq path.
        if ($groq_key !== '') {
            $result = self::call_provider(
                self::GROQ_URL,
                self::GROQ_MODEL,
                $groq_key,
                $tmp_path,
                $mime,
                $original_name
            );
            if (!is_wp_error($result)) {
                return array('text' => $result, 'provider' => 'groq');
            }
            self::log_failover('groq', $result);
            // Fall through to OpenAI.
        }

        // OpenAI fallback path.
        if ($openai_key !== '') {
            $result = self::call_provider(
                self::OPENAI_URL,
                self::OPENAI_MODEL,
                $openai_key,
                $tmp_path,
                $mime,
                $original_name
            );
            if (!is_wp_error($result)) {
                return array('text' => $result, 'provider' => 'openai');
            }
            return $result;
        }

        // Groq was tried and failed; no OpenAI key to fall back to.
        return new WP_Error('zc_transcribe_failed', 'Groq transcription failed and no fallback provider is configured.');
    }

    /**
     * Upload an audio file to an OpenAI-compatible /audio/transcriptions
     * endpoint. Returns the transcribed text string on success, WP_Error
     * on transport or HTTP errors.
     *
     * WordPress's HTTP API has no native multipart helper, so the body
     * is assembled by hand: boundary string + form parts for `file`,
     * `model`, `response_format`, then the closing boundary.
     */
    private static function call_provider($url, $model, $api_key, $tmp_path, $mime, $original_name) {
        $bytes = @file_get_contents($tmp_path);
        if ($bytes === false) {
            return new WP_Error('zc_transcribe_read', 'Could not read audio file.');
        }

        $boundary = '----ZenCortextBoundary' . bin2hex(random_bytes(16));
        $safe_name = self::sanitize_upload_filename($original_name, $mime);
        $safe_mime = self::sanitize_mime($mime);

        $body  = '';
        // model
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"model\"\r\n\r\n";
        $body .= $model . "\r\n";
        // response_format
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"response_format\"\r\n\r\n";
        $body .= "json\r\n";
        // file
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$safe_name}\"\r\n";
        $body .= "Content-Type: {$safe_mime}\r\n\r\n";
        $body .= $bytes . "\r\n";
        // close
        $body .= "--{$boundary}--\r\n";

        $response = wp_remote_post($url, array(
            'timeout' => self::TIMEOUT_SEC,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
                'Accept'        => 'application/json',
            ),
            'body' => $body,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            $msg = self::extract_error_message($raw);
            return new WP_Error(
                'zc_transcribe_http_' . $code,
                'Transcription provider returned HTTP ' . $code . ($msg !== '' ? ': ' . $msg : '')
            );
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || !isset($json['text']) || !is_string($json['text'])) {
            return new WP_Error('zc_transcribe_parse', 'Transcription response missing "text" field.');
        }

        $text = trim($json['text']);
        if ($text === '') {
            return new WP_Error('zc_transcribe_empty', 'Transcription returned empty text.');
        }

        return $text;
    }

    private static function extract_error_message($raw) {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            if (isset($json['error']['message']) && is_string($json['error']['message'])) {
                return $json['error']['message'];
            }
            if (isset($json['message']) && is_string($json['message'])) return $json['message'];
        }
        return '';
    }

    private static function sanitize_upload_filename($name, $mime) {
        $name = (string) $name;
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        if ($name === '' || $name === false) {
            $ext  = self::ext_from_mime($mime);
            $name = 'audio' . ($ext !== '' ? '.' . $ext : '');
        }
        return $name;
    }

    private static function sanitize_mime($mime) {
        $mime = (string) $mime;
        return preg_match('#^audio/[a-z0-9.+\-]+$#i', $mime) ? $mime : 'audio/webm';
    }

    private static function ext_from_mime($mime) {
        $map = array(
            'audio/webm' => 'webm',
            'audio/ogg'  => 'ogg',
            'audio/mp4'  => 'm4a',
            'audio/mpeg' => 'mp3',
            'audio/wav'  => 'wav',
            'audio/x-wav'=> 'wav',
        );
        foreach ($map as $needle => $ext) {
            if (stripos((string) $mime, $needle) === 0) return $ext;
        }
        return 'webm';
    }

    /**
     * Surface failover events in debug.log so an admin can tell when
     * Groq is degraded and the OpenAI fallback is doing the work.
     * Silent in production unless WP_DEBUG_LOG is on.
     */
    private static function log_failover($provider, $err) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) return;
        $msg = is_wp_error($err) ? $err->get_error_message() : (string) $err;
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic only; gated on operational error paths to land in the WP debug.log when WP_DEBUG_LOG is on.
        error_log('[zen-cortext] Voice transcribe failover from ' . $provider . ': ' . $msg);
    }
}
