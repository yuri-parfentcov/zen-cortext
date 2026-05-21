<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Zen Cortext — Design tab body.
 *
 * Included by settings-page.php when ?tab=design. No .wrap or <h1>;
 * those are owned by the parent Settings page so the tab strip stays
 * consistent. Save flows are REST-driven (zen-cortext/v1/design), so
 * this content sits OUTSIDE the options.php form on the Settings page.
 *
 * Markup reuses .zce-* class names from chat-editor.css so the existing
 * stylesheet applies without forking.
 */
if (!defined('ABSPATH')) exit;
?>
<div id="zen-cortext-design" class="zce-wrap">
<p class="zce-subtitle"><?php esc_html_e('Visual design surface for the visitor chat. Tune the brand colors here — saves apply instantly across every page that renders the chat. No file edits, no plugin-update churn.', 'zen-cortext'); ?></p>

<?php
// Float button section — a sticky CTA injected into every public
// page (except the chat page itself) that links to the visitor chat.
// Chat-page list comes from the same detector that powers the
// Settings → Chat pages tab, used here for the target-page select.
$fb         = Zen_Cortext_Design::get_float_button();
$chat_pages = Zen_Cortext_Design::list_chat_pages();
?>
<section class="zce-panel zcd-section zcd-float">
    <h2 class="zcd-section-title"><?php esc_html_e('Float button', 'zen-cortext'); ?></h2>
    <p class="zcd-section-hint"><?php esc_html_e('Sticky CTA shown on every public page (except the chat page itself) that takes the visitor to the AI consultant. Position, icon, and hover text are configurable; the click target is your detected visitor chat page by default.', 'zen-cortext'); ?></p>

    <!-- Live preview — mirrors the actual rendered button (color, icon,
         position relative to a faux viewport). JS updates this on every
         input change. Padding is shown to scale relative to the preview
         frame so admins can sanity-check corner placement before saving. -->
    <div class="zcfb-preview-wrap" aria-hidden="true">
        <span class="zcfb-preview-label"><?php esc_html_e('Live preview', 'zen-cortext'); ?></span>
        <div id="zcfb-preview-viewport" class="zcfb-preview-viewport">
            <a id="zcfb-preview-btn" class="zcfb-preview-btn" href="#" onclick="return false;" tabindex="-1">
                <img id="zcfb-preview-img" src="" alt="" />
            </a>
        </div>
    </div>

    <style>
    /* Preview viewport — a 280x180 faux browser viewport. The button
       inside is rendered with the same dimensions / shadow / icon
       proportions as the public render so the admin sees exactly how
       it'll look. JS positions it according to vertical/horizontal/
       padding (padding is preserved 1:1 from the input value since the
       admin form caps it at 200px and the viewport is plenty large). */
    .zcfb-preview-wrap {
        max-width: 320px; margin: 6px 0 22px;
    }
    .zcfb-preview-label {
        display: block; font-size: 12px; color: #50575e; margin-bottom: 6px;
        text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600;
    }
    .zcfb-preview-viewport {
        position: relative; width: 280px; height: 180px;
        background: repeating-linear-gradient(45deg, #f6f7f7, #f6f7f7 10px, #fff 10px, #fff 20px);
        border: 1px solid #c3c4c7; border-radius: 6px; overflow: hidden;
    }
    .zcfb-preview-btn {
        position: absolute;
        display: flex; align-items: center; justify-content: center;
        width: 40px; height: 40px; border-radius: 50%;
        background: #ffffff;
        box-shadow: 0 4px 16px rgba(0,0,0,0.18);
        text-decoration: none;
        transition: top 0.15s ease, bottom 0.15s ease, left 0.15s ease, right 0.15s ease, background 0.1s ease;
    }
    .zcfb-preview-btn img { width: 60%; height: 60%; object-fit: contain; display: block; }
    .zcfb-preview-btn.is-disabled {
        opacity: 0.35; filter: grayscale(0.6);
    }
    </style>

    <table class="form-table" role="presentation">
        <tr>
            <th><label for="zcfb-enabled"><?php esc_html_e('Enabled', 'zen-cortext'); ?></label></th>
            <td>
                <label>
                    <input type="checkbox" id="zcfb-enabled" <?php checked(!empty($fb['enabled'])); ?> />
                    <?php esc_html_e('Show the float button on the public site.', 'zen-cortext'); ?>
                </label>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e('Vertical position', 'zen-cortext'); ?></th>
            <td>
                <?php foreach (array('top' => __('Top', 'zen-cortext'), 'middle' => __('Middle', 'zen-cortext'), 'bottom' => __('Bottom', 'zen-cortext')) as $val => $label): ?>
                    <label style="margin-right:14px;">
                        <input type="radio" name="zcfb-vertical" value="<?php echo esc_attr($val); ?>" <?php checked($fb['vertical'], $val); ?> />
                        <?php echo esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e('Horizontal position', 'zen-cortext'); ?></th>
            <td>
                <?php foreach (array('left' => __('Left', 'zen-cortext'), 'right' => __('Right', 'zen-cortext')) as $val => $label): ?>
                    <label style="margin-right:14px;">
                        <input type="radio" name="zcfb-horizontal" value="<?php echo esc_attr($val); ?>" <?php checked($fb['horizontal'], $val); ?> />
                        <?php echo esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
            </td>
        </tr>
        <tr>
            <th><label for="zcfb-padding"><?php esc_html_e('Padding (px)', 'zen-cortext'); ?></label></th>
            <td>
                <input type="number" id="zcfb-padding" class="small-text" min="0" max="200" step="1" value="<?php echo esc_attr((int) $fb['padding']); ?>" />
                <p class="description"><?php esc_html_e('Distance from the chosen edge of the viewport (0–200).', 'zen-cortext'); ?></p>
            </td>
        </tr>
        <tr>
            <th><label for="zcfb-button-color"><?php esc_html_e('Button color', 'zen-cortext'); ?></label></th>
            <td>
                <input type="color" id="zcfb-button-color" value="<?php echo esc_attr((string) $fb['button_color']); ?>" />
                <input type="text" id="zcfb-button-color-hex" class="small-text code" maxlength="9" value="<?php echo esc_attr((string) $fb['button_color']); ?>" />
                <p class="description"><?php esc_html_e('Background of the circle behind the icon. Defaults to white so the chat.png reads on any page.', 'zen-cortext'); ?></p>
            </td>
        </tr>
        <tr>
            <th><label for="zcfb-icon-url"><?php esc_html_e('Icon', 'zen-cortext'); ?></label></th>
            <td>
                <input type="url" id="zcfb-icon-url" class="regular-text" value="<?php echo esc_attr((string) $fb['icon_url']); ?>" placeholder="<?php echo esc_attr(ZEN_CORTEXT_PLUGIN_URL . 'public/assets/chat.png'); ?>" />
                <button type="button" class="button" id="zcfb-icon-pick"><?php esc_html_e('Choose from media library', 'zen-cortext'); ?></button>
                <button type="button" class="button" id="zcfb-icon-default"><?php esc_html_e('Use bundled default', 'zen-cortext'); ?></button>
                <p class="description"><?php
                    /* translators: %s is the default icon URL (bundled with the plugin) */
                    printf(esc_html__('Square image (PNG/SVG/WebP) shown at 64×64 px. Default ships with the plugin: %s', 'zen-cortext'), '<code>' . esc_html(ZEN_CORTEXT_PLUGIN_URL . 'public/assets/chat.png') . '</code>');
                ?></p>
            </td>
        </tr>
        <tr>
            <th><label for="zcfb-hover-text"><?php esc_html_e('Hover text', 'zen-cortext'); ?></label></th>
            <td>
                <input type="text" id="zcfb-hover-text" class="regular-text" maxlength="120" value="<?php echo esc_attr((string) $fb['hover_text']); ?>" placeholder="<?php esc_attr_e('Talk to our AI consultant', 'zen-cortext'); ?>" />
                <p class="description"><?php esc_html_e('Shown as a tooltip on hover (and as the button\'s accessibility label).', 'zen-cortext'); ?></p>
            </td>
        </tr>
        <tr>
            <th><label for="zcfb-target"><?php esc_html_e('Target page', 'zen-cortext'); ?></label></th>
            <td>
                <select id="zcfb-target">
                    <option value="0"><?php esc_html_e('Auto-detect (first visitor chat page)', 'zen-cortext'); ?></option>
                    <?php foreach ($chat_pages as $p): ?>
                        <option value="<?php echo (int) $p['id']; ?>" <?php selected((int) $fb['target_page_id'], (int) $p['id']); ?>>
                            <?php echo esc_html(($p['title'] !== '' ? $p['title'] : '(no title)') . ' — ' . Zen_Cortext_Design::describe_mechanisms($p['mechanisms'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php esc_html_e('Which detected chat page the button opens. Auto-detect picks the first page using the visitor template / shortcode.', 'zen-cortext'); ?></p>
            </td>
        </tr>
    </table>

    <p>
        <button type="button" class="button button-primary" id="zcfb-save"><?php esc_html_e('Save Float Button', 'zen-cortext'); ?></button>
        <span class="zce-colors-status" id="zcfb-status" aria-live="polite"></span>
    </p>
</section>

<?php
// Typography section — both inputs default to empty so a blank state
// means "inherit from host theme". The fallbacks shown as placeholders
// are what the standalone /talk/ chat page uses when nothing is set.
$ty_font_family   = (string) get_option('zen_cortext_font_family', '');
$ty_font_size     = (int)    get_option('zen_cortext_font_size', 0);
$ty_family_ph     = Zen_Cortext_Defaults::font_family_standalone_fallback();
$ty_size_ph       = Zen_Cortext_Defaults::font_size_standalone_fallback();
?>
<section class="zce-panel zcd-section zcd-typography">
    <h2 class="zcd-section-title"><?php esc_html_e('Typography', 'zen-cortext'); ?></h2>
    <p class="zcd-section-hint">
        <?php esc_html_e('Leave both empty so the chat inherits the host theme\'s font and base size — that\'s the recommended default. Fill them in when the theme\'s font doesn\'t fit the chat (display fonts, unusual sizes) or when you need a specific brand font.', 'zen-cortext'); ?>
        <br>
        <?php esc_html_e('The standalone /talk/ chat page has no theme to inherit from; it falls back to a WordPress-native system font stack and 16px when these fields are empty.', 'zen-cortext'); ?>
    </p>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="zcty-font-family"><?php esc_html_e('Base font', 'zen-cortext'); ?></label></th>
            <td>
                <input type="text"
                       id="zcty-font-family"
                       class="regular-text"
                       value="<?php echo esc_attr($ty_font_family); ?>"
                       placeholder="<?php echo esc_attr($ty_family_ph); ?>"
                       spellcheck="false" />
                <p class="description">
                    <?php esc_html_e('Any CSS font-family value. Empty = use the host theme\'s body font.', 'zen-cortext'); ?>
                </p>
            </td>
        </tr>
        <tr>
            <th><label for="zcty-font-size"><?php esc_html_e('Base font size', 'zen-cortext'); ?></label></th>
            <td>
                <input type="number"
                       id="zcty-font-size"
                       min="10"
                       max="64"
                       step="1"
                       value="<?php echo $ty_font_size > 0 ? (int) $ty_font_size : ''; ?>"
                       placeholder="<?php echo (int) $ty_size_ph; ?>" />
                <span style="margin-left:6px;">px</span>
                <p class="description">
                    <?php esc_html_e('Empty = inherit from host theme. Affects message bubbles, the input field, and the send button. Other elements (page title, intro card name) use their own sizes.', 'zen-cortext'); ?>
                </p>
            </td>
        </tr>
    </table>
    <p>
        <button type="button" class="button button-primary" id="zcty-save"><?php esc_html_e('Save Typography', 'zen-cortext'); ?></button>
        <button type="button" class="button" id="zcty-clear" title="<?php esc_attr_e('Clear both fields back to inherit', 'zen-cortext'); ?>"><?php esc_html_e('Clear', 'zen-cortext'); ?></button>
        <span class="zce-colors-status" id="zcty-status" aria-live="polite"></span>
    </p>
</section>

<section class="zce-panel" data-zce-panel="colors">
    <h2 class="zcd-section-title"><?php esc_html_e('Colors', 'zen-cortext'); ?></h2>
    <div class="zce-colors-grid">
        <div class="zce-colors-list">
            <?php foreach (Zen_Cortext_Design::color_tokens() as $token => $meta): ?>
                <div class="zce-color-row" data-token="<?php echo esc_attr($token); ?>">
                    <label class="zce-color-label">
                        <span class="zce-color-name"><?php echo esc_html($meta['label']); ?></span>
                        <code class="zce-color-token"><?php echo esc_html($token); ?></code>
                    </label>
                    <div class="zce-color-controls">
                        <input type="color"
                               class="zce-color-picker"
                               data-default="<?php echo esc_attr($meta['default']); ?>">
                        <input type="text"
                               class="zce-color-hex"
                               spellcheck="false"
                               maxlength="9">
                        <button type="button" class="zce-color-reset button-link" title="<?php esc_attr_e('Reset to default', 'zen-cortext'); ?>">↺</button>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="zce-colors-actions">
                <button type="button" class="button button-primary" id="zce-colors-save"><?php esc_html_e('Save Colors', 'zen-cortext'); ?></button>
                <button type="button" class="button" id="zce-colors-reset-all"><?php esc_html_e('Reset all to defaults', 'zen-cortext'); ?></button>
                <span class="zce-colors-status" id="zce-colors-status" aria-live="polite"></span>
            </div>
        </div>

        <aside class="zce-colors-preview">
            <h3 class="zce-preview-title"><?php esc_html_e('Live preview', 'zen-cortext'); ?></h3>
            <p class="zce-preview-hint"><?php esc_html_e('Updates instantly as you adjust pickers — these are the same tokens chat.css uses on the live site. Every styled element of the visitor chat is shown below so you can verify contrast and brand cohesion before saving.', 'zen-cortext'); ?></p>
            <?php
            // Pull the saved intro card so the preview reflects the
            // actual identity card a visitor would see — admins comparing
            // palettes against their real name + role + body get a more
            // honest test than against placeholder copy.
            $preview_intro = (array) get_option('zen_cortext_intro_card', array());
            $preview_intro = wp_parse_args($preview_intro, Zen_Cortext_Defaults::intro_card());
            $preview_logo  = !empty($preview_intro['logo_url']) ? $preview_intro['logo_url'] : '';
            $preview_name  = $preview_intro['name'] ?: get_bloginfo('name');
            $preview_role  = $preview_intro['role'];
            $preview_body  = $preview_intro['body'] ?: 'I draw on the published content of this site to answer pre-sales questions — case studies, services, FAQs.';
            $preview_site  = $preview_intro['site_url'] ?: home_url('/');
            $preview_disp  = preg_replace('#^https?://#', '', untrailingslashit($preview_site));
            ?>
            <div class="zen-cortext-root zce-mini-chat">

                <?php
                // Left-rail card sample — uses the live saved quick_links
                // so admins comparing palettes see their actual rail UI.
                // The chat-page CSS for .zcp-rail-btn isn't loaded in
                // admin, so we inline a compact equivalent below that
                // mirrors the same token references (--zc-surface, etc.)
                // and the same hover behavior. Drop-in HTML so the JS
                // hover-map can match these elements like any other.
                $preview_links = (array) get_option('zen_cortext_quick_links', Zen_Cortext_Defaults::default_quick_links());
                $preview_link  = !empty($preview_links[0]) ? $preview_links[0] : null;
                ?>
                <?php if ($preview_link): ?>
                <div class="zce-preview-rail">
                    <span class="zce-preview-rail-label"><?php esc_html_e('Left rail card', 'zen-cortext'); ?></span>
                    <a class="zcp-rail-btn" href="#" onclick="return false;">
                        <span class="zcp-rail-btn-icon" aria-hidden="true"><?php echo esc_html($preview_link['icon'] ?? '🌐'); ?></span>
                        <span class="zcp-rail-btn-label">
                            <?php if (!empty($preview_link['prefix'])): ?>
                                <span class="zcp-rail-btn-prefix"><?php echo esc_html($preview_link['prefix']); ?></span>
                            <?php endif; ?>
                            <?php echo esc_html($preview_link['label'] ?? ''); ?>
                        </span>
                    </a>
                </div>
                <?php endif; ?>

                <div class="zc-hero">
                    <h2><?php esc_html_e('Talk to a', 'zen-cortext'); ?> <span class="accent"><?php esc_html_e('technical consultant', 'zen-cortext'); ?></span></h2>
                </div>

                <div class="zc-intro-card" style="opacity:1;">
                    <div class="zc-intro-top">
                        <?php if ($preview_logo): ?>
                            <a href="#" onclick="return false;" class="zc-intro-logo">
                                <img src="<?php echo esc_url($preview_logo); ?>" alt="">
                            </a>
                        <?php endif; ?>
                        <div class="zc-intro-meta">
                            <div class="zc-intro-name"><?php echo esc_html($preview_name); ?></div>
                            <div class="zc-intro-role"><?php echo esc_html($preview_role); ?></div>
                        </div>
                    </div>
                    <div class="zc-intro-body"><p><?php echo esc_html($preview_body); ?></p></div>
                    <div class="zc-intro-actions">
                        <a href="#" onclick="return false;" class="zc-intro-link"><?php echo esc_html($preview_disp); ?> &#8599;</a>
                    </div>
                </div>

                <div class="zc-message assistant"><div class="zc-bubble"><?php esc_html_e('Hi — how can I help you today?', 'zen-cortext'); ?></div></div>
                <div class="zc-message user"><div class="zc-bubble"><?php esc_html_e('My WooCommerce store is slow under real traffic.', 'zen-cortext'); ?></div></div>
                <div class="zc-message assistant">
                    <div class="zc-bubble"><?php esc_html_e('That\'s the most common complaint we hear — and it almost always points to the origin choking once cache is bypassed. Which path do you want to dig into?', 'zen-cortext'); ?></div>
                    <div class="zc-message-chips">
                        <button type="button" class="zc-message-chip"><?php esc_html_e('Diagnose the bottleneck', 'zen-cortext'); ?></button>
                        <button type="button" class="zc-message-chip selected"><?php esc_html_e('See a case like ours', 'zen-cortext'); ?></button>
                        <button type="button" class="zc-message-chip"><?php esc_html_e('Get a quote', 'zen-cortext'); ?></button>
                    </div>
                </div>

                <div class="zc-typing" style="display:flex;">
                    <div class="zc-typing-bubble"><span></span><span></span><span></span></div>
                </div>

                <div class="zc-input-area">
                    <div class="zc-share">
                        <button type="button" class="zc-share-button">
                            <span class="zc-share-icon">🔗</span>
                            <span class="zc-share-label"><?php esc_html_e('Save this conversation', 'zen-cortext'); ?></span>
                        </button>
                        <button type="button" class="zc-email-button">
                            <span class="zc-email-icon">✉</span>
                            <span class="zc-email-label"><?php esc_html_e('Email me a copy', 'zen-cortext'); ?></span>
                        </button>
                        <button type="button" class="zc-delete-button">
                            <span class="zc-delete-icon">🗑</span>
                            <span class="zc-delete-label"><?php esc_html_e('Delete', 'zen-cortext'); ?></span>
                        </button>
                    </div>
                    <div class="zc-chips" style="display:flex;">
                        <button type="button" class="zc-chip">📋 <?php esc_html_e('What services do you offer?', 'zen-cortext'); ?></button>
                        <button type="button" class="zc-chip">💰 <?php esc_html_e('How does pricing work?', 'zen-cortext'); ?></button>
                        <button type="button" class="zc-chip selected">📞 <?php esc_html_e('Talk to a human', 'zen-cortext'); ?></button>
                    </div>
                    <div class="zc-input-row">
                        <textarea class="zc-input" rows="1" placeholder="<?php esc_attr_e('Describe your situation...', 'zen-cortext'); ?>"></textarea>
                        <button class="zc-send"><?php esc_html_e('Send', 'zen-cortext'); ?></button>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</section>
</div><!-- /#zen-cortext-design -->

<style>
.zcd-section { margin-bottom: 32px; padding-bottom: 16px; border-bottom: 1px solid #dcdcde; }
.zcd-section-title { margin: 24px 0 8px; }
.zcd-section-hint { color: #50575e; margin: 0 0 16px; max-width: 880px; }
.zcd-create-form { margin: 12px 0 18px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
.zcd-create-form .description { color: #50575e; }
.zcd-pages-table { margin-top: 8px; max-width: 1100px; }
.zcd-pages-table th, .zcd-pages-table td { vertical-align: middle; }
.zcd-pages-table .zcd-actions { white-space: nowrap; }
.zcd-pages-table .zcd-actions .button { margin-right: 4px; }
.zcd-empty { color: #50575e; margin-top: 8px; }
</style>
