<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Zen Cortext — Saved Chats list page.
 * Available: $result (rows, total, pages), $stats, $page, $search, $has_utm
 */
if (!defined('ABSPATH')) exit;

function zcc_first_user_msg($messages_json) {
    $msgs = json_decode($messages_json, true);
    if (!is_array($msgs)) return '';
    foreach ($msgs as $m) {
        if (isset($m['role'], $m['content']) && $m['role'] === 'user') {
            return (string) $m['content'];
        }
    }
    return '';
}

function zcc_truncate($s, $n = 100) {
    $s = trim((string) $s);
    if (function_exists('mb_substr')) {
        return mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s;
    }
    return strlen($s) > $n ? substr($s, 0, $n) . '…' : $s;
}

function zcc_pill($value, $label = '') {
    $value = trim((string) $value);
    if ($value === '') return '<span class="zcc-pill-empty">—</span>';
    if ($label !== '') {
        return '<span class="zcc-pill" title="' . esc_attr($label . ': ' . $value) . '">' . esc_html(zcc_truncate($value, 28)) . '</span>';
    }
    return '<span class="zcc-pill">' . esc_html(zcc_truncate($value, 28)) . '</span>';
}

$base_url = admin_url('admin.php?page=zen-cortext-chats');
?>
<div class="wrap zen-cortext-wrap">
    <h1><?php esc_html_e('Zen Cortext — Saved Chats', 'zen-cortext'); ?></h1>
    <p class="description">
        <?php esc_html_e('Every public chat session is automatically saved with full conversation transcript and marketing attribution (UTM, gclid, msclkid, fbc/fbp, referrer).', 'zen-cortext'); ?>
    </p>

    <div class="zcc-stats" id="zen-cortext-chats-root">
        <table class="widefat striped" style="max-width:680px;">
            <tbody>
                <tr><th><?php esc_html_e('Total saved chats', 'zen-cortext'); ?></th><td><strong><?php echo (int) $stats['total']; ?></strong> <span class="description">(<?php echo (int) $stats['active']; ?> active, <?php echo (int) $stats['deleted']; ?> deleted)</span></td></tr>
                <tr><th><?php esc_html_e('Multi-message conversations', 'zen-cortext'); ?></th><td><?php echo (int) $stats['multi_message']; ?></td></tr>
                <tr><th><?php esc_html_e('With UTM', 'zen-cortext'); ?></th><td><?php echo (int) $stats['with_utm']; ?></td></tr>
                <tr><th><?php esc_html_e('With gclid', 'zen-cortext'); ?></th><td><?php echo (int) $stats['with_gclid']; ?></td></tr>
                <tr><th><?php esc_html_e('With msclkid', 'zen-cortext'); ?></th><td><?php echo (int) $stats['with_msclkid']; ?></td></tr>
            </tbody>
        </table>
    </div>

    <form method="get" class="zcc-filters">
        <input type="hidden" name="page" value="zen-cortext-chats" />
        <p class="search-box" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search messages, UTM, gclid…', 'zen-cortext'); ?>" class="regular-text" />
            <label style="display:inline-flex; align-items:center; gap:6px;">
                <input type="checkbox" name="has_utm" value="1" <?php checked($has_utm); ?> />
                <?php esc_html_e('Only with UTM / gclid / msclkid', 'zen-cortext'); ?>
            </label>
            <label style="display:inline-flex; align-items:center; gap:6px;">
                <input type="checkbox" name="hide_deleted" value="1" <?php checked($hide_deleted); ?> />
                <?php esc_html_e('Hide deleted', 'zen-cortext'); ?>
            </label>
            <button type="submit" class="button"><?php esc_html_e('Filter', 'zen-cortext'); ?></button>
            <?php if ($search !== '' || $has_utm || $hide_deleted): ?>
                <a class="button-link" href="<?php echo esc_url($base_url); ?>"><?php esc_html_e('Clear', 'zen-cortext'); ?></a>
            <?php endif; ?>
        </p>
    </form>

    <table class="widefat striped zcc-list">
        <thead>
            <tr>
                <th><?php esc_html_e('First message', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Lead', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Msgs', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Source', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Medium', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Campaign', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Click ID', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Updated', 'zen-cortext'); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($result['rows'])): ?>
                <tr><td colspan="9"><em><?php esc_html_e('No saved chats yet.', 'zen-cortext'); ?></em></td></tr>
            <?php else: foreach ($result['rows'] as $r):
                $view_url = add_query_arg(array('action' => 'view', 'id' => (int) $r['id']), $base_url);
                $click_id = $r['gclid'] !== '' ? ('gclid: ' . $r['gclid']) : ($r['msclkid'] !== '' ? ('msclkid: ' . $r['msclkid']) : '');
                $first = zcc_first_user_msg($r['messages']);
                $is_deleted = !empty($r['deleted_at']);
                $row_class = $is_deleted ? 'zcc-deleted' : '';
            ?>
                <tr data-id="<?php echo (int) $r['id']; ?>" class="<?php echo esc_attr($row_class); ?>">
                    <td>
                        <a href="<?php echo esc_url($view_url); ?>"><strong><?php echo esc_html(zcc_truncate($first, 90)); ?></strong></a>
                        <?php if ($is_deleted): ?>
                            <span class="zcc-badge-deleted" title="<?php echo esc_attr('Deleted by visitor on ' . $r['deleted_at']); ?>"><?php esc_html_e('DELETED', 'zen-cortext'); ?></span>
                        <?php endif; ?>
                        <div class="row-actions">
                            <span class="view"><a href="<?php echo esc_url($view_url); ?>"><?php esc_html_e('View', 'zen-cortext'); ?></a> · </span>
                            <?php if ($is_deleted): ?>
                                <span class="restore"><a href="#" class="zcc-restore"><?php esc_html_e('Restore', 'zen-cortext'); ?></a> · </span>
                            <?php endif; ?>
                            <span class="delete"><a href="#" class="zcc-delete" style="color:#b32d2e;"><?php $is_deleted ? esc_html_e('Delete forever', 'zen-cortext') : esc_html_e('Delete', 'zen-cortext'); ?></a></span>
                        </div>
                    </td>
                    <td>
                        <?php if (!empty($r['lead_submitted_at'])): ?>
                            <span class="zcc-lead-badge" title="<?php echo esc_attr('Submitted ' . $r['lead_submitted_at']); ?>">★ <?php echo esc_html($r['lead_name'] ?: $r['lead_email']); ?></span>
                            <?php if (!empty($r['lead_email'])): ?>
                                <div class="zcc-lead-sub"><?php echo esc_html($r['lead_email']); ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="zcc-pill-empty">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo (int) $r['message_count']; ?></td>
                    <td><?php echo wp_kses_post(zcc_pill($r['utm_source'])); ?></td>
                    <td><?php echo wp_kses_post(zcc_pill($r['utm_medium'])); ?></td>
                    <td><?php echo wp_kses_post(zcc_pill($r['utm_campaign'])); ?></td>
                    <td><?php echo wp_kses_post($click_id !== '' ? zcc_pill($click_id, 'Click ID') : '<span class="zcc-pill-empty">—</span>'); ?></td>
                    <td><?php echo esc_html(substr($r['updated_at'], 0, 16)); ?></td>
                    <td class="zcc-row-actions">
                        <a href="<?php echo esc_url($view_url); ?>" class="button button-small"><?php esc_html_e('View', 'zen-cortext'); ?></a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>

    <?php if ($result['pages'] > 1): ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php
                    /* translators: %d is the total number of saved chats matching the current filters. */
                    echo sprintf(esc_html__('%d items', 'zen-cortext'), (int) $result['total']); ?></span>
                <?php
                echo wp_kses_post( paginate_links(array(
                    'base'      => add_query_arg('paged', '%#%'),
                    'format'    => '',
                    'current'   => $page,
                    'total'     => $result['pages'],
                    'prev_text' => '‹',
                    'next_text' => '›',
                )) );
                ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.zen-cortext-wrap .zcc-stats { margin: 12px 0 18px; }
.zen-cortext-wrap .zcc-stats th { text-align: left; padding: 6px 12px; width: 260px; }
.zen-cortext-wrap .zcc-stats td { padding: 6px 12px; }
.zen-cortext-wrap .zcc-filters { margin: 16px 0; }
.zen-cortext-wrap .zcc-list .zcc-pill {
    display: inline-block; padding: 2px 8px; border-radius: 10px;
    background: #f0f6fc; color: #1d2327; font-size: 11px; font-weight: 600;
    max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    vertical-align: middle;
}
.zen-cortext-wrap .zcc-list .zcc-pill-empty { color: #aaa; font-size: 11px; }
.zen-cortext-wrap .zcc-list .zcc-row-actions { text-align: right; white-space: nowrap; }

.zen-cortext-wrap .zcc-list tr.zcc-deleted td { background: #fafafa; color: #888; }
.zen-cortext-wrap .zcc-list .zcc-lead-badge {
    display: inline-block;
    padding: 3px 9px;
    border-radius: 10px;
    background: #e7f3d4;
    color: #3a5c1a;
    font-size: 11px;
    font-weight: 600;
    max-width: 180px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: middle;
}
.zen-cortext-wrap .zcc-list .zcc-lead-sub {
    font-size: 11px;
    color: #666;
    margin-top: 2px;
    max-width: 180px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.zen-cortext-wrap .zcc-list tr.zcc-deleted td a { color: #888; }
.zen-cortext-wrap .zcc-list tr.zcc-deleted td > a strong { text-decoration: line-through; }
.zen-cortext-wrap .zcc-list .zcc-badge-deleted {
    display: inline-block;
    margin-left: 8px;
    padding: 2px 8px;
    border-radius: 10px;
    background: #d63638;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.04em;
    vertical-align: middle;
}
</style>

<script>
(function () {
    var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    var nonce   = <?php echo wp_json_encode(wp_create_nonce('zen_cortext_admin')); ?>;

    function postAction(action, id, confirmMsg) {
        if (confirmMsg && !confirm(confirmMsg)) return Promise.resolve(false);
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', nonce);
        fd.append('id', id);
        return fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp && resp.success) return true;
                alert((resp && resp.data && resp.data.message) || 'Request failed');
                return false;
            })
            .catch(function () { alert('Request failed'); return false; });
    }

    document.querySelectorAll('.zcc-delete').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var tr = a.closest('tr');
            postAction('zen_cortext_chat_delete', tr.dataset.id,
                'Permanently delete this saved chat? This cannot be undone.')
                .then(function (ok) { if (ok) tr.remove(); });
        });
    });

    document.querySelectorAll('.zcc-restore').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var tr = a.closest('tr');
            postAction('zen_cortext_chat_restore', tr.dataset.id, null)
                .then(function (ok) { if (ok) window.location.reload(); });
        });
    });
})();
</script>
