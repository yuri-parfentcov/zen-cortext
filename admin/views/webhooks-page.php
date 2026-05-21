<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Zen Cortext — Webhooks admin page.
 * CRUD list + editor for outbound JSON POSTs fired on chat events
 * (lead.captured, invite.sent, admin.joined/left, chat.started).
 */
if (!defined('ABSPATH')) exit;
?>
<div class="wrap zen-cortext-wrap">
<h1><?php esc_html_e('Zen Cortext — Webhooks', 'zen-cortext'); ?></h1>

<div id="zen-cortext-webhooks-root" class="zen-cortext-attribution">

    <p class="description">
        <?php esc_html_e('Outbound JSON POSTs to your CRM, Zapier, Slack, or custom endpoint when chat events happen. Each endpoint subscribes to the events it cares about; deliveries are fire-and-forget (no queue, no retry) so a slow target never stalls the visitor\'s request.', 'zen-cortext'); ?>
        <br>
        <?php esc_html_e('Body is JSON with a common envelope: { event, delivery_id, occurred_at, site, data }. Headers include X-Zen-Cortext-Event and X-Zen-Cortext-Delivery. Use the "Send test" button after saving to verify reachability.', 'zen-cortext'); ?>
    </p>

    <div class="zca-toolbar">
        <button type="button" class="button button-primary" id="zwh-new"><?php esc_html_e('+ New endpoint', 'zen-cortext'); ?></button>
    </div>

    <table class="widefat striped zca-list" id="zwh-list">
        <thead>
            <tr>
                <th><?php esc_html_e('Label', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('URL', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Events', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Enabled', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Updated', 'zen-cortext'); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody id="zwh-list-body">
            <tr><td colspan="6"><em><?php esc_html_e('Loading…', 'zen-cortext'); ?></em></td></tr>
        </tbody>
    </table>

    <!-- Editor — hidden until New or Edit -->
    <div class="zca-editor" id="zwh-editor" style="display:none;">
        <h3 id="zwh-editor-title"><?php esc_html_e('New endpoint', 'zen-cortext'); ?></h3>

        <input type="hidden" id="zwh-id" value="" />

        <table class="form-table" role="presentation">
            <tr>
                <th><label for="zwh-label"><?php esc_html_e('Label', 'zen-cortext'); ?></label></th>
                <td>
                    <input type="text" id="zwh-label" class="regular-text" placeholder="<?php esc_attr_e('e.g. HubSpot lead sync', 'zen-cortext'); ?>" />
                    <p class="description"><?php esc_html_e('Internal name shown in the list. Defaults to the URL host if left blank.', 'zen-cortext'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="zwh-url"><?php esc_html_e('Endpoint URL', 'zen-cortext'); ?></label></th>
                <td>
                    <input type="url" id="zwh-url" class="regular-text code" placeholder="https://hooks.example.com/zen-cortext" />
                    <p class="description"><?php esc_html_e('Full HTTPS URL that accepts a POST with a JSON body.', 'zen-cortext'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Events to send', 'zen-cortext'); ?></th>
                <td>
                    <div id="zwh-events"><em><?php esc_html_e('Loading…', 'zen-cortext'); ?></em></div>
                    <p class="description"><?php esc_html_e('Only checked events will trigger this endpoint.', 'zen-cortext'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Enabled', 'zen-cortext'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" id="zwh-enabled" checked />
                        <?php esc_html_e('Fire deliveries to this endpoint.', 'zen-cortext'); ?>
                    </label>
                </td>
            </tr>
        </table>

        <p class="zca-editor-actions">
            <button type="button" class="button button-primary" id="zwh-save"><?php esc_html_e('Save', 'zen-cortext'); ?></button>
            <button type="button" class="button" id="zwh-cancel"><?php esc_html_e('Cancel', 'zen-cortext'); ?></button>
            <button type="button" class="button" id="zwh-test" style="display:none;"><?php esc_html_e('Send test', 'zen-cortext'); ?></button>
            <button type="button" class="button button-link-delete" id="zwh-delete" style="float:right;display:none;"><?php esc_html_e('Delete endpoint', 'zen-cortext'); ?></button>
            <span id="zwh-save-status" class="zca-status"></span>
        </p>
    </div>
</div>
</div>
