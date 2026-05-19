<?php
/**
 * Zen Cortext — Chat settings → Prompts tab.
 *
 * Under-the-hood LLM-facing text:
 *  - System prompt + the "Adapt to my Knowledge Base" modal (REST call
 *    to /admin/adapt-system-prompt that proposes a fresh prompt grounded
 *    in the synced KB).
 *  - Survey framing prompt (the template wrapping whichever survey was
 *    picked on the Basic tab; uses {intro}/{questions}/{outcome} subs).
 *  - Read-only "What the gatekeeper sees" preview — debug view derived
 *    from system prompt + welcome + chips + site name. No save.
 *
 * Saves through the `zen_cortext_chat_prompts` settings group.
 */
if (!defined('ABSPATH')) exit;
?>
<form method="post" action="options.php">
    <?php settings_fields('zen_cortext_chat_prompts'); ?>

    <h2 style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
        <span><?php esc_html_e('System prompt', 'zen-cortext'); ?></span>
        <button type="button" id="zc-adapt-system-prompt" class="button button-secondary" style="font-weight:normal;">
            <span class="dashicons dashicons-update" style="vertical-align:middle;line-height:1.4;"></span>
            <?php esc_html_e('Adapt to my Knowledge Base', 'zen-cortext'); ?>
        </button>
    </h2>
    <p class="description">
        <?php esc_html_e('The KB block is appended automatically when the chat runs.', 'zen-cortext'); ?>
        <br>
        <?php esc_html_e('Adapt: AI reads your synced Knowledge Base and proposes a system prompt grounded in your actual site content. Preview the result before applying — the current prompt is preserved until you click Apply.', 'zen-cortext'); ?>
    </p>
    <?php
    // Defensive empty-fallback: if the saved option is an empty string
    // (the third arg to get_option only fires on missing, not blank),
    // show the bundled default so admins always see real text in the
    // editor instead of staring at a blank field after a botched save.
    $system_prompt_val = (string) get_option('zen_cortext_system_prompt', '');
    if (trim($system_prompt_val) === '') {
        $system_prompt_val = Zen_Cortext_Defaults::system_prompt();
    }
    ?>
    <textarea name="zen_cortext_system_prompt" id="zen_cortext_system_prompt" rows="20" class="large-text code"><?php echo esc_textarea($system_prompt_val); ?></textarea>

    <!-- Adapter modal — hidden until the user clicks "Adapt to my Knowledge Base". -->
    <div id="zc-adapt-modal" style="display:none; position:fixed; inset:0; z-index:100000; background:rgba(0,0,0,0.6);" role="dialog" aria-modal="true" aria-labelledby="zc-adapt-modal-title">
        <div style="position:absolute; inset:32px; background:#fff; border-radius:6px; display:flex; flex-direction:column; box-shadow:0 24px 80px rgba(0,0,0,0.3);">
            <div style="padding:16px 24px; border-bottom:1px solid #dcdcde; display:flex; align-items:center; justify-content:space-between;">
                <h2 id="zc-adapt-modal-title" style="margin:0; font-size:18px;"><?php esc_html_e('Adapt system prompt to Knowledge Base', 'zen-cortext'); ?></h2>
                <div style="display:flex; gap:8px; align-items:center;">
                    <span id="zc-adapt-status" style="color:#646970; font-size:13px;"></span>
                    <button type="button" id="zc-adapt-close" class="button" aria-label="<?php esc_attr_e('Close', 'zen-cortext'); ?>">&times;</button>
                </div>
            </div>
            <div id="zc-adapt-loading" style="flex:1; display:flex; align-items:center; justify-content:center; color:#646970; padding:32px; text-align:center;">
                <div>
                    <span class="spinner is-active" style="float:none; margin:0 auto 12px; display:block;"></span>
                    <div><?php esc_html_e('Reading your Knowledge Base and rewriting the system prompt — typically 8–20 seconds…', 'zen-cortext'); ?></div>
                </div>
            </div>
            <div id="zc-adapt-error" style="display:none; padding:24px; color:#b32d2e;"></div>
            <div id="zc-adapt-content" style="flex:1; display:none; overflow:hidden; padding:0;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1px; background:#dcdcde; height:100%;">
                    <div style="background:#fff; display:flex; flex-direction:column;">
                        <div style="padding:8px 16px; background:#f6f7f7; border-bottom:1px solid #dcdcde; font-weight:600;"><?php esc_html_e('Current', 'zen-cortext'); ?></div>
                        <textarea id="zc-adapt-current" readonly style="flex:1; border:0; padding:16px; font-family:monospace; font-size:12px; resize:none;"></textarea>
                    </div>
                    <div style="background:#fff; display:flex; flex-direction:column;">
                        <div style="padding:8px 16px; background:#f0f6fc; border-bottom:1px solid #dcdcde; font-weight:600; color:#1d4ed8;"><?php esc_html_e('Proposed', 'zen-cortext'); ?></div>
                        <textarea id="zc-adapt-proposed" style="flex:1; border:0; padding:16px; font-family:monospace; font-size:12px; resize:none;"></textarea>
                    </div>
                </div>
            </div>
            <div style="padding:12px 24px; border-top:1px solid #dcdcde; display:flex; justify-content:flex-end; gap:8px; background:#f6f7f7;">
                <button type="button" id="zc-adapt-discard" class="button"><?php esc_html_e('Discard', 'zen-cortext'); ?></button>
                <button type="button" id="zc-adapt-apply" class="button button-primary" disabled><?php esc_html_e('Apply to system prompt', 'zen-cortext'); ?></button>
            </div>
        </div>
    </div>

    <script>
    (function(){
        var $modal    = document.getElementById('zc-adapt-modal');
        var $btn      = document.getElementById('zc-adapt-system-prompt');
        var $close    = document.getElementById('zc-adapt-close');
        var $loading  = document.getElementById('zc-adapt-loading');
        var $content  = document.getElementById('zc-adapt-content');
        var $error    = document.getElementById('zc-adapt-error');
        var $current  = document.getElementById('zc-adapt-current');
        var $proposed = document.getElementById('zc-adapt-proposed');
        var $apply    = document.getElementById('zc-adapt-apply');
        var $discard  = document.getElementById('zc-adapt-discard');
        var $status   = document.getElementById('zc-adapt-status');
        var $field    = document.getElementById('zen_cortext_system_prompt');

        if (!$btn) return;

        function openModal() {
            $modal.style.display = 'block';
            $loading.style.display = 'flex';
            $content.style.display = 'none';
            $error.style.display = 'none';
            $status.textContent = '';
            $apply.disabled = true;
        }
        function closeModal() { $modal.style.display = 'none'; }
        function showError(msg) {
            $loading.style.display = 'none';
            $content.style.display = 'none';
            $error.style.display = 'block';
            $error.textContent = msg;
        }

        $btn.addEventListener('click', function() {
            openModal();
            var url   = '<?php echo esc_url_raw(rest_url('zen-cortext/v1/admin/adapt-system-prompt')); ?>';
            var nonce = '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';

            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
                body: '{}'
            })
            .then(function(r) { return r.json().then(function(data) { return { ok: r.ok, status: r.status, data: data }; }); })
            .then(function(res) {
                if (!res.ok) {
                    var msg = (res.data && (res.data.message || res.data.code)) || ('HTTP ' + res.status);
                    showError(msg);
                    return;
                }
                $loading.style.display = 'none';
                $content.style.display = 'block';
                $current.value  = res.data.current  || '';
                $proposed.value = res.data.proposed || '';
                $apply.disabled = !$proposed.value;
                var info = '';
                if (res.data.tokens_in)  info += res.data.tokens_in  + ' in';
                if (res.data.tokens_out) info += (info ? ' / ' : '') + res.data.tokens_out + ' out';
                if (res.data.kb_chars)   info += (info ? ' · ' : '') + Math.round(res.data.kb_chars / 1000) + 'k KB chars';
                $status.textContent = info;
            })
            .catch(function(err) { showError('Network error: ' + err.message); });
        });

        $close.addEventListener('click', closeModal);
        $discard.addEventListener('click', closeModal);
        $modal.addEventListener('click', function(e) { if (e.target === $modal) closeModal(); });

        $apply.addEventListener('click', function() {
            if (!$proposed.value || !$field) return;
            $field.value = $proposed.value;
            closeModal();
            // Scroll the textarea into view and flash a confirmation.
            $field.scrollIntoView({ behavior: 'smooth', block: 'center' });
            var orig = $field.style.boxShadow;
            $field.style.boxShadow = '0 0 0 3px #2271b1';
            setTimeout(function() { $field.style.boxShadow = orig; }, 1500);
        });
    })();
    </script>

    <h2><?php esc_html_e('Survey framing prompt', 'zen-cortext'); ?></h2>
    <p class="description">
        <?php esc_html_e('The system-prompt block injected when a survey is active. Per-survey content (intro, questions, outcome) substitutes into these placeholders:', 'zen-cortext'); ?>
        <code>{intro}</code>, <code>{questions}</code>, <code>{outcome}</code>.
        <br>
        <?php esc_html_e('Tune this template per site — the default explicitly tells the AI that the survey\'s topic overrides any topic restriction in the base persona, so an off-scope interview (e.g. wine on a marketing-agency chat) doesn\'t get refused mid-question.', 'zen-cortext'); ?>
    </p>
    <?php
    // Same defensive empty-fallback as the system prompt above: if a
    // botched save left an empty value behind, show the bundled default
    // in the textarea instead of a blank field.
    $survey_template = (string) get_option('zen_cortext_survey_prompt_template', '');
    if (trim($survey_template) === '') {
        $survey_template = Zen_Cortext_Defaults::survey_prompt_template();
    }
    ?>
    <textarea name="zen_cortext_survey_prompt_template" rows="22" class="large-text code"><?php echo esc_textarea($survey_template); ?></textarea>
    <p class="description">
        <?php
        printf(
            /* translators: 1 = link to Basic tab (where the survey picker lives), 2 = link to Surveys page */
            wp_kses(
                __('Pick the active survey on the %1$s tab; manage individual survey scripts on %2$s.', 'zen-cortext'),
                array('a' => array('href' => array()))
            ),
            '<a href="' . esc_url(add_query_arg(array('page' => 'zen-cortext-chat', 'tab' => 'basic'), admin_url('admin.php'))) . '">' . esc_html__('Basic chat', 'zen-cortext') . '</a>',
            '<a href="' . esc_url(admin_url('admin.php?page=zen-cortext-surveys')) . '">' . esc_html__('Surveys', 'zen-cortext') . '</a>'
        );
        ?>
    </p>

    <?php
    // Phase 4 — read-only preview of what the Haiku gatekeeper sees about
    // this site. Derived live from system prompt + welcome + chips + site
    // name on every page load. The cache invalidates on update_option for
    // those keys, so saving the basic tab makes changes visible here
    // immediately on next render. If this block looks wrong, fix the
    // source fields (System prompt above, plus Welcome / chips / site
    // name on the basic tab).
    $assistant_context_preview = '';
    if (class_exists('Zen_Cortext_Filter')) {
        $assistant_context_preview = Zen_Cortext_Filter::build_assistant_context(null, false);
    }
    ?>
    <h2><?php esc_html_e('What the gatekeeper sees', 'zen-cortext'); ?></h2>
    <p class="description">
        <?php esc_html_e('Read-only preview of the site context block injected into every Haiku classifier call. The gatekeeper decides which visitor messages are on-topic by comparing them to this. If a chip topic gets mis-classified as off-topic, check that it appears here verbatim.', 'zen-cortext'); ?>
        <br>
        <?php esc_html_e('Derived from: Site name + tagline, System prompt (identity portion), default Welcome message, and default starter chips. When a visitor matches an Attribution Context rule, the rule\'s campaign welcome + chips + context brief are layered on top of this block at runtime — open Attribution Contexts to edit those overrides.', 'zen-cortext'); ?>
    </p>
    <textarea rows="14" class="large-text code" readonly style="background:#f6f7f7;"><?php
        echo esc_textarea($assistant_context_preview !== '' ? $assistant_context_preview : __('(empty — set Site name, System prompt, Welcome message, or Default chips on the Basic tab.)', 'zen-cortext'));
    ?></textarea>

    <?php submit_button(); ?>
</form>
