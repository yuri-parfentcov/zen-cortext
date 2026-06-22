<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Zen Cortext — Saved Chat detail page.
 * Available:
 *   $chat              assoc array from Zen_Cortext_Chats::get
 *   $admin_user        null|array{id,display_name,user_email,user_login} — admin who took over (if any)
 *   $session           null|array — parent session row stamped on this chat (null = pre-sessions)
 *   $related_sessions  array — other sessions from the same browser (ip_hash match)
 */
if (!defined('ABSPATH')) exit;

$messages = json_decode($chat['messages'], true);
if (!is_array($messages)) $messages = array();

$back_url = admin_url('admin.php?page=zen-cortext-chats');

$attribution_rows = array(
    'Source'     => $chat['utm_source'],
    'Medium'     => $chat['utm_medium'],
    'Campaign'   => $chat['utm_campaign'],
    'Term'       => $chat['utm_term'],
    'Content'    => $chat['utm_content'],
    'gclid'      => $chat['gclid'],
    'msclkid'    => $chat['msclkid'],
    '_fbc'       => $chat['fbc'],
    '_fbp'       => $chat['fbp'],
    'Referrer'   => $chat['referrer'],
    'Landing'    => $chat['landing_page'],
    'User-Agent' => $chat['user_agent'],
    'IP hash'    => $chat['ip_hash'],
);
?>
<div class="wrap zen-cortext-wrap">
    <h1>
        <?php esc_html_e('Saved Chat', 'zen-cortext'); ?>
        <a href="<?php echo esc_url($back_url); ?>" class="page-title-action">← <?php esc_html_e('Back to list', 'zen-cortext'); ?></a>
    </h1>

    <?php if (!empty($chat['deleted_at'])): ?>
        <div class="notice notice-error" style="border-left-color:#d63638; padding:12px 16px; margin:16px 0;">
            <p style="margin:0; font-size:14px;">
                <strong><?php esc_html_e('This chat was deleted by the visitor on', 'zen-cortext'); ?> <?php echo esc_html($chat['deleted_at']); ?>.</strong>
                <?php esc_html_e('It is no longer accessible from the share link, but remains in the admin for your records.', 'zen-cortext'); ?>
                <button type="button" class="button button-small" id="zcd-restore" data-id="<?php echo (int) $chat['id']; ?>" style="margin-left:12px;"><?php esc_html_e('Restore', 'zen-cortext'); ?></button>
            </p>
        </div>
    <?php endif; ?>

    <?php if (!empty($chat['lead_submitted_at'])):
        $wa     = trim((string) ($chat['lead_whatsapp'] ?? ''));
        $wa_num = $wa !== '' ? preg_replace('/[^0-9+]/', '', $wa) : '';
    ?>
    <div class="zcd-lead">
        <div class="zcd-lead-head">
            <strong><?php esc_html_e('Lead captured', 'zen-cortext'); ?></strong>
            <span class="zcd-lead-when"><?php echo esc_html($chat['lead_submitted_at']); ?></span>
        </div>
        <div class="zcd-lead-body">
            <?php if (!empty($chat['lead_name'])): ?>
                <div><span class="zcd-lead-k"><?php esc_html_e('Name', 'zen-cortext'); ?></span>
                     <span class="zcd-lead-v"><?php echo esc_html($chat['lead_name']); ?></span></div>
            <?php endif; ?>
            <?php if (!empty($chat['lead_email'])): ?>
                <div><span class="zcd-lead-k"><?php esc_html_e('Email', 'zen-cortext'); ?></span>
                     <span class="zcd-lead-v"><a href="mailto:<?php echo esc_attr($chat['lead_email']); ?>"><?php echo esc_html($chat['lead_email']); ?></a></span></div>
            <?php endif; ?>
            <?php if ($wa !== ''): ?>
                <div><span class="zcd-lead-k"><?php esc_html_e('WhatsApp', 'zen-cortext'); ?></span>
                     <span class="zcd-lead-v">
                        <?php echo esc_html($wa); ?>
                        <?php if ($wa_num !== ''): ?>
                            · <a href="https://wa.me/<?php echo esc_attr(ltrim($wa_num, '+')); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open in WhatsApp', 'zen-cortext'); ?></a>
                        <?php endif; ?>
                     </span></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($admin_user)):
        $admin_url     = admin_url('user-edit.php?user_id=' . (int) $admin_user['id']);
        $admin_attached = !empty($chat['admin_attached_at']) ? (string) $chat['admin_attached_at'] : '';
        $admin_detached = !empty($chat['admin_detached_at']) ? (string) $chat['admin_detached_at'] : '';
        $invited_ids = trim((string) ($chat['invited_user_ids'] ?? ''));
        $invited_names = array();
        if ($invited_ids !== '') {
            $decoded = json_decode($invited_ids, true);
            if (is_array($decoded)) {
                foreach ($decoded as $uid) {
                    $u = get_userdata((int) $uid);
                    if ($u) $invited_names[] = (string) $u->display_name;
                }
            }
        }
    ?>
    <div class="zcd-admin">
        <div class="zcd-admin-head">
            <strong><?php esc_html_e('Admin took over this chat', 'zen-cortext'); ?></strong>
            <?php if ($admin_attached !== ''): ?>
                <span class="zcd-admin-when"><?php echo esc_html($admin_attached); ?>
                    <?php if ($admin_detached !== ''): ?>
                        → <?php echo esc_html($admin_detached); ?>
                    <?php else: ?>
                        · <?php esc_html_e('still attached', 'zen-cortext'); ?>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="zcd-admin-body">
            <div><span class="zcd-admin-k"><?php esc_html_e('Name', 'zen-cortext'); ?></span>
                 <span class="zcd-admin-v"><a href="<?php echo esc_url($admin_url); ?>"><?php echo esc_html($admin_user['display_name']); ?></a> <code><?php echo esc_html($admin_user['user_login']); ?></code></span></div>
            <?php if (!empty($admin_user['user_email'])): ?>
                <div><span class="zcd-admin-k"><?php esc_html_e('Email', 'zen-cortext'); ?></span>
                     <span class="zcd-admin-v"><a href="mailto:<?php echo esc_attr($admin_user['user_email']); ?>"><?php echo esc_html($admin_user['user_email']); ?></a></span></div>
            <?php endif; ?>
            <?php if (!empty($invited_names)): ?>
                <div><span class="zcd-admin-k"><?php esc_html_e('Invited', 'zen-cortext'); ?></span>
                     <span class="zcd-admin-v"><?php echo esc_html(implode(', ', $invited_names)); ?></span></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="zcd-meta">
        <div><strong><?php esc_html_e('Chat UID:', 'zen-cortext'); ?></strong> <code><?php echo esc_html($chat['chat_uid']); ?></code></div>
        <?php if (!empty($chat['session_uid'])): ?>
            <div><strong><?php esc_html_e('Session UID:', 'zen-cortext'); ?></strong> <code><?php echo esc_html($chat['session_uid']); ?></code></div>
        <?php endif; ?>
        <div><strong><?php esc_html_e('Created:', 'zen-cortext'); ?></strong> <?php echo esc_html($chat['created_at']); ?></div>
        <div><strong><?php esc_html_e('Updated:', 'zen-cortext'); ?></strong> <?php echo esc_html($chat['updated_at']); ?></div>
        <div><strong><?php esc_html_e('Messages:', 'zen-cortext'); ?></strong> <?php echo (int) $chat['message_count']; ?></div>
    </div>

    <div class="zcd-grid">
        <div class="zcd-conversation">
            <h2><?php esc_html_e('Conversation', 'zen-cortext'); ?></h2>
            <?php if (empty($messages)): ?>
                <p><em><?php esc_html_e('No messages yet.', 'zen-cortext'); ?></em></p>
            <?php else: foreach ($messages as $m):
                if (empty($m['role']) || empty($m['content'])) continue;
                $role = $m['role'] === 'assistant' ? 'assistant' : 'user';
                $enrichment = ($role === 'user' && !empty($m['enrichment']) && is_array($m['enrichment']))
                    ? $m['enrichment']
                    : null;
            ?>
                <div class="zcd-msg zcd-msg-<?php echo esc_attr($role); ?>">
                    <div class="zcd-msg-role"><?php echo esc_html(strtoupper($role)); ?></div>
                    <div class="zcd-msg-bubble"><?php echo nl2br(esc_html($m['content'])); ?></div>
                    <?php if ($enrichment !== null):
                        $tag_fields = array(
                            'intent'               => 'intent',
                            'conversation_quality' => 'quality',
                            'urgency_to_action'    => 'urgency',
                            'expertise_signal'     => 'expertise',
                        );
                        $parts = array();
                        foreach ($tag_fields as $key => $label) {
                            if (empty($enrichment[$key])) continue;
                            $parts[] = '<span class="zcd-tag-k">' . esc_html($label) . '</span>'
                                     . '<span class="zcd-tag-v">' . esc_html($enrichment[$key]) . '</span>';
                        }
                        if (!empty($parts)):
                    ?>
                        <div class="zcd-enrichment" title="<?php echo esc_attr(
                            !empty($enrichment['classified_at'])
                                ? 'Classified at ' . $enrichment['classified_at']
                                : ''
                        ); ?>"><?php echo wp_kses_post(implode(' · ', $parts)); ?></div>
                    <?php endif; endif; ?>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="zcd-right">
            <div class="zcd-attribution">
                <h2><?php esc_html_e('Attribution', 'zen-cortext'); ?></h2>
                <table class="widefat striped">
                    <tbody>
                    <?php foreach ($attribution_rows as $label => $value):
                        $value = trim((string) $value);
                    ?>
                        <tr>
                            <th><?php echo esc_html($label); ?></th>
                            <td>
                                <?php if ($value === ''): ?>
                                    <span class="zcd-empty">—</span>
                                <?php else: ?>
                                    <code><?php echo esc_html($value); ?></code>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!empty($session) || !empty($related_sessions) || !empty($chat['session_uid'])):
                $sessions_base = admin_url('admin.php?page=zen-cortext-sessions');
            ?>
            <div class="zcd-sessions">
                <h2><?php esc_html_e('Visitor sessions', 'zen-cortext'); ?></h2>
                <?php if (!empty($session)):
                    $src_med = trim((string) $session['utm_source'] . ($session['utm_medium'] !== '' ? ' / ' . $session['utm_medium'] : ''));
                    $click_id = $session['gclid'] !== '' ? ('gclid: ' . $session['gclid']) : ($session['msclkid'] !== '' ? ('msclkid: ' . $session['msclkid']) : '');
                ?>
                <div class="zcd-session-primary">
                    <div class="zcd-session-head">
                        <strong><?php esc_html_e('Parent session', 'zen-cortext'); ?></strong>
                        <span class="zcd-session-uid"><code><?php echo esc_html($session['session_uid']); ?></code></span>
                    </div>
                    <table class="widefat striped zcd-session-table">
                        <tbody>
                            <tr><th><?php esc_html_e('First seen', 'zen-cortext'); ?></th><td><?php echo esc_html($session['first_seen_at']); ?></td></tr>
                            <tr><th><?php esc_html_e('Last seen', 'zen-cortext'); ?></th><td><?php echo esc_html($session['last_seen_at']); ?></td></tr>
                            <tr><th><?php esc_html_e('Source / Medium', 'zen-cortext'); ?></th><td><?php echo $src_med !== '' ? esc_html($src_med) : '<span class="zcd-empty">—</span>'; ?></td></tr>
                            <tr><th><?php esc_html_e('Campaign', 'zen-cortext'); ?></th><td><?php echo $session['utm_campaign'] !== '' ? esc_html($session['utm_campaign']) : '<span class="zcd-empty">—</span>'; ?></td></tr>
                            <tr><th><?php esc_html_e('Click ID', 'zen-cortext'); ?></th><td><?php echo $click_id !== '' ? '<code>' . esc_html($click_id) . '</code>' : '<span class="zcd-empty">—</span>'; ?></td></tr>
                            <tr><th><?php esc_html_e('Landing', 'zen-cortext'); ?></th><td><?php echo $session['landing_page'] !== '' ? '<a href="' . esc_url($session['landing_page']) . '" target="_blank" rel="noopener">' . esc_html($session['landing_page']) . '</a>' : '<span class="zcd-empty">—</span>'; ?></td></tr>
                            <tr><th><?php esc_html_e('Chats attached', 'zen-cortext'); ?></th><td><?php echo (int) $session['chat_count']; ?></td></tr>
                            <tr><th><?php esc_html_e('Enriched', 'zen-cortext'); ?></th><td><?php echo !empty($session['enriched']) ? esc_html__('yes', 'zen-cortext') : esc_html__('no', 'zen-cortext'); ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <?php elseif (!empty($chat['session_uid'])): ?>
                    <p class="zcd-session-empty"><em><?php esc_html_e('Stamped with a session_uid that no longer exists (the session row was deleted).', 'zen-cortext'); ?></em></p>
                <?php endif; ?>

                <?php if (!empty($related_sessions)): ?>
                <div class="zcd-session-related">
                    <h3><?php
                        printf(
                            /* translators: %d is the number of other visit sessions matched by IP hash. */
                            esc_html(_n('Other visit (%d)', 'Other visits (%d)', count($related_sessions), 'zen-cortext')),
                            count($related_sessions)
                        );
                    ?></h3>
                    <p class="description"><?php esc_html_e('Matched by IP hash — same browser/network.', 'zen-cortext'); ?></p>
                    <ul class="zcd-related-list">
                    <?php foreach ($related_sessions as $rs):
                        $rs_label = trim((string) $rs['utm_campaign']);
                        if ($rs_label === '') $rs_label = trim((string) $rs['utm_source']);
                        if ($rs_label === '') $rs_label = __('(direct)', 'zen-cortext');
                        $sessions_url = add_query_arg(array('s' => $rs['session_uid'], 'enriched' => '0'), $sessions_base);
                    ?>
                        <li>
                            <span class="zcd-related-when"><?php echo esc_html(substr((string) $rs['last_seen_at'], 0, 16)); ?></span>
                            <a href="<?php echo esc_url($sessions_url); ?>"><strong><?php echo esc_html($rs_label); ?></strong></a>
                            <?php if ((int) $rs['chat_count'] > 0): ?>
                                <span class="zcd-related-chats"><?php
                                    /* translators: %d is the number of chats in the related session. */
                                    printf(esc_html(_n('%d chat', '%d chats', (int) $rs['chat_count'], 'zen-cortext')), (int) $rs['chat_count']);
                                ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>


