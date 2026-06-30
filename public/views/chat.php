<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Inner chat shell — controller only.
 *
 * The visible markup lives in chat.tpl.html (and its factory copy at
 * factory/chat.tpl.html). This file builds the context dict and asks
 * the template renderer to produce the HTML. Admins edit the .tpl.html,
 * never this file — the editor's allow-list excludes .php so a typo
 * here can't break the public chat.
 *
 * Available locals when this file is `include`d from the shortcode:
 *   $zen_cortext_intro (array) — the zen_cortext_intro_card option payload.
 */

if (!defined('ABSPATH')) exit;

$zen_cortext_intro = isset($zen_cortext_intro) && is_array($zen_cortext_intro) ? $zen_cortext_intro : array();

$zen_cortext_intro_body_html = Zen_Cortext_Defaults::render_intro_body_html($zen_cortext_intro['body'] ?? '');

$zen_cortext_site_url = (string) ($zen_cortext_intro['site_url'] ?? '');

$zen_cortext_context = array(
    'has_logo_or_site'        => !empty($zen_cortext_intro['site_url']) || !empty($zen_cortext_intro['logo_url']),
    'input_placeholder'       => __('Describe your situation...', 'zen-cortext'),
    'email_input_placeholder' => __('your@email.com', 'zen-cortext'),
    'intro' => array(
        'name'             => (string) ($zen_cortext_intro['name'] ?? get_bloginfo('name')),
        'role'             => (string) ($zen_cortext_intro['role'] ?? ''),
        'logo_url'         => (string) ($zen_cortext_intro['logo_url'] ?? ''),
        'site_url'         => $zen_cortext_site_url,
        'site_url_or_hash' => $zen_cortext_site_url !== '' ? $zen_cortext_site_url : '#',
        'site_display'     => preg_replace('#^https?://#', '', $zen_cortext_site_url),
        'body_html'        => $zen_cortext_intro_body_html,
    ),
);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer returns the chat HTML template with placeholders replaced by sanitized strings.
echo Zen_Cortext_Template_Renderer::render('chat.tpl.html', $zen_cortext_context);
