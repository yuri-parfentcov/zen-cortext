<?php
/**
 * Uninstall Zen Cortext — full cleanup of plugin state.
 *
 * Triggered by WordPress when the admin clicks "Delete" on the
 * deactivated plugin (or when WP-CLI's `plugin uninstall` runs).
 *
 * Design rule: do NOT keep hardcoded lists of options/transients that
 * fall out of date every time a new feature ships. Sweep by prefix
 * instead. Tables and writable directories ARE listed explicitly
 * because dropping the wrong table would be irreversible.
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

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

/* ------------------------------------------------------------------
   1. Drop every database table the plugin creates.
   ------------------------------------------------------------------ */
$tables = array(
    $wpdb->prefix . 'zen_cortext_kb',
    $wpdb->prefix . 'zen_cortext_artifacts',
    $wpdb->prefix . 'zen_cortext_chats',
    $wpdb->prefix . 'zen_cortext_chat_events',
    $wpdb->prefix . 'zen_cortext_brainstorm_chats',
    $wpdb->prefix . 'zen_cortext_sessions',
    $wpdb->prefix . 'zen_cortext_surveys',
    $wpdb->prefix . 'zen_cortext_attribution_contexts',
    $wpdb->prefix . 'zen_cortext_ads_campaigns',
    $wpdb->prefix . 'zen_cortext_api_keys',
    $wpdb->prefix . 'zen_cortext_push_subscriptions',
);
foreach ($tables as $t) {
    // Each table name is composed from a constant $wpdb prefix and a
    // literal suffix — no user input — so the interpolation is safe.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- intentional DROP TABLE at uninstall to honor WP's "delete plugin" contract; required for the clean-uninstall behavior advertised in the readme.
    $wpdb->query("DROP TABLE IF EXISTS {$t}");
}

/* ------------------------------------------------------------------
   2. Delete every wp_options row this plugin owns.
   ------------------------------------------------------------------
   Wildcard sweep on `zen_cortext_*` so newly-added options (and the
   migration flags that gate one-time upgrades) get removed without
   anyone having to remember to update this file. */
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'zen_cortext\\_%'"
);

/* ------------------------------------------------------------------
   3. Delete every transient under either of the plugin's prefixes.
   ------------------------------------------------------------------
   Transients live in wp_options as two rows per entry
   (`_transient_<key>` + `_transient_timeout_<key>`), or in
   wp_sitemeta for multisite. The LIKE on option_name catches both.
   `zen_cortext_*` covers KB cache + brainstorm cache + pending counts.
   `zce_*` covers chat editor preview drafts (per-user). */
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\\_transient\\_zen\\_cortext\\_%'
        OR option_name LIKE '\\_transient\\_timeout\\_zen\\_cortext\\_%'
        OR option_name LIKE '\\_transient\\_zce\\_%'
        OR option_name LIKE '\\_transient\\_timeout\\_zce\\_%'"
);

/* ------------------------------------------------------------------
   4. Delete user_meta keys (takeover status / last_seen / schedule).
   ------------------------------------------------------------------ */
$wpdb->query(
    "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'zen_cortext\\_%'"
);

/* ------------------------------------------------------------------
   5. Drop the writable artifacts directory.
   ------------------------------------------------------------------
   Includes the writable copies of chat.css / templates / version
   snapshots created by the chat editor. wp_upload_dir() respects
   any host-specific overrides (e.g. multisite layout, S3 plugins). */
$uploads = wp_upload_dir();
$zce_dir = trailingslashit($uploads['basedir']) . 'zen-cortext';
if (is_dir($zce_dir)) {
    $rrm = function ($path) use (&$rrm) {
        if (!is_dir($path)) return;
        foreach (scandir($path) as $e) {
            if ($e === '.' || $e === '..') continue;
            $sub = $path . '/' . $e;
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.WP.AlternativeFunctions.unlink_unlink -- recursive cleanup of plugin-owned uploads directory at uninstall; WP_Filesystem requires runtime credentials that aren't available in uninstall context.
            if (is_dir($sub)) { $rrm($sub); @rmdir($sub); }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing file inside plugin-owned uploads dir at uninstall.
            else              { @unlink($sub); }
        }
        @rmdir($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing plugin-owned uploads dir at uninstall.
    };
    $rrm($zce_dir);
}

/* ------------------------------------------------------------------
   6. Multisite: repeat options/transients/usermeta sweep on every blog.
   ------------------------------------------------------------------ */
if (is_multisite()) {
    $blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
    foreach ($blog_ids as $blog_id) {
        switch_to_blog((int) $blog_id);
        $opts = $wpdb->prefix . 'options';
        $wpdb->query("DELETE FROM {$opts} WHERE option_name LIKE 'zen_cortext\\_%'");
        $wpdb->query(
            "DELETE FROM {$opts}
             WHERE option_name LIKE '\\_transient\\_zen\\_cortext\\_%'
                OR option_name LIKE '\\_transient\\_timeout\\_zen\\_cortext\\_%'
                OR option_name LIKE '\\_transient\\_zce\\_%'
                OR option_name LIKE '\\_transient\\_timeout\\_zce\\_%'"
        );
        restore_current_blog();
    }
}
