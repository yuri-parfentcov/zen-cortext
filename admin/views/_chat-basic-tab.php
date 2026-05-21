<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Zen Cortext — Chat settings → Basic tab.
 *
 * Visitor-facing chrome rendered before any conversation happens:
 *  - Intro card (the speaker identity card shown at the top of the chat)
 *  - Welcome message (the first AI-emitted greeting line)
 *  - Default starter chips (the quick-reply buttons above the input)
 *  - Default survey selector (which survey to run; the framing prompt
 *    that wraps it lives on the Prompts tab)
 *
 * Each save round-trips through the `zen_cortext_chat_basic` settings
 * group so changes here can't blank options that belong to the rail
 * or prompts tabs.
 *
 * Available locals: $intro (passed in by the parent chat-page.php router).
 */
if (!defined('ABSPATH')) exit;
?>
<form method="post" action="options.php">
    <?php settings_fields('zen_cortext_chat_basic'); ?>

    <h2><?php esc_html_e('Intro card (step 1)', 'zen-cortext'); ?></h2>
    <p class="description" style="max-width:880px;">
        <?php esc_html_e('The identity card the visitor sees first — speaker name, role, body text, optional logo, and an outbound site link. Logo URL defaults to your WordPress Site Icon.', 'zen-cortext'); ?>
    </p>
    <table class="form-table" role="presentation">
        <tr>
            <th><label><?php esc_html_e('Name', 'zen-cortext'); ?></label></th>
            <td><input type="text" name="zen_cortext_intro_card[name]" value="<?php echo esc_attr($intro['name'] ?? ''); ?>" class="regular-text" /></td>
        </tr>
        <tr>
            <th><label><?php esc_html_e('Role / tagline', 'zen-cortext'); ?></label></th>
            <td><input type="text" name="zen_cortext_intro_card[role]" value="<?php echo esc_attr($intro['role'] ?? ''); ?>" class="regular-text" /></td>
        </tr>
        <tr>
            <th><label><?php esc_html_e('Body', 'zen-cortext'); ?></label></th>
            <td><textarea name="zen_cortext_intro_card[body]" rows="4" class="large-text"><?php echo esc_textarea($intro['body'] ?? ''); ?></textarea></td>
        </tr>
        <tr>
            <th><label><?php esc_html_e('Logo URL', 'zen-cortext'); ?></label></th>
            <td><input type="url" name="zen_cortext_intro_card[logo_url]" value="<?php echo esc_attr($intro['logo_url'] ?? ''); ?>" class="regular-text" /></td>
        </tr>
        <tr>
            <th><label><?php esc_html_e('Site URL', 'zen-cortext'); ?></label></th>
            <td><input type="url" name="zen_cortext_intro_card[site_url]" value="<?php echo esc_attr($intro['site_url'] ?? ''); ?>" class="regular-text" /></td>
        </tr>
    </table>

    <h2><?php esc_html_e('Welcome message (step 2)', 'zen-cortext'); ?></h2>
    <p class="description" style="max-width:880px;">
        <?php esc_html_e('The first AI-emitted line of the conversation. Treated as a starter prompt — the AI is told to open with this text verbatim.', 'zen-cortext'); ?>
    </p>
    <?php
    // Same empty-fallback pattern as the survey-framing textarea below:
    // get_option's default only fires when the option is missing, NOT
    // when it's an empty string. We want the admin to always see real
    // content in the field.
    $welcome_msg = (string) get_option('zen_cortext_welcome_message', '');
    if (trim($welcome_msg) === '') {
        $welcome_msg = Zen_Cortext_Defaults::welcome_message();
    }
    ?>
    <textarea name="zen_cortext_welcome_message" rows="6" class="large-text"><?php echo esc_textarea($welcome_msg); ?></textarea>

    <h2><?php esc_html_e('Default starter chips', 'zen-cortext'); ?></h2>
    <p class="description">
        <?php esc_html_e('Quick-reply buttons shown above the input when no Attribution Context rule overrides them. One chip per line. Chat picks 4 at random per visit. Empty = no chips shown.', 'zen-cortext'); ?>
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
    <?php
    $default_chips     = (array) get_option('zen_cortext_default_chips', array());
    $default_chips_txt = Zen_Cortext_Admin::chips_to_textarea($default_chips);
    ?>
    <textarea name="zen_cortext_default_chips" rows="10" class="large-text code"
              placeholder="📦 | Office cleaning | I need office cleaning&#10;🏢 | Warehouse | What about warehouses?&#10;Get a quote"><?php
        echo esc_textarea($default_chips_txt);
    ?></textarea>

    <h2><?php esc_html_e('Default survey', 'zen-cortext'); ?></h2>
    <p class="description">
        <?php
        printf(
            wp_kses(
                /* translators: %1$s is the opening <a> tag pointing to the Surveys admin page, %2$s is the closing </a>. */
                __('Optional interview script the AI weaves into the conversation when no Attribution Context rule overrides it. Manage scripts on %1$sZen Cortext → Surveys%2$s.', 'zen-cortext'),
                array('a' => array('href' => array()))
            ),
            '<a href="' . esc_url(admin_url('admin.php?page=zen-cortext-surveys')) . '">',
            '</a>'
        );
        ?>
    </p>
    <?php
    $default_survey_id = (int) get_option('zen_cortext_default_survey_id', 0);
    $available_surveys = class_exists('Zen_Cortext_Surveys')
        ? Zen_Cortext_Surveys::all(array('only_enabled' => true))
        : array();
    ?>
    <select name="zen_cortext_default_survey_id">
        <option value="0"><?php esc_html_e('— None —', 'zen-cortext'); ?></option>
        <?php foreach ($available_surveys as $sv): ?>
            <option value="<?php echo (int) $sv['id']; ?>" <?php selected($default_survey_id, (int) $sv['id']); ?>>
                <?php
                    echo esc_html($sv['label']);
                    if (!empty($sv['question_count'])) {
                        echo ' (' . (int) $sv['question_count'] . ' Q)';
                    }
                ?>
            </option>
        <?php endforeach; ?>
    </select>
    <p class="description" style="max-width:880px;">
        <?php
        printf(
            wp_kses(
                /* translators: %1$s is the HTML link to the Prompts tab; %2$s is the HTML link to the Surveys page. */
                __('The framing prompt that wraps the chosen survey lives on the %1$s tab. Manage individual survey scripts on %2$s.', 'zen-cortext'),
                array('a' => array('href' => array()))
            ),
            '<a href="' . esc_url(add_query_arg(array('page' => 'zen-cortext-chat', 'tab' => 'prompts'), admin_url('admin.php'))) . '">' . esc_html__('Prompts', 'zen-cortext') . '</a>',
            '<a href="' . esc_url(admin_url('admin.php?page=zen-cortext-surveys')) . '">' . esc_html__('Surveys', 'zen-cortext') . '</a>'
        );
        ?>
    </p>

    <?php submit_button(); ?>
</form>
