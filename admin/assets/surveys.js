/* global jQuery, zenCortextAdmin */
(function ($) {
    'use strict';

    $(function () {
        var $root = $('#zen-cortext-surveys-root');
        if (!$root.length) return;

        var $list   = $('#zsv-list-body');
        var $editor = $('#zsv-editor');
        var $delete = $('#zsv-delete');
        var $status = $('#zsv-save-status');
        var $parseStatus = $('#zsv-parse-status');

        function ajaxUrl() { return zenCortextAdmin.ajaxUrl; }
        function nonce()   { return zenCortextAdmin.nonce; }

        loadList();

        $('#zsv-new').on('click', function () { openEditor(null); });
        $('#zsv-cancel').on('click', function () { closeEditor(); });
        $('#zsv-save').on('click', saveSurvey);
        $('#zsv-delete').on('click', deleteSurvey);

        $list.on('click', '.zsv-edit', function () {
            openEditor(parseInt($(this).data('id'), 10));
        });

        function loadList() {
            $list.html('<tr><td colspan="6"><em>Loading…</em></td></tr>');
            $.post(ajaxUrl(), { action: 'zen_cortext_surveys_list', nonce: nonce() })
                .done(function (resp) {
                    if (!resp || !resp.success) {
                        $list.html('<tr><td colspan="6">Failed to load.</td></tr>');
                        return;
                    }
                    renderList(resp.data.rows || []);
                })
                .fail(function () {
                    $list.html('<tr><td colspan="6">Network error.</td></tr>');
                });
        }

        function renderList(rows) {
            if (!rows.length) {
                $list.html('<tr><td colspan="6"><em>No surveys yet. Click "+ New survey" to add one.</em></td></tr>');
                return;
            }
            var html = '';
            rows.forEach(function (r) {
                html += '<tr>'
                     + '<td><strong>' + escapeHtml(r.label) + '</strong></td>'
                     + '<td>' + escapeHtml(r.description || '—') + '</td>'
                     + '<td>' + (parseInt(r.question_count, 10) || 0) + '</td>'
                     + '<td>' + (parseInt(r.enabled, 10) ? '✓' : '—') + '</td>'
                     + '<td>' + escapeHtml(r.updated_at || '') + '</td>'
                     + '<td><button type="button" class="button button-small zsv-edit" data-id="' + parseInt(r.id, 10) + '">Edit</button></td>'
                     + '</tr>';
            });
            $list.html(html);
        }

        function openEditor(id) {
            $status.text('').removeClass('error success');
            $parseStatus.text('').removeClass('error success');

            if (!id) {
                fillEditor({ id: 0, label: '', description: '', script: '', outcome_instructions: '', enabled: 1 });
                $('#zsv-editor-title').text('New survey');
                $delete.hide();
                $editor.show();
                $('html, body').animate({ scrollTop: $editor.offset().top - 40 }, 200);
                return;
            }

            $.post(ajaxUrl(), { action: 'zen_cortext_surveys_get', nonce: nonce(), id: id })
                .done(function (resp) {
                    if (!resp || !resp.success) {
                        alert((resp && resp.data && resp.data.message) || 'Load failed');
                        return;
                    }
                    fillEditor(resp.data.row);
                    $('#zsv-editor-title').text('Edit survey: ' + (resp.data.row.label || ''));
                    $delete.show();
                    $editor.show();
                    $('html, body').animate({ scrollTop: $editor.offset().top - 40 }, 200);
                });
        }

        function closeEditor() {
            $editor.hide();
            $status.text('').removeClass('error success');
            $parseStatus.text('').removeClass('error success');
        }

        function fillEditor(row) {
            $('#zsv-id').val(row.id || 0);
            $('#zsv-label').val(row.label || '');
            $('#zsv-description').val(row.description || '');
            $('#zsv-script').val(row.script || '');
            $('#zsv-outcome').val(row.outcome_instructions || '');
            $('#zsv-enabled').prop('checked', parseInt(row.enabled, 10) !== 0);

            // Light-touch summary of the parsed script after load.
            if (row.parsed && row.parsed.questions) {
                $parseStatus
                    .removeClass('error')
                    .addClass('success')
                    .text(row.parsed.questions.length + ' questions parsed.');
            } else {
                $parseStatus.text('').removeClass('error success');
            }
        }

        function saveSurvey() {
            $status.text('Saving…').removeClass('error success');
            $parseStatus.text('').removeClass('error success');

            $.post(ajaxUrl(), {
                action:               'zen_cortext_surveys_save',
                nonce:                nonce(),
                id:                   parseInt($('#zsv-id').val(), 10) || 0,
                label:                $('#zsv-label').val() || '',
                description:          $('#zsv-description').val() || '',
                script:               $('#zsv-script').val() || '',
                outcome_instructions: $('#zsv-outcome').val() || '',
                enabled:              $('#zsv-enabled').is(':checked') ? 1 : 0
            })
            .done(function (resp) {
                if (!resp || !resp.success) {
                    var msg = (resp && resp.data && resp.data.message) || 'Save failed';
                    $status.text(msg).addClass('error');
                    $parseStatus.text(msg).addClass('error');
                    return;
                }
                $status.text('Saved ✓').addClass('success');
                if (resp.data && resp.data.row) {
                    if (resp.data.row.id) {
                        $('#zsv-id').val(resp.data.row.id);
                        $delete.show();
                        $('#zsv-editor-title').text('Edit survey: ' + (resp.data.row.label || ''));
                    }
                    if (resp.data.row.parsed && resp.data.row.parsed.questions) {
                        $parseStatus
                            .removeClass('error')
                            .addClass('success')
                            .text(resp.data.row.parsed.questions.length + ' questions parsed.');
                    }
                }
                loadList();
            })
            .fail(function () {
                $status.text('Network error').addClass('error');
            });
        }

        function deleteSurvey() {
            var id = parseInt($('#zsv-id').val(), 10) || 0;
            if (!id) return;
            if (!confirm('Delete this survey? Any attribution rules pointing to it will be detached.')) return;
            $.post(ajaxUrl(), { action: 'zen_cortext_surveys_delete', nonce: nonce(), id: id })
                .done(function (resp) {
                    if (!resp || !resp.success) {
                        alert((resp && resp.data && resp.data.message) || 'Delete failed');
                        return;
                    }
                    closeEditor();
                    loadList();
                });
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }
    });
})(jQuery);
