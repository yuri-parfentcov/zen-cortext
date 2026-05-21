<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Zen Cortext — Chat settings → Left panel / modal menu tab.
 *
 * Everything that renders in the side rail on desktop / mobile-menu
 * modal next to the chat:
 *  - Quick links (pipe-delimited textarea + emoji palette)
 *  - Live Chat Takeover (enable, button label, invitable users)
 *  - Team Expertise (per-user routing descriptions; only shown when
 *    invitable users are selected)
 *
 * Saves through the `zen_cortext_chat_rail` settings group.
 */
if (!defined('ABSPATH')) exit;
?>
<form method="post" action="options.php">
    <?php settings_fields('zen_cortext_chat_rail'); ?>

    <h2><?php esc_html_e('Side rail — quick links', 'zen-cortext'); ?></h2>
    <p class="description" style="max-width:880px;">
        <?php esc_html_e('Link cards rendered next to the visitor chat — vertical rail on desktop (left edge of /talk/), modal menu on mobile. One link per line.', 'zen-cortext'); ?>
        <br>
        <?php
        printf(
            /* translators: %1$s is the full rail-link format example (icon | prefix | label | url), %2$s is the literal optional "prefix" field, both wrapped in <code>. */
            esc_html__('Format: %1$s — %2$s is optional. All cards open in a new tab so visitors stay anchored on the chat page.', 'zen-cortext'),
            '<code>icon | prefix | label | url</code>',
            '<code>prefix</code>'
        );
        ?>
        <br>
        <?php esc_html_e('Examples:', 'zen-cortext'); ?>
        <code>🌐 | Main site: | zenrepublic.agency | https://zenrepublic.agency/</code>,
        <code>📁 | | Case studies | /projects/</code>
    </p>
    <?php
    $quick_links     = (array) get_option('zen_cortext_quick_links', Zen_Cortext_Defaults::default_quick_links());
    $quick_links_txt = Zen_Cortext_Admin::quick_links_to_textarea($quick_links);
    ?>
    <textarea name="zen_cortext_quick_links" id="zen-cortext-quick-links" rows="8" class="large-text code"
              placeholder="🌐 | Main site: | zenrepublic.agency | https://zenrepublic.agency/&#10;📁 | | Case studies | /projects/"><?php
        echo esc_textarea($quick_links_txt);
    ?></textarea>

    <div class="zcql-emoji-palette" style="margin-top:8px;">
        <p class="description" style="margin:0 0 4px;">
            <?php esc_html_e('Click an icon to insert it at the cursor:', 'zen-cortext'); ?>
        </p>
        <div class="zcql-emoji-grid" style="display:flex;flex-wrap:wrap;gap:4px;max-width:720px;">
            <?php
            // Curated palette of link-friendly emojis. Tap to insert
            // into the textarea at the cursor position. Admins can
            // still paste any emoji directly — this is a shortcut,
            // not an allowlist.
            $palette = array(
                '🌐','🏠','📁','📂','📄','📋','📅','📊',
                '📞','✉','💬','💼','💰','💡','🎯','🚀',
                '🛒','📷','📍','⭐','❤','🔗','🔍','🎓',
                '🏆','✓','⚡','⚙','⚠','→','↗','★',
            );
            foreach ($palette as $glyph): ?>
                <button type="button" class="zcql-emoji-btn"
                        data-emoji="<?php echo esc_attr($glyph); ?>"
                        style="font-size:18px;line-height:1;padding:6px 8px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;cursor:pointer;min-width:32px;">
                    <?php echo esc_html($glyph); ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
    (function(){
        var $ta = document.getElementById('zen-cortext-quick-links');
        if (!$ta) return;
        document.querySelectorAll('.zcql-emoji-btn').forEach(function(btn){
            btn.addEventListener('click', function(e){
                e.preventDefault();
                var emoji = btn.getAttribute('data-emoji') || '';
                if (!emoji) return;
                var start = $ta.selectionStart, end = $ta.selectionEnd;
                var val   = $ta.value;
                // Insert at the cursor. If the cursor is at the
                // start of a non-empty line and the line doesn't
                // already start with an icon, follow up with " | "
                // so the admin can type prefix/label/url directly.
                var before     = val.slice(0, start);
                var after      = val.slice(end);
                var lineStart  = before.lastIndexOf('\n') + 1;
                var atLineHead = (start === lineStart);
                var insert     = atLineHead && after.charAt(0) !== '|' ? (emoji + ' | ') : emoji;
                $ta.value      = before + insert + after;
                var caret      = start + insert.length;
                $ta.focus();
                $ta.setSelectionRange(caret, caret);
            });
        });
    })();
    </script>

    <h2><?php esc_html_e('Live Chat Takeover', 'zen-cortext'); ?></h2>
    <p class="description"><?php esc_html_e('Allow visitors to invite a team member into the chat. The AI pauses while a human is in the conversation.', 'zen-cortext'); ?></p>

    <?php
    $show_invite        = get_option('zen_cortext_show_invite_buttons', false);
    $invite_label       = get_option('zen_cortext_invite_label', 'Talk to a real person');
    $invitable_selected = (array) get_option('zen_cortext_invitable_users', array());
    $all_users          = get_users(array('capability' => 'edit_posts', 'fields' => array('ID', 'display_name', 'user_email')));
    ?>
    <table class="form-table" role="presentation">
        <tr>
            <th><?php esc_html_e('Enable invite buttons', 'zen-cortext'); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="zen_cortext_show_invite_buttons" value="1" <?php checked($show_invite); ?> />
                    <?php esc_html_e('Show "invite a team member" buttons in the public chat.', 'zen-cortext'); ?>
                </label>
            </td>
        </tr>
        <tr>
            <th><label for="zen_cortext_invite_label"><?php esc_html_e('Button label', 'zen-cortext'); ?></label></th>
            <td>
                <input type="text" id="zen_cortext_invite_label" name="zen_cortext_invite_label"
                       value="<?php echo esc_attr($invite_label); ?>" class="regular-text" />
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e('Invitable team members', 'zen-cortext'); ?></th>
            <td>
                <?php if (empty($all_users)): ?>
                    <p class="description"><?php esc_html_e('No users with edit_posts capability found.', 'zen-cortext'); ?></p>
                <?php else: foreach ($all_users as $u): ?>
                    <label style="display:block; margin-bottom:6px;">
                        <input type="checkbox" name="zen_cortext_invitable_users[]"
                               value="<?php echo (int) $u->ID; ?>"
                            <?php checked(in_array((int) $u->ID, array_map('intval', $invitable_selected), true)); ?> />
                        <?php echo esc_html($u->display_name . ' (' . $u->user_email . ')'); ?>
                    </label>
                <?php endforeach; endif; ?>
                <p class="description"><?php esc_html_e('Checked users appear as team-member cards in the rail + invite buttons in the chat. They receive email + push notifications when invited.', 'zen-cortext'); ?></p>
            </td>
        </tr>
    </table>

    <?php
    // Team Expertise section — shown only when there are invitable users.
    // Without that gate the section would be empty + confusing on a
    // fresh install with no team configured.
    if (!empty($invitable_selected)):
        $team_expertise = (array) get_option('zen_cortext_team_expertise', array());
    ?>
    <h2><?php esc_html_e('Team Expertise (for AI routing)', 'zen-cortext'); ?></h2>
    <p class="description"><?php esc_html_e('Describe each team member\'s areas of expertise. The AI uses this to recommend the right person when a visitor\'s question matches a specific domain.', 'zen-cortext'); ?></p>
    <table class="form-table" role="presentation">
        <?php foreach ($invitable_selected as $uid):
            $uid = (int) $uid;
            $u = get_userdata($uid);
            if (!$u) continue;
            $expertise_text = isset($team_expertise[$uid]) ? $team_expertise[$uid] : '';
        ?>
        <tr>
            <th><label for="zen_cortext_team_expertise_<?php echo (int) $uid; ?>"><?php echo esc_html($u->display_name); ?></label></th>
            <td>
                <textarea id="zen_cortext_team_expertise_<?php echo (int) $uid; ?>"
                          name="zen_cortext_team_expertise[<?php echo (int) $uid; ?>]"
                          rows="4" class="large-text"><?php echo esc_textarea($expertise_text); ?></textarea>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <?php submit_button(); ?>
</form>
