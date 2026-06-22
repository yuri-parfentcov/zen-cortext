/*
 * Zen Cortext admin — User Sessions list (expand/detail + delete).
 * Moved out of an inline <script> block; ajaxUrl, nonce and chatBaseUrl
 * come from the localized zenCortextAdmin object.
 */
(function () {
    var ZC = window.zenCortextAdmin || {};
    var ajaxUrl     = ZC.ajaxUrl;
    var nonce       = ZC.nonce;
    var chatBaseUrl = ZC.chatBaseUrl;

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
