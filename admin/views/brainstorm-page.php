<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Zen Cortext — Admin Brainstorm chat page.
 *
 * Persistent chat with Claude Opus + extended thinking, wired to the same
 * KB / Artifacts / Team Expertise context the visitor chat uses. Each
 * conversation is saved per-user (admin can revisit and continue past
 * brainstorms or delete them).
 */
if (!defined('ABSPATH')) exit;
?>
<div class="wrap zen-cortext-wrap zen-cortext-brainstorm">
    <h1><?php esc_html_e('Zen Cortext — Brainstorm', 'zen-cortext'); ?></h1>
    <p class="description">
        <?php esc_html_e('Internal chat for ideation and content drafting. Pick a model below (Opus for deepest reasoning, Sonnet or Haiku to save cost), grounded in the same Knowledge Base, Knowledge Artifacts, and Team Expertise the visitor chat sees. Conversations are saved automatically — pick one from the sidebar to continue.', 'zen-cortext'); ?>
    </p>

    <div id="zcb-root" class="zcb-root">
        <aside class="zcb-sidebar" id="zcb-sidebar">
            <button type="button" class="button button-primary zcb-new-btn" id="zcb-new">
                + <?php esc_html_e('New brainstorm', 'zen-cortext'); ?>
            </button>
            <div class="zcb-list" id="zcb-list" aria-live="polite">
                <div class="zcb-list-empty"><?php esc_html_e('Loading…', 'zen-cortext'); ?></div>
            </div>
        </aside>

        <section class="zcb-main">
            <div class="zcb-toolbar">
                <span class="zcb-meta">
                    <?php
                    // Backend label is filterable so the optional CLI companion
                    // plugin can advertise itself; the core plugin is API-only.
                    $zcb_backend   = apply_filters('zen_cortext_backend_label', 'Anthropic API (billed)');
                    $zcb_caching   = apply_filters('zen_cortext_backend_caching', 'ephemeral');
                    $zcb_models    = Zen_Cortext_API::brainstorm_models();
                    $zcb_default   = Zen_Cortext_API::BRAINSTORM_MODEL;
                    ?>
                    <span class="zcb-meta-item"><?php esc_html_e('Backend:', 'zen-cortext'); ?> <code><?php echo esc_html($zcb_backend); ?></code></span>
                    <span class="zcb-meta-item">
                        <label for="zcb-model"><?php esc_html_e('Model:', 'zen-cortext'); ?></label>
                        <select id="zcb-model" class="zcb-model-select">
                            <?php foreach ($zcb_models as $zcb_id => $zcb_m) : ?>
                                <option value="<?php echo esc_attr($zcb_id); ?>" <?php selected($zcb_id, $zcb_default); ?>>
                                    <?php echo esc_html($zcb_m['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </span>
                    <span class="zcb-meta-item"><?php esc_html_e('Caching:', 'zen-cortext'); ?> <code><?php echo esc_html($zcb_caching); ?></code></span>
                    <span class="zcb-meta-item zcb-usage" id="zcb-usage" hidden></span>
                </span>
            </div>

            <div class="zcb-chat" id="zcb-chat">
                <div class="zcb-msg zcb-msg-system">
                    <?php esc_html_e('Ask anything. Try: "Brainstorm 5 angles for a longform article on uncached origin TTFB", or "Draft an outline for a Shopify→Woo migration case study using the Acme artifact".', 'zen-cortext'); ?>
                </div>
            </div>

            <div class="zcb-input-row">
                <textarea id="zcb-input" rows="3" placeholder="<?php esc_attr_e('What do you want to brainstorm? (Cmd/Ctrl + Enter to send)', 'zen-cortext'); ?>"></textarea>
                <button type="button" class="button button-primary" id="zcb-send">
                    <?php esc_html_e('Send', 'zen-cortext'); ?>
                </button>
            </div>
            <div class="zcb-status" id="zcb-status" aria-live="polite"></div>
        </section>
    </div>
</div>
