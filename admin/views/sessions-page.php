<?php
/**
 * Zen Cortext — User Sessions list page.
 * Available: $result (rows, total, pages), $stats, $rules,
 *            $page, $search, $enriched_only, $has_chats, $rule_id
 */
if (!defined('ABSPATH')) exit;

function zcs_truncate($s, $n = 60) {
    $s = trim((string) $s);
    if (function_exists('mb_substr')) {
        return mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s;
    }
    return strlen($s) > $n ? substr($s, 0, $n) . '…' : $s;
}

function zcs_pill($value) {
    $value = trim((string) $value);
    if ($value === '') return '<span class="zcs-pill-empty">—</span>';
    return '<span class="zcs-pill" title="' . esc_attr($value) . '">' . esc_html(zcs_truncate($value, 28)) . '</span>';
}

function zcs_host_only($url) {
    $url = trim((string) $url);
    if ($url === '') return '';
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) && $host !== '' ? $host : $url;
}

$base_url   = admin_url('admin.php?page=zen-cortext-sessions');
$rules_by_id = array();
if (is_array($rules)) {
    foreach ($rules as $rule) {
        $rules_by_id[(int) $rule['id']] = (string) $rule['label'];
    }
}
?>
<div class="wrap zen-cortext-wrap">
    <h1><?php esc_html_e('Zen Cortext — User Sessions', 'zen-cortext'); ?></h1>
    <p class="description">
        <?php esc_html_e('Every page load fires a beacon that creates or extends a visitor session (GA-style: new session after 30 minutes of inactivity OR when attribution changes mid-visit). Chats started during a session are attached to it, so you can see the full attribution map plus every chat the visitor had on that visit.', 'zen-cortext'); ?>
    </p>

    <div class="zcs-stats" id="zen-cortext-sessions-root">
        <table class="widefat striped" style="max-width:680px;">
            <tbody>
                <tr><th><?php esc_html_e('Total sessions', 'zen-cortext'); ?></th><td><strong><?php echo (int) $stats['total']; ?></strong></td></tr>
                <tr><th><?php esc_html_e('Enriched (with attribution)', 'zen-cortext'); ?></th><td><?php echo (int) $stats['enriched']; ?></td></tr>
                <tr><th><?php esc_html_e('With attached chats', 'zen-cortext'); ?></th><td><?php echo (int) $stats['with_chats']; ?></td></tr>
                <tr><th><?php esc_html_e('Last 24 hours', 'zen-cortext'); ?></th><td><?php echo (int) $stats['last_24h']; ?></td></tr>
                <tr><th><?php esc_html_e('Last 7 days', 'zen-cortext'); ?></th><td><?php echo (int) $stats['last_7d']; ?></td></tr>
            </tbody>
        </table>
    </div>

    <form method="get" class="zcs-filters">
        <input type="hidden" name="page" value="zen-cortext-sessions" />
        <p class="search-box" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search session uid, UTM, gclid, referrer, landing page…', 'zen-cortext'); ?>" class="regular-text" />
            <label style="display:inline-flex; align-items:center; gap:6px;">
                <input type="checkbox" name="enriched" value="1" <?php checked($enriched_only); ?> />
                <?php esc_html_e('Enriched only', 'zen-cortext'); ?>
            </label>
            <label style="display:inline-flex; align-items:center; gap:6px;">
                <input type="checkbox" name="has_chats" value="1" <?php checked($has_chats); ?> />
                <?php esc_html_e('With chats', 'zen-cortext'); ?>
            </label>
            <?php if (!empty($rules)): ?>
            <label style="display:inline-flex; align-items:center; gap:6px;">
                <?php esc_html_e('Rule:', 'zen-cortext'); ?>
                <select name="rule_id">
                    <option value="0"><?php esc_html_e('— Any —', 'zen-cortext'); ?></option>
                    <?php foreach ($rules as $rule):
                        $rid = (int) $rule['id']; ?>
                        <option value="<?php echo $rid; ?>" <?php selected($rule_id, $rid); ?>>
                            <?php echo esc_html($rule['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <button type="submit" class="button"><?php esc_html_e('Filter', 'zen-cortext'); ?></button>
            <?php if ($search !== '' || $has_chats || $rule_id || !$enriched_only): ?>
                <a class="button-link" href="<?php echo esc_url($base_url); ?>"><?php esc_html_e('Clear', 'zen-cortext'); ?></a>
            <?php endif; ?>
        </p>
    </form>

    <table class="widefat striped zcs-list">
        <thead>
            <tr>
                <th style="width:74px;"></th>
                <th><?php esc_html_e('Last seen', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('First seen', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Source / Medium', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Campaign', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Click ID', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Landing', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Rule', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Chats', 'zen-cortext'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($result['rows'])): ?>
                <tr><td colspan="9"><em><?php esc_html_e('No sessions yet.', 'zen-cortext'); ?></em></td></tr>
            <?php else: foreach ($result['rows'] as $r):
                $sid       = (int) $r['id'];
                $src_med   = trim($r['utm_source'] . ($r['utm_medium'] !== '' ? ' / ' . $r['utm_medium'] : ''));
                $click_id  = $r['gclid'] !== '' ? ('gclid: ' . $r['gclid']) : ($r['msclkid'] !== '' ? ('msclkid: ' . $r['msclkid']) : '');
                $rule_label = !empty($r['rule_id']) && isset($rules_by_id[(int) $r['rule_id']])
                    ? $rules_by_id[(int) $r['rule_id']]
                    : '';
                $row_class = !empty($r['enriched']) ? 'zcs-enriched' : 'zcs-direct';
            ?>
                <tr data-id="<?php echo $sid; ?>" class="<?php echo esc_attr($row_class); ?>">
                    <td class="zcs-row-actions">
                        <button type="button" class="button button-small zcs-expand" aria-label="<?php esc_attr_e('Expand', 'zen-cortext'); ?>">+</button>
                        <button type="button" class="button button-small zcs-delete" aria-label="<?php esc_attr_e('Delete', 'zen-cortext'); ?>" title="<?php esc_attr_e('Delete session', 'zen-cortext'); ?>">×</button>
                    </td>
                    <td><?php echo esc_html(substr($r['last_seen_at'], 0, 16)); ?></td>
                    <td><?php echo esc_html(substr($r['first_seen_at'], 0, 16)); ?></td>
                    <td><?php echo zcs_pill($src_med); ?></td>
                    <td><?php echo zcs_pill($r['utm_campaign']); ?></td>
                    <td><?php echo $click_id !== '' ? zcs_pill($click_id) : '<span class="zcs-pill-empty">—</span>'; ?></td>
                    <td>
                        <?php if ($r['landing_page'] !== ''): ?>
                            <span class="zcs-pill" title="<?php echo esc_attr($r['landing_page']); ?>">
                                <?php echo esc_html(zcs_truncate(zcs_host_only($r['landing_page']) . (parse_url($r['landing_page'], PHP_URL_PATH) ?: ''), 36)); ?>
                            </span>
                        <?php else: ?><span class="zcs-pill-empty">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($rule_label !== ''): ?>
                            <span class="zcs-pill zcs-pill-rule" title="<?php echo esc_attr($rule_label); ?>">
                                <?php echo esc_html(zcs_truncate($rule_label, 24)); ?>
                            </span>
                        <?php else: ?><span class="zcs-pill-empty">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int) $r['chat_count'] > 0): ?>
                            <span class="zcs-badge-chats"><?php echo (int) $r['chat_count']; ?></span>
                        <?php else: ?><span class="zcs-pill-empty">0</span><?php endif; ?>
                    </td>
                </tr>
                <tr class="zcs-detail-row" data-detail-for="<?php echo $sid; ?>" style="display:none;">
                    <td colspan="9" class="zcs-detail-cell">
                        <div class="zcs-detail-loading"><em><?php esc_html_e('Loading…', 'zen-cortext'); ?></em></div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>

    <?php if ($result['pages'] > 1): ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo sprintf(esc_html__('%d items', 'zen-cortext'), (int) $result['total']); ?></span>
                <?php
                echo paginate_links(array(
                    'base'      => add_query_arg('paged', '%#%'),
                    'format'    => '',
                    'current'   => $page,
                    'total'     => $result['pages'],
                    'prev_text' => '‹',
                    'next_text' => '›',
                ));
                ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.zen-cortext-wrap .zcs-stats { margin: 12px 0 18px; }
.zen-cortext-wrap .zcs-stats th { text-align: left; padding: 6px 12px; width: 260px; }
.zen-cortext-wrap .zcs-stats td { padding: 6px 12px; }
.zen-cortext-wrap .zcs-filters { margin: 16px 0; }
.zen-cortext-wrap .zcs-list .zcs-pill {
    display: inline-block; padding: 2px 8px; border-radius: 10px;
    background: #f0f6fc; color: #1d2327; font-size: 11px; font-weight: 600;
    max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    vertical-align: middle;
}
.zen-cortext-wrap .zcs-list .zcs-pill-rule { background: #e7f3d4; color: #3a5c1a; }
.zen-cortext-wrap .zcs-list .zcs-pill-empty { color: #aaa; font-size: 11px; }
.zen-cortext-wrap .zcs-list .zcs-badge-chats {
    display: inline-block; padding: 2px 9px; border-radius: 10px;
    background: #2271b1; color: #fff; font-size: 11px; font-weight: 700;
    min-width: 18px; text-align: center;
}
.zen-cortext-wrap .zcs-list tr.zcs-direct td { color: #555; }
.zen-cortext-wrap .zcs-list tr.zcs-detail-row td { background: #f6f7f7; padding: 14px 18px; }
.zen-cortext-wrap .zcs-list .zcs-row-actions { white-space: nowrap; }
.zen-cortext-wrap .zcs-list .zcs-expand { font-weight: 700; width: 28px; padding: 0; line-height: 24px; }
.zen-cortext-wrap .zcs-list .zcs-expand.is-open { background: #2271b1; color: #fff; border-color: #2271b1; }
.zen-cortext-wrap .zcs-list .zcs-delete {
    font-weight: 700; width: 28px; padding: 0; line-height: 24px;
    color: #b32d2e; margin-left: 4px;
}
.zen-cortext-wrap .zcs-list .zcs-delete:hover { background: #b32d2e; color: #fff; border-color: #b32d2e; }
.zen-cortext-wrap .zcs-list tr.zcs-deleting td { opacity: 0.4; pointer-events: none; }

.zen-cortext-wrap .zcs-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
@media (max-width: 980px) { .zen-cortext-wrap .zcs-detail-grid { grid-template-columns: 1fr; } }
.zen-cortext-wrap .zcs-detail-grid h3 { margin: 0 0 8px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.04em; color: #50575e; }
.zen-cortext-wrap .zcs-detail-grid dl { margin: 0; display: grid; grid-template-columns: 130px 1fr; gap: 4px 12px; font-size: 12px; }
.zen-cortext-wrap .zcs-detail-grid dt { color: #646970; }
.zen-cortext-wrap .zcs-detail-grid dd { margin: 0; word-break: break-all; }
.zen-cortext-wrap .zcs-detail-grid dd .empty { color: #aaa; }

.zen-cortext-wrap .zcs-journey { margin-top: 14px; }
.zen-cortext-wrap .zcs-journey ol { margin: 6px 0 0 18px; font-size: 12px; }
.zen-cortext-wrap .zcs-journey ol li { margin: 2px 0; }
.zen-cortext-wrap .zcs-journey time { color: #646970; margin-right: 6px; }

.zen-cortext-wrap .zcs-chats { margin-top: 14px; }
.zen-cortext-wrap .zcs-chats h3 { margin: 0 0 6px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.04em; color: #50575e; }
.zen-cortext-wrap .zcs-chats table { width: 100%; background: #fff; border: 1px solid #e0e0e0; border-collapse: collapse; }
.zen-cortext-wrap .zcs-chats th, .zen-cortext-wrap .zcs-chats td { padding: 6px 10px; border-bottom: 1px solid #f0f0f1; text-align: left; font-size: 12px; }
.zen-cortext-wrap .zcs-chats tr:last-child td { border-bottom: none; }
.zen-cortext-wrap .zcs-chats .zcs-lead-badge {
    display: inline-block; padding: 2px 8px; border-radius: 10px;
    background: #e7f3d4; color: #3a5c1a; font-size: 11px; font-weight: 600;
}
.zen-cortext-wrap .zcs-chats .zcs-deleted-badge {
    display: inline-block; padding: 2px 8px; border-radius: 10px;
    background: #d63638; color: #fff; font-size: 10px; font-weight: 700;
    letter-spacing: 0.04em; margin-left: 6px;
}
</style>

<script>
(function () {
    var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    var nonce   = <?php echo wp_json_encode(wp_create_nonce('zen_cortext_admin')); ?>;
    var chatBaseUrl = <?php echo wp_json_encode(admin_url('admin.php?page=zen-cortext-chats&action=view&id=')); ?>;

    function escapeHtml(s) {
        var div = document.createElement('div');
        div.textContent = (s === null || s === undefined) ? '' : String(s);
        return div.innerHTML;
    }

    function field(label, value) {
        var v = (value === null || value === undefined || value === '') ? '<span class="empty">—</span>' : escapeHtml(value);
        return '<dt>' + escapeHtml(label) + '</dt><dd>' + v + '</dd>';
    }

    function renderDetail(cell, data) {
        var s = data.session || {};
        var chats = data.chats || [];
        var journey = (s.journey && s.journey.length) ? s.journey : [];

        var html = '<div class="zcs-detail-grid">';
        // Left column — UTM + click IDs
        html += '<div><h3>Attribution</h3><dl>';
        html += field('utm_source',   s.utm_source);
        html += field('utm_medium',   s.utm_medium);
        html += field('utm_campaign', s.utm_campaign);
        html += field('utm_term',     s.utm_term);
        html += field('utm_content',  s.utm_content);
        html += field('gclid',        s.gclid);
        html += field('msclkid',      s.msclkid);
        html += field('fbc',          s.fbc);
        html += field('fbp',          s.fbp);
        html += '</dl></div>';
        // Right column — Visit + identity
        html += '<div><h3>Visit</h3><dl>';
        html += field('First seen',   s.first_seen_at);
        html += field('Last seen',    s.last_seen_at);
        html += field('Referrer',     s.referrer);
        html += field('Landing page', s.landing_page);
        html += field('User agent',   s.user_agent);
        html += field('IP hash',      s.ip_hash);
        html += field('Session uid',  s.session_uid);
        html += field('Rule id',      s.rule_id);
        html += field('Enriched',     parseInt(s.enriched, 10) ? 'yes' : 'no');
        html += '</dl></div>';
        html += '</div>'; // /grid

        if (journey.length) {
            html += '<div class="zcs-journey"><h3>Pageview journey (' + journey.length + ')</h3><ol>';
            journey.forEach(function (pv) {
                html += '<li><time>' + escapeHtml(pv.ts || '') + '</time>' + escapeHtml(pv.url || '') + '</li>';
            });
            html += '</ol></div>';
        }

        html += '<div class="zcs-chats"><h3>Attached chats (' + chats.length + ')</h3>';
        if (!chats.length) {
            html += '<p><em>No chats attached to this session.</em></p>';
        } else {
            html += '<table><thead><tr><th>First message</th><th>Lead</th><th>Msgs</th><th>Updated</th><th></th></tr></thead><tbody>';
            chats.forEach(function (c) {
                var leadCell = c.lead_submitted_at
                    ? '<span class="zcs-lead-badge">★ ' + escapeHtml(c.lead_name || c.lead_email) + '</span>'
                    : '<span class="zcs-pill-empty">—</span>';
                var deletedBadge = c.deleted_at ? '<span class="zcs-deleted-badge">DELETED</span>' : '';
                var firstMsg = c.first_user_msg || '(no message)';
                if (firstMsg.length > 90) firstMsg = firstMsg.slice(0, 90) + '…';
                var viewLink = chatBaseUrl + c.id;
                html += '<tr>'
                     +  '<td><a href="' + escapeHtml(viewLink) + '"><strong>' + escapeHtml(firstMsg) + '</strong></a>' + deletedBadge + '</td>'
                     +  '<td>' + leadCell + '</td>'
                     +  '<td>' + (c.message_count | 0) + '</td>'
                     +  '<td>' + escapeHtml((c.updated_at || '').slice(0, 16)) + '</td>'
                     +  '<td><a href="' + escapeHtml(viewLink) + '" class="button button-small">View</a></td>'
                     +  '</tr>';
            });
            html += '</tbody></table>';
        }
        html += '</div>';

        cell.innerHTML = html;
    }

    document.querySelectorAll('.zcs-list .zcs-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tr = btn.closest('tr');
            var id = tr.dataset.id;
            if (!id) return;
            if (!confirm('Permanently delete this session? Attached chats will remain but will no longer link back to a session.')) return;

            tr.classList.add('zcs-deleting');
            var fd = new FormData();
            fd.append('action', 'zen_cortext_session_delete');
            fd.append('nonce', nonce);
            fd.append('id', id);
            fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (resp && resp.success) {
                        // Remove the row + its detail row.
                        var detail = document.querySelector('.zcs-detail-row[data-detail-for="' + id + '"]');
                        if (detail) detail.remove();
                        tr.remove();
                    } else {
                        tr.classList.remove('zcs-deleting');
                        alert((resp && resp.data && resp.data.message) || 'Delete failed.');
                    }
                })
                .catch(function () {
                    tr.classList.remove('zcs-deleting');
                    alert('Delete failed.');
                });
        });
    });

    document.querySelectorAll('.zcs-list .zcs-expand').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tr = btn.closest('tr');
            var id = tr.dataset.id;
            var detail = document.querySelector('.zcs-detail-row[data-detail-for="' + id + '"]');
            if (!detail) return;

            var isOpen = detail.style.display !== 'none';
            if (isOpen) {
                detail.style.display = 'none';
                btn.textContent = '+';
                btn.classList.remove('is-open');
                return;
            }

            detail.style.display = '';
            btn.textContent = '–';
            btn.classList.add('is-open');

            var cell = detail.querySelector('.zcs-detail-cell');
            // Only fetch once per session per page load. Subsequent expands
            // just re-display the cached DOM.
            if (cell.dataset.loaded === '1') return;

            var fd = new FormData();
            fd.append('action', 'zen_cortext_session_get');
            fd.append('nonce', nonce);
            fd.append('id', id);
            fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (resp && resp.success) {
                        renderDetail(cell, resp.data);
                        cell.dataset.loaded = '1';
                    } else {
                        cell.innerHTML = '<p>' + ((resp && resp.data && resp.data.message) || 'Failed to load session.') + '</p>';
                    }
                })
                .catch(function () {
                    cell.innerHTML = '<p>Failed to load session.</p>';
                });
        });
    });
})();
</script>
