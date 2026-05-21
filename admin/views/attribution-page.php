<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Zen Cortext — Attribution Context admin page.
 * CRUD list + editor for rules that customize the visitor chat based on
 * UTM tags, referrer host, or gclid presence.
 */
if (!defined('ABSPATH')) exit;
?>
<div class="wrap zen-cortext-wrap">
<h1><?php esc_html_e('Zen Cortext — Attribution Context', 'zen-cortext'); ?></h1>

<div id="zen-cortext-attribution-root" class="zen-cortext-attribution">

    <p class="description">
        <?php esc_html_e('Customize the visitor chat based on traffic source. Each rule matches on UTM tags, referrer pattern, or gclid presence — the most specific match wins. The matched rule injects its context_text into the AI system prompt and replaces the welcome message + starter chips.', 'zen-cortext'); ?>
        <br>
        <?php esc_html_e('Most match fields accept a comma-separated list — useful for grouping equivalent values (e.g. utm_source: google, bing) into a single rule.', 'zen-cortext'); ?>
    </p>

    <div class="zca-toolbar">
        <button type="button" class="button button-primary" id="zat-new"><?php esc_html_e('+ New rule', 'zen-cortext'); ?></button>
    </div>

    <table class="widefat striped zca-list" id="zat-list">
        <thead>
            <tr>
                <th><?php esc_html_e('Label', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Source', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Medium', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Campaign', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Referrer', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('gclid', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Priority', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Enabled', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Updated', 'zen-cortext'); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody id="zat-list-body">
            <tr><td colspan="10"><em><?php esc_html_e('Loading…', 'zen-cortext'); ?></em></td></tr>
        </tbody>
    </table>

    <!-- Editor — hidden until New or Edit -->
    <div class="zca-editor" id="zat-editor" style="display:none;">
        <h3 id="zat-editor-title"><?php esc_html_e('New rule', 'zen-cortext'); ?></h3>

        <input type="hidden" id="zat-id" value="" />

        <table class="form-table" role="presentation">
            <tr>
                <th><label for="zat-label"><?php esc_html_e('Label', 'zen-cortext'); ?></label></th>
                <td><input type="text" id="zat-label" class="regular-text" placeholder="<?php esc_attr_e('e.g. Foreman Pro Q2 GAds', 'zen-cortext'); ?>" /></td>
            </tr>

            <tr>
                <th colspan="2"><h4><?php esc_html_e('Match conditions', 'zen-cortext'); ?></h4>
                    <p class="description"><?php esc_html_e('Leave a field empty to treat it as a wildcard. The rule matches when ALL non-empty conditions match.', 'zen-cortext'); ?></p>
                </th>
            </tr>
            <tr>
                <th><label for="zat-utm-source"><?php esc_html_e('utm_source', 'zen-cortext'); ?></label></th>
                <td><input type="text" id="zat-utm-source" class="regular-text" />
                    <p class="description">
                        <?php esc_html_e('Comma-separated. Matches if the visitor\'s utm_source equals ANY entry. Case-insensitive.', 'zen-cortext'); ?>
                        <?php
                        printf(
                            /* translators: %s is a <code>-wrapped example value(s) the admin can paste into the field. */
                            ' ' . wp_kses(__('Example: %s.', 'zen-cortext'), array('code' => array())),
                            '<code>google, bing</code>'
                        );
                        ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="zat-utm-medium"><?php esc_html_e('utm_medium', 'zen-cortext'); ?></label></th>
                <td><input type="text" id="zat-utm-medium" class="regular-text" />
                    <p class="description">
                        <?php esc_html_e('Comma-separated. Matches if the visitor\'s utm_medium equals ANY entry.', 'zen-cortext'); ?>
                        <?php
                        printf(
                            /* translators: %s is a <code>-wrapped example value(s) the admin can paste into the field. */
                            ' ' . wp_kses(__('Example: %s.', 'zen-cortext'), array('code' => array())),
                            '<code>cpc, ppc</code>'
                        );
                        ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="zat-utm-campaign"><?php esc_html_e('utm_campaign', 'zen-cortext'); ?></label></th>
                <td>
                    <input type="text" id="zat-utm-campaign" class="regular-text" />
                    <span class="zat-synced-picker-wrap" style="margin-left:8px;">
                        <select id="zat-synced-picker" style="max-width:340px;">
                            <option value=""><?php esc_html_e('— Or pick from Google Ads sync —', 'zen-cortext'); ?></option>
                        </select>
                    </span>
                    <p class="description">
                        <?php esc_html_e('Comma-separated. Each entry can be a campaign name OR the numeric campaign ID. If any entry matches a synced row (by either field), live Google Ads metadata is appended to the AI prompt.', 'zen-cortext'); ?>
                        <?php
                        printf(
                            /* translators: %s is a <code>-wrapped example value(s) the admin can paste into the field. */
                            ' ' . wp_kses(__('Example: %s.', 'zen-cortext'), array('code' => array())),
                            '<code>foreman-pro-q2, foreman-pro-q3</code>'
                        );
                        ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="zat-referrer-host"><?php esc_html_e('Referrer pattern', 'zen-cortext'); ?></label></th>
                <td><input type="text" id="zat-referrer-host" class="regular-text" />
                    <p class="description">
                        <?php esc_html_e('Comma-separated. Each entry is one of:', 'zen-cortext'); ?>
                        <br>
                        <?php
                        printf(
                            wp_kses(
                                /* translators: %1$s is a sample bare host, %2$s is the literal "www.", %3$s is a path-fragment example, %4$s is a host-plus-path example — all in <code> tags. */
                                __('%1$s — bare host (matches the referrer\'s host exactly, %2$s stripped automatically); %3$s — path fragment (matches anywhere in the referrer URL); %4$s — host + path fragment (good for internal-traffic rules).', 'zen-cortext'),
                                array('code' => array())
                            ),
                            '<code>facebook.com</code>',
                            '<code>www.</code>',
                            '<code>/blog</code>',
                            '<code>zenrepublic.agency/services</code>'
                        );
                        ?>
                        <br>
                        <?php
                        printf(
                            /* translators: %s is a <code>-wrapped example value(s) the admin can paste into the field. */
                            wp_kses(__('Example: %s.', 'zen-cortext'), array('code' => array())),
                            '<code>facebook.com, /blog, zenrepublic.agency/services</code>'
                        );
                        ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Has gclid?', 'zen-cortext'); ?></th>
                <td>
                    <label><input type="checkbox" id="zat-gclid-present" /> <?php esc_html_e('Match any visitor with a Google Ads click ID', 'zen-cortext'); ?></label>
                    <p class="description"><?php esc_html_e('Useful as a coarse fallback when UTM tags are missing but the gclid is present.', 'zen-cortext'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="zat-priority"><?php esc_html_e('Priority', 'zen-cortext'); ?></label></th>
                <td>
                    <input type="number" id="zat-priority" value="0" step="1" />
                    <p class="description"><?php esc_html_e('Tiebreaker when multiple rules match with the same specificity. Higher wins. Specificity always beats priority.', 'zen-cortext'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Enabled', 'zen-cortext'); ?></th>
                <td><label><input type="checkbox" id="zat-enabled" checked /> <?php esc_html_e('Active', 'zen-cortext'); ?></label></td>
            </tr>

            <tr>
                <th colspan="2"><h4><?php esc_html_e('Customization', 'zen-cortext'); ?></h4></th>
            </tr>
            <tr>
                <th><label for="zat-survey"><?php esc_html_e('Attached survey', 'zen-cortext'); ?></label></th>
                <td>
                    <select id="zat-survey">
                        <option value=""><?php esc_html_e('— None (use Default Survey from Chat tab) —', 'zen-cortext'); ?></option>
                    </select>
                    <p class="description">
                        <?php
                        printf(
                            wp_kses(
                                /* translators: %1$s is an opening <a> tag pointing to the Surveys admin page, %2$s is the closing </a>. */
                                __('Optional. The AI weaves the chosen survey\'s questions into the chat when this rule matches. Manage scripts on %1$sZen Cortext → Surveys%2$s.', 'zen-cortext'),
                                array('a' => array('href' => array()))
                            ),
                            '<a href="' . esc_url(admin_url('admin.php?page=zen-cortext-surveys')) . '">',
                            '</a>'
                        );
                        ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="zat-context"><?php esc_html_e('System prompt context', 'zen-cortext'); ?></label></th>
                <td>
                    <textarea id="zat-context" rows="6" class="large-text code" placeholder="<?php esc_attr_e('e.g. This campaign targets WooCommerce store owners running Foreman inventory. Hero promise: sync your stock in 5 minutes. Focus on inventory pain. Mention the Foreman case study when relevant.', 'zen-cortext'); ?>"></textarea>
                    <p class="description"><?php esc_html_e('Appended to the system prompt when a visitor matches. Tell the AI what offer/landing they came from and how to frame the conversation.', 'zen-cortext'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="zat-invite"><?php esc_html_e('Welcome message', 'zen-cortext'); ?></label></th>
                <td>
                    <textarea id="zat-invite" rows="6" class="large-text" placeholder="<?php esc_attr_e('Welcome! I see you came from our Foreman ad — happy to walk you through the integration.', 'zen-cortext'); ?>"></textarea>
                    <p class="description"><?php esc_html_e('Replaces the default welcome typewriter (step 2) on the chat page when this rule matches.', 'zen-cortext'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="zat-chips"><?php esc_html_e('Starter chips', 'zen-cortext'); ?></label></th>
                <td>
                    <p class="description" style="margin-top:0;">
                        <?php esc_html_e('Replaces the default starter chips when this rule matches. One chip per line. Chat picks 4 at random per visit. Empty = no chips shown.', 'zen-cortext'); ?>
                        <br>
                        <?php
                        printf(
                            /* translators: %1$s is the full chip format example (emoji | label | message), %2$s and %3$s are the optional individual fields ("emoji" and "message"), each wrapped in <code>. */
                            esc_html__('Format per line: %1$s — %2$s and %3$s are optional.', 'zen-cortext'),
                            '<code>emoji | label | message</code>',
                            '<code>emoji</code>',
                            '<code>message</code>'
                        );
                        ?>
                        <br>
                        <?php esc_html_e('Examples:', 'zen-cortext'); ?>
                        <code>📦 | Office cleaning | I need office cleaning</code>,
                        <code>Office cleaning | I need office cleaning</code>,
                        <code>Get a quote</code>
                    </p>
                    <textarea id="zat-chips" rows="10" class="large-text code"
                              placeholder="📦 | Office cleaning | I need office cleaning&#10;🏢 | Warehouse | What about warehouses?&#10;Get a quote"></textarea>
                </td>
            </tr>
            <tr>
                <th><label for="zat-intro-enabled"><?php esc_html_e('Intro card override', 'zen-cortext'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" id="zat-intro-enabled" />
                        <?php esc_html_e('Replace the global intro card (step 1) when this rule matches.', 'zen-cortext'); ?>
                    </label>
                    <div id="zat-intro-fields" style="margin-top:12px;display:none;">
                        <p class="description" style="margin-top:0;">
                            <?php esc_html_e('Shown above the welcome typewriter on the chat page. Leave a field blank to render it empty for this rule — global values are NOT merged in.', 'zen-cortext'); ?>
                        </p>
                        <table class="form-table zat-intro-inner" role="presentation">
                            <tr>
                                <th><label for="zat-intro-name"><?php esc_html_e('Name', 'zen-cortext'); ?></label></th>
                                <td><input type="text" id="zat-intro-name" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th><label for="zat-intro-role"><?php esc_html_e('Role / tagline', 'zen-cortext'); ?></label></th>
                                <td><input type="text" id="zat-intro-role" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th><label for="zat-intro-body"><?php esc_html_e('Body', 'zen-cortext'); ?></label></th>
                                <td><textarea id="zat-intro-body" rows="4" class="large-text"></textarea></td>
                            </tr>
                            <tr>
                                <th><label for="zat-intro-logo"><?php esc_html_e('Logo URL', 'zen-cortext'); ?></label></th>
                                <td><input type="url" id="zat-intro-logo" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th><label for="zat-intro-site"><?php esc_html_e('Site URL', 'zen-cortext'); ?></label></th>
                                <td><input type="url" id="zat-intro-site" class="regular-text" /></td>
                            </tr>
                        </table>
                        <p>
                            <button type="button" class="button" id="zat-intro-prefill"><?php esc_html_e('Prefill from global intro card', 'zen-cortext'); ?></button>
                        </p>
                    </div>
                </td>
            </tr>
        </table>

        <div id="zat-synced-preview" class="zat-synced-preview" style="display:none;">
            <h4>
                <?php esc_html_e('Synced Google Ads data for', 'zen-cortext'); ?>
                <code id="zat-synced-preview-name"></code>
            </h4>
            <p class="description">
                <?php esc_html_e('This data is appended to the AI prompt automatically whenever this rule matches. Use it as reference when writing your context — and click the button below to seed a starter Context block based on this data.', 'zen-cortext'); ?>
            </p>
            <div class="zat-synced-preview-grid">
                <div>
                    <h5><?php esc_html_e('Top headlines', 'zen-cortext'); ?>
                        <span class="count" id="zat-synced-headlines-count"></span></h5>
                    <ul id="zat-synced-headlines-list"></ul>
                </div>
                <div>
                    <h5><?php esc_html_e('Top keywords', 'zen-cortext'); ?>
                        <span class="count" id="zat-synced-keywords-count"></span></h5>
                    <ul id="zat-synced-keywords-list"></ul>
                </div>
            </div>
            <p>
                <button type="button" class="button" id="zat-synced-insert">
                    <?php esc_html_e('Insert summary into Context', 'zen-cortext'); ?>
                </button>
                <span id="zat-synced-insert-status" class="zca-status"></span>
            </p>
        </div>

        <p class="zca-editor-actions">
            <button type="button" class="button button-primary" id="zat-save"><?php esc_html_e('Save', 'zen-cortext'); ?></button>
            <button type="button" class="button" id="zat-cancel"><?php esc_html_e('Cancel', 'zen-cortext'); ?></button>
            <button type="button" class="button button-link-delete" id="zat-delete" style="float:right;display:none;"><?php esc_html_e('Delete rule', 'zen-cortext'); ?></button>
            <span id="zat-save-status" class="zca-status"></span>
        </p>
    </div>
</div>

<style>
.zen-cortext-attribution .zat-synced-preview {
    background: #f6f7f7;
    border-left: 4px solid #2271b1;
    padding: 14px 18px;
    margin: 16px 0 24px 0;
}
.zen-cortext-attribution .zat-synced-preview h4 { margin: 0 0 4px 0; }
.zen-cortext-attribution .zat-synced-preview-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-top: 12px;
}
.zen-cortext-attribution .zat-synced-preview-grid h5 {
    margin: 0 0 6px 0;
    font-size: 13px;
}
.zen-cortext-attribution .zat-synced-preview-grid h5 .count {
    color: #757575;
    font-weight: normal;
}
.zen-cortext-attribution .zat-synced-preview ul {
    margin: 0;
    padding-left: 18px;
    max-height: 220px;
    overflow-y: auto;
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 3px;
    padding: 8px 8px 8px 26px;
}
.zen-cortext-attribution .zat-synced-preview li { margin-bottom: 2px; }
</style>
</div>
