<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Template Name: Zen Cortext — Live Chat Admin (PWA)
 *
 * Standalone admin page for the live chat takeover system. Not part of
 * wp-admin — uses magic-link auth with Bearer session tokens. Designed as
 * a PWA so admins can install it on mobile and receive push notifications.
 *
 * No wp_head() / wp_footer() — same empty shell pattern as the client chat.
 */

if (!defined('ABSPATH')) {
    exit;
}

$livechat_css_url = ZEN_CORTEXT_PLUGIN_URL . 'public/assets/livechat.css';
$livechat_js_url  = ZEN_CORTEXT_PLUGIN_URL . 'public/assets/livechat.js';
$rest_root        = rest_url('zen-cortext/v1');
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#646B3A">
<title><?php echo esc_html(wp_get_document_title()); ?></title>

<?php
// Match the visitor chat — only fetch Yanone when the saved font option
// names it. Fresh installs get the WP-native stack and skip the Google
// Fonts preconnect entirely.
$livechat_font_family = (string) get_option('zen_cortext_font_family', Zen_Cortext_Defaults::font_family());
$livechat_load_yanone = (stripos($livechat_font_family, 'Yanone Kaffeesatz') !== false);
?>
<?php
// This is a standalone full-page template that owns the entire HTML document
// (no theme header/footer, no wp_head/wp_footer). wp_enqueue_*() cannot
// emit into a document we hand-rolled — the inline <link>/<script> tags
// below are the correct pattern here.
// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet,WordPress.WP.EnqueuedResources.NonEnqueuedScript
?>
<?php if ($livechat_load_yanone): ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Yanone+Kaffeesatz:wght@400;500;600;700&display=swap">
<?php endif; ?>
<link rel="stylesheet" href="<?php echo esc_url($livechat_css_url); ?>?ver=<?php echo esc_attr(ZEN_CORTEXT_VERSION); ?>">
<link rel="manifest" href="<?php echo esc_url(ZEN_CORTEXT_PLUGIN_URL . 'public/assets/manifest.json'); ?>">
<link rel="apple-touch-icon" href="/biometrics.png">
</head>
<body>

<div id="zlc-app"></div>

<script>
window.zlcConfig = {
    restRoot: <?php echo wp_json_encode(esc_url_raw($rest_root)); ?>,
    homeUrl:  <?php echo wp_json_encode(home_url('/')); ?>,
    version:  <?php echo wp_json_encode(ZEN_CORTEXT_VERSION); ?>
};
</script>
<script src="<?php echo esc_url($livechat_js_url); ?>?ver=<?php echo esc_attr(ZEN_CORTEXT_VERSION); ?>"></script>
<?php // phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet,WordPress.WP.EnqueuedResources.NonEnqueuedScript ?>
</body>
</html>
