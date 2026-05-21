<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Zen Cortext — Getting Started (Initialization) admin page.
 *
 * Collapsible step-by-step guide that links to every configuration
 * surface the plugin exposes, in the order an admin should touch
 * them. Each step uses native <details>/<summary> for the accordion
 * behavior (zero JS); pending steps start expanded with a ⚠ marker,
 * completed steps collapse to a one-line ✓ summary so a returning
 * admin sees their progress at a glance.
 *
 * All "is step done?" signals come from Zen_Cortext_Setup_State so
 * this template stays declarative.
 */

if (!defined('ABSPATH')) exit;

$state = Zen_Cortext_Setup_State::summary();
$steps_by_key = array();
foreach ($state['steps'] as $s) {
    $steps_by_key[$s['key']] = $s;
}

/**
 * Helper — render one step block. Pending steps start expanded.
 *
 *   $key       state-array key (matches Setup_State::summary)
 *   $num       1-based step number shown in the badge
 *   $title     short title
 *   $optional  bool — render the "Optional" tag instead of "Required"
 *   $body_cb   closure that prints the body HTML for this step
 */
$render_step = function ($key, $num, $title, $optional, $body_cb) use ($steps_by_key) {
    $done    = !empty($steps_by_key[$key]['done']);
    $subtext = isset($steps_by_key[$key]['subtext']) ? (string) $steps_by_key[$key]['subtext'] : '';
    $open    = $done ? '' : ' open';
    $marker  = $done ? '<span class="zci-status zci-done" title="' . esc_attr__('Done', 'zen-cortext') . '">&#10003;</span>'
                     : '<span class="zci-status zci-pending" title="' . esc_attr__('Pending', 'zen-cortext') . '">&#9888;</span>';
    $tag     = $optional
        ? '<span class="zci-tag zci-tag-optional">' . esc_html__('Optional', 'zen-cortext') . '</span>'
        : '<span class="zci-tag zci-tag-required">' . esc_html__('Required', 'zen-cortext') . '</span>';
    ?>
    <details class="zci-step zci-step-<?php echo esc_attr($done ? 'done' : 'pending'); ?>"<?php echo esc_attr($open); ?>>
        <summary>
            <span class="zci-num"><?php echo (int) $num; ?></span>
            <span class="zci-title"><?php echo esc_html($title); ?></span>
            <?php echo wp_kses_post($tag); ?>
            <?php if ($subtext !== ''): ?>
                <span class="zci-subtext"><?php echo esc_html($subtext); ?></span>
            <?php endif; ?>
            <span class="zci-marker"><?php echo wp_kses_post($marker); ?></span>
        </summary>
        <div class="zci-body">
            <?php $body_cb(); ?>
        </div>
    </details>
    <?php
};

/** URL builder — adds &from=init so destination pages can later add
 *  a "Back to Getting Started" link without parsing the referrer. */
$init_url = function ($slug, $tab = '') {
    $args = array('page' => $slug);
    if ($tab !== '') $args['tab'] = $tab;
    $args['from'] = 'init';
    return admin_url('admin.php?' . http_build_query($args));
};

$test_chat_url = Zen_Cortext_Setup_State::first_chat_page_url();
?>
<div class="wrap zen-cortext-init">
    <h1><?php esc_html_e('Getting Started with Zen Cortext', 'zen-cortext'); ?></h1>

    <p class="zci-intro">
        <?php esc_html_e('Follow these steps in order to get the AI consultant live on your site. Each card links straight to the screen where you configure that step.', 'zen-cortext'); ?>
        <?php esc_html_e('Completed steps collapse to one line so you can scan your progress; pending steps stay open with what you still need to do.', 'zen-cortext'); ?>
    </p>

    <div class="zci-progress" role="status" aria-live="polite">
        <?php if ($state['all_done']): ?>
            <span class="zci-progress-done">
                <?php
                /* translators: 1 = check mark */
                printf(esc_html__('%s All required steps are done. The chat should be live.', 'zen-cortext'),
                    '<span class="zci-status zci-done">&#10003;</span>');
                ?>
            </span>
        <?php else: ?>
            <?php
            printf(
                /* translators: %1$d is the number of completed required setup steps; %2$d is the total number of required steps. */
                esc_html__('%1$d of %2$d required steps done', 'zen-cortext'),
                (int) $state['required_done'],
                (int) $state['required_total']
            );
            ?>
            <span class="zci-progress-bar" aria-hidden="true">
                <span class="zci-progress-fill" style="width:<?php echo (int) (
                    $state['required_total'] > 0
                        ? ($state['required_done'] / $state['required_total']) * 100
                        : 0
                ); ?>%;"></span>
            </span>
        <?php endif; ?>
    </div>

    <div class="zci-steps">

        <?php $render_step('api_key', 1, __('Connect Claude (API key)', 'zen-cortext'), false, function () use ($init_url) { ?>
            <p>
                <?php esc_html_e('Zen Cortext talks to Anthropic\'s Claude API. Paste your API key on the Connection tab so the plugin can make requests on your behalf.', 'zen-cortext'); ?>
            </p>
            <p>
                <strong><?php esc_html_e('Where to get a key:', 'zen-cortext'); ?></strong>
                <?php esc_html_e('sign in to the Anthropic Console, open the API Keys page, click "Create Key", and copy the value that starts with', 'zen-cortext'); ?>
                <code>sk-ant-</code>.
                <?php esc_html_e('Set a monthly spend limit on the Billing → Usage Cap page while you\'re there — it caps what a runaway error can cost.', 'zen-cortext'); ?>
            </p>
            <p class="zci-actions">
                <a href="<?php echo esc_url($init_url('zen-cortext', 'connection')); ?>" class="button button-primary">
                    <?php esc_html_e('Open Connection settings', 'zen-cortext'); ?>
                </a>
                <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener" class="button">
                    <?php esc_html_e('Anthropic Console → API Keys ↗', 'zen-cortext'); ?>
                </a>
                <a href="https://console.anthropic.com/settings/limits" target="_blank" rel="noopener" class="button">
                    <?php esc_html_e('Set a usage cap ↗', 'zen-cortext'); ?>
                </a>
            </p>
        <?php }); ?>

        <?php $render_step('kb', 2, __('Build the Knowledge Base', 'zen-cortext'), false, function () use ($init_url) { ?>
            <p>
                <?php esc_html_e('The AI answers from YOUR content. The Knowledge Base indexes selected post types (pages, posts, portfolio items, FAQs…), classifies each entry into a content type, and restructures the text into a clean format the model can cite.', 'zen-cortext'); ?>
            </p>
            <p>
                <?php esc_html_e('Open the KB page and click "Rebuild KB". The first run takes a few minutes; subsequent post edits sync automatically.', 'zen-cortext'); ?>
            </p>
            <p class="zci-actions">
                <a href="<?php echo esc_url($init_url('zen-cortext-kb')); ?>" class="button button-primary">
                    <?php esc_html_e('Open Knowledge Base', 'zen-cortext'); ?>
                </a>
            </p>
        <?php }); ?>

        <?php $render_step('artifacts', 3, __('Add Knowledge Artifacts', 'zen-cortext'), true, function () use ($init_url) { ?>
            <p>
                <?php esc_html_e('Artifacts are curated, structured documents (case studies, technical articles, FAQs, marketing pages) that the AI cites alongside your KB. Useful when you want the AI to reference content that isn\'t a regular WordPress post — e.g. a hand-written engagement summary or a technical spec.', 'zen-cortext'); ?>
            </p>
            <p>
                <?php esc_html_e('You can skip this for now and add them later. The chat works fine on just the KB.', 'zen-cortext'); ?>
            </p>
            <p class="zci-actions">
                <a href="<?php echo esc_url($init_url('zen-cortext-kb', 'artifacts')); ?>" class="button button-primary">
                    <?php esc_html_e('Open Artifacts', 'zen-cortext'); ?>
                </a>
            </p>
        <?php }); ?>

        <?php $render_step('chat_page', 4, __('Create or pick a chat page', 'zen-cortext'), false, function () use ($init_url) { ?>
            <p>
                <?php esc_html_e('The visitor chat lives on a regular WordPress page using either the "Zen Cortext — Full-page client chat" page template or the [zen_cortext] shortcode. The Settings → Chat pages tab shows every detected chat page on the site and has a one-click "Quick-create chat page" button if you don\'t have one yet.', 'zen-cortext'); ?>
            </p>
            <p class="zci-actions">
                <a href="<?php echo esc_url($init_url('zen-cortext', 'pages')); ?>" class="button button-primary">
                    <?php esc_html_e('Open Chat pages', 'zen-cortext'); ?>
                </a>
            </p>
        <?php }); ?>

        <?php $render_step('design', 5, __('Design — palette, typography, float button', 'zen-cortext'), false, function () use ($init_url) { ?>
            <p>
                <?php esc_html_e('Tune the chat\'s look so it matches your site:', 'zen-cortext'); ?>
            </p>
            <ul>
                <li><?php esc_html_e('Colors — pick the brand palette via 13 token-level color inputs with a live preview.', 'zen-cortext'); ?></li>
                <li><?php esc_html_e('Typography — set a base font family + base size, or leave empty to inherit from the host theme.', 'zen-cortext'); ?></li>
                <li><?php esc_html_e('Float button — optional sticky CTA that injects a floating chat icon across the site (position, icon, hover text, target page).', 'zen-cortext'); ?></li>
            </ul>
            <p class="zci-actions">
                <a href="<?php echo esc_url($init_url('zen-cortext', 'design')); ?>" class="button button-primary">
                    <?php esc_html_e('Open Design', 'zen-cortext'); ?>
                </a>
            </p>
        <?php }); ?>

        <?php $render_step('prompts', 6, __('Prompts — system, welcome, survey framing', 'zen-cortext'), false, function () use ($init_url) { ?>
            <p>
                <?php esc_html_e('Define how the AI introduces itself and what its job is. Three editable prompts:', 'zen-cortext'); ?>
            </p>
            <ul>
                <li><strong><?php esc_html_e('System prompt', 'zen-cortext'); ?></strong> — <?php esc_html_e('the AI\'s persona, scope, and rules. There\'s an "Adapt to KB" button that suggests a tailored version based on what your KB now contains.', 'zen-cortext'); ?></li>
                <li><strong><?php esc_html_e('Welcome message', 'zen-cortext'); ?></strong> — <?php esc_html_e('the AI\'s opening line in every new conversation.', 'zen-cortext'); ?></li>
                <li><strong><?php esc_html_e('Survey framing prompt', 'zen-cortext'); ?></strong> — <?php esc_html_e('the wrapper text used when a survey/interview script is active.', 'zen-cortext'); ?></li>
            </ul>
            <p class="zci-actions">
                <a href="<?php echo esc_url($init_url('zen-cortext-chat', 'prompts')); ?>" class="button button-primary">
                    <?php esc_html_e('Open Prompts', 'zen-cortext'); ?>
                </a>
            </p>
        <?php }); ?>

        <?php $render_step('team_members', 7, __('Team members — invites + lead/error routing', 'zen-cortext'), true, function () use ($init_url) { ?>
            <p>
                <?php esc_html_e('Pick which WordPress users are part of your sales/support team. They\'re used for three things:', 'zen-cortext'); ?>
            </p>
            <ul>
                <li><?php esc_html_e('The AI can invite them into a conversation via the [invite: Name] marker.', 'zen-cortext'); ?></li>
                <li><?php esc_html_e('They receive an email when a visitor submits the lead-capture form.', 'zen-cortext'); ?></li>
                <li><?php esc_html_e('They receive a throttled email if the AI hits an API error (billing, rate limit, outage).', 'zen-cortext'); ?></li>
            </ul>
            <p class="zci-actions">
                <a href="<?php echo esc_url($init_url('zen-cortext-chat', 'rail')); ?>" class="button button-primary">
                    <?php esc_html_e('Open Team / Left panel', 'zen-cortext'); ?>
                </a>
            </p>
        <?php }); ?>

        <?php $render_step('float_button', 8, __('Float button (optional)', 'zen-cortext'), true, function () use ($init_url) { ?>
            <p>
                <?php esc_html_e('Enable a small floating chat icon that appears on every public page (except the chat page itself) and takes the visitor to the chat. Configure position, color, icon, and hover text on the Design tab.', 'zen-cortext'); ?>
            </p>
            <p class="zci-actions">
                <a href="<?php echo esc_url($init_url('zen-cortext', 'design')); ?>" class="button button-primary">
                    <?php esc_html_e('Open Design → Float button', 'zen-cortext'); ?>
                </a>
            </p>
        <?php }); ?>

        <?php $render_step('voice', 9, __('Voice input — speech-to-text key', 'zen-cortext'), true, function () use ($init_url) { ?>
            <p>
                <?php esc_html_e('Let visitors record voice messages instead of typing. The plugin uses Groq Whisper as the primary transcription provider (fast + free tier) with OpenAI Whisper as a fallback — bring your own key for whichever service you prefer. Either key alone is enough; you don\'t need both.', 'zen-cortext'); ?>
            </p>
            <p>
                <strong><?php esc_html_e('Where to get a key:', 'zen-cortext'); ?></strong>
            </p>
            <ul>
                <li><?php
                    printf(
                        wp_kses(
                            /* translators: %1$s is the link to the Groq API keys page. */
                            __('Groq (recommended): create a key at %1$s. Free tier covers thousands of minutes of audio.', 'zen-cortext'),
                            array('a' => array('href' => array(), 'target' => array(), 'rel' => array()))
                        ),
                        '<a href="https://console.groq.com/keys" target="_blank" rel="noopener">console.groq.com/keys ↗</a>'
                    );
                ?></li>
                <li><?php
                    printf(
                        wp_kses(
                            /* translators: %1$s is the link to the OpenAI API keys page. */
                            __('OpenAI (fallback): create a key at %1$s. Paid per-minute pricing but very robust.', 'zen-cortext'),
                            array('a' => array('href' => array(), 'target' => array(), 'rel' => array()))
                        ),
                        '<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com/api-keys ↗</a>'
                    );
                ?></li>
            </ul>
            <p>
                <?php esc_html_e('On the Voice tab, flip "Enable voice input", paste a key, and a microphone button will appear next to the chat input on mobile (where typing is most painful).', 'zen-cortext'); ?>
            </p>
            <p class="zci-actions">
                <a href="<?php echo esc_url($init_url('zen-cortext', 'voice')); ?>" class="button button-primary">
                    <?php esc_html_e('Open Voice settings', 'zen-cortext'); ?>
                </a>
            </p>
        <?php }); ?>

        <?php $render_step('surveys', 10, __('Surveys / interview scripts (optional)', 'zen-cortext'), true, function () use ($init_url) { ?>
            <p>
                <?php esc_html_e('Write a short interview the AI weaves into the conversation to learn about the visitor (their goals, current setup, decision criteria). The script is treated as guidance, not a recital — the AI keeps natural rapport.', 'zen-cortext'); ?>
            </p>
            <p>
                <?php esc_html_e('You can have multiple scripts and tie them to specific Attribution rules (different surveys for different campaigns) or set one as the global default on the Chat → Basic tab.', 'zen-cortext'); ?>
            </p>
            <p class="zci-actions">
                <a href="<?php echo esc_url($init_url('zen-cortext-surveys')); ?>" class="button button-primary">
                    <?php esc_html_e('Open Surveys', 'zen-cortext'); ?>
                </a>
            </p>
        <?php }); ?>

        <?php $render_step('attribution', 11, __('Attribution context rules (optional, advanced)', 'zen-cortext'), true, function () use ($init_url) { ?>
            <p>
                <?php esc_html_e('Override the AI\'s framing for visitors arriving from a specific ad / UTM / referrer. Each rule defines: a match condition (campaign name, UTM source, referer regex…) and a context block that gets prepended to the system prompt for those visitors only.', 'zen-cortext'); ?>
            </p>
            <p>
                <?php esc_html_e('Useful when you run several campaigns and want the AI to speak differently depending on what the visitor saw before they arrived.', 'zen-cortext'); ?>
            </p>
            <p class="zci-actions">
                <a href="<?php echo esc_url($init_url('zen-cortext-attribution')); ?>" class="button button-primary">
                    <?php esc_html_e('Open Attribution', 'zen-cortext'); ?>
                </a>
            </p>
        <?php }); ?>

        <?php $render_step('test_chat', 12, __('Test the chat', 'zen-cortext'), false, function () use ($test_chat_url) { ?>
            <p>
                <?php esc_html_e('Open your chat page in a regular browser tab (not the admin) and ask a question that\'s clearly in scope for your site. Verify: the welcome message looks right, the answer is grounded in your KB, the suggested chips make sense, and any team-member invite buttons appear correctly.', 'zen-cortext'); ?>
            </p>
            <?php if ($test_chat_url !== ''): ?>
                <p class="zci-actions">
                    <a href="<?php echo esc_url($test_chat_url); ?>" target="_blank" rel="noopener" class="button button-primary">
                        <?php esc_html_e('Open chat page ↗', 'zen-cortext'); ?>
                    </a>
                </p>
            <?php else: ?>
                <p><em><?php esc_html_e('Create or pick a chat page in step 4 first.', 'zen-cortext'); ?></em></p>
            <?php endif; ?>
        <?php }); ?>

    </div><!-- /.zci-steps -->
</div><!-- /.wrap -->

<style>
/* Layout — narrow column so step text reads as a guide, not a wall. */
.zen-cortext-init { max-width: 920px; }
.zen-cortext-init .zci-intro { color: #50575e; max-width: 760px; }

.zen-cortext-init .zci-progress {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 8px;
    padding: 12px 16px;
    margin: 16px 0 24px;
    font-size: 14px;
    color: #1d2327;
}
.zen-cortext-init .zci-progress-done { color: #00a32a; font-weight: 600; }
.zen-cortext-init .zci-progress-bar {
    display: block;
    margin-top: 8px;
    height: 6px;
    background: #f0f0f1;
    border-radius: 3px;
    overflow: hidden;
}
.zen-cortext-init .zci-progress-fill {
    display: block;
    height: 100%;
    background: #2271b1;
    transition: width 0.4s ease;
}

.zen-cortext-init .zci-steps { display: flex; flex-direction: column; gap: 10px; }

.zen-cortext-init .zci-step {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 8px;
    overflow: hidden;
}
.zen-cortext-init .zci-step-done { border-left: 3px solid #00a32a; }
.zen-cortext-init .zci-step-pending { border-left: 3px solid #f0c33c; }

.zen-cortext-init .zci-step summary {
    list-style: none;
    cursor: pointer;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    user-select: none;
}
.zen-cortext-init .zci-step summary::-webkit-details-marker { display: none; }
.zen-cortext-init .zci-step[open] summary {
    background: #f6f7f7;
    border-bottom: 1px solid #dcdcde;
}

.zen-cortext-init .zci-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: #2271b1;
    color: #fff;
    font-weight: 600;
    font-size: 13px;
    flex-shrink: 0;
}
.zen-cortext-init .zci-step-done .zci-num { background: #00a32a; }

.zen-cortext-init .zci-title {
    font-weight: 600;
    color: #1d2327;
    font-size: 15px;
}

.zen-cortext-init .zci-tag {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 2px 8px;
    border-radius: 999px;
    font-weight: 600;
}
.zen-cortext-init .zci-tag-required { background: #2271b110; color: #2271b1; }
.zen-cortext-init .zci-tag-optional { background: #f0f0f1;   color: #646970; }

.zen-cortext-init .zci-subtext {
    color: #646970;
    font-size: 12px;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    margin-left: 4px;
}

.zen-cortext-init .zci-marker { margin-left: auto; }
.zen-cortext-init .zci-status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    font-size: 13px;
    font-weight: 700;
}
.zen-cortext-init .zci-status.zci-done    { background: #00a32a; color: #fff; }
.zen-cortext-init .zci-status.zci-pending { background: #f0c33c; color: #1d2327; }

.zen-cortext-init .zci-body { padding: 4px 18px 18px; line-height: 1.55; color: #2c3338; }
.zen-cortext-init .zci-body p { max-width: 720px; }
.zen-cortext-init .zci-body ul { margin: 6px 0 12px 22px; max-width: 720px; }
.zen-cortext-init .zci-body li { margin-bottom: 4px; }
.zen-cortext-init .zci-body code {
    background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 3px;
    padding: 1px 5px; font-size: 12px;
}
.zen-cortext-init .zci-body .zci-actions {
    margin-top: 12px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
</style>
