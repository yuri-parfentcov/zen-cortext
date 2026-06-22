<?php
/**
 * Attribution context lookup + system-prompt injection.
 *
 * Visitor arrives with UTM tags / gclid / referrer. We score every enabled
 * row in wp_zen_cortext_attribution_contexts by specificity: utm_campaign
 * (8) > utm_medium (4) > utm_source (2) = referrer_host (2) > gclid_present
 * (1). Highest score wins; row.priority breaks ties; updated_at is the
 * final tiebreaker. Specificity beats priority by design — a row keyed to
 * a specific campaign should always beat a generic gclid catch-all, even
 * if the catch-all has a higher priority.
 *
 * Manual table only — Apps Script never writes here. Joined to
 * wp_zen_cortext_ads_campaigns at lookup time on
 * attribution_contexts.match_utm_campaign ↔ ads_campaigns.campaign_name
 * (case-insensitive) so the prompt block can include live ad copy.
 */


/*
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 *
 * Justification: this file is a data-access layer for plugin-owned tables
 * (wp_zen_cortext_*). Each query is built around a $wpdb->prefix . 'zen_cortext_…'
 * table name, which cannot be passed via a %s placeholder ($wpdb->prepare does
 * not bind identifiers). Every user-controlled value in WHERE / VALUES /
 * SET clauses goes through $wpdb->prepare(). Admin analytics aggregates
 * (SUM / COUNT / CASE over plugin-owned tables) are real-time and not
 * candidates for the WP_Object_Cache.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zen_Cortext_Attribution {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'zen_cortext_attribution_contexts';
    }

    /**
     * Match a visitor's attribution against enabled rules. Returns the
     * winning row (associative array) or null.
     */
    public static function resolve($attribution) {
        global $wpdb;
        if (!is_array($attribution)) return null;

        $rows = $wpdb->get_results(
            "SELECT * FROM " . self::table() . " WHERE enabled = 1",
            ARRAY_A
        );
        if (!$rows) return null;

        $utm_source   = self::lower($attribution['utm_source']   ?? '');
        $utm_medium   = self::lower($attribution['utm_medium']   ?? '');
        $utm_campaign = self::lower($attribution['utm_campaign'] ?? '');
        $referrer     = (string) ($attribution['referrer'] ?? '');
        $referrer_host = self::host_of($referrer);
        // Pre-compute the host+path form once per request — that's the
        // canonical surface the referrer matcher tests against (no scheme,
        // no www., no query, no fragment) so admins write rules against
        // a stable shape regardless of what variant the visitor arrived on.
        $referrer_canon = self::canonical_url($referrer);
        $gclid        = (string) ($attribution['gclid'] ?? '');

        $best = null;
        foreach ($rows as $row) {
            $score = 0;

            if (!self::multi_value_match($row['match_utm_campaign'] ?? '', $utm_campaign, $matched)) continue;
            if ($matched) $score += 8;

            if (!self::multi_value_match($row['match_utm_medium'] ?? '', $utm_medium, $matched)) continue;
            if ($matched) $score += 4;

            if (!self::multi_value_match($row['match_utm_source'] ?? '', $utm_source, $matched)) continue;
            if ($matched) $score += 2;

            if (!self::referrer_pattern_match($row['match_referrer_host'] ?? '', $referrer_host, $referrer_canon, $matched)) continue;
            if ($matched) $score += 2;

            if ((int) $row['match_gclid_present'] === 1) {
                if ($gclid === '') continue;
                $score += 1;
            }

            // A row with no matchers at all is a wildcard catch-all (score 0).
            if ($best === null) { $best = array('row' => $row, 'score' => $score); continue; }

            if ($score > $best['score']) {
                $best = array('row' => $row, 'score' => $score);
                continue;
            }
            if ($score === $best['score']) {
                $a_pri = (int) $row['priority'];
                $b_pri = (int) $best['row']['priority'];
                if ($a_pri > $b_pri) {
                    $best = array('row' => $row, 'score' => $score);
                    continue;
                }
                if ($a_pri === $b_pri && strtotime($row['updated_at']) > strtotime($best['row']['updated_at'])) {
                    $best = array('row' => $row, 'score' => $score);
                }
            }
        }

        return $best ? $best['row'] : null;
    }

    /**
     * Return the system-prompt block to append. Three sources combined:
     *
     *   1. Visitor traffic — every captured UTM/click-id/referrer field,
     *      always emitted when present. Most useful field here is
     *      utm_term, which on Google Ads typically carries the actual
     *      keyword that triggered the ad.
     *   2. Admin-curated context — the matched rule's context_text.
     *   3. Synced Google Ads metadata — looked up by the visitor's actual
     *      utm_campaign (with the rule's match_utm_campaign as fallback)
     *      so unconfigured-but-recognized campaigns still get factual
     *      context. Manual rule isn't required to surface this.
     *
     * Returns '' only when there's nothing useful to add (no UTMs, no
     * matched rule, no synced metadata).
     */
    public static function build_system_block($attribution) {
        if (!is_array($attribution)) $attribution = array();

        $row     = self::resolve($attribution);
        $traffic = self::traffic_summary($attribution);

        // Look up synced GAds metadata. Prefer the visitor's ACTUAL
        // utm_campaign — that's the live signal. Fall back to the matched
        // rule's match_utm_campaign for cases where the rule was keyed to
        // a particular campaign but the visitor's tag is in a different
        // shape (rare, but possible).
        $ads = null;
        if (class_exists('Zen_Cortext_Ads_Campaigns')) {
            $visitor_campaign = trim((string) ($attribution['utm_campaign'] ?? ''));
            if ($visitor_campaign !== '') {
                $ads = Zen_Cortext_Ads_Campaigns::find_by_id_or_name($visitor_campaign);
            }
            if (!$ads && $row) {
                $rule_campaign = trim((string) $row['match_utm_campaign']);
                if ($rule_campaign !== '' && $rule_campaign !== $visitor_campaign) {
                    $ads = Zen_Cortext_Ads_Campaigns::find_by_id_or_name($rule_campaign);
                }
            }
        }

        if ($traffic === '' && !$row && !$ads) return '';

        $lines = array();
        $lines[] = "\n\n<visitor_attribution>";

        if ($traffic !== '') {
            $lines[] = "This visitor arrived via:";
            $lines[] = $traffic;
        }

        if ($row) {
            $context = trim((string) $row['context_text']);
            if ($context !== '') {
                $lines[] = "";
                $lines[] = "CAMPAIGN CONTEXT (admin-curated):";
                $lines[] = $context;
            }
        }

        if ($ads) {
            $headlines = self::decode_string_list($ads['top_headlines'] ?? '');
            $keywords  = self::decode_string_list($ads['top_keywords']  ?? '');
            $lines[] = "";
            $lines[] = "CAMPAIGN METADATA (from Google Ads):";
            if (isset($ads['type']) && $ads['type'] === 'group') {
                $lines[] = "Ad group: " . (string) $ads['ad_group_name']
                         . " (id " . (string) $ads['ad_group_id'] . ")"
                         . " — Campaign: " . (string) $ads['campaign_name']
                         . " (id " . (string) $ads['campaign_id'] . ")";
            } else {
                $lines[] = "Campaign: " . (string) $ads['campaign_name']
                         . " (id " . (string) $ads['campaign_id'] . ")";
            }
            if ($headlines) {
                $lines[] = "Top headlines shown to this visitor:";
                foreach (array_slice($headlines, 0, 8) as $h) {
                    $lines[] = "- " . $h;
                }
            }
            if ($keywords) {
                $lines[] = "Main keywords this campaign targets:";
                foreach (array_slice($keywords, 0, 12) as $k) {
                    $lines[] = "- " . $k;
                }
            }
        }

        $lines[] = "</visitor_attribution>";
        return implode("\n", $lines);
    }

    /**
     * Payload for the public GET /attribution-context endpoint. Returns
     * matched flag, label (debug only), invite_message, chips array, and
     * rule_id (int when matched, null otherwise — used by the client to
     * slot saved chat uids per attribution rule in localStorage).
     */
    public static function get_invite_payload($attribution) {
        $row = self::resolve($attribution);
        if (!$row) {
            return array(
                'matched'        => false,
                'label'          => '',
                'invite_message' => '',
                'chips'          => array(),
                'intro_card'     => null,
                'rule_id'        => null,
            );
        }
        $chips      = self::decode_chips((string) ($row['chips_json'] ?? ''));
        $intro_card = self::decode_intro_card((string) ($row['intro_card_json'] ?? ''));
        return array(
            'matched'        => true,
            'label'          => (string) $row['label'],
            'invite_message' => (string) $row['invite_message'],
            'chips'          => $chips,
            'intro_card'     => $intro_card,
            'rule_id'        => isset($row['id']) ? (int) $row['id'] : null,
        );
    }

    /**
     * Return the survey_id attached to the matched rule, or 0 if none.
     * Caller is expected to fall back to the global default if 0.
     */
    public static function active_survey_id($attribution) {
        $row = self::resolve($attribution);
        if (!$row) return 0;
        return (int) ($row['survey_id'] ?? 0);
    }

    /* ---------------- CRUD ---------------- */

    public static function list_all() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, label, match_utm_source, match_utm_medium, match_utm_campaign,
                    match_referrer_host, match_gclid_present, priority, enabled,
                    survey_id, created_at, updated_at
             FROM " . self::table() . "
             ORDER BY enabled DESC, priority DESC, updated_at DESC",
            ARRAY_A
        );
    }

    public static function get($id) {
        global $wpdb;
        $id = (int) $id;
        if ($id <= 0) return null;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d",
            $id
        ), ARRAY_A);
    }

    /**
     * Insert when $id is 0/empty, update otherwise. Returns the row id on
     * success, WP_Error on validation failure.
     */
    public static function save($id, $data) {
        global $wpdb;

        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            return new WP_Error('zen_cortext_attribution', 'Label is required.');
        }

        $survey_id = (int) ($data['survey_id'] ?? 0);

        $clean = array(
            'label'               => $label,
            'match_utm_source'    => self::nullable_csv($data['match_utm_source']    ?? null, 255),
            'match_utm_medium'    => self::nullable_csv($data['match_utm_medium']    ?? null, 255),
            'match_utm_campaign'  => self::nullable_csv($data['match_utm_campaign']  ?? null, 255),
            'match_referrer_host' => self::nullable_referrer_pattern($data['match_referrer_host'] ?? null, 1024),
            'match_gclid_present' => !empty($data['match_gclid_present']) ? 1 : 0,
            'priority'            => max(-32768, min(32767, (int) ($data['priority'] ?? 0))),
            'enabled'             => !empty($data['enabled']) ? 1 : 0,
            'context_text'        => (string) ($data['context_text']   ?? ''),
            'invite_message'      => (string) ($data['invite_message'] ?? ''),
            'chips_json'          => self::normalize_chips_json($data['chips_json'] ?? ''),
            'intro_card_json'     => self::normalize_intro_card_json($data['intro_card_json'] ?? ''),
            'survey_id'           => $survey_id > 0 ? $survey_id : null,
            'updated_at'          => current_time('mysql'),
        );

        $id = (int) $id;
        if ($id > 0) {
            $existing = self::get($id);
            if (!$existing) {
                return new WP_Error('zen_cortext_attribution', 'Row not found.');
            }
            $wpdb->update(self::table(), $clean, array('id' => $id));
            return $id;
        }

        $clean['created_at'] = current_time('mysql');
        $ok = $wpdb->insert(self::table(), $clean);
        if ($ok === false) {
            return new WP_Error('zen_cortext_attribution', 'DB insert failed: ' . $wpdb->last_error);
        }
        return (int) $wpdb->insert_id;
    }

    public static function delete($id) {
        global $wpdb;
        $id = (int) $id;
        if ($id <= 0) return new WP_Error('zen_cortext_attribution', 'Invalid id');
        $wpdb->delete(self::table(), array('id' => $id), array('%d'));
        return true;
    }

    /* ---------------- helpers ---------------- */

    private static function lower($s) {
        $s = trim((string) $s);
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    private static function host_of($url) {
        $url = trim((string) $url);
        if ($url === '') return '';
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') return '';
        $host = self::lower($host);
        if (strpos($host, 'www.') === 0) $host = substr($host, 4);
        return $host;
    }

    private static function nullable_str($value, $maxlen) {
        if ($value === null) return null;
        $value = trim((string) $value);
        if ($value === '') return null;
        if (function_exists('mb_substr') && function_exists('mb_strlen')) {
            if (mb_strlen($value) > $maxlen) $value = mb_substr($value, 0, $maxlen);
        } else {
            if (strlen($value) > $maxlen) $value = substr($value, 0, $maxlen);
        }
        return sanitize_text_field($value);
    }

    /**
     * Normalize a comma-separated list of bare values (utm_*). Splits on
     * comma, trims each entry, drops empties, lower-cases, dedupes, and
     * re-joins. Returns null when the cleaned list is empty so the schema's
     * "field empty = wildcard" semantics still hold.
     */
    private static function nullable_csv($value, $maxlen) {
        if ($value === null) return null;
        $value = (string) $value;
        if (trim($value) === '') return null;
        $parts = array_filter(array_map(function ($p) {
            return trim((string) $p);
        }, explode(',', $value)), 'strlen');
        $parts = array_values(array_unique(array_map(array(__CLASS__, 'lower'), $parts)));
        if (empty($parts)) return null;
        $joined = implode(',', $parts);
        return self::nullable_str($joined, $maxlen);
    }

    /**
     * Normalize a comma-separated list of referrer patterns. Each entry is
     * either a bare host (`facebook.com`), a path (`/blog`), or a host+path
     * fragment (`zenrepublic.agency/blog`). For bare-URL entries with a
     * scheme we strip the scheme/www so the stored value matches the
     * canonical form the matcher tests against.
     */
    private static function nullable_referrer_pattern($value, $maxlen) {
        if ($value === null) return null;
        $value = (string) $value;
        if (trim($value) === '') return null;
        $clean = array();
        foreach (explode(',', $value) as $raw) {
            $entry = trim((string) $raw);
            if ($entry === '') continue;
            // Strip scheme + www. so admins can paste full URLs without
            // breaking the matcher (it sees scheme-less host+path).
            $entry = preg_replace('#^[a-z]+://#i', '', $entry);
            $entry = preg_replace('#^www\.#i', '', $entry);
            $entry = self::lower($entry);
            // Strip any trailing slash on a bare-host entry (no path) so
            // "facebook.com/" doesn't behave differently from "facebook.com".
            if (strpos($entry, '/') === strlen($entry) - 1) {
                $entry = rtrim($entry, '/');
            }
            if ($entry === '') continue;
            $clean[$entry] = true;
        }
        if (empty($clean)) return null;
        $joined = implode(',', array_keys($clean));
        return self::nullable_str($joined, $maxlen);
    }

    /**
     * Test the visitor's UTM value against a stored CSV pattern. Returns
     * true on either "stored is empty" (wildcard) or "any entry matches",
     * false on miss. The $matched out-param tells the caller whether a
     * non-wildcard rule actually contributed a score.
     */
    private static function multi_value_match($stored, $visitor_value, &$matched) {
        $matched = false;
        $stored = trim((string) $stored);
        if ($stored === '') return true; // wildcard
        $visitor_value = self::lower((string) $visitor_value);
        if ($visitor_value === '') return false;
        foreach (explode(',', $stored) as $entry) {
            $entry = self::lower(trim($entry));
            if ($entry === '') continue;
            if ($entry === $visitor_value) {
                $matched = true;
                return true;
            }
        }
        return false;
    }

    /**
     * Test the visitor's referrer against a stored CSV pattern. Each entry:
     *   - contains "/" → substring-match against the referrer's canonical
     *     "host+path" form. Use this for path-scoped rules ("/blog" matches
     *     any URL whose path contains /blog) or for internal-traffic rules
     *     ("zenrepublic.agency/blog").
     *   - bare host → exact match against the referrer's host (current
     *     behaviour). Wildcard subdomains aren't supported — list each one.
     */
    private static function referrer_pattern_match($stored, $visitor_host, $visitor_canon, &$matched) {
        $matched = false;
        $stored = trim((string) $stored);
        if ($stored === '') return true; // wildcard
        if ($visitor_host === '' && $visitor_canon === '') return false;

        foreach (explode(',', $stored) as $entry) {
            $entry = self::lower(trim($entry));
            if ($entry === '') continue;
            if (strpos($entry, '/') !== false) {
                // Path / URL-fragment match — substring of host+path. We don't
                // anchor the start so "/blog" matches both "host/blog/post"
                // and "host/section/blog".
                if ($visitor_canon !== '' && strpos($visitor_canon, $entry) !== false) {
                    $matched = true;
                    return true;
                }
            } else {
                // Bare host — exact match.
                if ($visitor_host !== '' && $entry === $visitor_host) {
                    $matched = true;
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Reduce a referrer URL to "host/path" with no scheme, no www., no
     * query, no fragment, no trailing slash. Stable surface for the
     * pattern matcher.
     */
    private static function canonical_url($url) {
        $url = trim((string) $url);
        if ($url === '') return '';
        $parts = wp_parse_url($url);
        if (!is_array($parts)) return self::lower($url);
        $host = isset($parts['host']) ? self::lower($parts['host']) : '';
        if (strpos($host, 'www.') === 0) $host = substr($host, 4);
        $path = isset($parts['path']) ? $parts['path'] : '';
        $combined = $host . $path;
        $combined = rtrim($combined, '/');
        return self::lower($combined);
    }

    private static function decode_string_list($json) {
        if (!is_string($json) || $json === '') return array();
        $arr = json_decode($json, true);
        if (!is_array($arr)) return array();
        $clean = array();
        foreach ($arr as $v) {
            if (!is_string($v)) continue;
            $v = trim($v);
            if ($v !== '') $clean[] = $v;
        }
        return $clean;
    }

    public static function decode_chips($json) {
        if (!is_string($json) || $json === '') return array();
        $arr = json_decode($json, true);
        if (!is_array($arr)) return array();
        $clean = array();
        foreach ($arr as $chip) {
            if (!is_array($chip)) continue;
            $emoji   = isset($chip['emoji'])   ? trim((string) $chip['emoji'])   : '';
            $label   = isset($chip['label'])   ? trim((string) $chip['label'])   : '';
            $message = isset($chip['message']) ? trim((string) $chip['message']) : '';
            if ($label === '' && $message === '') continue;
            if ($message === '') $message = $label;
            if ($label === '')   $label   = $message;
            $clean[] = array('emoji' => $emoji, 'label' => $label, 'message' => $message);
        }
        return $clean;
    }

    private static function normalize_chips_json($value) {
        // Accept either a JSON string or an already-decoded array.
        if (is_array($value)) {
            $arr = $value;
        } else {
            $arr = json_decode((string) $value, true);
            if (!is_array($arr)) return '';
        }
        $clean = array();
        foreach ($arr as $chip) {
            if (!is_array($chip)) continue;
            $emoji   = isset($chip['emoji'])   ? sanitize_text_field((string) $chip['emoji'])   : '';
            $label   = isset($chip['label'])   ? sanitize_text_field((string) $chip['label'])   : '';
            $message = isset($chip['message']) ? sanitize_text_field((string) $chip['message']) : '';
            if ($label === '' && $message === '') continue;
            $clean[] = array('emoji' => $emoji, 'label' => $label, 'message' => $message);
        }
        return $clean ? wp_json_encode($clean) : '';
    }

    /**
     * Accept a JSON string or an already-decoded array. Returns the
     * canonical JSON shape ({name,role,body,logo_url,site_url}) when
     * the admin enabled the override AND set at least one field; '' when
     * the override is disabled / empty, signalling "fall back to the
     * global zen_cortext_intro_card option for this visitor".
     */
    private static function normalize_intro_card_json($value) {
        if (is_array($value)) {
            $arr = $value;
        } else {
            $raw = trim((string) $value);
            if ($raw === '') return '';
            $arr = json_decode($raw, true);
            if (!is_array($arr)) return '';
        }
        $name     = isset($arr['name'])     ? sanitize_text_field((string) $arr['name'])     : '';
        $role     = isset($arr['role'])     ? sanitize_text_field((string) $arr['role'])     : '';
        $body     = isset($arr['body'])     ? wp_kses_post((string) $arr['body'])            : '';
        $logo_url = isset($arr['logo_url']) ? esc_url_raw((string) $arr['logo_url'])         : '';
        $site_url = isset($arr['site_url']) ? esc_url_raw((string) $arr['site_url'])         : '';
        if ($name === '' && $role === '' && $body === '' && $logo_url === '' && $site_url === '') {
            return '';
        }
        return wp_json_encode(array(
            'name'     => $name,
            'role'     => $role,
            'body'     => $body,
            'logo_url' => $logo_url,
            'site_url' => $site_url,
        ));
    }

    /**
     * Decode a stored intro_card_json blob back into a 5-field array.
     * Returns null when the rule has no override set — the caller is
     * expected to fall back to the global zen_cortext_intro_card option
     * in that case.
     */
    public static function decode_intro_card($value) {
        $raw = trim((string) $value);
        if ($raw === '') return null;
        $arr = json_decode($raw, true);
        if (!is_array($arr)) return null;
        $body = isset($arr['body']) ? (string) $arr['body'] : '';
        return array(
            'name'     => isset($arr['name'])     ? (string) $arr['name']     : '',
            'role'     => isset($arr['role'])     ? (string) $arr['role']     : '',
            'body'     => $body,
            // Pre-rendered safe HTML so the chat.js DOM swap can drop it
            // straight into innerHTML without re-doing wpautop/kses on
            // the client (admins use <ul>/<li>/<b>/<a> in body copy).
            // Same allowed-tags whitelist as the global card render.
            'body_html' => class_exists('Zen_Cortext_Defaults')
                ? Zen_Cortext_Defaults::render_intro_body_html($body)
                : '',
            'logo_url' => isset($arr['logo_url']) ? (string) $arr['logo_url'] : '',
            'site_url' => isset($arr['site_url']) ? (string) $arr['site_url'] : '',
        );
    }

    /**
     * Multi-line bullet list describing every captured signal about where
     * this visitor came from. Empty fields are skipped. utm_term is the
     * single most valuable field for the AI — on Google paid search with
     * {keyword} value tracking it carries the actual keyword (and with
     * {searchterm}, the literal user query) — so it's labeled explicitly.
     */
    private static function traffic_summary($attribution) {
        if (!is_array($attribution)) return '';

        $lines = array();

        $source = trim((string) ($attribution['utm_source'] ?? ''));
        $medium = trim((string) ($attribution['utm_medium'] ?? ''));
        if ($source !== '' || $medium !== '') {
            $combined = $source !== '' ? $source : '(unset)';
            if ($medium !== '') $combined .= ' / ' . $medium;
            $lines[] = '- Source: ' . self::cap_field($combined, 200);
        }

        $campaign = trim((string) ($attribution['utm_campaign'] ?? ''));
        if ($campaign !== '') {
            $lines[] = '- Campaign (utm_campaign): ' . self::cap_field($campaign, 200);
        }

        $term = trim((string) ($attribution['utm_term'] ?? ''));
        if ($term !== '') {
            $lines[] = '- Search keyword (utm_term): "' . self::cap_field($term, 200) . '"';
        }

        $content = trim((string) ($attribution['utm_content'] ?? ''));
        if ($content !== '') {
            $lines[] = '- Ad variant (utm_content): ' . self::cap_field($content, 200);
        }

        $gclid = trim((string) ($attribution['gclid'] ?? ''));
        if ($gclid !== '') {
            $lines[] = '- Google Ads click (gclid present)';
        }

        $msclkid = trim((string) ($attribution['msclkid'] ?? ''));
        if ($msclkid !== '') {
            $lines[] = '- Microsoft Ads click (msclkid present)';
        }

        $fbc = trim((string) ($attribution['fbc'] ?? ''));
        $fbp = trim((string) ($attribution['fbp'] ?? ''));
        if ($fbc !== '' || $fbp !== '') {
            $lines[] = '- Facebook attribution cookies present';
        }

        $ref = trim((string) ($attribution['referrer'] ?? ''));
        if ($ref !== '') {
            $host = self::host_of($ref);
            if ($host !== '') {
                $lines[] = '- Referrer host: ' . self::cap_field($host, 191);
            }
        }

        $landing = trim((string) ($attribution['landing_page'] ?? ''));
        if ($landing !== '') {
            $lines[] = '- Landing page: ' . self::cap_field($landing, 200);
        }

        return implode("\n", $lines);
    }

    private static function cap_field($s, $max) {
        $s = (string) $s;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($s) > $max) return mb_substr($s, 0, $max - 1) . '…';
        } else {
            if (strlen($s) > $max) return substr($s, 0, $max - 1) . '…';
        }
        return $s;
    }
}
