<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Zen Cortext — unified API admin page.
 * Wrapper that emits the wrap + h1 + tab nav and includes the active
 * sub-view. $tab comes from Zen_Cortext_Admin::render_api_page() and is
 * already validated to either 'keys' or 'docs'.
 */
if (!defined('ABSPATH')) exit;

$tabs = array(
    'keys' => __('Keys',          'zen-cortext'),
    'docs' => __('Documentation', 'zen-cortext'),
);
$base_url = admin_url('admin.php?page=zen-cortext-api-keys');
?>
<div class="wrap zen-cortext-wrap">
<h1><?php esc_html_e('Zen Cortext — API', 'zen-cortext'); ?></h1>

<nav class="nav-tab-wrapper">
    <?php foreach ($tabs as $slug => $label): ?>
        <a href="<?php echo esc_url(add_query_arg('tab', $slug, $base_url)); ?>"
           class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>">
            <?php echo esc_html($label); ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php
if ($tab === 'docs') {
    include __DIR__ . '/api-docs-page.php';
} else {
    include __DIR__ . '/api-keys-page.php';
}
?>
</div>
