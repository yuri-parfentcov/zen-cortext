<?php
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

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Yanone+Kaffeesatz:wght@400;500;600;700&display=swap">
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
</body>
</html>
