<?php
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
            <p class="zce-preview-hint"><?php esc_html_e('Updates instantly as you adjust pickers — these are the same tokens chat.css uses on the live site.', 'zen-cortext'); ?></p>
            <div class="zen-cortext-root zce-mini-chat">
                <div class="zc-message assistant"><div class="zc-bubble">Hi — how can I help you today?</div></div>
                <div class="zc-message user"><div class="zc-bubble">My WooCommerce store is slow under real traffic.</div></div>
                <div class="zc-message assistant"><div class="zc-bubble">That's the most common complaint we hear — and it almost always points to the origin choking once cache is bypassed.</div></div>
                <div class="zc-input-area">
                    <div class="zc-input-row">
                        <textarea class="zc-input" rows="1" placeholder="Describe your situation..."></textarea>
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
