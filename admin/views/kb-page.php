<?php
/**
 * Zen Cortext — Knowledge Base page (tabbed).
 *
 * Two tabs sharing one .wrap + <h1>:
 *  - kb (default): post types to index, classify prompt template,
 *    content types editor, Rebuild/Clear pipeline.
 *  - artifacts: hand-curated knowledge items editor (formerly its own
 *    submenu page). The legacy ?page=zen-cortext-artifacts URL 302s
 *    here via Zen_Cortext_Admin::redirect_legacy_kb_tab().
 *
 * Available: $stats, $tab
 */
if (!defined('ABSPATH')) exit;

$tab = isset($tab) && $tab === 'artifacts' ? 'artifacts' : 'kb';

$tabs = array(
    'kb'        => __('Knowledge Base', 'zen-cortext'),
    'artifacts' => __('Knowledge Artifacts', 'zen-cortext'),
);
?>
<div class="wrap zen-cortext-wrap">
    <h1><?php esc_html_e('Knowledge Base', 'zen-cortext'); ?></h1>

    <h2 class="nav-tab-wrapper">
        <?php foreach ($tabs as $key => $label): ?>
            <a href="<?php echo esc_url(add_query_arg(array('page' => 'zen-cortext-kb', 'tab' => $key), admin_url('admin.php'))); ?>"
               class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($label); ?>
            </a>
        <?php endforeach; ?>
    </h2>

    <?php if ($tab === 'artifacts'):
        include ZEN_CORTEXT_PLUGIN_DIR . 'admin/views/_artifacts-tab.php';
    else:
        $post_types_selected = (array) get_option('zen_cortext_post_types', array('post', 'page'));
        $all_post_types      = get_post_types(array('public' => true), 'objects');
        $classify_prompt     = (string) get_option('zen_cortext_classify_prompt', Zen_Cortext_Defaults::classify_prompt());
        $content_types       = Zen_Cortext_KB_Types::all();
        $pending             = (int) $stats['needs_classify'] + (int) $stats['needs_structure'];
    ?>

    <form method="post" action="options.php">
        <?php settings_fields('zen_cortext_kb'); ?>

        <h2><?php esc_html_e('Post types to index', 'zen-cortext'); ?></h2>
        <p class="description" style="max-width:860px;">
            <?php esc_html_e('Which WordPress post types get synced into the KB. Distinct from "Content types" below, which describes how the AI categorizes them.', 'zen-cortext'); ?>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th><?php esc_html_e('Post types', 'zen-cortext'); ?></th>
                <td>
                    <?php foreach ($all_post_types as $pt_obj): ?>
                        <label style="display:inline-block; margin-right:14px;">
                            <input type="checkbox" name="zen_cortext_post_types[]"
                                   value="<?php echo esc_attr($pt_obj->name); ?>"
                                <?php checked(in_array($pt_obj->name, $post_types_selected, true)); ?> />
                            <?php echo esc_html($pt_obj->labels->singular_name . ' (' . $pt_obj->name . ')'); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description"><?php esc_html_e('Default: post, page. WP_after_insert_post and before_delete_post hooks auto-sync these types — the KB stays current without manual Sync.', 'zen-cortext'); ?></p>
                </td>
            </tr>
        </table>

        <hr style="margin:28px 0;">

        <h2><?php esc_html_e('Pipeline', 'zen-cortext'); ?></h2>

        <div style="background:#f0f6fc; border-left:4px solid #2271b1; padding:12px 16px; margin:12px 0; max-width:880px;">
            <p style="margin:0 0 6px;"><strong><?php esc_html_e('Rebuild is incremental — it only spends tokens on rows that actually need work.', 'zen-cortext'); ?></strong></p>
            <ul style="margin:0 0 0 18px; padding:0; list-style:disc;">
                <li><?php esc_html_e('Sync detects new posts and posts whose content has changed (compared byte-for-byte to the cached copy).', 'zen-cortext'); ?></li>
                <li><?php esc_html_e('Classify processes only rows with NULL classification — new + reset rows. Already-classified rows are skipped.', 'zen-cortext'); ?></li>
                <li><?php esc_html_e('Restructure processes only rows with NULL structured content. Already-restructured rows are skipped.', 'zen-cortext'); ?></li>
                <li><?php esc_html_e('If nothing changed since the last Rebuild, the loops finish immediately with zero API calls.', 'zen-cortext'); ?></li>
                <li><?php
                    printf(
                        /* translators: %s is wrapped as a <strong> emphasis */
                        esc_html__('To force a full re-process of every row (e.g. after editing the classify prompt or restructure prompts), use %s first — that truncates the KB so the next Rebuild reprocesses everything.', 'zen-cortext'),
                        '<strong>' . esc_html__('Clear KB', 'zen-cortext') . '</strong>'
                    );
                ?></li>
            </ul>
        </div>

        <div class="zen-cortext-stats" id="zen-cortext-stats">
            <?php echo Zen_Cortext_Admin::render_stats_inline($stats); // phpcs:ignore ?>
        </div>

        <?php if ($pending > 0): ?>
        <p style="background:#fff8e5; border-left:4px solid #f0b849; padding:10px 14px; margin:12px 0;">
            <strong><?php echo (int) $pending; ?></strong>
            <?php esc_html_e('row(s) pending — Rebuild KB will spend approximately one API call per row on classify and one per row on restructure. Already-processed rows are untouched.', 'zen-cortext'); ?>
        </p>
        <?php else: ?>
        <p style="background:#edfaef; border-left:4px solid #00a32a; padding:10px 14px; margin:12px 0;">
            <?php esc_html_e('Everything is processed. Rebuild now will just verify sync state — no API calls.', 'zen-cortext'); ?>
        </p>
        <?php endif; ?>

        <p>
            <button type="button" class="button button-primary button-large" id="zen-cortext-rebuild">
                <?php esc_html_e('Rebuild KB', 'zen-cortext'); ?>
            </button>
            <button type="button" class="button button-link-delete" id="zen-cortext-clear" style="margin-left:12px;">
                <?php esc_html_e('Clear KB', 'zen-cortext'); ?>
            </button>
            <span class="description" style="margin-left:8px;">
                <?php esc_html_e('Resumable — if a Rebuild fails mid-loop, click again to pick up where it stopped without re-doing finished rows.', 'zen-cortext'); ?>
            </span>
        </p>

        <div id="zen-cortext-progress"></div>
        <div id="zen-cortext-log" class="zen-cortext-log"></div>

        <hr style="margin:28px 0;">

        <h2><?php esc_html_e('Content types', 'zen-cortext'); ?></h2>
        <p class="description" style="max-width:860px;">
            <?php esc_html_e('The categories the AI classifies synced posts into, plus a per-type restructure prompt that defines the output schema for the chat context. Each type appears as a bullet inside the classify prompt template below; the AI picks the best fit (or "other" if nothing matches).', 'zen-cortext'); ?>
        </p>

        <div id="zen-cortext-types-editor" data-existing-slugs="<?php
            echo esc_attr(implode(',', wp_list_pluck($content_types, 'slug')));
        ?>">
            <div class="zen-cortext-types-list">
                <?php foreach ($content_types as $i => $t): ?>
                    <?php zen_cortext_render_type_row($i, $t, false); ?>
                <?php endforeach; ?>
            </div>
            <p>
                <button type="button" class="button" id="zen-cortext-types-add">
                    + <?php esc_html_e('Add content type', 'zen-cortext'); ?>
                </button>
                <button type="button" class="button button-primary" id="zen-cortext-types-save" style="margin-left:8px;">
                    <?php esc_html_e('Save content types', 'zen-cortext'); ?>
                </button>
                <span id="zen-cortext-types-status" class="description" style="margin-left:8px;"></span>
            </p>
        </div>

        <hr style="margin:28px 0;">

        <h2><?php esc_html_e('Classify prompt template', 'zen-cortext'); ?></h2>
        <p class="description" style="max-width:860px;">
            <?php
                printf(
                    /* translators: %1$s = placeholder token, %2$s, %3$s = other placeholders */
                    esc_html__('The prompt sent to the AI for each row during classification. Must contain %1$s (auto-replaced with the bulleted list of types from above) plus %2$s and %3$s for the row title and content.', 'zen-cortext'),
                    '<code>&lt;&lt;categories&gt;&gt;</code>',
                    '<code>{title}</code>',
                    '<code>{content}</code>'
                );
            ?>
        </p>
        <textarea name="zen_cortext_classify_prompt" rows="12" class="large-text code"><?php echo esc_textarea($classify_prompt); ?></textarea>

        <?php submit_button(__('Save post types + classify prompt', 'zen-cortext')); ?>
    </form>
    <?php endif; // $tab branch ?>
</div>

<?php
/**
 * Render one row in the types editor. Slug is read-only after first
 * save — changing it would orphan KB rows classified against the old
 * slug. To "rename", admin creates a new type and deletes the old one.
 *
 * @param int|string $index_or_id  Stable DOM id suffix. For server-rendered rows: numeric index.
 * @param array      $type
 * @param bool       $is_new_row   True for client-side template rendering (slug editable).
 */
function zen_cortext_render_type_row($index_or_id, $type, $is_new_row) {
    $slug   = isset($type['slug']) ? $type['slug'] : '';
    $label  = isset($type['label']) ? $type['label'] : '';
    $desc   = isset($type['description']) ? $type['description'] : '';
    $prompt = isset($type['restructure_prompt']) ? $type['restructure_prompt'] : '';
    ?>
    <div class="zen-cortext-type-row" data-slug="<?php echo esc_attr($slug); ?>"
         style="border:1px solid #c3c4c7; border-radius:4px; padding:14px 16px; margin-bottom:14px; background:#fff;">
        <div style="display:flex; gap:14px; align-items:flex-start;">
            <div style="flex:0 0 220px;">
                <label style="display:block; font-weight:600; margin-bottom:4px;">
                    <?php esc_html_e('Slug', 'zen-cortext'); ?>
                </label>
                <input type="text"
                       class="zct-slug regular-text"
                       value="<?php echo esc_attr($slug); ?>"
                       placeholder="case_study"
                       <?php echo $is_new_row ? '' : 'readonly'; ?>
                       style="font-family:monospace;<?php echo $is_new_row ? '' : ' background:#f6f7f7;'; ?> width:100%;" />
                <p class="description" style="margin-top:4px;">
                    <?php if ($is_new_row): ?>
                        <?php esc_html_e('lowercase letters, digits, underscore', 'zen-cortext'); ?>
                    <?php else: ?>
                        <?php esc_html_e('immutable — delete and recreate to rename', 'zen-cortext'); ?>
                    <?php endif; ?>
                </p>
            </div>
            <div style="flex:1;">
                <label style="display:block; font-weight:600; margin-bottom:4px;">
                    <?php esc_html_e('Label', 'zen-cortext'); ?>
                </label>
                <input type="text" class="zct-label regular-text" value="<?php echo esc_attr($label); ?>"
                       placeholder="Case Studies" style="width:100%;" />
                <p class="description" style="margin-top:4px;"><?php esc_html_e('Display heading inside the chat context block.', 'zen-cortext'); ?></p>
            </div>
            <div style="flex:0 0 auto;">
                <button type="button" class="button button-link-delete zct-remove" style="margin-top:24px;">
                    <?php esc_html_e('Remove', 'zen-cortext'); ?>
                </button>
            </div>
        </div>
        <div style="margin-top:10px;">
            <label style="display:block; font-weight:600; margin-bottom:4px;">
                <?php esc_html_e('Description (for the classifier)', 'zen-cortext'); ?>
            </label>
            <input type="text" class="zct-description regular-text" value="<?php echo esc_attr($desc); ?>"
                   placeholder="<?php esc_attr_e('Brief description used in the classify prompt.', 'zen-cortext'); ?>"
                   style="width:100%;" />
        </div>
        <div style="margin-top:10px;">
            <label style="display:block; font-weight:600; margin-bottom:4px;">
                <?php esc_html_e('Restructure prompt', 'zen-cortext'); ?>
            </label>
            <textarea class="zct-prompt large-text code" rows="10" style="width:100%; font-family:monospace; font-size:12px;"><?php echo esc_textarea($prompt); ?></textarea>
            <p class="description" style="margin-top:4px;">
                <?php esc_html_e('Sent to the AI when restructuring a row of this type. Output is stored in the KB and injected into the visitor chat context.', 'zen-cortext'); ?>
            </p>
        </div>
    </div>
    <?php
}
?>

<!-- Template for new rows added client-side. Cloned by admin.js. -->
<script type="text/html" id="zen-cortext-type-row-template">
    <?php zen_cortext_render_type_row('__INDEX__', array(), true); ?>
</script>
