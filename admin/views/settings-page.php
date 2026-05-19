<?php
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
    'sessions'   => __('User sessions', 'zen-cortext'),
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
            <h2><?php esc_html_e('Backend for internal jobs', 'zen-cortext'); ?></h2>
            <p class="description"><?php esc_html_e('Choose how the plugin runs classify + restructure jobs. The frontend chat always uses the HTTP API (CLI cannot stream into the browser).', 'zen-cortext'); ?></p>
            <?php $processor = get_option('zen_cortext_processor', 'api'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th><?php esc_html_e('Processor', 'zen-cortext'); ?></th>
                    <td>
                        <label style="display:block; margin-bottom:6px;">
                            <input type="radio" name="zen_cortext_processor" value="api" <?php checked($processor, 'api'); ?> />
                            <?php esc_html_e('Anthropic HTTP API (uses the API key below)', 'zen-cortext'); ?>
                        </label>
                        <label style="display:block;">
                            <input type="radio" name="zen_cortext_processor" value="cli" <?php checked($processor, 'cli'); ?> />
                            <?php esc_html_e('Claude Code CLI (shells out to the `claude` binary on this server)', 'zen-cortext'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Anthropic HTTP API', 'zen-cortext'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="zen_cortext_api_key"><?php esc_html_e('API Key', 'zen-cortext'); ?></label></th>
                    <td>
                        <input type="password" id="zen_cortext_api_key" name="zen_cortext_api_key"
                               value="<?php echo esc_attr(get_option('zen_cortext_api_key', '')); ?>"
                               class="regular-text" autocomplete="off" />
                        <button type="button" class="button" id="zen-cortext-test-connection"><?php esc_html_e('Test connection', 'zen-cortext'); ?></button>
                        <p class="description"><?php esc_html_e('Stored locally as a WP option. Get a key from console.anthropic.com. Required for the frontend chat regardless of the processor choice above.', 'zen-cortext'); ?></p>
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
                        <p class="description"><?php esc_html_e('Used for classify + restructure when processor = API. Sonnet recommended.', 'zen-cortext'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="zen_cortext_max_tokens"><?php esc_html_e('Max tokens', 'zen-cortext'); ?></label></th>
                    <td>
                        <input type="number" id="zen_cortext_max_tokens" name="zen_cortext_max_tokens"
                               value="<?php echo esc_attr(get_option('zen_cortext_max_tokens', 2048)); ?>"
                               class="small-text" min="64" max="8192" />
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Claude Code CLI', 'zen-cortext'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="zen_cortext_cli_path"><?php esc_html_e('CLI binary', 'zen-cortext'); ?></label></th>
                    <td>
                        <input type="text" id="zen_cortext_cli_path" name="zen_cortext_cli_path"
                               value="<?php echo esc_attr(get_option('zen_cortext_cli_path', 'claude')); ?>"
                               class="regular-text" />
                        <p class="description"><?php esc_html_e('Path to the claude binary. Use a full path like /usr/local/bin/claude if it is not on PHP\'s PATH. The CLI must be authenticated for the OS user that runs PHP.', 'zen-cortext'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="zen_cortext_cli_model"><?php esc_html_e('CLI job model', 'zen-cortext'); ?></label></th>
                    <td>
                        <input type="text" id="zen_cortext_cli_model" name="zen_cortext_cli_model"
                               value="<?php echo esc_attr(get_option('zen_cortext_cli_model', 'sonnet')); ?>"
                               class="regular-text" />
                        <p class="description"><?php esc_html_e('Passed as --model. Aliases like sonnet/haiku/opus or full IDs both work.', 'zen-cortext'); ?></p>
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
                            /* translators: %s is a link to the Groq console */
                            printf(
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
                            /* translators: %s is a link to the OpenAI API key console */
                            printf(
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

        <?php endif; ?>

        <?php submit_button(); ?>
    </form>
    <?php endif; // tab === 'pages' branch closes here ?>
</div>
