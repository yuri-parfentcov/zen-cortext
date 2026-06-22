<?php
/**
 * Zen Cortext — Setup state probes.
 *
 * Centralises every "is the admin done with step N?" check so the
 * Getting Started page (admin/views/_getting-started.php) can render
 * ✓ / ⚠ indicators without inlining queries.
 *
 * Each method returns bool. summary() returns the full state array +
 * progress totals so the view can stay dumb.
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

class Zen_Cortext_Setup_State {

    /** Anthropic API key is saved (any non-empty value). */
    public static function api_key() {
        return trim((string) get_option('zen_cortext_api_key', '')) !== '';
    }

    /** Knowledge base table has at least one row. Cached briefly so
     *  per-render COUNT(*) doesn't hit the DB on every Getting Started
     *  view — the value only flips when the admin actually syncs. */
    public static function kb_indexed() {
        $cached = get_transient('zen_cortext_kb_count');
        if ($cached === false) {
            global $wpdb;
            $cached = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}zen_cortext_kb");
            set_transient('zen_cortext_kb_count', $cached, 60);
        }
        return (int) $cached > 0;
    }

    public static function artifacts() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}zen_cortext_artifacts") > 0;
    }

    /** A chat page exists somewhere on the site. Reuses the same
     *  detector the Design tab uses for the float button target. */
    public static function chat_page() {
        if (!class_exists('Zen_Cortext_Design')) return false;
        $pages = Zen_Cortext_Design::list_chat_pages();
        return !empty($pages);
    }

    /** Design considered "customised" if the admin has saved any color
     *  override, a non-empty font option, or enabled the float button. */
    public static function design_customised() {
        $colors = (array) get_option('zen_cortext_chat_colors', array());
        if (!empty($colors)) return true;
        if (trim((string) get_option('zen_cortext_font_family', '')) !== '') return true;
        if ((int) get_option('zen_cortext_font_size', 0) > 0) return true;
        $fb = (array) get_option('zen_cortext_float_button', array());
        if (!empty($fb['enabled'])) return true;
        return false;
    }

    /** System prompt or welcome message differs from the bundled default. */
    public static function prompts_customised() {
        if (!class_exists('Zen_Cortext_Defaults')) return false;
        $sys      = (string) get_option('zen_cortext_system_prompt', '');
        $welcome  = (string) get_option('zen_cortext_welcome_message', '');
        $survey   = (string) get_option('zen_cortext_survey_prompt_template', '');
        if ($sys     !== '' && $sys     !== Zen_Cortext_Defaults::system_prompt())          return true;
        if ($welcome !== '' && $welcome !== Zen_Cortext_Defaults::welcome_message())        return true;
        if ($survey  !== '' && $survey  !== Zen_Cortext_Defaults::survey_prompt_template()) return true;
        return false;
    }

    public static function team_members() {
        $ids = (array) get_option('zen_cortext_invitable_users', array());
        return !empty(array_filter($ids));
    }

    public static function float_button_enabled() {
        $fb = (array) get_option('zen_cortext_float_button', array());
        return !empty($fb['enabled']);
    }

    public static function surveys() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}zen_cortext_surveys") > 0;
    }

    public static function attribution() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}zen_cortext_attribution_contexts") > 0;
    }

    /**
     * Voice input is "configured" when the master toggle is ON AND at
     * least one transcription key is saved (Groq Whisper primary, OpenAI
     * Whisper fallback). Either key alone is enough to make the feature
     * work — the runtime falls back to whichever is set.
     */
    public static function voice_configured() {
        if (!get_option('zen_cortext_voice_enabled', false)) return false;
        $groq   = trim((string) get_option('zen_cortext_groq_api_key', ''));
        $openai = trim((string) get_option('zen_cortext_openai_api_key', ''));
        return ($groq !== '' || $openai !== '');
    }

    /** Any chat conversation has been started (testing signal). */
    public static function chats_started() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}zen_cortext_chats") > 0;
    }

    /** Pretty short API-key tail for the "✓ sk-ant-…abc4" subtext. */
    public static function api_key_display() {
        $key = trim((string) get_option('zen_cortext_api_key', ''));
        if ($key === '') return '';
        if (strlen($key) <= 14) return $key;
        return substr($key, 0, 8) . '…' . substr($key, -4);
    }

    /** First chat page URL — used as the "Test the chat" CTA target. */
    public static function first_chat_page_url() {
        if (!class_exists('Zen_Cortext_Design')) return '';
        $pages = Zen_Cortext_Design::list_chat_pages();
        if (empty($pages)) return '';
        $first = reset($pages);
        if (!empty($first['url'])) return (string) $first['url'];
        if (!empty($first['id']))  return (string) get_permalink((int) $first['id']);
        return '';
    }

    /**
     * Full state snapshot for the Getting Started view.
     * Steps are pre-shaped into the structure the view loops over so
     * the template stays dumb. `required` marks steps that count
     * against the progress total.
     */
    public static function summary() {
        $steps = array(
            array(
                'key'      => 'api_key',
                'required' => true,
                'done'     => self::api_key(),
                'subtext'  => self::api_key() ? self::api_key_display() : '',
            ),
            array(
                'key'      => 'kb',
                'required' => true,
                'done'     => self::kb_indexed(),
            ),
            array(
                'key'      => 'artifacts',
                'required' => false,
                'done'     => self::artifacts(),
            ),
            array(
                'key'      => 'chat_page',
                'required' => true,
                'done'     => self::chat_page(),
            ),
            array(
                'key'      => 'design',
                'required' => true,
                'done'     => self::design_customised(),
            ),
            array(
                'key'      => 'prompts',
                'required' => true,
                'done'     => self::prompts_customised(),
            ),
            array(
                'key'      => 'team_members',
                'required' => false,
                'done'     => self::team_members(),
            ),
            array(
                'key'      => 'float_button',
                'required' => false,
                'done'     => self::float_button_enabled(),
            ),
            array(
                'key'      => 'voice',
                'required' => false,
                'done'     => self::voice_configured(),
            ),
            array(
                'key'      => 'surveys',
                'required' => false,
                'done'     => self::surveys(),
            ),
            array(
                'key'      => 'attribution',
                'required' => false,
                'done'     => self::attribution(),
            ),
            array(
                'key'      => 'test_chat',
                'required' => true,
                'done'     => self::chats_started(),
            ),
        );

        $required_total = 0;
        $required_done  = 0;
        foreach ($steps as $s) {
            if (!empty($s['required'])) {
                $required_total++;
                if (!empty($s['done'])) $required_done++;
            }
        }

        return array(
            'steps'          => $steps,
            'required_done'  => $required_done,
            'required_total' => $required_total,
            'all_done'       => ($required_done === $required_total),
        );
    }
}
