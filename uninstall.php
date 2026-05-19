<?php
/**
 * Uninstall Zen Cortext — drop table and delete options.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$tables = array(
    $wpdb->prefix . 'zen_cortext_kb',
    $wpdb->prefix . 'zen_cortext_attribution_contexts',
    $wpdb->prefix . 'zen_cortext_ads_campaigns',
    $wpdb->prefix . 'zen_cortext_surveys',
);
foreach ($tables as $t) {
    $wpdb->query("DROP TABLE IF EXISTS {$t}");
}

$options = array(
    'zen_cortext_api_key',
    'zen_cortext_model',
    'zen_cortext_classify_model',
    'zen_cortext_max_tokens',
    'zen_cortext_system_prompt',
    'zen_cortext_welcome_message',
    'zen_cortext_intro_card',
    'zen_cortext_post_types',
    'zen_cortext_classify_prompt',
    'zen_cortext_restructure_prompts',
    'zen_cortext_content_types',
    'zen_cortext_db_version',
    'zen_cortext_apps_script_key_hash',
    'zen_cortext_apps_script_key_last4',
    'zen_cortext_apps_script_key_rotated_at',
    'zen_cortext_chat_colors',
    'zen_cortext_default_survey_id',
    'zen_cortext_survey_prompt_template',
);

foreach ($options as $opt) {
    delete_option($opt);
}

// Plugin classes are not loaded during uninstall — flush KB transients
// directly rather than going through Zen_Cortext_KB::flush_cache().
delete_transient('zen_cortext_kb_cache');
delete_transient('zen_cortext_kb_brainstorm_cache');
delete_transient('zen_cortext_kb_pending');

// Sweep all per-user chat-editor preview drafts. Transients live as two
// rows per entry in wp_options (`_transient_<key>` and
// `_transient_timeout_<key>`); the LIKE on the option_name catches both.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_zce\\_preview\\_%' OR option_name LIKE '\\_transient\\_timeout\\_zce\\_preview\\_%'");

// Drop the writable artifacts directory (live + version snapshots).
$uploads = wp_upload_dir();
$zce_dir = trailingslashit($uploads['basedir']) . 'zen-cortext';
if (is_dir($zce_dir)) {
    $rrm = function ($path) use (&$rrm) {
        if (!is_dir($path)) return;
        foreach (scandir($path) as $e) {
            if ($e === '.' || $e === '..') continue;
            $sub = $path . '/' . $e;
            if (is_dir($sub)) { $rrm($sub); @rmdir($sub); }
            else              { @unlink($sub); }
        }
        @rmdir($path);
    };
    $rrm($zce_dir);
}
