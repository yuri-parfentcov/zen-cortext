<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Zen Cortext — Knowledge Artifacts tab body.
 *
 * Included by kb-page.php when ?tab=artifacts. No .wrap or <h1> here;
 * those are owned by the parent page so the tab nav stays consistent.
 * All CRUD runs via AJAX (zen_cortext_artifact_*). The chat builder
 * streams from /wp-json/zen-cortext/v1/artifact-chat.
 */
if (!defined('ABSPATH')) exit;

// Artifact types are sourced from the unified content types option,
// edited on the sibling Knowledge Base tab. Adding "product_review"
// there makes it immediately available here, no plugin update needed.
$artifact_types = Zen_Cortext_KB_Types::labels();
?>

<?php if (empty($artifact_types)): ?>
<div class="notice notice-warning inline" style="margin:12px 0;">
    <p>
        <?php
        printf(
            wp_kses(
                /* translators: %s is the HTML link to the Knowledge Base tab where content types are defined. */
                __('No content types are defined yet. Open the %s tab and add at least one before creating artifacts.', 'zen-cortext'),
                array('a' => array('href' => array()))
            ),
            '<a href="' . esc_url(add_query_arg(array('page' => 'zen-cortext-kb', 'tab' => 'kb'), admin_url('admin.php'))) . '"><strong>' . esc_html__('Knowledge Base', 'zen-cortext') . '</strong></a>'
        );
        ?>
    </p>
</div>
<?php endif; ?>

<div id="zen-cortext-artifacts-root" class="zen-cortext-artifacts">

    <p class="description">
        <?php esc_html_e('Hand-curated knowledge items used as context in the public chat. Authored either as free text or by chatting with the AI builder, then restructured automatically on save.', 'zen-cortext'); ?>
    </p>

    <div class="zca-toolbar">
        <button type="button" class="button button-primary" id="zca-new"><?php esc_html_e('+ New artifact', 'zen-cortext'); ?></button>

        <label class="zca-filter-label" for="zca-filter-type"><?php esc_html_e('Filter:', 'zen-cortext'); ?></label>
        <select id="zca-filter-type">
            <option value=""><?php esc_html_e('All types', 'zen-cortext'); ?></option>
            <?php foreach ($artifact_types as $key => $label): ?>
                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <table class="widefat striped zca-list" id="zca-list">
        <thead>
            <tr>
                <th><?php esc_html_e('Title', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Type', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Author', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Source', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Updated', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Body', 'zen-cortext'); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody id="zca-list-body">
            <tr><td colspan="7"><em><?php esc_html_e('Loading…', 'zen-cortext'); ?></em></td></tr>
        </tbody>
    </table>

    <div class="zca-stats" id="zca-stats"></div>

    <!-- Editor panel: hidden until New or Edit -->
    <div class="zca-editor" id="zca-editor" style="display:none;">
        <h3 id="zca-editor-title"><?php esc_html_e('New artifact', 'zen-cortext'); ?></h3>

        <input type="hidden" id="zca-id" value="" />
        <input type="hidden" id="zca-source" value="manual" />

        <table class="form-table" role="presentation">
            <tr>
                <th><label for="zca-title"><?php esc_html_e('Title', 'zen-cortext'); ?></label></th>
                <td><input type="text" id="zca-title" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="zca-type"><?php esc_html_e('Type', 'zen-cortext'); ?></label></th>
                <td>
                    <select id="zca-type">
                        <?php foreach ($artifact_types as $key => $label): ?>
                            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="zca-author"><?php esc_html_e('Author', 'zen-cortext'); ?></label></th>
                <td>
                    <select id="zca-author">
                        <option value=""><?php esc_html_e('— None —', 'zen-cortext'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Associate this artifact with a team member. The AI will use this attribution in the chat context.', 'zen-cortext'); ?></p>
                </td>
            </tr>
        </table>

        <!-- Chat builder panel — collapsible, sits above the textarea -->
        <div class="zca-builder">
            <div class="zca-builder-header">
                <button type="button" class="button" id="zca-builder-toggle">
                    <?php esc_html_e('💬 Chat builder', 'zen-cortext'); ?>
                </button>
                <span class="description"><?php esc_html_e('Discuss the topic with the AI, then click Form Artifact to populate the body below.', 'zen-cortext'); ?></span>
            </div>

            <div class="zca-builder-panel" id="zca-builder-panel" style="display:none;">

                <div class="zca-refs" id="zca-refs">
                    <label class="zca-refs-label"><?php esc_html_e('Reference artifacts (optional)', 'zen-cortext'); ?></label>
                    <div class="zca-refs-pills" id="zca-refs-pills"></div>
                    <div class="zca-refs-search">
                        <input type="text" id="zca-refs-input"
                               placeholder="<?php esc_attr_e('Search artifacts to include as background context…', 'zen-cortext'); ?>"
                               autocomplete="off" />
                        <div class="zca-refs-dropdown" id="zca-refs-dropdown" hidden></div>
                    </div>
                    <p class="description">
                        <?php esc_html_e('Pick existing artifacts the AI should read as background. The AI uses them for terminology, structure, and duplicate detection — it will NOT copy facts from them into your new artifact unless you mention them in chat.', 'zen-cortext'); ?>
                    </p>
                </div>

                <div class="zca-chat" id="zca-chat"></div>

                <div class="zca-chat-input">
                    <textarea id="zca-chat-input" rows="2" placeholder="<?php esc_attr_e('Tell the AI what you want to capture…', 'zen-cortext'); ?>"></textarea>
                    <button type="button" class="button" id="zca-chat-send"><?php esc_html_e('Send', 'zen-cortext'); ?></button>
                </div>

                <div class="zca-builder-actions">
                    <button type="button" class="button button-secondary" id="zca-form-artifact">
                        <?php esc_html_e('📋 Form artifact (populate body below)', 'zen-cortext'); ?>
                    </button>
                    <button type="button" class="button-link" id="zca-chat-reset"><?php esc_html_e('Reset chat', 'zen-cortext'); ?></button>
                </div>
            </div>
        </div>

        <h4><?php esc_html_e('Body', 'zen-cortext'); ?></h4>
        <p class="description"><?php esc_html_e('Free-form text. The AI restructures this into the schema for the selected type when you save.', 'zen-cortext'); ?></p>
        <textarea id="zca-raw" rows="14" class="large-text code"></textarea>

        <p class="zca-editor-actions">
            <button type="button" class="button" id="zca-save" title="<?php esc_attr_e('Save metadata changes without re-running the AI restructure step. Existing structured content is preserved.', 'zen-cortext'); ?>"><?php esc_html_e('Save', 'zen-cortext'); ?></button>
            <button type="button" class="button button-primary" id="zca-save-restructure" title="<?php esc_attr_e('Save and re-run the AI restructure step on the body. Takes ~10-20s. Use this after edits to the body text.', 'zen-cortext'); ?>"><?php esc_html_e('Save and Restructure', 'zen-cortext'); ?></button>
            <button type="button" class="button" id="zca-cancel"><?php esc_html_e('Cancel', 'zen-cortext'); ?></button>
            <span id="zca-save-status" class="zca-status"></span>
        </p>

        <div class="zca-preview" id="zca-preview" style="display:none;">
            <h4><?php esc_html_e('Restructured (used in chat context)', 'zen-cortext'); ?></h4>
            <pre id="zca-preview-body"></pre>
        </div>
    </div>
</div><!-- /#zen-cortext-artifacts-root -->
