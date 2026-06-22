<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Template Name: Zen Cortext — Full-page client chat
 *
 * Empty shell template — bypasses theme chrome AND the standard WordPress
 * head/footer pipeline. We do NOT call wp_head() / wp_footer(); instead we
 * register the plugin's own assets and print ONLY those handles via
 * wp_print_styles()/wp_print_scripts(), so everything goes through the core
 * enqueue pipeline while no theme or other-plugin assets leak in (no Avada
 * CSS, no jQuery, no Fusion JS, no Leadin / HubSpot, no emoji, no oEmbed).
 * The only things on the page are:
 *
 *   - The plugin's chat.css (zen-cortext-public)
 *   - The page-chrome stylesheet chat-page.css (zen-cortext-chat-page)
 *   - The Design-settings inline CSS (font + --zc-* token overrides)
 *   - The plugin's chat.js + localized zenCortextConfig
 *   - chat-page.js (mobile menu modal + email click-to-copy)
 *
 * Layout:
 *   - No top header bar, no footer.
 *   - Desktop (≥ 900px): vertical floating "rail" of quick-action buttons
 *     pinned to the left edge, vertically centered.
 *   - Mobile (< 900px): the rail is hidden; a single circular icon button
 *     sits top-left and opens the same buttons in a centered modal.
 *
 * Usage:
 *   1. Pages → Add New
 *   2. Page Attributes → Template → "Zen Cortext — Full-page client chat"
 *   3. Publish → visit the page URL.
 */

if (!defined('ABSPATH')) {
    exit;
}

// $intro is consumed by public/views/chat.php (the intro card markup).
// The chat runtime config (rest URLs, voice, chips, welcome message) is
// localized onto chat.js via Zen_Cortext_Shortcode::chat_config_payload()
// in the footer below, and the stylesheets/scripts are enqueued + printed
// through the core pipeline — no hand-written asset URLs needed here.
$intro = get_option('zen_cortext_intro_card', Zen_Cortext_Defaults::intro_card());

// Team member cards — pulled dynamically from user meta. The user list
// is shared with the in-chat invite buttons, takeover, and the AI's
// team-expertise context, so we read from the same canonical option
// instead of hardcoding IDs that only happen to exist on this install.
$team_user_ids = array_values(array_map('intval', (array) get_option('zen_cortext_invitable_users', array())));

$team_members = array();
foreach ($team_user_ids as $uid) {
    $u = get_userdata($uid);
    if (!$u) continue;

    $avatar_att = (int) get_user_meta($uid, 'zen_user_avatar', true);
    $avatar_url = $avatar_att ? wp_get_attachment_image_url($avatar_att, 'thumbnail') : get_avatar_url($uid, array('size' => 80));

    $email    = get_the_author_meta('author_email', $uid);
    if (empty($email)) $email = $u->user_email;
    $whatsapp = get_the_author_meta('author_whatsapp', $uid);
    $linkedin = get_the_author_meta('author_linkedin', $uid);
    $wa_num   = $whatsapp ? preg_replace('/[^0-9]/', '', $whatsapp) : '';
    $role     = function_exists('zen_get_user_role')
        ? zen_get_user_role($uid)
        : trim((string) get_user_meta($uid, 'zen_user_role', true));

    $team_members[] = array(
        'name'       => $u->display_name,
        'role'       => $role,
        'avatar_url' => $avatar_url,
        'profile'    => get_author_posts_url($uid),
        'email'      => $email,
        'wa_num'     => $wa_num,
        'linkedin'   => $linkedin,
    );
}

// Quick links — admin-managed via Chat settings → "Side rail — quick links".
// Falls back to the bundled defaults so a fresh install renders sensible
// links before the admin customizes anything. Each saved row is in the
// {icon, label, url, prefix, target} shape produced by sanitize_quick_links;
// the markup loop below maps it to {href, target, icon, label, prefix}
// for the existing rail-button template.
$saved_links = (array) get_option('zen_cortext_quick_links', Zen_Cortext_Defaults::default_quick_links());
$quick_links = array();
foreach ($saved_links as $row) {
    if (!is_array($row)) continue;
    $url   = isset($row['url'])   ? (string) $row['url']   : '';
    $label = isset($row['label']) ? (string) $row['label'] : '';
    if ($url === '' && $label === '') continue;
    $quick_links[] = array(
        'href'   => $url,
        'target' => isset($row['target']) ? (string) $row['target'] : '',
        'icon'   => isset($row['icon'])   ? (string) $row['icon']   : '',
        'label'  => $label,
        'prefix' => isset($row['prefix']) ? (string) $row['prefix'] : '',
    );
}

ob_start();
foreach ($team_members as $member) { ?>
    <div class="zcp-team-card">
      <div class="zcp-team-top">
        <a class="zcp-team-left" href="<?php echo esc_url($member['profile']); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr($member['name']); ?>">
            <img class="zcp-team-avatar" src="<?php echo esc_url($member['avatar_url']); ?>" alt="<?php echo esc_attr($member['name']); ?>" width="52" height="52" loading="eager" />
            <div class="zcp-team-name"><?php echo esc_html($member['name']); ?></div>
        </a>
        <div class="zcp-team-buttons">
            <?php if (!empty($member['email'])) : ?>
                <a href="mailto:<?php echo esc_attr($member['email']); ?>" class="zcp-team-btn zcp-team-btn-email" data-email="<?php echo esc_attr($member['email']); ?>" title="<?php esc_attr_e('Copy email', 'zen-cortext'); ?>">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                    <span>Email</span>
                    <span class="zcp-team-toast">Copied!</span>
                </a>
            <?php endif; ?>
            <?php if (!empty($member['wa_num'])) : ?>
                <a href="https://wa.me/<?php echo esc_attr($member['wa_num']); ?>" class="zcp-team-btn zcp-team-btn-wa" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e('Chat on WhatsApp', 'zen-cortext'); ?>">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/></svg>
                    <span>WhatsApp</span>
                </a>
            <?php endif; ?>
            <?php if (!empty($member['linkedin'])) : ?>
                <a href="<?php echo esc_url($member['linkedin']); ?>" class="zcp-team-btn zcp-team-btn-li" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e('LinkedIn', 'zen-cortext'); ?>">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
                    <span>LinkedIn</span>
                </a>
            <?php endif; ?>
        </div>
      </div>
      <?php if (!empty($member['role'])) : ?>
        <div class="zcp-team-role"><?php echo esc_html($member['role']); ?></div>
      <?php endif; ?>
    </div>
<?php }

foreach ($quick_links as $ql) {
    // All rail cards open in a new tab — sanitizer guarantees
    // target=_blank, but render is defensive in case a future save
    // path stores something else.
    $target_attr = ' target="_blank" rel="noopener"';
    $ext_icon    = ' <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:.5;vertical-align:middle;margin-left:2px"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';
    $prefix      = !empty($ql['prefix']) ? '<span class="zcp-rail-btn-prefix">' . esc_html($ql['prefix']) . '</span> ' : '';
    ?>
    <a class="zcp-rail-btn" href="<?php echo esc_url($ql['href']); ?>"<?php echo $target_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded attribute fragment, safe. ?>>
        <span class="zcp-rail-btn-icon" aria-hidden="true"><?php echo esc_html($ql['icon']); ?></span>
        <span class="zcp-rail-btn-label"><?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $prefix and $ext_icon are hardcoded markup built above ($prefix wraps esc_html'd text; $ext_icon is a static external-link SVG); $ql['label'] is esc_html'd inline. wp_kses_post would strip the SVG.
            echo $prefix . esc_html($ql['label']) . $ext_icon;
        ?></span>
    </a>
<?php }
$buttons_html = ob_get_clean();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,follow">
<?php
// Standalone page skips wp_head(), so the theme's header injectors never run
// here. Emit the admin's custom Header code (Settings → Tracking) so the
// conversion-critical chat page can carry GTM/GA4/Pixel/verification tags.
Zen_Cortext_Shortcode::print_header_code();
?>
<title><?php echo esc_html(wp_get_document_title()); ?></title>

<?php
// Typography (Design settings) — both options default to '' (= "inherit
// from the host theme"). The standalone chat page has no host theme, so an
// empty option falls back to the system stack / 14px declared in Defaults.
// No webfont is shipped: admins set whatever font-family they want in
// Design settings and it lands via the inline CSS below — the plugin makes
// no external font request.
$chat_font_family_opt = trim((string) get_option('zen_cortext_font_family', ''));
$chat_font_family     = $chat_font_family_opt !== ''
    ? $chat_font_family_opt
    : Zen_Cortext_Defaults::font_family_standalone_fallback();

$chat_font_size_opt = (int) get_option('zen_cortext_font_size', 0);
$chat_font_size_px  = $chat_font_size_opt > 0
    ? $chat_font_size_opt
    : Zen_Cortext_Defaults::font_size_standalone_fallback();

// Register + enqueue the plugin's own assets, then print ONLY those
// handles. Everything goes through the core enqueue/printing pipeline (no
// hand-written <link>/<style> tags) while the standalone document stays
// free of theme / other-plugin assets.
$zc_shortcode = Zen_Cortext_Shortcode::get_instance();
$zc_shortcode->register_assets();
wp_enqueue_style('zen-cortext-public');
wp_enqueue_style('zen-cortext-chat-page');

// Design-settings inline CSS, printed AFTER the stylesheets so it wins:
//  - body font-family / font-size from the picker (standalone fallbacks)
//  - the --zc-* color tokens + typography overrides (empty on default sites)
$zc_body_css     = 'body.zcp-body{font-family:' . Zen_Cortext_Shortcode::sanitize_font_family($chat_font_family)
                 . ';font-size:' . (int) $chat_font_size_px . 'px;}';
$zc_override_css = Zen_Cortext_Shortcode::build_color_override_css();
wp_add_inline_style('zen-cortext-chat-page', $zc_body_css . $zc_override_css);

wp_print_styles(array('zen-cortext-public', 'zen-cortext-chat-page'));
?>
</head>
<body class="zcp-body">
<?php Zen_Cortext_Shortcode::print_body_code(); ?>
<?php
// The visible body markup is owned by chat-page-body.tpl.html (admins
// edit the .tpl.html in the Chat Editor; raw PHP is no longer accepted
// inside it). This file remains the controller — it builds the buttons
// HTML and the inner chat HTML, then hands them to the renderer.
ob_start();
include ZEN_CORTEXT_PLUGIN_DIR . 'public/views/chat.php';
$chat_html = ob_get_clean();

$zc_chat_page_body = Zen_Cortext_Template_Renderer::render('chat-page-body.tpl.html', array(
    'rail_buttons_html'    => $buttons_html, // already sanitized at construction time
    'chat_html'            => $chat_html,
    // Fingerprint "tap to chat" icon for the mobile trigger button — a
    // bundled plugin asset (portable; no hardcoded site-root path).
    'mobile_trigger_icon'  => plugins_url('public/assets/biometrics.png', ZEN_CORTEXT_PLUGIN_FILE),
    'quick_actions_label'  => __('Quick actions', 'zen-cortext'),
    'mobile_open_label'    => __('Open quick actions menu', 'zen-cortext'),
));
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $zc_chat_page_body is full page HTML (SVG icons + Alpine.js directives + the chat shell) produced by Zen_Cortext_Template_Renderer, which escapes EVERY dynamic placeholder itself (esc_html for {{ key }}, esc_url for {{ url: }}, esc_attr for {{ attr: }}; only pre-built {{ raw: }} HTML is passed through). The two raw inputs — $buttons_html and $chat_html — are assembled above/in chat.php with per-field esc_html()/esc_url()/esc_attr(). wp_kses_post() can't be used here: it would strip the SVG elements and Alpine attributes and break the page.
echo $zc_chat_page_body;
?>

<?php
// Localize the runtime config onto chat.js, enqueue the modal / email-copy
// behaviors (chat-page.js), then print ONLY the plugin's scripts. Same
// enqueue/printing pipeline as the head — no hand-written <script> tags,
// and nothing the theme or other plugins queued leaks onto the page.
wp_enqueue_script('zen-cortext-public');
wp_localize_script('zen-cortext-public', 'zenCortextConfig', Zen_Cortext_Shortcode::chat_config_payload());
wp_enqueue_script('zen-cortext-chat-page');
wp_print_scripts(array('zen-cortext-public', 'zen-cortext-chat-page'));

// Admin's custom Footer code (Settings → Tracking) — closing-body scripts,
// chat widgets, deferred trackers, etc.
Zen_Cortext_Shortcode::print_footer_code();
?>
</body>
</html>
