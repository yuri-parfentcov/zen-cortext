<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Zen Cortext — User Sessions list page.
 * Available: $result (rows, total, pages), $stats, $rules,
 *            $page, $search, $enriched_only, $has_chats, $rule_id
 */
if (!defined('ABSPATH')) exit;

function zen_cortext_sessions_truncate($s, $n = 60) {
    $s = trim((string) $s);
    if (function_exists('mb_substr')) {
        return mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s;
    }
    return strlen($s) > $n ? substr($s, 0, $n) . '…' : $s;
}

function zen_cortext_sessions_pill($value) {
    $value = trim((string) $value);
    if ($value === '') return '<span class="zcs-pill-empty">—</span>';
    return '<span class="zcs-pill" title="' . esc_attr($value) . '">' . esc_html(zen_cortext_sessions_truncate($value, 28)) . '</span>';
}

function zen_cortext_sessions_host_only($url) {
    $url = trim((string) $url);
    if ($url === '') return '';
    $host = wp_parse_url($url, PHP_URL_HOST);
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
                        <option value="<?php echo (int) $rid; ?>" <?php selected($rule_id, $rid); ?>>
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
                <tr data-id="<?php echo (int) $sid; ?>" class="<?php echo esc_attr($row_class); ?>">
                    <td class="zcs-row-actions">
                        <button type="button" class="button button-small zcs-expand" aria-label="<?php esc_attr_e('Expand', 'zen-cortext'); ?>">+</button>
                        <button type="button" class="button button-small zcs-delete" aria-label="<?php esc_attr_e('Delete', 'zen-cortext'); ?>" title="<?php esc_attr_e('Delete session', 'zen-cortext'); ?>">×</button>
                    </td>
                    <td><?php echo esc_html(substr($r['last_seen_at'], 0, 16)); ?></td>
                    <td><?php echo esc_html(substr($r['first_seen_at'], 0, 16)); ?></td>
                    <td><?php echo wp_kses_post(zen_cortext_sessions_pill($src_med)); ?></td>
                    <td><?php echo wp_kses_post(zen_cortext_sessions_pill($r['utm_campaign'])); ?></td>
                    <td><?php echo wp_kses_post($click_id !== '' ? zen_cortext_sessions_pill($click_id) : '<span class="zcs-pill-empty">—</span>'); ?></td>
                    <td>
                        <?php if ($r['landing_page'] !== ''): ?>
                            <span class="zcs-pill" title="<?php echo esc_attr($r['landing_page']); ?>">
                                <?php echo esc_html(zen_cortext_sessions_truncate(zen_cortext_sessions_host_only($r['landing_page']) . (wp_parse_url($r['landing_page'], PHP_URL_PATH) ?: ''), 36)); ?>
                            </span>
                        <?php else: ?><span class="zcs-pill-empty">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($rule_label !== ''): ?>
                            <span class="zcs-pill zcs-pill-rule" title="<?php echo esc_attr($rule_label); ?>">
                                <?php echo esc_html(zen_cortext_sessions_truncate($rule_label, 24)); ?>
                            </span>
                        <?php else: ?><span class="zcs-pill-empty">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int) $r['chat_count'] > 0): ?>
                            <span class="zcs-badge-chats"><?php echo (int) $r['chat_count']; ?></span>
                        <?php else: ?><span class="zcs-pill-empty">0</span><?php endif; ?>
                    </td>
                </tr>
                <tr class="zcs-detail-row" data-detail-for="<?php echo (int) $sid; ?>" style="display:none;">
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
                <span class="displaying-num"><?php
                    /* translators: %d is the total number of sessions matching the current filters. */
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


