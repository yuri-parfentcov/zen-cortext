<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Zen Cortext — Settings page (tabs).
 * Available: $tab
 *
 * Note: the Knowledge Base tab moved to its own submenu page
 * (admin.php?page=zen-cortext-kb / admin/views/kb-page.php).
 */
if (!defined('ABSPATH')) exit;

$tabs = array(
    'connection' => __('Connection', 'zen-cortext'),
    'voice'      => __('Voice', 'zen-cortext'),
    'sessions'   => __('Tracking', 'zen-cortext'),
    'pages'      => __('Chat pages', 'zen-cortext'),
    'design'     => __('Design', 'zen-cortext'),
);
?>
<div class="wrap zen-cortext-wrap">
    <h1><?php esc_html_e('Zen Cortext', 'zen-cortext'); ?></h1>

    <h2 class="nav-tab-wrapper">
        <?php foreach ($tabs as $key => $label): ?>
            <a href="<?php echo esc_url(add_query_arg(array('page' => 'zen-cortext', 'tab' => $key), admin_url('admin.php'))); ?>"
               class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($label); ?>
            </a>
        <?php endforeach; ?>
    </h2>

    <?php if ($tab === 'pages'):
        // Chat pages tab — moved here from the Design admin page. Stays
        // outside the settings-API <form> below because the "+ Create
        // chat page" button has its own POST form to admin-post.php
        // and nested forms aren't valid HTML.
        $chat_pages   = Zen_Cortext_Design::list_chat_pages();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- flash-message presence flag read after admin-post.php redirect; gated by current_user_can in the parent render method.
        $created_flag = isset($_GET['created']) ? sanitize_key((string) $_GET['created']) : '';
        ?>
        <p class="description" style="max-width:880px;">
            <?php esc_html_e('Every WordPress page where the visitor or admin chat appears — either via the [zen_cortext] shortcode embedded in page content, or via one of the plugin\'s full-page templates. Use the button below to spin up a fresh page with the shortcode ready to go.', 'zen-cortext'); ?>
        </p>

        <?php if ($created_flag === 'fail'): ?>
            <div class="notice notice-error inline"><p><?php esc_html_e('Could not create the page. Check that you have permission to publish pages.', 'zen-cortext'); ?></p></div>
        <?php endif; ?>

        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="margin:12px 0 18px;">
            <input type="hidden" name="action" value="zen_cortext_create_chat_page">
            <?php wp_nonce_field('zen_cortext_create_chat_page'); ?>
            <button type="submit" class="button button-primary"><?php esc_html_e('+ Create chat page', 'zen-cortext'); ?></button>
            <span class="description" style="margin-left:8px;"><?php esc_html_e('Creates a draft page with [zen_cortext] embedded and opens it in the editor for you to rename and publish.', 'zen-cortext'); ?></span>
        </form>

        <?php if (empty($chat_pages)): ?>
            <p><em><?php esc_html_e('No chat pages found yet. Use "+ Create chat page" above, or add the [zen_cortext] shortcode to any existing WordPress page.', 'zen-cortext'); ?></em></p>
        <?php else: ?>
            <table class="widefat striped" style="max-width:1100px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Title', 'zen-cortext'); ?></th>
                        <th><?php esc_html_e('URL', 'zen-cortext'); ?></th>
                        <th><?php esc_html_e('Mechanism', 'zen-cortext'); ?></th>
                        <th><?php esc_html_e('Status', 'zen-cortext'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($chat_pages as $p):
                        $permalink   = get_permalink($p['id']);
                        $edit_link   = get_edit_post_link($p['id']);
                        $status_disp = $p['status'] === 'publish' ? __('Published', 'zen-cortext') : ucfirst($p['status']);
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($p['title'] !== '' ? $p['title'] : __('(no title)', 'zen-cortext')); ?></strong></td>
                            <td>
                                <?php if ($permalink): ?>
                                    <code style="font-size:11px;"><?php echo esc_html(str_replace(home_url('/'), '/', $permalink)); ?></code>
                                <?php else: ?>
                                    <em>—</em>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(Zen_Cortext_Design::describe_mechanisms($p['mechanisms'])); ?></td>
                            <td><?php echo esc_html($status_disp); ?></td>
                            <td style="white-space:nowrap;">
                                <?php if ($edit_link): ?>
                                    <a href="<?php echo esc_url($edit_link); ?>" class="button button-small" style="margin-right:4px;"><?php esc_html_e('Edit', 'zen-cortext'); ?></a>
                                <?php endif; ?>
                                <?php if ($permalink && $p['status'] === 'publish'): ?>
                                    <a href="<?php echo esc_url($permalink); ?>" target="_blank" rel="noopener" class="button button-small"><?php esc_html_e('View', 'zen-cortext'); ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php elseif ($tab === 'design'):
        // Design tab — colors + float button. Saves are REST-driven
        // (zen-cortext/v1/design/*) so this stays outside the options.php
        // form. Assets are enqueued by Zen_Cortext_Design::enqueue()
        // gated on $_GET['tab'] === 'design'.
        include ZEN_CORTEXT_PLUGIN_DIR . 'admin/views/_design-tab.php';
    else: ?>
    <form method="post" action="options.php">
        <?php
        // Each tab uses its own settings group so saving one tab cannot
        // blank options that belong to other tabs (see register_settings()
        // in class-zen-cortext-admin.php for the full mapping).
        settings_fields(Zen_Cortext_Admin::settings_group_for_tab($tab));
        ?>

        <?php if ($tab === 'connection'): ?>
            <?php
            // Active backend for INTERNAL admin AI jobs (classify / restructure
            // / Brainstorm / artifact builder / Template Editor AI). Default is
            // the Anthropic HTTP API; the optional Claude Code CLI add-on
            // plugin overrides this label via the zen_cortext_backend_label
            // filter when it's installed + active. The visitor chat always uses
            // the HTTP API regardless.
            $zc_default_backend = __('Anthropic HTTP API', 'zen-cortext');
            $zc_backend_label   = apply_filters('zen_cortext_backend_label', $zc_default_backend);
            $zc_cli_active      = ($zc_backend_label !== $zc_default_backend);
            ?>
            <h2><?php esc_html_e('Backend for internal AI jobs', 'zen-cortext'); ?></h2>
            <p class="description" style="font-size:13px;">
                <span style="display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:6px;vertical-align:middle;background:<?php echo $zc_cli_active ? '#46b450' : '#2271b1'; ?>;"></span>
                <strong><?php echo esc_html($zc_backend_label); ?></strong>
                <?php if ($zc_cli_active): ?>
                    — <?php esc_html_e('the Claude Code CLI add-on is active and handling internal jobs (subscription-billed). The visitor chat still uses the HTTP API.', 'zen-cortext'); ?>
                <?php else: ?>
                    — <?php esc_html_e('all AI jobs use the Anthropic HTTP API. (Install the optional Claude Code CLI add-on to route internal jobs through a local Claude binary.)', 'zen-cortext'); ?>
                <?php endif; ?>
            </p>

            <h2><?php esc_html_e('Anthropic HTTP API', 'zen-cortext'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="zen_cortext_api_key"><?php esc_html_e('API Key', 'zen-cortext'); ?></label></th>
                    <td>
                        <input type="password" id="zen_cortext_api_key" name="zen_cortext_api_key"
                               value="<?php echo esc_attr(get_option('zen_cortext_api_key', '')); ?>"
                               class="regular-text" autocomplete="off" />
                        <button type="button" class="button" id="zen-cortext-test-connection"><?php esc_html_e('Test connection', 'zen-cortext'); ?></button>
                        <p class="description"><?php esc_html_e('Stored locally as a WP option. Get a key from console.anthropic.com. Required for the chat and all AI features.', 'zen-cortext'); ?></p>
                        <div id="zen-cortext-test-result"></div>
                    </td>
                </tr>
                <tr>
                    <th><label for="zen_cortext_model"><?php esc_html_e('Chat model', 'zen-cortext'); ?></label></th>
                    <td>
                        <input type="text" id="zen_cortext_model" name="zen_cortext_model"
                               value="<?php echo esc_attr(get_option('zen_cortext_model', 'claude-sonnet-4-6')); ?>"
                               class="regular-text" />
                        <p class="description"><?php esc_html_e('Default: claude-sonnet-4-6 (used by the frontend chat).', 'zen-cortext'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="zen_cortext_classify_model"><?php esc_html_e('API job model', 'zen-cortext'); ?></label></th>
                    <td>
                        <input type="text" id="zen_cortext_classify_model" name="zen_cortext_classify_model"
                               value="<?php echo esc_attr(get_option('zen_cortext_classify_model', 'claude-sonnet-4-6')); ?>"
                               class="regular-text" />
                        <p class="description"><?php esc_html_e('Used for classify + restructure. Sonnet recommended.', 'zen-cortext'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="zen_cortext_max_tokens"><?php esc_html_e('Max tokens', 'zen-cortext'); ?></label></th>
                    <td>
                        <input type="number" id="zen_cortext_max_tokens" name="zen_cortext_max_tokens"
                               value="<?php echo esc_attr(get_option('zen_cortext_max_tokens', 2048)); ?>"
                               class="small-text" min="64" max="8192" />
                        <p class="description">
                            <?php esc_html_e('Upper bound on the response length (in tokens) for Anthropic HTTP API calls. Default: 2048. Allowed: 64–8192.', 'zen-cortext'); ?>
                        </p>
                        <p class="description">
                            <strong><?php esc_html_e('Applies to:', 'zen-cortext'); ?></strong>
                            <?php esc_html_e('visitor chat streaming, Knowledge Base restructure, Knowledge Artifact restructure, and artifact synthesis from a brainstorm transcript.', 'zen-cortext'); ?>
                        </p>
                        <p class="description">
                            <strong><?php esc_html_e('Does NOT affect:', 'zen-cortext'); ?></strong>
                            <?php esc_html_e('the Brainstorm page (uses Opus 4.6 with a dedicated 24000-token cap for extended thinking) or short utility calls like classification (hardcoded small caps).', 'zen-cortext'); ?>
                        </p>
                        <p class="description">
                            <?php esc_html_e('Higher = longer answers and KB rewrites, but more cost per call. Lower = tighter responses, cheaper, but risk of being truncated mid-sentence.', 'zen-cortext'); ?>
                        </p>
                    </td>
                </tr>
            </table>


        <?php elseif ($tab === 'voice'):
            // Voice transcription — Groq Whisper Large v3 Turbo with
            // optional OpenAI Whisper fallback. Same bring-your-own-key
            // model as the Anthropic credentials on the Connection tab.
            $voice_enabled   = (bool) get_option('zen_cortext_voice_enabled', false);
            $groq_api_key    = (string) get_option('zen_cortext_groq_api_key', '');
            $openai_api_key  = (string) get_option('zen_cortext_openai_api_key', '');
            ?>
            <h2><?php esc_html_e('Voice transcription (mobile)', 'zen-cortext'); ?></h2>
            <p class="description" style="max-width:880px;">
                <?php esc_html_e('Adds a mic button to the visitor chat on mobile devices so visitors can dictate instead of typing. The browser records short clips and the plugin transcribes them via Groq Whisper Large v3 Turbo. OpenAI Whisper is used automatically as a fallback if Groq returns an error. Both providers are paid via your own keys — Zen Cortext never proxies the call.', 'zen-cortext'); ?>
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th><?php esc_html_e('Voice messages', 'zen-cortext'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="zen_cortext_voice_enabled" value="1" <?php checked($voice_enabled); ?> />
                            <?php esc_html_e('Enable voice input on mobile', 'zen-cortext'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When off, the mic button is never rendered for visitors and the /transcribe endpoint returns 503.', 'zen-cortext'); ?></p>
                    </td>
                </tr>
            </table>

            <h3><?php esc_html_e('Primary provider — Groq (Whisper Large v3 Turbo)', 'zen-cortext'); ?></h3>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="zen_cortext_groq_api_key"><?php esc_html_e('Groq API key', 'zen-cortext'); ?></label></th>
                    <td>
                        <input type="password" id="zen_cortext_groq_api_key" name="zen_cortext_groq_api_key"
                               value="<?php echo esc_attr($groq_api_key); ?>"
                               class="regular-text" autocomplete="off" />
                        <p class="description"><?php
                            printf(
                                /* translators: %s is the HTML link to the Groq API-keys console page. */
                                esc_html__('Stored locally as a WP option. Get a key at %s — pricing is pay-as-you-go and Groq bills you directly.', 'zen-cortext'),
                                '<a href="https://console.groq.com/keys" target="_blank" rel="noopener">console.groq.com/keys</a>'
                            );
                        ?></p>
                    </td>
                </tr>
            </table>

            <h3><?php esc_html_e('Fallback provider — OpenAI Whisper (optional)', 'zen-cortext'); ?></h3>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="zen_cortext_openai_api_key"><?php esc_html_e('OpenAI API key', 'zen-cortext'); ?></label></th>
                    <td>
                        <input type="password" id="zen_cortext_openai_api_key" name="zen_cortext_openai_api_key"
                               value="<?php echo esc_attr($openai_api_key); ?>"
                               class="regular-text" autocomplete="off" />
                        <p class="description"><?php
                            printf(
                                /* translators: %s is the HTML link to the OpenAI API-keys console page. */
                                esc_html__('Used only if Groq fails (network or auth error). Get a key at %s. Leave blank to disable the fallback.', 'zen-cortext'),
                                '<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com/api-keys</a>'
                            );
                        ?></p>
                    </td>
                </tr>
            </table>

        <?php elseif ($tab === 'sessions'):
            // User sessions — controls the visitor-session beacon. Default
            // is enabled (tracking on) so a fresh install collects data
            // immediately. GDPR mode wraps the beacon JS in a Google
            // Consent Mode v2 check so it only fires after the visitor
            // has granted analytics_storage.
            $sessions_enabled = (bool) get_option('zen_cortext_sessions_enabled', true);
            $sessions_gdpr    = (bool) get_option('zen_cortext_sessions_gdpr_compliant', false);
            ?>
            <h2><?php esc_html_e('User sessions tracking', 'zen-cortext'); ?></h2>
            <p class="description" style="max-width:880px;">
                <?php esc_html_e('Every guest pageview registers / extends a visitor session (GA-style: a new session starts after 30 minutes of inactivity OR when attribution changes). Sessions carry UTM tags, click IDs (gclid / msclkid / fbc / fbp), referrer, landing page — used by the Attribution Context rules and the Saved Chats browser to tie chats back to their traffic source.', 'zen-cortext'); ?>
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th><?php esc_html_e('Tracking', 'zen-cortext'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="zen_cortext_sessions_enabled" value="1" <?php checked($sessions_enabled); ?> />
                            <?php esc_html_e('Enable user sessions tracking', 'zen-cortext'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When off, the session beacon is never printed to the public site — no rows are written to wp_zen_cortext_sessions and no webhook fires.', 'zen-cortext'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('GDPR compliance', 'zen-cortext'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="zen_cortext_sessions_gdpr_compliant" value="1" <?php checked($sessions_gdpr); ?> />
                            <?php esc_html_e('Respect Google Consent Mode v2', 'zen-cortext'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When on, the session beacon waits for `analytics_storage` to be set to `granted` (via gtag consent calls or your cookie banner). If consent is denied or never granted, no beacon fires. Use this if your site shows a GDPR/CCPA cookie banner.', 'zen-cortext'); ?>
                            <br>
                            <?php esc_html_e('Has no effect if tracking is disabled above.', 'zen-cortext'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php
            // Custom code (chat pages). The full-page chat templates (/talk/)
            // own the whole document and skip wp_head()/wp_footer(), so the
            // theme's header/footer injectors, GTM4WP, and header-footer-code
            // plugins never run there. These fields inject the admin's own code
            // directly — GTM, GA4, Meta Pixel, site-verification tags, etc.
            $header_code = (string) get_option('zen_cortext_header_code', '');
            $body_code   = (string) get_option('zen_cortext_body_code', '');
            $footer_code = (string) get_option('zen_cortext_footer_code', '');
            ?>
            <h2><?php esc_html_e('Custom code (chat pages)', 'zen-cortext'); ?></h2>
            <p class="description" style="max-width:880px;">
                <?php esc_html_e('The full-page chat (the /talk/ page) renders its own document and intentionally bypasses your theme — so analytics or scripts you add via your theme or a header/footer plugin do NOT load there. Paste that code below and it will be injected into the standalone chat page. Use it for Google Tag Manager, Google Analytics, the Meta Pixel, site-verification meta tags, or any custom script.', 'zen-cortext'); ?>
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="zen_cortext_header_code"><?php esc_html_e('Header code', 'zen-cortext'); ?></label></th>
                    <td>
                        <textarea id="zen_cortext_header_code" name="zen_cortext_header_code" rows="6" class="large-text code" spellcheck="false" placeholder="&lt;!-- e.g. Google Tag Manager / GA4 / verification meta --&gt;"><?php echo esc_textarea($header_code); ?></textarea>
                        <p class="description"><?php esc_html_e('Printed inside <head>, as high as possible. Best for tag managers, analytics loaders, and <meta> verification tags.', 'zen-cortext'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="zen_cortext_body_code"><?php esc_html_e('Body code', 'zen-cortext'); ?></label></th>
                    <td>
                        <textarea id="zen_cortext_body_code" name="zen_cortext_body_code" rows="6" class="large-text code" spellcheck="false" placeholder="&lt;!-- e.g. GTM &lt;noscript&gt; fallback --&gt;"><?php echo esc_textarea($body_code); ?></textarea>
                        <p class="description"><?php esc_html_e('Printed immediately after the opening <body> tag. Best for the GTM <noscript> fallback.', 'zen-cortext'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="zen_cortext_footer_code"><?php esc_html_e('Footer code', 'zen-cortext'); ?></label></th>
                    <td>
                        <textarea id="zen_cortext_footer_code" name="zen_cortext_footer_code" rows="6" class="large-text code" spellcheck="false" placeholder="&lt;!-- e.g. deferred trackers / chat widgets --&gt;"><?php echo esc_textarea($footer_code); ?></textarea>
                        <p class="description"><?php esc_html_e('Printed just before the closing </body> tag. Best for deferred scripts and third-party widgets.', 'zen-cortext'); ?></p>
                    </td>
                </tr>
            </table>

        <?php endif; ?>

        <?php submit_button(); ?>
    </form>
    <?php endif; // tab === 'pages' branch closes here ?>
</div>
