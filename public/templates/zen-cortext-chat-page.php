<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Template Name: Zen Cortext — Full-page client chat
 *
 * Empty shell template — bypasses theme chrome AND the standard WordPress
 * head/footer pipeline. We do NOT call wp_head() / wp_footer(), so no plugin
 * or theme assets leak in (no Avada CSS, no jQuery, no Fusion JS, no
 * Leadin / HubSpot, no emoji, no oEmbed). The only things on the page are:
 *
 *   - This template's inline page chrome CSS
 *   - The plugin's chat.css
 *   - Yanone Kaffeesatz font (Google Fonts)
 *   - The plugin's chat.js + zenCortextConfig
 *   - A small inline script for the mobile menu modal
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

$intro          = get_option('zen_cortext_intro_card', Zen_Cortext_Defaults::intro_card());
$welcome        = get_option('zen_cortext_welcome_message', Zen_Cortext_Defaults::welcome_message());
$default_chips  = array_values((array) get_option('zen_cortext_default_chips', array()));

// chat.css is admin-editable from the Template Editor. asset_url()
// returns the uploaded "live" URL when it exists, factory URL otherwise,
// with an mtime cache-buster baked in.
$chat_css_url          = Zen_Cortext_Template_Renderer::asset_url('chat.css');
$chat_js_url           = ZEN_CORTEXT_PLUGIN_URL . 'public/assets/chat.js';
$rest_url              = rest_url('zen-cortext/v1/send');
$rest_root             = rest_url('zen-cortext/v1');
$attribution_ctx_url   = rest_url('zen-cortext/v1/attribution-context');
$transcribe_url        = rest_url('zen-cortext/v1/transcribe');
$voice_enabled         = (bool) get_option('zen_cortext_voice_enabled', false);
$voice_max_sec         = 60;

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
        <span class="zcp-rail-btn-label"><?php echo wp_kses_post($prefix . esc_html($ql['label']) . $ext_icon); ?></span>
    </a>
<?php }
$buttons_html = ob_get_clean();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,follow">
<title><?php echo esc_html(wp_get_document_title()); ?></title>

<?php
// Typography — both options default to '' (empty = "inherit from host
// theme"). Standalone /talk/ has no host theme, so an empty option
// substitutes the system-stack / 16px fallback declared in Defaults.
//
// The Google Fonts <link> is only emitted when the resolved value
// mentions Yanone — case-insensitive sniff so admins typing
// 'Yanone Kaffeesatz' in any casing still get the font file.
// Other font stacks (system, brand-CDN, etc.) skip the preconnect.
$chat_font_family_opt = trim((string) get_option('zen_cortext_font_family', ''));
$chat_font_family     = $chat_font_family_opt !== ''
    ? $chat_font_family_opt
    : Zen_Cortext_Defaults::font_family_standalone_fallback();

$chat_font_size_opt = (int) get_option('zen_cortext_font_size', 0);
$chat_font_size_px  = $chat_font_size_opt > 0
    ? $chat_font_size_opt
    : Zen_Cortext_Defaults::font_size_standalone_fallback();

$load_yanone_font = (stripos($chat_font_family, 'Yanone Kaffeesatz') !== false);
?>
<?php
// Standalone chat page owns the whole document — no wp_head/wp_footer,
// so wp_enqueue_*() cannot apply. Inline tags are the correct pattern.
// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet,WordPress.WP.EnqueuedResources.NonEnqueuedScript
?>
<?php if ($load_yanone_font): ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Yanone+Kaffeesatz:wght@400;500;600;700&display=swap">
<?php endif; ?>
<link rel="stylesheet" href="<?php echo esc_url($chat_css_url); ?>">
<?php
// Per-site --zc-* overrides set in the Chat Editor → Colors panel. Echoed
// AFTER chat.css so cascade beats the published defaults. Empty string
// when no overrides are configured, so this is a no-op on default sites.
echo Zen_Cortext_Shortcode::build_color_override_style(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns a hardcoded <style> block built from validated hex/rgba values.
?>
<style>
/* Hard reset — we own this page completely. */
html, body { margin: 0; padding: 0; }
*, *::before, *::after { box-sizing: border-box; }

body.zcp-body {
    /* !important keeps a host theme's body rule from overriding the
       chat page's own background — fallback matches chat.css :root so
       fresh installs without saved color overrides still look right. */
    background: var(--zc-bg, #ffffff) !important;
    color: var(--zc-text, #3c434a);
    font-family: <?php echo esc_attr( wp_strip_all_tags($chat_font_family) ); ?>;
    font-size: <?php echo (int) $chat_font_size_px; ?>px;
    line-height: 1.2;
    min-height: 100dvh;
}
.zcp-page { min-height: 100dvh; display: flex; flex-direction: column; background: var(--zc-bg, #ffffff); }

/* ----- Main (chat container) — no header / no footer chrome ----- */
.zcp-main {
    flex: 1 0 auto;
    width: 100%;
    max-width: 950px;
    margin: 0 auto;
    padding: 32px 24px;
    background: var(--zc-bg, #ffffff);
}
/* On desktop, keep the chat clear of the floating left rail (rail width 300px
   + 24px left offset + 24px gap = 348px). On wide enough viewports the chat
   re-centers naturally. */
@media (min-width: 900px) {
    .zcp-main {
        margin-left: max(348px, calc(50% - 475px));
        margin-right: 24px;
    }
}
.zcp-main .zen-cortext-root {
    max-width: none;
    margin: 0;
    padding: 0;
}

/* =====================================================================
   Desktop floating quick-action rail (left edge, vertically centered)
   ===================================================================== */
.zcp-rail {
    position: fixed;
    top: 50%;
    left: 24px;
    transform: translateY(-50%);
    display: flex;
    flex-direction: column;
    gap: 16px;
    z-index: 50;
    width: 300px;
    max-height: calc(100dvh - 48px);
    overflow-y: auto;
    overflow-x: hidden;
}

/* --- Team member cards --- */
.zcp-team-card {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 16px;
    background: var(--zc-surface, #ffffff);
    border: 1px solid var(--zc-border, #c3c4c7);
    border-radius: 14px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}
.zcp-team-top {
    display: flex;
    align-items: center;
    gap: 14px;
}
.zcp-team-role {
    font-size: 1em;
    color: var(--zc-text-muted, #646970);
    line-height: 1.35;
}
a.zcp-team-left, .zcp-team-left {
    display: flex;
    flex-direction: column;
    align-items: center;
    flex-shrink: 0;
    gap: 6px;
    text-decoration: none;
    color: inherit;
}
a.zcp-team-left:hover .zcp-team-name { text-decoration: underline; }
.zcp-team-avatar {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    object-fit: cover;
}
.zcp-team-name {
    font-size: 1em;
    font-weight: 600;
    color: var(--zc-text-strong, #1d2327);
    line-height: 1.2;
    text-align: center;
    max-width: 86px;
    word-wrap: break-word;
}
.zcp-team-buttons {
    display: flex;
    flex-direction: column;
    gap: 6px;
    align-items: flex-start;
}
.zcp-team-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 12px;
    border-radius: 6px;
    font-size: 1em;
    text-decoration: none;
    cursor: pointer;
    line-height: 1;
    transition: background .15s, color .15s;
    position: relative;
    font-family: inherit;
}
.zcp-team-btn svg { flex-shrink: 0; width: 17px; height: 17px; }
.zcp-team-btn-email { background: #f0f0f0; color: #333; }
.zcp-team-btn-email:hover { background: #e2e2e2; color: #111; }
.zcp-team-btn-wa { background: #25d366; color: #fff; }
.zcp-team-btn-wa:hover { background: #1fb855; color: #fff; }
.zcp-team-btn-li { background: #0A66C2; color: #fff; }
.zcp-team-btn-li:hover { background: #004182; color: #fff; }
.zcp-team-toast {
    position: absolute;
    top: -28px;
    left: 50%;
    transform: translateX(-50%);
    background: #333;
    color: #fff;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.6875em;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: opacity .25s;
}
.zcp-team-toast.zcp-show { opacity: 1; }

/* --- Quick link buttons ---
   No left-border stripe — the previous 3px olive stripe was hardcoded
   to one palette and the user has rejected left-stripe accents on
   cards in general. Hover swaps the full border to --zc-accent so the
   brand color still lands on interaction without committing one side
   of the card to a palette-specific value. */
.zcp-rail-btn {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 18px;
    background: var(--zc-surface, #ffffff);
    border: 1px solid var(--zc-border, #c3c4c7);
    border-radius: 10px;
    text-decoration: none;
    color: var(--zc-text, #3c434a);
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    transition: background-color .15s, border-color .15s, transform .12s, box-shadow .15s;
    font-family: inherit;
    line-height: 1.2;
}
.zcp-rail-btn:hover {
    background: var(--zc-assistant-bg, #f0f0f1);
    border-color: var(--zc-accent, #2271b1);
    box-shadow: 0 4px 14px rgba(0,0,0,0.08);
    transform: translateX(2px);
}
.zcp-rail-btn:active { transform: translateX(2px) translateY(1px); }
.zcp-rail-btn-icon {
    font-size: 1.375em;
    line-height: 1;
    flex-shrink: 0;
}
.zcp-rail-btn-label {
    font-size: 1.125em;
    font-weight: 600;
    color: var(--zc-text-strong, #1d2327);
}
.zcp-rail-btn-prefix {
    font-weight: 400;
    /* Sized against parent .zcp-rail-btn-label (1.125em), so 0.889em
       restores the original 16/18 ratio (16px prefix / 18px label). */
    font-size: 0.889em;
    color: var(--zc-text-muted, #646970);
}

/* =====================================================================
   Mobile floating menu trigger + modal
   Hidden by default; shown only on small viewports.
   ===================================================================== */
.zcp-mobile-trigger {
    display: none;
    position: fixed;
    top: 16px;
    left: 16px;
    z-index: 60;
    width: 52px;
    height: 52px;
    padding: 0;
    background: var(--zc-surface, #ffffff);
    border: 1px solid var(--zc-border, #c3c4c7);
    border-radius: 50%;
    box-shadow: 0 4px 12px rgba(0,0,0,0.10);
    cursor: pointer;
    align-items: center;
    justify-content: center;
    transition: transform .12s, box-shadow .15s;
}
.zcp-mobile-trigger:hover { box-shadow: 0 6px 16px rgba(0,0,0,0.14); }
.zcp-mobile-trigger:active { transform: translateY(1px); }
.zcp-mobile-trigger img {
    width: 30px; height: 30px; display: block;
}

.zcp-modal {
    position: fixed;
    inset: 0;
    z-index: 100;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    background: rgba(20, 22, 30, 0.55);
    padding: 64px 16px 24px;
    overflow-y: auto;
    -webkit-tap-highlight-color: transparent;
}
.zcp-modal[hidden] { display: none; }
.zcp-modal-card {
    width: 100%;
    max-width: 420px;
    background: var(--zc-surface, #ffffff);
    border-radius: 14px;
    padding: 18px 18px 22px;
    box-shadow: 0 12px 40px rgba(0,0,0,0.20);
    position: relative;
}
.zcp-modal-title {
    font-size: 1.25em;
    font-weight: 600;
    margin: 4px 0 14px;
    color: var(--zc-text-strong, #1d2327);
    padding-right: 36px;
}
.zcp-modal-close {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: 0;
    background: transparent;
    color: var(--zc-text-muted, #646970);
    font-size: 1.625em;
    line-height: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background-color .12s, color .12s;
}
.zcp-modal-close:hover { background: var(--zc-assistant-bg, #f0f0f1); color: var(--zc-text-strong, #1d2327); }
.zcp-modal-list { display: flex; flex-direction: column; gap: 12px; }
.zcp-modal-list .zcp-rail-btn { width: 100%; }
.zcp-modal-list .zcp-team-card { box-shadow: none; border: 1px solid var(--zc-border, #c3c4c7); }

/* ----- Breakpoint: switch from desktop rail to mobile trigger ----- */
@media (max-width: 899px) {
    .zcp-rail { display: none; }
    .zcp-mobile-trigger { display: flex; }
    .zcp-main { padding: 80px 16px 24px; }
    /* Body font-size override removed in 2.34.10 — picker now drives
       both desktop and mobile via the inline body rule above. */
}
</style>
</head>
<body class="zcp-body">
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
    'mobile_trigger_icon'  => '/biometrics.png',
    'quick_actions_label'  => __('Quick actions', 'zen-cortext'),
    'mobile_open_label'    => __('Open quick actions menu', 'zen-cortext'),
));
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer returns the full chat-page body HTML built from a template; vars are sanitized at construction.
echo $zc_chat_page_body;
?>

<script>
window.zenCortextConfig = {
    restUrl:               <?php echo wp_json_encode(esc_url_raw($rest_url)); ?>,
    restRoot:              <?php echo wp_json_encode(esc_url_raw($rest_root)); ?>,
    attributionContextUrl: <?php echo wp_json_encode(esc_url_raw($attribution_ctx_url)); ?>,
    transcribeUrl:         <?php echo wp_json_encode(esc_url_raw($transcribe_url)); ?>,
    voiceEnabled:          <?php echo wp_json_encode($voice_enabled); ?>,
    voiceMaxSec:           <?php echo wp_json_encode($voice_max_sec); ?>,
    introCard:             <?php echo wp_json_encode($intro); ?>,
    welcomeMessage:        <?php echo wp_json_encode($welcome); ?>,
    defaultChips:          <?php echo wp_json_encode($default_chips); ?>
};

/* Mobile quick-actions modal */
(function () {
    var trigger = document.getElementById('zcp-mobile-trigger');
    var modal   = document.getElementById('zcp-modal');
    var closeBtn = document.getElementById('zcp-modal-close');
    if (!trigger || !modal || !closeBtn) return;

    function open() {
        modal.hidden = false;
        document.documentElement.style.overflow = 'hidden';
    }
    function close() {
        modal.hidden = true;
        document.documentElement.style.overflow = '';
    }
    trigger.addEventListener('click', open);
    closeBtn.addEventListener('click', close);
    modal.addEventListener('click', function (e) {
        // click on the backdrop (not the card) closes
        if (e.target === modal) close();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) close();
    });
})();

/* Email click-to-copy with toast */
(function () {
    document.addEventListener('click', function (e) {
        var el = e.target.closest('.zcp-team-btn-email');
        if (!el) return;
        var email = el.getAttribute('data-email');
        if (!email) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            e.preventDefault();
            navigator.clipboard.writeText(email).then(function () {
                var t = el.querySelector('.zcp-team-toast');
                if (!t) return;
                t.classList.add('zcp-show');
                setTimeout(function () { t.classList.remove('zcp-show'); }, 1500);
            });
        }
    });
})();
</script>
<script src="<?php echo esc_url($chat_js_url); ?>?ver=<?php echo esc_attr(ZEN_CORTEXT_VERSION); ?>"></script>
<?php // phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet,WordPress.WP.EnqueuedResources.NonEnqueuedScript ?>

<?php
/* OPTIONAL — to add tracking or other plugins back, uncomment:
   wp_footer();
   …but be aware this will pull in everything that hooks wp_footer.
   For surgical control, do_action('zen_cortext_chat_page_footer'); and
   wire individual hooks elsewhere. */
?>
</body>
</html>
