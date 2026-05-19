/* Zen Cortext — API Keys admin page.
   Multi-key bearer-token auth for the external read API (wp-json/zc/v1/*).
   Keys are create-only + revoke-only (immutable model). Raw token is
   shown exactly once in a one-time panel after create. */
(function ($) {
    'use strict';

    $(function () {
        if (!$('#zen-cortext-api-keys-root').length) return;
        init();
    });

    function ajaxUrl() { return (window.zenCortextAdmin || {}).ajaxUrl || ''; }
    function nonce()   { return (window.zenCortextAdmin || {}).nonce   || ''; }
    function scopeCatalog() {
        var c = (window.zenCortextApiKeys || {}).scopes;
        return c && typeof c === 'object' ? c : {};
    }
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function init() {
        var $list   = $('#zwk-list-body');
        var $editor = $('#zwk-editor');
        var $panel  = $('#zwk-token-panel');
        var $status = $('#zwk-status');
        var $tokenStatus = $('#zwk-token-status');

        renderScopeCheckboxes();
        loadList();

        $('#zwk-new').on('click',         function () { openEditor(); });
        $('#zwk-cancel').on('click',      function () { closeEditor(); });
        $('#zwk-create').on('click',      createKey);
        $('#zwk-token-copy').on('click',  copyToken);
        $('#zwk-token-dismiss').on('click', dismissTokenPanel);
        $list.on('click', '.zwk-revoke', revokeKey);

        function renderScopeCheckboxes() {
            var cat = scopeCatalog();
            var $wrap = $('#zwk-scopes');
            $wrap.empty();
            Object.keys(cat).forEach(function (key) {
                var def = cat[key] || {};
                var $row = $(
                    '<label class="zwk-scope-row" style="display:block;margin:6px 0;">' +
                      '<input type="checkbox" class="zwk-scope-cb" value="' + escapeHtml(key) + '" /> ' +
                      '<code>' + escapeHtml(key) + '</code> — ' +
                      '<span>' + escapeHtml(def.label || key) + '</span>' +
                      '<br><span class="description" style="margin-left:24px;">' + escapeHtml(def.description || '') + '</span>' +
                    '</label>'
                );
                $wrap.append($row);
            });
            if (!Object.keys(cat).length) $wrap.html('<em>No scopes available.</em>');
        }

        function loadList() {
            $list.html('<tr><td colspan="8"><em>Loading…</em></td></tr>');
            $.post(ajaxUrl(), { action: 'zen_cortext_api_keys_list', nonce: nonce() })
                .done(function (resp) {
                    if (!resp || !resp.success) {
                        $list.html('<tr><td colspan="8"><em>Load failed.</em></td></tr>');
                        return;
                    }
                    var rows = (resp.data && resp.data.rows) || [];
                    if (!rows.length) {
                        $list.html('<tr><td colspan="8"><em>No keys yet. Click "+ New key" to create one.</em></td></tr>');
                        return;
                    }
                    var html = '';
                    rows.forEach(function (r) {
                        var revoked = !!r.revoked_at;
                        var scopes  = Array.isArray(r.scopes) ? r.scopes : [];
                        var badges  = scopes.map(function (s) {
                            return '<code style="font-size:11px;background:#f0f0f1;padding:1px 5px;border-radius:3px;margin-right:3px;">' + escapeHtml(s) + '</code>';
                        }).join('');
                        var statusBadge = revoked
                            ? '<span style="color:#d63638;">Revoked</span>'
                            : '<span style="color:#00a32a;">Active</span>';
                        html += '<tr style="' + (revoked ? 'opacity:0.55;' : '') + '">' +
                            '<td>' + escapeHtml(r.label || '') + '</td>' +
                            '<td><code style="font-size:11px;">' + escapeHtml(r.key_prefix || '') + '…</code></td>' +
                            '<td>' + (badges || '<em style="color:#999;">none</em>') + '</td>' +
                            '<td>' + r.rate_per_min + '/min · ' + r.rate_per_hour + '/hr</td>' +
                            '<td>' + escapeHtml(r.last_used_at || '—') + '</td>' +
                            '<td>' + statusBadge + '</td>' +
                            '<td>' + escapeHtml(r.created_at || '') + '</td>' +
                            '<td>' + (revoked
                                ? ''
                                : '<button type="button" class="button button-small button-link-delete zwk-revoke" data-id="' + parseInt(r.id, 10) + '">Revoke</button>'
                              ) + '</td>' +
                        '</tr>';
                    });
                    $list.html(html);
                })
                .fail(function () {
                    $list.html('<tr><td colspan="8"><em>Network error.</em></td></tr>');
                });
        }

        function openEditor() {
            $status.text('').removeClass('error success');
            $panel.hide();
            $('#zwk-label').val('');
            $('#zwk-rate-min').val('60');
            $('#zwk-rate-hour').val('3000');
            $('.zwk-scope-cb').prop('checked', false);
            $editor.show();
            $('html, body').animate({ scrollTop: $editor.offset().top - 40 }, 200);
        }

        function closeEditor() {
            $editor.hide();
            $status.text('').removeClass('error success');
        }

        function collectScopes() {
            var out = [];
            $('.zwk-scope-cb:checked').each(function () { out.push($(this).val()); });
            return out;
        }

        function createKey() {
            var label  = $('#zwk-label').val() || '';
            var scopes = collectScopes();
            if (!label.trim())     { $status.text('Label is required.').addClass('error');  return; }
            if (!scopes.length)    { $status.text('Pick at least one scope.').addClass('error'); return; }

            $status.text('Creating…').removeClass('error success');
            $.ajax({
                url: ajaxUrl(),
                method: 'POST',
                // Default jQuery serialization sends scopes[]=… so PHP parses
                // it as an array (the same fix applied to the webhooks page
                // after the earlier traditional-true bug).
                data: {
                    action:         'zen_cortext_api_keys_create',
                    nonce:          nonce(),
                    label:          label,
                    scopes:         scopes,
                    rate_per_min:   parseInt($('#zwk-rate-min').val(),  10) || 60,
                    rate_per_hour:  parseInt($('#zwk-rate-hour').val(), 10) || 3000
                }
            })
            .done(function (resp) {
                if (!resp || !resp.success) {
                    $status.text((resp && resp.data && resp.data.message) || 'Create failed').addClass('error');
                    return;
                }
                $status.text('Created ✓').addClass('success');
                $editor.hide();
                showTokenPanel(resp.data.token || '');
                loadList();
            })
            .fail(function () {
                $status.text('Network error').addClass('error');
            });
        }

        function showTokenPanel(token) {
            $('#zwk-token').val(token);
            $tokenStatus.text('').removeClass('error success');
            $panel.show();
            $('html, body').animate({ scrollTop: $panel.offset().top - 40 }, 200);
        }

        function dismissTokenPanel() {
            // Best-effort sanitization: clear the visible token so it
            // can't be recovered by re-opening dev tools / scrolling back.
            $('#zwk-token').val('');
            $panel.hide();
        }

        function copyToken() {
            var $el = $('#zwk-token');
            $el[0].select();
            $el[0].setSelectionRange(0, 99999);
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            if (ok) {
                $tokenStatus.text('Copied ✓').addClass('success');
            } else {
                // Fallback for clipboards behind permission prompts: keep
                // the field selected so Cmd/Ctrl+C still works manually.
                $tokenStatus.text('Press Cmd/Ctrl+C to copy.').addClass('error');
            }
        }

        function revokeKey(e) {
            var id = parseInt($(e.currentTarget).data('id'), 10) || 0;
            if (!id) return;
            if (!window.confirm('Revoke this key? Active integrations using it will start receiving 401. This cannot be undone.')) return;
            $.post(ajaxUrl(), { action: 'zen_cortext_api_keys_revoke', nonce: nonce(), id: id })
                .done(function () { loadList(); });
        }
    }
})(jQuery);
