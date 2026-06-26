<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Template Name: Zen Cortext — Live Chat Admin (PWA)
 *
 * Standalone admin page for the live chat takeover system. Not part of
 * wp-admin — uses magic-link auth with Bearer session tokens. Designed as
 * a PWA so admins can install it on mobile and receive push notifications.
 *
 * No wp_head() / wp_footer() — same empty shell pattern as the client chat:
 * the plugin's own assets are registered and printed through the core
 * enqueue pipeline (wp_print_styles/wp_print_scripts) so nothing the theme
 * or other plugins queued leaks onto this standalone document.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Register the plugin's public assets so the livechat handles exist, then
// enqueue + print only those (no hand-written <link>/<script> tags). No
// webfont is loaded — the plugin makes no external font request.
$zc_shortcode = Zen_Cortext_Shortcode::get_instance();
$zc_shortcode->register_assets();
wp_enqueue_style('zen-cortext-livechat');
wp_enqueue_script('zen-cortext-livechat');
wp_localize_script('zen-cortext-livechat', 'zenCortextLivechat', array(
    'restRoot' => esc_url_raw(rest_url('zen-cortext/v1')),
    'homeUrl'  => home_url('/'),
    'version'  => ZEN_CORTEXT_VERSION,
    // Brand icon for in-page notifications + the PWA touch icon — driven
    // by the Design → float-button icon setting (bundled plugin default
    // when unset); never a hardcoded site-root path.
    'icon'     => Zen_Cortext_Design::brand_icon_url(),
));
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#646B3A">
<title><?php echo esc_html(wp_get_document_title()); ?></title>
<?php wp_print_styles(array('zen-cortext-livechat')); ?>
<link rel="manifest" href="<?php echo esc_url(ZEN_CORTEXT_PLUGIN_URL . 'public/assets/manifest.json'); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url(Zen_Cortext_Design::brand_icon_url()); ?>">
</head>
<body>

<div id="zlc-app"></div>

<?php wp_print_scripts(array('zen-cortext-livechat')); ?>
</body>
</html>
