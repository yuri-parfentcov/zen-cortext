<?php
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
                        ); ?>"><?php echo implode(' · ', $parts); ?></div>
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

<style>
.zen-cortext-wrap .zcd-meta {
    background: #fff; border: 1px solid #c3c4c7; padding: 12px 16px;
    margin: 16px 0; display: flex; flex-wrap: wrap; gap: 28px; font-size: 13px;
}
.zen-cortext-wrap .zcd-grid {
    display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 24px;
}
@media (max-width: 1100px) {
    .zen-cortext-wrap .zcd-grid { grid-template-columns: 1fr; }
}
.zen-cortext-wrap .zcd-conversation, .zen-cortext-wrap .zcd-attribution {
    background: #fff; border: 1px solid #c3c4c7; padding: 18px 22px;
}
.zen-cortext-wrap .zcd-msg { margin-bottom: 18px; }
.zen-cortext-wrap .zcd-msg-role {
    font-size: 10px; font-weight: 700; color: #50575e;
    text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;
}
.zen-cortext-wrap .zcd-msg-bubble {
    padding: 12px 16px; border-radius: 8px; font-size: 14px; line-height: 1.5;
    white-space: pre-wrap; word-wrap: break-word;
}
.zen-cortext-wrap .zcd-msg-user .zcd-msg-bubble {
    background: #2271b1; color: #fff; border-bottom-right-radius: 2px;
}
.zen-cortext-wrap .zcd-msg-assistant .zcd-msg-bubble {
    background: #f6f7f7; color: #1d2327; border-left: 3px solid #DBEB7E;
    border-bottom-left-radius: 2px;
}
.zen-cortext-wrap .zcd-attribution th { width: 110px; padding: 8px 10px; font-size: 12px; }
.zen-cortext-wrap .zcd-attribution td { padding: 8px 10px; font-size: 12px; word-break: break-all; }
.zen-cortext-wrap .zcd-empty { color: #aaa; }
.zen-cortext-wrap .zcd-attribution code {
    background: #f0f0f1; padding: 2px 6px; border-radius: 3px;
    font-size: 11px;
}
.zen-cortext-wrap .zcd-lead {
    margin: 16px 0 20px;
    padding: 14px 18px;
    background: #e7f3d4;
    border-left: 4px solid #7a9c2e;
    border-radius: 4px;
}
.zen-cortext-wrap .zcd-lead-head {
    display: flex; justify-content: space-between; align-items: baseline;
    margin-bottom: 8px; font-size: 14px;
}
.zen-cortext-wrap .zcd-lead-head strong { color: #3a5c1a; font-size: 15px; }
.zen-cortext-wrap .zcd-lead-when { color: #6b7b4a; font-size: 12px; }
.zen-cortext-wrap .zcd-lead-body { display: flex; flex-wrap: wrap; gap: 8px 24px; font-size: 14px; }
.zen-cortext-wrap .zcd-lead-k {
    color: #6b7b4a; font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .5px; margin-right: 6px;
}
.zen-cortext-wrap .zcd-lead-v { color: #222; }
.zen-cortext-wrap .zcd-enrichment {
    margin-top: 6px; padding: 4px 0 0 2px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 11px; color: #787c82; line-height: 1.6;
    display: flex; flex-wrap: wrap; gap: 4px 10px;
}
.zen-cortext-wrap .zcd-enrichment .zcd-tag-k {
    color: #a7aaae; margin-right: 4px;
}
.zen-cortext-wrap .zcd-enrichment .zcd-tag-v {
    color: #3c434a;
}

/* Admin handler block — uses the existing lead-card visual language with a
   distinct color so it reads as a different facet of the same chat. */
.zen-cortext-wrap .zcd-admin {
    margin: 16px 0 20px;
    padding: 14px 18px;
    background: #f0f6fc;
    border-left: 4px solid #2271b1;
    border-radius: 4px;
}
.zen-cortext-wrap .zcd-admin-head {
    display: flex; justify-content: space-between; align-items: baseline;
    margin-bottom: 8px; font-size: 14px;
}
.zen-cortext-wrap .zcd-admin-head strong { color: #1d4d80; font-size: 15px; }
.zen-cortext-wrap .zcd-admin-when { color: #4a6b8a; font-size: 12px; }
.zen-cortext-wrap .zcd-admin-body { display: flex; flex-wrap: wrap; gap: 8px 24px; font-size: 14px; }
.zen-cortext-wrap .zcd-admin-k {
    color: #4a6b8a; font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .5px; margin-right: 6px;
}
.zen-cortext-wrap .zcd-admin-v { color: #222; }
.zen-cortext-wrap .zcd-admin code {
    background: #d9e7f2; color: #1d4d80; padding: 1px 6px; border-radius: 3px; font-size: 11px;
}

/* Right column wraps the attribution table + sessions block, stacked. */
.zen-cortext-wrap .zcd-right { display: flex; flex-direction: column; gap: 18px; }

/* Sessions block — narrow right-column variant matching the attribution
   table's visual weight. Uses a single-column table so labels and values
   don't collide in the tight horizontal space. */
.zen-cortext-wrap .zcd-sessions {
    background: #fff; border: 1px solid #c3c4c7; padding: 18px 22px;
}
.zen-cortext-wrap .zcd-sessions h2 { margin: 0 0 10px; font-size: 14px; }
.zen-cortext-wrap .zcd-sessions h3 {
    margin: 14px 0 4px; font-size: 11px;
    text-transform: uppercase; letter-spacing: .05em; color: #50575e;
}
.zen-cortext-wrap .zcd-session-head {
    display: flex; flex-direction: column; gap: 2px; margin-bottom: 6px;
}
.zen-cortext-wrap .zcd-session-head strong { font-size: 12px; color: #50575e; }
.zen-cortext-wrap .zcd-session-uid code {
    font-size: 11px; background: #f0f0f1; padding: 1px 6px; border-radius: 3px;
    display: inline-block; max-width: 100%; overflow-wrap: anywhere;
}
.zen-cortext-wrap .zcd-session-table th {
    width: 110px; font-size: 12px; padding: 6px 10px; text-align: left;
}
.zen-cortext-wrap .zcd-session-table td {
    font-size: 12px; padding: 6px 10px; word-break: break-all;
}
.zen-cortext-wrap .zcd-session-table code {
    background: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-size: 11px;
}
.zen-cortext-wrap .zcd-session-empty {
    margin: 4px 0 0; color: #646970; font-size: 12px;
}
.zen-cortext-wrap .zcd-session-related .description {
    margin: 2px 0 8px; font-style: italic; color: #646970; font-size: 11px;
}
.zen-cortext-wrap .zcd-related-list {
    list-style: none; margin: 0; padding: 0;
    border-top: 1px solid #f0f0f1;
}
.zen-cortext-wrap .zcd-related-list li {
    display: flex; align-items: baseline; gap: 8px;
    padding: 6px 0; border-bottom: 1px solid #f0f0f1; font-size: 12px;
}
.zen-cortext-wrap .zcd-related-when {
    color: #646970; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 11px; flex-shrink: 0;
}
.zen-cortext-wrap .zcd-related-chats {
    margin-left: auto; color: #2271b1; font-size: 11px; font-weight: 600;
}
</style>

<script>
(function () {
    var btn = document.getElementById('zcd-restore');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var fd = new FormData();
        fd.append('action', 'zen_cortext_chat_restore');
        fd.append('nonce', <?php echo wp_json_encode(wp_create_nonce('zen_cortext_admin')); ?>);
        fd.append('id', btn.dataset.id);
        fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, {
            method: 'POST', body: fd, credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
            if (resp && resp.success) { window.location.reload(); }
            else { alert((resp && resp.data && resp.data.message) || 'Restore failed'); }
        })
        .catch(function () { alert('Request failed'); });
    });
})();
</script>
