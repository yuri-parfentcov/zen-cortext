<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Zen Cortext — API › Keys tab.
 * Manages multi-key bearer-token auth for the external read API
 * (wp-json/zc/v1/*). Keys are immutable after create — revoked
 * (one-way) but not edited. Raw token shown ONCE at creation
 * (GitHub PAT model). Included from views/api-page.php; the wrap
 * div + h1 + nav-tab-wrapper are emitted by that wrapper.
 */
if (!defined('ABSPATH')) exit;
?>
<div id="zen-cortext-api-keys-root" class="zen-cortext-attribution">

    <p class="description">
        <?php esc_html_e('Bearer-token authentication for the external read API. Use these keys from your CRM, BI, or any downstream system that needs to read chats, leads, attribution rules, or knowledge base metadata via GET /wp-json/zc/v1/*.', 'zen-cortext'); ?>
        <br>
        <?php
        printf(
            /* translators: %s is a <code>-wrapped base URL of the read-only REST API exposed by the plugin. */
            esc_html__('Base URL: %s', 'zen-cortext'),
            '<code id="zwk-base-url">' . esc_html(home_url('/wp-json/zc/v1')) . '</code>'
        );
        ?>
        <br>
        <?php esc_html_e('Send requests with: Authorization: Bearer <token>. The token is shown ONCE at creation — copy it before closing that panel; the server only stores a hash and cannot recover it.', 'zen-cortext'); ?>
    </p>

    <div class="zca-toolbar">
        <button type="button" class="button button-primary" id="zwk-new"><?php esc_html_e('+ New key', 'zen-cortext'); ?></button>
    </div>

    <table class="widefat striped zca-list" id="zwk-list">
        <thead>
            <tr>
                <th><?php esc_html_e('Label', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Prefix', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Scopes', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Rate limit', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Last used', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Status', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Created', 'zen-cortext'); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody id="zwk-list-body">
            <tr><td colspan="8"><em><?php esc_html_e('Loading…', 'zen-cortext'); ?></em></td></tr>
        </tbody>
    </table>

    <!-- Editor: New key form -->
    <div class="zca-editor" id="zwk-editor" style="display:none;">
        <h3><?php esc_html_e('New API key', 'zen-cortext'); ?></h3>

        <table class="form-table" role="presentation">
            <tr>
                <th><label for="zwk-label"><?php esc_html_e('Label', 'zen-cortext'); ?></label></th>
                <td>
                    <input type="text" id="zwk-label" class="regular-text" placeholder="<?php esc_attr_e('e.g. HubSpot integration, BI dashboard', 'zen-cortext'); ?>" />
                    <p class="description"><?php esc_html_e('A short name for your records. The label is shown in this list — pick something that identifies what this key is for.', 'zen-cortext'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Scopes', 'zen-cortext'); ?></th>
                <td>
                    <div id="zwk-scopes"><em><?php esc_html_e('Loading…', 'zen-cortext'); ?></em></div>
                    <p class="description"><?php esc_html_e('Each scope unlocks one endpoint family. Pick the minimum set the integration needs.', 'zen-cortext'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="zwk-rate-min"><?php esc_html_e('Rate limit', 'zen-cortext'); ?></label></th>
                <td>
                    <input type="number" id="zwk-rate-min"  class="small-text" min="1" max="10000"   value="60" />
                    <?php esc_html_e('requests per minute', 'zen-cortext'); ?>
                    &nbsp;·&nbsp;
                    <input type="number" id="zwk-rate-hour" class="small-text" min="1" max="1000000" value="3000" />
                    <?php esc_html_e('per hour', 'zen-cortext'); ?>
                    <p class="description"><?php esc_html_e('Sliding-window per key. Exceeding either bucket returns HTTP 429 with a Retry-After header.', 'zen-cortext'); ?></p>
                </td>
            </tr>
        </table>

        <p class="zca-editor-actions">
            <button type="button" class="button button-primary" id="zwk-create"><?php esc_html_e('Create key', 'zen-cortext'); ?></button>
            <button type="button" class="button" id="zwk-cancel"><?php esc_html_e('Cancel', 'zen-cortext'); ?></button>
            <span id="zwk-status" class="zca-status"></span>
        </p>
    </div>

    <!-- One-time token display panel — only shown right after a create -->
    <div class="zca-editor" id="zwk-token-panel" style="display:none;background:#fff7e0;border-left:4px solid #d63638;">
        <h3 style="margin-top:0;"><?php esc_html_e('Copy this key now — it will not be shown again.', 'zen-cortext'); ?></h3>
        <p>
            <?php esc_html_e('This is the only time the raw token is visible. After you dismiss this panel, only the prefix will appear in the list. If you lose it, revoke and create a new key.', 'zen-cortext'); ?>
        </p>
        <p>
            <input type="text" id="zwk-token" readonly style="width:100%;font-family:monospace;font-size:14px;padding:8px;" />
        </p>
        <p>
            <button type="button" class="button button-primary" id="zwk-token-copy"><?php esc_html_e('Copy to clipboard', 'zen-cortext'); ?></button>
            <button type="button" class="button" id="zwk-token-dismiss"><?php esc_html_e('I\'ve saved it, dismiss', 'zen-cortext'); ?></button>
            <span id="zwk-token-status" class="zca-status"></span>
        </p>
    </div>
</div>
