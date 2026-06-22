<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Zen Cortext — Chat Template Editor admin page.
 * Two-tab editor scoped to the visitor /talk/ chat templates:
 *   1. Template Code panel — CodeMirror + AI sidebar + iframe preview + version dropdown.
 *   2. Help panel — reference and recipes for the editor.
 *
 * Color configuration lives on the dedicated Design page
 * (Zen Cortext → Design) — moved out of here so design decisions
 * don't sit inside a code-editor surface.
 */
if (!defined('ABSPATH')) exit;
?>
<div class="wrap zce-wrap" id="zen-cortext-chat-editor">

    <h1 class="zce-title"><?php esc_html_e('Template Editor', 'zen-cortext'); ?></h1>
    <p class="zce-subtitle">
        <?php
        printf(
            /* translators: %s is the HTML link to the plugin's Design admin tab. */
            esc_html__('Edit the structure of the visitor chat templates. Template edits go through a staged preview before publish. For color overrides, use the dedicated %s page.', 'zen-cortext'),
            '<a href="' . esc_url(admin_url('admin.php?page=zen-cortext&tab=design')) . '">' . esc_html__('Design', 'zen-cortext') . '</a>'
        );
        ?>
    </p>

    <nav class="zce-tabs" role="tablist">
        <button type="button" class="zce-tab" data-zce-tab="code" role="tab"><?php esc_html_e('Template Code', 'zen-cortext'); ?></button>
        <button type="button" class="zce-tab" data-zce-tab="help" role="tab"><?php esc_html_e('Help', 'zen-cortext'); ?></button>
    </nav>

    <!-- Template Code panel ----------------------------------------- -->
    <section class="zce-panel" data-zce-panel="code" hidden>
        <div class="zce-code-toolbar">
            <label class="zce-toolbar-field">
                <span><?php esc_html_e('Preview width', 'zen-cortext'); ?></span>
                <select id="zce-device-select">
                    <option value="full"><?php esc_html_e('Full', 'zen-cortext'); ?></option>
                    <option value="1440"><?php esc_html_e('Wide (1440)', 'zen-cortext'); ?></option>
                    <option value="1024"><?php esc_html_e('Desktop (1024)', 'zen-cortext'); ?></option>
                    <option value="768"><?php esc_html_e('Tablet (768)', 'zen-cortext'); ?></option>
                    <option value="375"><?php esc_html_e('Mobile (375)', 'zen-cortext'); ?></option>
                </select>
            </label>

            <span class="zce-toolbar-spacer"></span>

            <!-- Version selector + Restore live as a single group so the
                 dropdown is always visually attached to the button that
                 acts on it — easier to discover than burying the dropdown
                 elsewhere in the toolbar. -->
            <div class="zce-toolbar-group">
                <label class="zce-toolbar-field zce-toolbar-field--inline">
                    <span><?php esc_html_e('Version', 'zen-cortext'); ?></span>
                    <select id="zce-version-select" disabled>
                        <option value=""><?php esc_html_e('— loading —', 'zen-cortext'); ?></option>
                    </select>
                </label>
                <button type="button" class="button" id="zce-restore-version"><?php esc_html_e('Restore', 'zen-cortext'); ?></button>
            </div>

            <button type="button" class="button" id="zce-discard-preview"><?php esc_html_e('Discard preview', 'zen-cortext'); ?></button>
            <button type="button" class="button" id="zce-reset-factory"><?php esc_html_e('Reset to factory', 'zen-cortext'); ?></button>
            <button type="button" class="button button-primary" id="zce-save"><?php esc_html_e('Save', 'zen-cortext'); ?></button>
            <span class="zce-save-status" id="zce-save-status" aria-live="polite"></span>
        </div>

        <div class="zce-code-grid">

            <div class="zce-preview-pane">
                <iframe id="zce-preview-iframe"
                        class="zce-preview-iframe"
                        title="<?php esc_attr_e('Live preview', 'zen-cortext'); ?>"
                        src="about:blank"></iframe>
            </div>

            <aside class="zce-ai-pane" id="zce-ai-pane">
                <header class="zce-ai-header">
                    <nav class="zce-ai-tabs" role="tablist">
                        <button type="button" class="zce-ai-tab is-active" data-zce-ai-tab="chat" role="tab"><?php esc_html_e('AI assistant', 'zen-cortext'); ?></button>
                        <button type="button" class="zce-ai-tab" data-zce-ai-tab="code" role="tab"><?php esc_html_e('Code', 'zen-cortext'); ?></button>
                        <!-- File picker travels with the Code tab — it's only meaningful when
                             you're on the code panel, so it lives next to the tab that opens it. -->
                        <select id="zce-file-select"
                                class="zce-ai-file-select"
                                aria-label="<?php esc_attr_e('Editable file', 'zen-cortext'); ?>"></select>
                    </nav>
                    <button type="button"
                            class="zce-ai-expand"
                            id="zce-ai-expand"
                            aria-pressed="false"
                            aria-label="<?php esc_attr_e('Expand panel', 'zen-cortext'); ?>"
                            title="<?php esc_attr_e('Expand to full width', 'zen-cortext'); ?>"></button>
                </header>

                <!-- AI chat tab -->
                <div class="zce-ai-tabpanel" data-zce-ai-panel="chat">
                    <p class="zce-ai-hint"><?php esc_html_e('Describe a change. The AI rewrites the whole file, the editor swaps in the new source, and the preview reloads. Save when you\'re happy.', 'zen-cortext'); ?></p>
                    <div class="zce-ai-history" id="zce-ai-history" aria-live="polite"></div>
                    <form class="zce-ai-form" id="zce-ai-form" autocomplete="off">
                        <textarea id="zce-ai-input"
                                  class="zce-ai-input"
                                  rows="3"
                                  placeholder="<?php esc_attr_e('e.g. Replace the hero with a single h1 that reads &quot;Talk to a consultant&quot;', 'zen-cortext'); ?>"></textarea>
                        <button type="submit" class="button button-primary" id="zce-ai-send"><?php esc_html_e('Ask AI', 'zen-cortext'); ?></button>
                    </form>
                </div>

                <!-- Code editor tab -->
                <div class="zce-ai-tabpanel" data-zce-ai-panel="code" hidden>
                    <textarea id="zce-source"
                              class="zce-source"
                              spellcheck="false"
                              autocomplete="off"
                              autocorrect="off"
                              autocapitalize="off"></textarea>
                </div>
            </aside>
        </div>
    </section>

    <!-- Help panel ---------------------------------------------------- -->
    <section class="zce-panel zce-help" data-zce-panel="help" hidden>

        <h2><?php esc_html_e('How the editor is wired', 'zen-cortext'); ?></h2>
        <p><?php esc_html_e("There are three editable artifacts. Each one has a factory copy bundled with the plugin (read-only) and a live copy under wp-content/uploads/zen-cortext/ that the editor writes to. The factory copy is what 'Reset to factory' restores. Versions are timestamped backups created on every Save (last 10 kept per file).", 'zen-cortext'); ?></p>

        <table class="zce-help-table">
            <thead>
                <tr><th><?php esc_html_e('File', 'zen-cortext'); ?></th><th><?php esc_html_e('What it controls', 'zen-cortext'); ?></th><th><?php esc_html_e('Edit when…', 'zen-cortext'); ?></th></tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>chat.tpl.html</code></td>
                    <td><?php esc_html_e('The inner chat shell — intro card, hero, message area, input row, share/delete pills. Renders inside any page that uses the [zen_cortext] shortcode or the full-page template.', 'zen-cortext'); ?></td>
                    <td><?php esc_html_e('Changing the visible structure or copy of the chat itself.', 'zen-cortext'); ?></td>
                </tr>
                <tr>
                    <td><code>chat-page-body.tpl.html</code></td>
                    <td><?php esc_html_e('The visible body of the standalone full-page wrapper — left rail with team cards / quick links, mobile menu modal, the <main> that wraps the chat.', 'zen-cortext'); ?></td>
                    <td><?php esc_html_e('Restructuring the rail, modal, or page-level chrome around the chat.', 'zen-cortext'); ?></td>
                </tr>
                <tr>
                    <td><code>chat.css</code></td>
                    <td><?php esc_html_e('All visual styling for the chat shell. Defines the --zc-* CSS custom properties and consumes them throughout. Changes here apply everywhere the chat renders.', 'zen-cortext'); ?></td>
                    <td><?php esc_html_e('Spacing, typography, hover states, anything beyond the colors a picker can express.', 'zen-cortext'); ?></td>
                </tr>
            </tbody>
        </table>

        <h2><?php esc_html_e('Storage layers (what survives what)', 'zen-cortext'); ?></h2>
        <ol>
            <li><strong><?php esc_html_e('Preview (transient).', 'zen-cortext'); ?></strong> <?php esc_html_e("Your in-progress draft, kept in the database for an hour, isolated per admin user. It's what the iframe shows while you type. Click 'Discard preview' to drop it.", 'zen-cortext'); ?></li>
            <li><strong><?php esc_html_e('Live (uploads).', 'zen-cortext'); ?></strong> <?php esc_html_e('What the public site actually serves. Click Save to commit your draft here.', 'zen-cortext'); ?></li>
            <li><strong><?php esc_html_e('Versions (uploads).', 'zen-cortext'); ?></strong> <?php esc_html_e('Every Save snapshots the previous live copy here, last 10 per file. Pick a timestamp from the dropdown and click Restore to roll back.', 'zen-cortext'); ?></li>
            <li><strong><?php esc_html_e('Factory (plugin tree).', 'zen-cortext'); ?></strong> <?php esc_html_e("The shipped baseline. Read-only. 'Reset to factory' copies factory → live (and snapshots your current live to versions first).", 'zen-cortext'); ?></li>
        </ol>

        <h2><?php esc_html_e('Recommended editing order', 'zen-cortext'); ?></h2>
        <ol>
            <li><?php
            printf(
                /* translators: %s is the HTML link to the plugin's Design admin tab. */
                esc_html__('Try the %s page first. Most "I want a different look" requests are color picks — done in 30 seconds, never touches a template file.', 'zen-cortext'),
                '<a href="' . esc_url(admin_url('admin.php?page=zen-cortext&tab=design')) . '">' . esc_html__('Design', 'zen-cortext') . '</a>'
            );
            ?></li>
            <li><?php esc_html_e("If colors aren't enough but the structure is fine, edit chat.css. Spacing, typography, hover states all live there.", 'zen-cortext'); ?></li>
            <li><?php esc_html_e('Only when you actually need to add/remove/reorder elements: open the relevant .tpl.html file in Template Code.', 'zen-cortext'); ?></li>
            <li><?php esc_html_e("Save in small steps. Every Save is a version snapshot, so frequent saves give you fine-grained rollback if a later edit goes wrong.", 'zen-cortext'); ?></li>
            <li><?php esc_html_e("Watch the iframe preview. It auto-refreshes as you type for templates; for chat.css you click Save to see the effect.", 'zen-cortext'); ?></li>
        </ol>

        <h2><?php esc_html_e('Template syntax (.tpl.html files)', 'zen-cortext'); ?></h2>
        <p><?php esc_html_e('Templates are HTML with placeholders. Raw PHP is rejected at save time — the runtime can never crash on a typo.', 'zen-cortext'); ?></p>
        <table class="zce-help-table">
            <thead><tr><th><?php esc_html_e('Placeholder', 'zen-cortext'); ?></th><th><?php esc_html_e('Behavior', 'zen-cortext'); ?></th></tr></thead>
            <tbody>
                <tr><td><code>{{ key }}</code></td><td><?php esc_html_e('Print value, HTML-escaped. Default — use this for any user-facing text.', 'zen-cortext'); ?></td></tr>
                <tr><td><code>{{ raw:key }}</code></td><td><?php esc_html_e('Print as raw HTML. Only the controller is allowed to put pre-sanitized HTML here.', 'zen-cortext'); ?></td></tr>
                <tr><td><code>{{ url:key }}</code></td><td><?php esc_html_e('esc_url() — use inside href / src.', 'zen-cortext'); ?></td></tr>
                <tr><td><code>{{ attr:key }}</code></td><td><?php esc_html_e('esc_attr() — use inside any HTML attribute value.', 'zen-cortext'); ?></td></tr>
                <tr><td><code>{{ t:Some text }}</code></td><td><?php esc_html_e('Translated string via __() — for static labels.', 'zen-cortext'); ?></td></tr>
                <tr><td><code>{{ if:key }} … {{ /if }}</code></td><td><?php esc_html_e('Show block when key is truthy. Use {{ if:!key }} for the inverse.', 'zen-cortext'); ?></td></tr>
                <tr><td><code>{{ each:list }} … {{ /each }}</code></td><td><?php esc_html_e('Loop over an array. Inside, item fields merge into the local context. {{ index0 }} / {{ index1 }} are auto-added.', 'zen-cortext'); ?></td></tr>
            </tbody>
        </table>
        <p><?php esc_html_e('Each {{ if:… }} must close with {{ /if }}; each {{ each:… }} must close with {{ /each }}. Unbalanced blocks reject at Save.', 'zen-cortext'); ?></p>

        <h2><?php esc_html_e('Element IDs the JS depends on', 'zen-cortext'); ?></h2>
        <p><?php esc_html_e('Renaming or removing these breaks the chat behavior silently. Keep them as IDs even if you change classes/structure around them:', 'zen-cortext'); ?></p>
        <ul class="zce-help-pills">
            <li><code>zen-cortext-root</code></li>
            <li><code>zc-chat</code></li>
            <li><code>zc-input</code></li>
            <li><code>zc-send</code></li>
            <li><code>zc-typing</code></li>
            <li><code>zc-chips</code></li>
            <li><code>zc-share</code></li>
            <li><code>zc-share-button</code></li>
            <li><code>zc-share-status</code></li>
            <li><code>zc-delete-button</code></li>
            <li><code>zc-intro-card</code></li>
            <li><code>zcp-modal</code></li>
            <li><code>zcp-modal-close</code></li>
            <li><code>zcp-modal-title</code></li>
            <li><code>zcp-mobile-trigger</code></li>
        </ul>

        <h2><?php esc_html_e('How Colors flow into the live chat', 'zen-cortext'); ?></h2>
        <ol>
            <li><?php
            printf(
                /* translators: %s is the HTML link to the plugin's Design admin tab. */
                esc_html__('On the %s page, drag a picker → the mini-chat repaints instantly via inline style. Nothing is saved yet.', 'zen-cortext'),
                '<a href="' . esc_url(admin_url('admin.php?page=zen-cortext&tab=design')) . '">' . esc_html__('Design', 'zen-cortext') . '</a>'
            );
            ?></li>
            <li><?php esc_html_e('Click Save Colors → values stored in the zen_cortext_chat_colors WP option.', 'zen-cortext'); ?></li>
            <li><?php esc_html_e('On every public render, the plugin attaches inline CSS right after chat.css (via wp_add_inline_style). CSS cascade puts those values on top of the file defaults — every var(--zc-*) reference resolves to your pick.', 'zen-cortext'); ?></li>
            <li><?php esc_html_e('chat.css itself is never modified by Color saves — your picks survive plugin updates.', 'zen-cortext'); ?></li>
        </ol>

        <h2><?php esc_html_e('How to use the AI assistant', 'zen-cortext'); ?></h2>
        <ol>
            <li><?php esc_html_e('Pick the file you want to edit (file dropdown next to the Code tab). The AI is told which file is open and what context keys are available — referencing them in your prompt is helpful but not required.', 'zen-cortext'); ?></li>
            <li><?php esc_html_e('Describe one focused change in plain English. "Add a privacy notice under the input." "Change the hero from h2 to h1." "Move the share button to the right of Send."', 'zen-cortext'); ?></li>
            <li><?php esc_html_e('Click Ask AI. The AI rewrites the FULL file (never partial diffs) so you can see exactly what it produced.', 'zen-cortext'); ?></li>
            <li><?php esc_html_e('The editor swaps in the new source, the iframe reloads. Inspect the result.', 'zen-cortext'); ?></li>
            <li><?php esc_html_e("If you like it, click Save (a version snapshot is taken first). If you don't, click Discard preview, or just keep iterating — the AI sees your prior turns.", 'zen-cortext'); ?></li>
            <li><?php esc_html_e("If the AI breaks something subtle (an ID renamed, a placeholder typo'd), the validator at Save time rejects bad templates outright — it can't ship to production.", 'zen-cortext'); ?></li>
        </ol>
        <p><strong><?php esc_html_e('What the AI knows:', 'zen-cortext'); ?></strong> <?php esc_html_e("the current file's full source, your conversation history this session, the placeholder syntax cheatsheet, the available context keys per file, and the --zc-* color tokens.", 'zen-cortext'); ?></p>
        <p><strong><?php esc_html_e('What the AI does NOT do:', 'zen-cortext'); ?></strong> <?php esc_html_e("autosave, edit multiple files in one turn, or change the live file without your Save click. Nothing reaches visitors until you commit.", 'zen-cortext'); ?></p>

        <h2><?php esc_html_e('When something goes wrong', 'zen-cortext'); ?></h2>
        <ul>
            <li><?php esc_html_e('Editor preview blank / weird: click Discard preview to drop the in-progress draft.', 'zen-cortext'); ?></li>
            <li><?php esc_html_e('Save says "Unbalanced {{ if }}" or similar: count opening vs closing markers; the error message tells you which side is off.', 'zen-cortext'); ?></li>
            <li><?php esc_html_e('Public chat looks broken after a Save: open the Version dropdown, pick the previous timestamp, click Restore. Live reverts and your bad save is preserved as another version (so the Restore itself is reversible).', 'zen-cortext'); ?></li>
            <li><?php esc_html_e('Deeper trouble: Reset to factory restores the bundled baseline (your current live becomes a version snapshot first, so even Reset is reversible).', 'zen-cortext'); ?></li>
        </ul>

    </section>

</div>
