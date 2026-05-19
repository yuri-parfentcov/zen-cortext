/* Zen Cortext — Webhooks admin page.
   CRUD on outbound webhook endpoints + a "Send test" button.
   Self-guards by root element so it's a no-op on other pages. */
(function ($) {
    'use strict';

    $(function () {
        if (!$('#zen-cortext-webhooks-root').length) return;
        init();
    });

    function ajaxUrl() { return (window.zenCortextAdmin || {}).ajaxUrl || ''; }
    function nonce()   { return (window.zenCortextAdmin || {}).nonce   || ''; }
    function eventCatalog() {
        var c = (window.zenCortextWebhooks || {}).events;
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
    function hostOf(url) {
        try { return new URL(url).host; } catch (e) { return url || ''; }
    }
    function init() {
        var $list   = $('#zwh-list-body');
        var $editor = $('#zwh-editor');
        var $status = $('#zwh-save-status');
        var $delete = $('#zwh-delete');
        var $test   = $('#zwh-test');

        renderEventCheckboxes();
        loadList();

        $('#zwh-new').on('click',    function () { openEditor(null); });
        $('#zwh-cancel').on('click', function () { closeEditor(); });
        $('#zwh-save').on('click',   saveRule);
        $delete.on('click',          deleteRule);
        $test.on('click',            sendTest);

        $list.on('click', '.zwh-edit', function () {
            openEditor(String($(this).data('id') || ''));
        });

        function renderEventCheckboxes() {
            var catalog = eventCatalog();
            var $wrap = $('#zwh-events');
            $wrap.empty();
            Object.keys(catalog).forEach(function (key) {
                var def = catalog[key] || {};
                var $row = $('<label class="zwh-event-row" style="display:block;margin:6px 0;">' +
                    '<input type="checkbox" class="zwh-event-cb" value="' + escapeHtml(key) + '" /> ' +
                    '<code>' + escapeHtml(key) + '</code> — ' +
                    '<span class="zwh-event-label">' + escapeHtml(def.label || key) + '</span>' +
                    '<br><span class="description" style="margin-left:24px;">' + escapeHtml(def.description || '') + '</span>' +
                '</label>');
                $wrap.append($row);
            });
            if (!Object.keys(catalog).length) {
                $wrap.html('<em>No events available.</em>');
            }
        }

        function loadList() {
            $list.html('<tr><td colspan="6"><em>Loading…</em></td></tr>');
            $.post(ajaxUrl(), { action: 'zen_cortext_webhooks_list', nonce: nonce() })
                .done(function (resp) {
                    if (!resp || !resp.success) {
                        $list.html('<tr><td colspan="6"><em>Load failed.</em></td></tr>');
                        return;
                    }
                    var rows = (resp.data && resp.data.rows) || [];
                    if (!rows.length) {
                        $list.html('<tr><td colspan="6"><em>No endpoints configured. Click "+ New endpoint" to add one.</em></td></tr>');
                        return;
                    }
                    var html = '';
                    rows.forEach(function (r) {
                        var url = String(r.url || '');
                        var events = Array.isArray(r.events) ? r.events : [];
                        var badges = events.map(function (e) {
                            return '<code style="font-size:11px;background:#f0f0f1;padding:1px 5px;border-radius:3px;margin-right:3px;">' + escapeHtml(e) + '</code>';
                        }).join('');
                        html += '<tr>' +
                            '<td>' + escapeHtml(r.label || hostOf(url)) + '</td>' +
                            '<td><code style="font-size:11px;">' + escapeHtml(hostOf(url)) + '</code></td>' +
                            '<td>' + (badges || '<em style="color:#999;">none</em>') + '</td>' +
                            '<td>' + (parseInt(r.enabled, 10) ? '✓' : '<em style="color:#999;">off</em>') + '</td>' +
                            '<td>' + escapeHtml(r.updated_at || '') + '</td>' +
                            '<td><button type="button" class="button button-small zwh-edit" data-id="' + escapeHtml(r.id) + '">Edit</button></td>' +
                        '</tr>';
                    });
                    $list.html(html);
                })
                .fail(function () {
                    $list.html('<tr><td colspan="6"><em>Network error.</em></td></tr>');
                });
        }

        function fillEditor(row) {
            $('#zwh-id').val(row.id || '');
            $('#zwh-label').val(row.label || '');
            $('#zwh-url').val(row.url || '');
            $('#zwh-enabled').prop('checked', parseInt(row.enabled, 10) !== 0);
            var subscribed = Array.isArray(row.events) ? row.events : [];
            $('.zwh-event-cb').each(function () {
                $(this).prop('checked', subscribed.indexOf($(this).val()) !== -1);
            });
            // Show "Send test" only on existing endpoints — it operates on
            // the stored row (id), not a live form state. The admin must
            // save edits first; that's the same convention as Delete.
            if (row.id) { $test.show(); $delete.show(); }
            else        { $test.hide(); $delete.hide(); }
        }

        function openEditor(id) {
            $status.text('').removeClass('error success');
            if (!id) {
                fillEditor({ id: '', label: '', url: '', events: [], enabled: 1 });
                $('#zwh-editor-title').text('New endpoint');
                $editor.show();
                $('html, body').animate({ scrollTop: $editor.offset().top - 40 }, 200);
                return;
            }
            // For existing rows we already have the data in the list response,
            // but the simpler path is to fetch all rows again and pluck the
            // one we want — keeps the editor source-of-truth aligned with
            // whatever's currently rendered in the list.
            $.post(ajaxUrl(), { action: 'zen_cortext_webhooks_list', nonce: nonce() })
                .done(function (resp) {
                    var rows = (resp && resp.success && resp.data && resp.data.rows) || [];
                    var row = rows.filter(function (r) { return String(r.id) === String(id); })[0];
                    if (!row) { alert('Row not found.'); return; }
                    fillEditor(row);
                    $('#zwh-editor-title').text('Edit endpoint: ' + (row.label || hostOf(row.url)));
                    $editor.show();
                    $('html, body').animate({ scrollTop: $editor.offset().top - 40 }, 200);
                });
        }

        function closeEditor() {
            $editor.hide();
            $status.text('').removeClass('error success');
        }

        function collectEvents() {
            var out = [];
            $('.zwh-event-cb:checked').each(function () { out.push($(this).val()); });
            return out;
        }

        function saveRule() {
            $status.text('Saving…').removeClass('error success');
            // jQuery default serialization: events as `events[]=a&events[]=b`
            // so PHP parses it as a real array on the server. `traditional:true`
            // would send `events=a&events=b` and PHP keeps only the last value
            // (silent data loss — every save would drop all but one event).
            $.ajax({
                url: ajaxUrl(),
                method: 'POST',
                data: {
                    action:  'zen_cortext_webhooks_save',
                    nonce:   nonce(),
                    id:      $('#zwh-id').val() || '',
                    label:   $('#zwh-label').val() || '',
                    url:     $('#zwh-url').val() || '',
                    events:  collectEvents(),
                    enabled: $('#zwh-enabled').is(':checked') ? 1 : 0
                }
            })
            .done(function (resp) {
                if (!resp || !resp.success) {
                    $status.text((resp && resp.data && resp.data.message) || 'Save failed').addClass('error');
                    return;
                }
                $status.text('Saved ✓').addClass('success');
                if (resp.data && resp.data.row) {
                    fillEditor(resp.data.row);
                }
                loadList();
            })
            .fail(function () {
                $status.text('Network error').addClass('error');
            });
        }

        function deleteRule() {
            var id = $('#zwh-id').val() || '';
            if (!id) return;
            if (!window.confirm('Delete this endpoint? This cannot be undone.')) return;
            $.post(ajaxUrl(), { action: 'zen_cortext_webhooks_delete', nonce: nonce(), id: id })
                .done(function (resp) {
                    if (!resp || !resp.success) {
                        $status.text((resp && resp.data && resp.data.message) || 'Delete failed').addClass('error');
                        return;
                    }
                    closeEditor();
                    loadList();
                });
        }

        function sendTest() {
            var id = $('#zwh-id').val() || '';
            if (!id) return;
            $status.text('Sending test…').removeClass('error success');
            $.post(ajaxUrl(), { action: 'zen_cortext_webhooks_test', nonce: nonce(), id: id })
                .done(function (resp) {
                    if (resp && resp.success) {
                        $status.text('Test sent ✓ (HTTP ' + (resp.data && resp.data.status) + ')').addClass('success');
                    } else {
                        var msg = (resp && resp.data && (resp.data.error || resp.data.message)) || 'Test failed';
                        var status = resp && resp.data && resp.data.status;
                        $status.text('Test failed' + (status ? ' (HTTP ' + status + ')' : '') + ': ' + msg).addClass('error');
                    }
                })
                .fail(function () {
                    $status.text('Network error').addClass('error');
                });
        }
    }
})(jQuery);
