<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Zen Cortext — Surveys admin page.
 * CRUD list + editor for interview scripts that the AI weaves into chat
 * conversations. Attached to the chat globally (Chat tab) or per
 * Attribution Context rule.
 */
if (!defined('ABSPATH')) exit;
?>
<div class="wrap zen-cortext-wrap">
<h1><?php esc_html_e('Zen Cortext — Surveys', 'zen-cortext'); ?></h1>

<div id="zen-cortext-surveys-root" class="zen-cortext-surveys">

    <p class="description">
        <?php esc_html_e('Interview scripts the AI weaves into the visitor chat. Each survey has an intro paragraph and a numbered list of questions. Surveys are guidance, not gates — the AI accepts free-text answers for any question and may skip ahead naturally. Attach a survey globally on Settings → Chat, or per traffic source on Attribution Context.', 'zen-cortext'); ?>
    </p>

    <div class="zca-toolbar">
        <button type="button" class="button button-primary" id="zsv-new"><?php esc_html_e('+ New survey', 'zen-cortext'); ?></button>
    </div>

    <table class="widefat striped zca-list" id="zsv-list">
        <thead>
            <tr>
                <th><?php esc_html_e('Label', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Description', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Questions', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Enabled', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Updated', 'zen-cortext'); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody id="zsv-list-body">
            <tr><td colspan="6"><em><?php esc_html_e('Loading…', 'zen-cortext'); ?></em></td></tr>
        </tbody>
    </table>

    <!-- Editor — hidden until New or Edit -->
    <div class="zca-editor" id="zsv-editor" style="display:none;">
        <h3 id="zsv-editor-title"><?php esc_html_e('New survey', 'zen-cortext'); ?></h3>

        <input type="hidden" id="zsv-id" value="" />

        <table class="form-table" role="presentation">
            <tr>
                <th><label for="zsv-label"><?php esc_html_e('Label', 'zen-cortext'); ?></label></th>
                <td>
                    <input type="text" id="zsv-label" class="regular-text" placeholder="<?php esc_attr_e('e.g. Foreman Pro qualifying interview', 'zen-cortext'); ?>" />
                    <p class="description"><?php esc_html_e('Internal name. Shown in the Default Survey dropdown on the Chat tab and on Attribution rules.', 'zen-cortext'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="zsv-description"><?php esc_html_e('Description', 'zen-cortext'); ?></label></th>
                <td>
                    <textarea id="zsv-description" rows="2" class="large-text" placeholder="<?php esc_attr_e('Optional internal note: when this survey should be used.', 'zen-cortext'); ?>"></textarea>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Enabled', 'zen-cortext'); ?></th>
                <td>
                    <label><input type="checkbox" id="zsv-enabled" checked /> <?php esc_html_e('Active', 'zen-cortext'); ?></label>
                    <p class="description"><?php esc_html_e('Disabled surveys stay attached but the AI ignores them — useful for staging changes before rollout.', 'zen-cortext'); ?></p>
                </td>
            </tr>

            <tr>
                <th colspan="2"><h4 style="margin-bottom:6px;"><?php esc_html_e('Interview script', 'zen-cortext'); ?></h4>
                    <div class="description" style="font-weight:normal;">
                        <p style="margin:0 0 8px 0;"><strong><?php esc_html_e('Grammar', 'zen-cortext'); ?></strong> <?php esc_html_e('— line-oriented, forgiving of stray whitespace. The parser walks the script top-to-bottom in three modes: intro, question, options.', 'zen-cortext'); ?></p>

                        <ul style="margin:0 0 12px 16px; list-style:disc;">
                            <li>
                                <code>INTRO:&nbsp;…</code>
                                <?php esc_html_e('Required, exactly once, on its own line at the very start (whitespace before it is fine). Text after the colon is the intro\'s first line. Additional non-blank lines that follow are appended to the intro paragraph. The intro ends at the first blank line. The intro tells the AI WHY the questions are being asked — it\'s motivation, not visitor-facing copy.', 'zen-cortext'); ?>
                            </li>
                            <li>
                                <code>1.&nbsp;Question&nbsp;text</code>
                                <?php esc_html_e('— numbered question. Any positive integer works (the number signals ordering only; gaps and non-sequential numbers are tolerated). Question text continues on the same line; it can wrap to a second line as long as no option line, blank line, or new question intervenes.', 'zen-cortext'); ?>
                            </li>
                            <li>
                                <code>-&nbsp;Option&nbsp;text</code>
                                <?php esc_html_e('— one option per line beneath a question, prefixed with hyphen + space. Options stop when a blank line, new question, or end-of-script is reached. The visitor can always type a free-text answer instead of clicking a chip — options are suggestions, never gates.', 'zen-cortext'); ?>
                            </li>
                            <li>
                                <?php esc_html_e('Blank lines separate sections. Stray text that doesn\'t fit any of the three positions is silently ignored — don\'t rely on it.', 'zen-cortext'); ?>
                            </li>
                        </ul>

                        <p style="margin:0 0 6px 0;"><strong><?php esc_html_e('Optional flags', 'zen-cortext'); ?></strong> <?php esc_html_e('— append to the end of a question line, in square brackets:', 'zen-cortext'); ?></p>
                        <ul style="margin:0 0 12px 16px; list-style:disc;">
                            <li><code>[multi]</code> &mdash; <?php esc_html_e('multi-select. Options render as toggle chips with a "Done" button; visitor may pick any subset.', 'zen-cortext'); ?></li>
                            <li><code>[single]</code> &mdash; <?php esc_html_e('single-select. (This is the default when options are present; the explicit form exists for clarity.) Options render as one-shot chips; clicking one submits.', 'zen-cortext'); ?></li>
                            <li><code>[open]</code> &mdash; <?php esc_html_e('forces free-text even if you list options below. Useful when you want the AI to ask conversationally without showing chips.', 'zen-cortext'); ?></li>
                        </ul>

                        <p style="margin:0 0 6px 0;"><strong><?php esc_html_e('Type auto-derivation', 'zen-cortext'); ?></strong></p>
                        <ul style="margin:0 0 12px 16px; list-style:disc;">
                            <li><?php esc_html_e('No options + no flag → implicitly', 'zen-cortext'); ?> <code>[open]</code>.</li>
                            <li><?php esc_html_e('Options + no flag → implicitly', 'zen-cortext'); ?> <code>[single]</code>.</li>
                            <li><?php esc_html_e('Explicit flag always wins (so', 'zen-cortext'); ?> <code>[open]</code> <?php esc_html_e('suppresses chips even when options are listed).', 'zen-cortext'); ?></li>
                        </ul>

                        <p style="margin:0 0 6px 0;"><strong><?php esc_html_e('Validation errors that block save', 'zen-cortext'); ?></strong></p>
                        <ul style="margin:0 0 12px 16px; list-style:disc;">
                            <li><?php esc_html_e('Missing', 'zen-cortext'); ?> <code>INTRO:</code> <?php esc_html_e('header, or more than one.', 'zen-cortext'); ?></li>
                            <li><?php esc_html_e('A numbered question line with empty text.', 'zen-cortext'); ?></li>
                            <li><?php esc_html_e('Zero questions in the script.', 'zen-cortext'); ?></li>
                            <li><?php esc_html_e('A question reached before the', 'zen-cortext'); ?> <code>INTRO:</code> <?php esc_html_e('header.', 'zen-cortext'); ?></li>
                        </ul>

                        <p style="margin:0 0 6px 0;"><strong><?php esc_html_e('Complete example', 'zen-cortext'); ?></strong></p>
                        <pre style="background:#f6f7f7; border:1px solid #dcdcde; border-radius:4px; padding:10px 12px; margin:0; font-size:12px; line-height:1.5; white-space:pre-wrap;"><?php
echo esc_html("INTRO: We'd like a few details so the team can quote accurately.\n"
            . "This continues the intro until the next blank line.\n"
            . "\n"
            . "1. What is your team size?\n"
            . "- 1-5\n"
            . "- 6-20\n"
            . "- 21-100\n"
            . "- 100+\n"
            . "\n"
            . "2. Which platforms do you use? [multi]\n"
            . "- Shopify\n"
            . "- WooCommerce\n"
            . "- Custom build\n"
            . "- Other\n"
            . "\n"
            . "3. What's your timeline?\n"
            . "(no options + no flag → implicitly [open])\n"
            . "\n"
            . "4. Anything else we should know? [open]\n"
            . "- This option is ignored because [open] suppresses chips");
                        ?></pre>
                    </div>
                </th>
            </tr>
            <tr>
                <th><label for="zsv-script"><?php esc_html_e('Script', 'zen-cortext'); ?></label></th>
                <td>
                    <textarea id="zsv-script" rows="20" class="large-text code" spellcheck="false" placeholder="<?php
                        echo esc_attr("INTRO: We'd like a few details to give you the best advice.\n\n1. What is your team size?\n- 1-5\n- 6-20\n- 21-100\n- 100+\n\n2. Which platforms do you use? [multi]\n- Shopify\n- WooCommerce\n- Custom\n\n3. What's your timeline for the project?");
                    ?>"></textarea>
                    <p class="description" id="zsv-parse-status"></p>
                </td>
            </tr>

            <tr>
                <th colspan="2"><h4 style="margin-bottom:6px;"><?php esc_html_e('Conclusion & action after the interview', 'zen-cortext'); ?></h4>
                    <p class="description" style="font-weight:normal;">
                        <?php esc_html_e('Free-text instructions for the AI: what to conclude from the answers and what to do next once enough has been gathered. Reference markers if you want a concrete action — e.g.', 'zen-cortext'); ?>
                        <code>[contact_form]</code>, <code>[invite: Yury]</code>, <code>[contact_form: Iuliia]</code>.
                        <?php esc_html_e('The AI will weave the conclusion into its own words; it won\'t recite this text verbatim.', 'zen-cortext'); ?>
                    </p>
                </th>
            </tr>
            <tr>
                <th><label for="zsv-outcome"><?php esc_html_e('After-interview action', 'zen-cortext'); ?></label></th>
                <td>
                    <textarea id="zsv-outcome" rows="8" class="large-text" spellcheck="true" placeholder="<?php
                        echo esc_attr("Examples:\n\n- Summarize the platforms they use and team size, then recommend either the Foundations package (small teams, 1 platform) or the Growth package (larger teams or multi-platform). After the recommendation, emit [contact_form] so they can request a quote.\n\n- If their timeline is under 4 weeks, emit [invite: Yury] right after the recommendation so a consultant joins immediately. Otherwise emit [contact_form] for a follow-up email.");
                    ?>"></textarea>
                </td>
            </tr>
        </table>

        <p class="zca-editor-actions">
            <button type="button" class="button button-primary" id="zsv-save"><?php esc_html_e('Save', 'zen-cortext'); ?></button>
            <button type="button" class="button" id="zsv-cancel"><?php esc_html_e('Cancel', 'zen-cortext'); ?></button>
            <button type="button" class="button button-link-delete" id="zsv-delete" style="float:right;display:none;"><?php esc_html_e('Delete survey', 'zen-cortext'); ?></button>
            <span id="zsv-save-status" class="zca-status"></span>
        </p>
    </div>
</div>

</div>
