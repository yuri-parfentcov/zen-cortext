<?php
/**
 * Zen Cortext — Chat settings page (tabbed).
 *
 * Three tabs sharing one .wrap + <h1>:
 *  - basic   (default): intro card, welcome message, starter chips.
 *  - rail            : side rail quick links, takeover, team expertise.
 *  - prompts         : system prompt + Adapt-to-KB modal, default
 *                      survey, survey framing template, gatekeeper
 *                      preview (read-only).
 *
 * Each tab posts its own form to options.php under its own settings
 * group (zen_cortext_chat_basic / _rail / _prompts) so saving on one
 * tab can't blank fields owned by the others — same pattern used by
 * settings-page.php for connection/voice/sessions.
 *
 * Available locals: $tab (passed in by render_chat_page).
 */
if (!defined('ABSPATH')) exit;

$tab = isset($tab) && in_array($tab, array('basic', 'rail', 'prompts'), true) ? $tab : 'basic';

$tabs = array(
    'basic'   => __('Basic chat', 'zen-cortext'),
    'rail'    => __('Left panel / menu', 'zen-cortext'),
    'prompts' => __('Prompts', 'zen-cortext'),
);

// Intro card is needed by the basic partial. Resolved here so the
// partial doesn't need to know about Defaults::intro_card().
$intro = get_option('zen_cortext_intro_card', Zen_Cortext_Defaults::intro_card());
?>
<div class="wrap zen-cortext-wrap">
    <h1><?php esc_html_e('Chat settings', 'zen-cortext'); ?></h1>

    <h2 class="nav-tab-wrapper">
        <?php foreach ($tabs as $key => $label): ?>
            <a href="<?php echo esc_url(add_query_arg(array('page' => 'zen-cortext-chat', 'tab' => $key), admin_url('admin.php'))); ?>"
               class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($label); ?>
            </a>
        <?php endforeach; ?>
    </h2>

    <?php
    if ($tab === 'rail') {
        include ZEN_CORTEXT_PLUGIN_DIR . 'admin/views/_chat-rail-tab.php';
    } elseif ($tab === 'prompts') {
        include ZEN_CORTEXT_PLUGIN_DIR . 'admin/views/_chat-prompts-tab.php';
    } else {
        include ZEN_CORTEXT_PLUGIN_DIR . 'admin/views/_chat-basic-tab.php';
    }
    ?>
</div>
