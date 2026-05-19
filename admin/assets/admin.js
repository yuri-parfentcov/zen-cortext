/* global jQuery, zenCortextAdmin */
(function ($) {
    'use strict';

    const cfg = window.zenCortextAdmin || {};
    const $log = $('#zen-cortext-log');
    const $progress = $('#zen-cortext-progress');
    const $stats = $('#zen-cortext-stats');

    function logLine(text, cls) {
        if (!$log.length) return;
        $log.addClass('active');
        const $line = $('<div/>').addClass('log-line').text(text);
        if (cls) $line.addClass(cls);
        $log.append($line);
        $log[0].scrollTop = $log[0].scrollHeight;
    }

    function setProgress(text) {
        $progress.text(text || '');
    }

    function ajax(action, extra) {
        return $.ajax({
            url: cfg.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: $.extend({ action: action, nonce: cfg.nonce }, extra || {})
        });
    }

    function renderStatsTable(stats) {
        let html = '<table class="widefat striped" style="max-width:560px;">';
        html += '<tbody>';
        html += '<tr><th>Total rows</th><td><strong>' + (stats.total || 0) + '</strong></td></tr>';
        html += '<tr><th>Needs classify</th><td>' + (stats.needs_classify || 0) + '</td></tr>';
        html += '<tr><th>Needs restructure</th><td>' + (stats.needs_structure || 0) + '</td></tr>';
        Object.keys(stats.by_class || {}).forEach(function (k) {
            html += '<tr><th>' + k + '</th><td>' + stats.by_class[k] + '</td></tr>';
        });
        html += '</tbody></table>';
        $stats.html(html);
    }

    /* ---- Test connection ---- */
    $('#zen-cortext-test-connection').on('click', function () {
        const $btn = $(this);
        const $result = $('#zen-cortext-test-result').removeClass('ok err').text('Testing…');
        $btn.prop('disabled', true);

        // Test the values currently TYPED in the form, not the values
        // already saved in the DB. Otherwise users have to "Save → Test →
        // realise it was wrong → Save again", which is awful UX.
        const $processor = $('input[name="zen_cortext_processor"]:checked');
        $.ajax({
            url: cfg.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action:    'zen_cortext_test_connection',
                nonce:     cfg.nonce,
                processor: $processor.length ? $processor.val() : '',
                api_key:   $('#zen_cortext_api_key').val() || '',
                cli_path:  $('#zen_cortext_cli_path').val() || '',
                cli_model: $('#zen_cortext_cli_model').val() || ''
            }
        }).done(function (resp) {
            if (resp.success) {
                $result.addClass('ok').text('✓ ' + resp.message);
            } else {
                $result.addClass('err').text('✗ ' + resp.message);
            }
        }).fail(function () {
            $result.addClass('err').text('Request failed');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    /* =====================================================================
       Rebuild KB — chained sync → classify-loop → restructure-loop.
       Replaces the three numbered buttons. Resumable: the per-step server
       endpoints are queue-based (next_unclassified / next_unstructured),
       so a click after a partial failure picks up where the previous run
       stopped without re-doing work.
       ===================================================================== */
    let rebuildAbort = false;

    // Bounded exponential backoff for transient errors. The AJAX endpoints
    // return JSON wrapping wp_send_json_error on AI rate-limits / 5xx;
    // jqXHR.fail fires only on network/HTTP failure. We retry both.
    function ajaxWithRetry(action, extra, maxRetries) {
        maxRetries = maxRetries == null ? 3 : maxRetries;
        let attempt = 0;
        return new Promise(function (resolve, reject) {
            function tryOnce() {
                attempt++;
                ajax(action, extra)
                    .done(function (resp) {
                        if (resp && resp.success) return resolve(resp);
                        const msg = (resp && resp.data && resp.data.message) || 'unknown';
                        // Don't retry hard validation errors — only transient ones.
                        const transient = /rate.?limit|timeout|503|502|504|network|temporar/i.test(msg);
                        if (attempt > maxRetries || !transient) return reject(new Error(msg));
                        const wait = Math.pow(2, attempt - 1) * 1000;
                        logLine('Retrying after ' + (wait / 1000) + 's (' + msg + ')…');
                        setTimeout(tryOnce, wait);
                    })
                    .fail(function (xhr, st, err) {
                        if (attempt > maxRetries) return reject(new Error('Network: ' + (err || st || 'request failed')));
                        const wait = Math.pow(2, attempt - 1) * 1000;
                        logLine('Network retry after ' + (wait / 1000) + 's…');
                        setTimeout(tryOnce, wait);
                    });
            }
            tryOnce();
        });
    }

    function syncStep() {
        logLine('1/3 Syncing posts from WordPress…');
        setProgress('Syncing…');
        return ajaxWithRetry('zen_cortext_sync').then(function (resp) {
            const c = resp.data.counts || {};
            let msg = 'Synced: total=' + c.total + ' inserted=' + c.inserted + ' updated=' + c.updated + ' reset=' + c.reset;
            if (c.orphans_removed > 0) {
                msg += ' orphans_removed=' + c.orphans_removed;
            }
            logLine(msg, 'ok');
            renderStatsTable(resp.data.stats);
        });
    }

    function classifyLoop() {
        logLine('2/3 Classifying…');
        function step() {
            if (rebuildAbort) return Promise.resolve();
            return ajaxWithRetry('zen_cortext_classify_next').then(function (resp) {
                const d = resp.data;
                renderStatsTable(d.stats);
                if (d.done) {
                    logLine('Classification complete.', 'ok');
                    return;
                }
                if (d.stale_skipped) {
                    logLine('#' + d.last_id + ' skipped (row mutated by hook mid-call) — will retry');
                } else {
                    logLine('#' + d.last_id + ' → ' + d.last_category + '  ' + (d.last_title || '').slice(0, 60));
                }
                setProgress('Classify remaining: ' + d.stats.needs_classify);
                return step();
            });
        }
        return step();
    }

    function restructureLoop() {
        logLine('3/3 Restructuring…');
        function step() {
            if (rebuildAbort) return Promise.resolve();
            return ajaxWithRetry('zen_cortext_restructure_next').then(function (resp) {
                const d = resp.data;
                renderStatsTable(d.stats);
                if (d.done) {
                    logLine('Restructure complete.', 'ok');
                    return;
                }
                if (d.stale_skipped) {
                    logLine('#' + d.last_id + ' skipped (row mutated by hook mid-call) — will retry');
                } else {
                    logLine('#' + d.last_id + ' → ' + d.chars + ' chars  ' + (d.last_title || '').slice(0, 60));
                }
                setProgress('Restructure remaining: ' + d.stats.needs_structure);
                return step();
            });
        }
        return step();
    }

    $('#zen-cortext-rebuild').on('click', function () {
        const $btn = $(this);
        if ($btn.data('running')) {
            rebuildAbort = true;
            $btn.text($btn.data('original') || 'Rebuild KB').prop('disabled', false).data('running', false);
            logLine('Aborted by user.', 'err');
            setProgress('');
            return;
        }
        rebuildAbort = false;
        const original = $btn.text();
        $btn.data('original', original).data('running', true).text('Stop rebuild');
        $log.removeClass('active');
        $log.empty();

        syncStep()
            .then(classifyLoop)
            .then(restructureLoop)
            .then(function () {
                logLine('Rebuild complete.', 'ok');
                setProgress('');
            })
            .catch(function (err) {
                logLine('Rebuild stopped: ' + (err && err.message ? err.message : err), 'err');
                setProgress('');
            })
            .then(function () {
                $btn.text(original).prop('disabled', false).data('running', false);
            });
    });

    /* =====================================================================
       Content types editor — add / remove / save.
       Render is server-side; this just persists changes via AJAX.
       Slug for existing types is read-only; new rows can pick a slug.
       ===================================================================== */
    const $typesEditor = $('#zen-cortext-types-editor');
    if ($typesEditor.length) {
        const $list = $typesEditor.find('.zen-cortext-types-list');
        const $status = $('#zen-cortext-types-status');

        function flashStatus(msg, cls) {
            $status.text(msg);
            $status.css('color', cls === 'err' ? '#b32d2e' : (cls === 'ok' ? '#157a3e' : ''));
            if (cls === 'ok') setTimeout(function () { $status.text(''); }, 3500);
        }

        $('#zen-cortext-types-add').on('click', function () {
            const tpl = document.getElementById('zen-cortext-type-row-template');
            if (!tpl) return;
            const html = tpl.innerHTML.replace(/__INDEX__/g, 'new-' + Date.now());
            const $row = $(html);
            $list.append($row);
            $row[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            $row.find('.zct-slug').focus();
        });

        $list.on('click', '.zct-remove', function () {
            const $row = $(this).closest('.zen-cortext-type-row');
            const slug = ($row.data('slug') || '').toString().trim();
            // New rows (no slug yet) can be removed locally with no AJAX.
            if (!slug) { $row.remove(); return; }
            if (!window.confirm('Remove content type "' + slug + '"? KB rows currently classified as this type will need to be reclassified.')) return;

            ajax('zen_cortext_types_delete', { slug: slug, force: '0' }).done(function (resp) {
                if (resp.success) {
                    $row.remove();
                    flashStatus('Removed "' + slug + '".', 'ok');
                    return;
                }
                const d = resp.data || {};

                // Hard block: artifacts are hand-curated and can't be auto-reset.
                if (d.code === 'zen_cortext_types_artifacts_in_use') {
                    window.alert(d.message || (d.artifact_rows_affected + ' artifacts use "' + slug + '". Re-type them on the Knowledge Artifacts tab first.'));
                    flashStatus('Cannot delete — artifacts in use.', 'err');
                    return;
                }

                // Soft block: KB rows can be reset on confirm.
                if (d.code === 'zen_cortext_types_in_use' && typeof d.kb_rows_affected !== 'undefined') {
                    if (!window.confirm(d.kb_rows_affected + ' KB rows are classified as "' + slug + '". Reset them to unclassified and delete this type? They\'ll be reclassified on the next Rebuild.')) return;
                    ajax('zen_cortext_types_delete', { slug: slug, force: '1' }).done(function (resp2) {
                        if (resp2.success) {
                            $row.remove();
                            flashStatus('Removed "' + slug + '" (' + (resp2.data.kb_rows_affected || 0) + ' rows reset).', 'ok');
                        } else {
                            flashStatus('Delete failed: ' + (resp2.data && resp2.data.message ? resp2.data.message : 'unknown'), 'err');
                        }
                    }).fail(function () { flashStatus('Delete request failed.', 'err'); });
                    return;
                }
                flashStatus('Delete failed: ' + (d.message || 'unknown'), 'err');
            }).fail(function () { flashStatus('Delete request failed.', 'err'); });
        });

        $('#zen-cortext-types-save').on('click', function () {
            const $btn = $(this).prop('disabled', true);
            flashStatus('Saving…');

            const types = [];
            $list.find('.zen-cortext-type-row').each(function () {
                const $r = $(this);
                types.push({
                    slug:               ($r.find('.zct-slug').val() || '').toString().trim().toLowerCase(),
                    label:              ($r.find('.zct-label').val() || '').toString(),
                    description:        ($r.find('.zct-description').val() || '').toString(),
                    restructure_prompt: ($r.find('.zct-prompt').val() || '').toString()
                });
            });

            ajax('zen_cortext_types_save', { types: JSON.stringify(types) })
                .done(function (resp) {
                    if (resp.success) {
                        flashStatus('Saved ' + (resp.data.types || []).length + ' types.', 'ok');
                        // After successful save, slug fields become read-only —
                        // but the simplest UX is to reload the page so the server
                        // can re-render with proper read-only state + fresh row IDs.
                        setTimeout(function () { location.reload(); }, 700);
                    } else {
                        flashStatus(resp.data && resp.data.message ? resp.data.message : 'Save failed.', 'err');
                    }
                })
                .fail(function () { flashStatus('Save request failed.', 'err'); })
                .always(function () { $btn.prop('disabled', false); });
        });
    }

    /* ---- Clear ---- */
    $('#zen-cortext-clear').on('click', function () {
        if (!window.confirm('Truncate the entire knowledge base table? This cannot be undone.')) return;
        const $btn = $(this).prop('disabled', true);
        ajax('zen_cortext_clear').done(function (resp) {
            if (resp.success) {
                logLine('KB cleared.', 'ok');
                renderStatsTable(resp.data.stats);
            } else {
                logLine('Clear failed', 'err');
            }
        }).always(function () { $btn.prop('disabled', false); });
    });

    /* =====================================================================
       Knowledge Artifacts — list / editor / chat builder
       Only initializes when the artifacts tab is rendered.
       ===================================================================== */
    if ($('#zen-cortext-artifacts-root').length) {
        const types = cfg.artifactTypes || {};
        const $listBody = $('#zca-list-body');
        const $stats    = $('#zca-stats');
        const $editor   = $('#zca-editor');
        const $editorTitle = $('#zca-editor-title');
        const $idField  = $('#zca-id');
        const $title    = $('#zca-title');
        const $type     = $('#zca-type');
        const $raw      = $('#zca-raw');
        const $source   = $('#zca-source');
        const $saveStatus = $('#zca-save-status');
        const $preview  = $('#zca-preview');
        const $previewBody = $('#zca-preview-body');
        const $author   = $('#zca-author');

        // Populate author dropdown from invitable users.
        (cfg.invitableUsers || []).forEach(function (u) {
            $author.append('<option value="' + u.id + '">' + $('<span>').text(u.display_name).html() + '</option>');
        });

        const $builderToggle = $('#zca-builder-toggle');
        const $builderPanel  = $('#zca-builder-panel');
        const $chat          = $('#zca-chat');
        const $chatInput     = $('#zca-chat-input');
        const $chatSend      = $('#zca-chat-send');
        const $formArtifact  = $('#zca-form-artifact');
        const $chatReset     = $('#zca-chat-reset');

        const $refsPills    = $('#zca-refs-pills');
        const $refsInput    = $('#zca-refs-input');
        const $refsDropdown = $('#zca-refs-dropdown');

        let chatMessages = []; // {role, content}
        let chatStreaming = false;
        let selectedRefs = [];   // [{id, title, type}]
        let refsSearchTimer = null;

        function fmtType(t) { return types[t] || t; }
        function escapeHtml(s) {
            const d = document.createElement('div');
            d.textContent = s == null ? '' : String(s);
            return d.innerHTML;
        }
        function fmtDate(s) {
            if (!s) return '';
            return s.replace(' ', ' ').slice(0, 16);
        }

        function loadList() {
            const filter = $('#zca-filter-type').val() || '';
            ajax('zen_cortext_artifact_list', { type: filter }).done(function (resp) {
                if (!resp.success) {
                    $listBody.html('<tr><td colspan="7" class="zca-err">Load failed.</td></tr>');
                    return;
                }
                renderList(resp.data.rows || []);
                renderArtifactStats(resp.data.stats || {});
            });
        }

        // Author lookup map from invitable users.
        const authorMap = {};
        (cfg.invitableUsers || []).forEach(function (u) { authorMap[u.id] = u.display_name; });

        function renderList(rows) {
            if (!rows.length) {
                $listBody.html('<tr><td colspan="7"><em>No artifacts yet. Click "+ New artifact" to create one.</em></td></tr>');
                return;
            }
            let html = '';
            rows.forEach(function (r) {
                const hasStructured = parseInt(r.structured_len, 10) > 0;
                const authorName = r.author_id ? (authorMap[r.author_id] || '—') : '—';
                html += '<tr data-id="' + r.id + '">'
                    + '<td><strong>' + escapeHtml(r.title) + '</strong></td>'
                    + '<td>' + escapeHtml(fmtType(r.type)) + '</td>'
                    + '<td>' + escapeHtml(authorName) + '</td>'
                    + '<td>' + escapeHtml(r.source) + '</td>'
                    + '<td>' + escapeHtml(fmtDate(r.updated_at)) + '</td>'
                    + '<td>' + (hasStructured ? '<span class="zca-pill ok">restructured</span>' : '<span class="zca-pill">draft</span>') + '</td>'
                    + '<td class="zca-row-actions">'
                        + '<button type="button" class="button-link zca-edit">Edit</button> · '
                        + '<button type="button" class="button-link delete zca-delete">Delete</button>'
                    + '</td>'
                    + '</tr>';
            });
            $listBody.html(html);
        }

        function renderArtifactStats(stats) {
            let html = '<table class="widefat striped" style="max-width:560px;"><tbody>';
            html += '<tr><th>Total artifacts</th><td><strong>' + (stats.total || 0) + '</strong></td></tr>';
            Object.keys(stats.by_type || {}).forEach(function (k) {
                html += '<tr><th>' + escapeHtml(fmtType(k)) + '</th><td>' + stats.by_type[k] + '</td></tr>';
            });
            html += '</tbody></table>';
            $stats.html(html);
        }

        function openEditor(row) {
            if (row) {
                $editorTitle.text('Edit artifact #' + row.id);
                $idField.val(row.id);
                $title.val(row.title || '');
                $type.val(row.type || 'general_info');
                $author.val(row.author_id || '');
                $raw.val(row.raw_content || '');
                $source.val(row.source || 'manual');
                if (row.structured) {
                    $previewBody.text(row.structured);
                    $preview.show();
                } else {
                    $preview.hide();
                }
            } else {
                $editorTitle.text('New artifact');
                $idField.val('');
                $title.val('');
                $type.val('general_info');
                $author.val('');
                $raw.val('');
                $source.val('manual');
                $preview.hide();
                $previewBody.text('');
            }
            $saveStatus.text('').removeClass('ok err');
            chatMessages = [];
            $chat.empty();
            selectedRefs = [];
            renderRefsPills();
            $refsInput.val('');
            $refsDropdown.empty().attr('hidden', true);
            $builderPanel.hide();
            $editor.show();
            $('html, body').animate({ scrollTop: $editor.offset().top - 40 }, 200);
        }

        function closeEditor() {
            $editor.hide();
        }

        $('#zca-new').on('click', function () { openEditor(null); });
        $('#zca-cancel').on('click', closeEditor);
        $('#zca-filter-type').on('change', loadList);

        $listBody.on('click', '.zca-edit', function () {
            const id = $(this).closest('tr').data('id');
            ajax('zen_cortext_artifact_get', { id: id }).done(function (resp) {
                if (resp.success) openEditor(resp.data.row);
                else window.alert('Load failed');
            });
        });

        $listBody.on('click', '.zca-delete', function () {
            const id = $(this).closest('tr').data('id');
            if (!window.confirm('Delete this artifact? It will stop being used as chat context.')) return;
            ajax('zen_cortext_artifact_delete', { id: id }).done(function (resp) {
                if (resp.success) {
                    loadList();
                } else {
                    window.alert(resp.data && resp.data.message ? resp.data.message : 'Delete failed');
                }
            });
        });

        // Two-button save flow:
        //   #zca-save              → metadata-only (no AI restructure call)
        //   #zca-save-restructure  → full save + AI restructure on the body
        //
        // The two buttons share one handler. The `restructure` flag tells
        // the backend whether to re-run the LLM step; on a metadata-only
        // save, the existing structured content is preserved untouched.
        function performArtifactSave(restructure) {
            const $primary = restructure ? $('#zca-save-restructure') : $('#zca-save');
            $('#zca-save, #zca-save-restructure').prop('disabled', true);
            $saveStatus
                .text(restructure ? 'Saving + restructuring (this may take ~10-20s)…' : 'Saving…')
                .removeClass('ok err');

            ajax('zen_cortext_artifact_save', {
                id:          $idField.val(),
                title:       $title.val(),
                type:        $type.val(),
                author_id:   $author.val(),
                raw_content: $raw.val(),
                source:      $source.val(),
                restructure: restructure ? '1' : '0'
            }).done(function (resp) {
                if (!resp.success) {
                    $saveStatus.text('✗ ' + (resp.data && resp.data.message ? resp.data.message : 'Save failed')).addClass('err');
                    return;
                }
                $saveStatus.text(restructure ? '✓ Saved + restructured.' : '✓ Saved.').addClass('ok');
                const row = resp.data.row;
                if (row) {
                    $idField.val(row.id);
                    $editorTitle.text('Edit artifact #' + row.id);
                    if (row.structured) {
                        $previewBody.text(row.structured);
                        $preview.show();
                    }
                }
                loadList();
            }).fail(function () {
                $saveStatus.text('✗ Request failed').addClass('err');
            }).always(function () {
                $('#zca-save, #zca-save-restructure').prop('disabled', false);
            });
        }

        $('#zca-save').on('click',             function () { performArtifactSave(false); });
        $('#zca-save-restructure').on('click', function () { performArtifactSave(true);  });

        /* ---- Chat builder ---- */

        $builderToggle.on('click', function () {
            $builderPanel.toggle();
            if ($builderPanel.is(':visible') && $chat.is(':empty')) {
                addChatMessage('assistant', 'Hi. Tell me what artifact you want to build (and what type), and I\'ll ask focused questions to fill in the details.');
            }
        });

        $chatReset.on('click', function () {
            chatMessages = [];
            $chat.empty();
            // Note: we do NOT clear selectedRefs on chat reset — the reference
            // pick is part of the editor session, not the chat turn. To clear
            // refs, the user removes pills individually or cancels the editor.
            addChatMessage('assistant', 'Reset. Tell me what artifact you want to build.');
        });

        /* ---- Reference artifacts multiselect ---- */

        function renderRefsPills() {
            if (!selectedRefs.length) {
                $refsPills.empty();
                return;
            }
            let html = '';
            selectedRefs.forEach(function (r) {
                html += '<span class="zca-ref-pill" data-id="' + r.id + '">'
                     +    '<span class="zca-ref-pill-type">' + escapeHtml(fmtType(r.type)) + '</span>'
                     +    '<span class="zca-ref-pill-title">' + escapeHtml(r.title) + '</span>'
                     +    '<button type="button" class="zca-ref-pill-remove" aria-label="Remove">×</button>'
                     +  '</span>';
            });
            $refsPills.html(html);
        }

        function renderRefsDropdown(rows) {
            if (!rows || !rows.length) {
                $refsDropdown.html('<div class="zca-refs-empty">No matches.</div>').attr('hidden', false);
                return;
            }
            const selectedIds = new Set(selectedRefs.map(function (r) { return parseInt(r.id, 10); }));
            let html = '';
            rows.forEach(function (r) {
                const isSelected = selectedIds.has(parseInt(r.id, 10));
                html += '<div class="zca-refs-result' + (isSelected ? ' selected' : '') + '" data-id="' + r.id + '" data-type="' + escapeHtml(r.type) + '" data-title="' + escapeHtml(r.title) + '">'
                     +    '<span class="zca-refs-result-title">' + escapeHtml(r.title) + '</span>'
                     +    '<span class="zca-refs-result-type">' + escapeHtml(fmtType(r.type)) + '</span>'
                     +    (isSelected ? '<span class="zca-refs-result-check">✓</span>' : '')
                     +  '</div>';
            });
            $refsDropdown.html(html).attr('hidden', false);
        }

        function searchRefs(query) {
            ajax('zen_cortext_artifact_search', {
                q:          query,
                exclude_id: $idField.val() || 0
            }).done(function (resp) {
                if (!resp.success) {
                    $refsDropdown.html('<div class="zca-refs-empty zca-err">Search failed.</div>').attr('hidden', false);
                    return;
                }
                renderRefsDropdown(resp.data.rows || []);
            });
        }

        $refsInput.on('input', function () {
            clearTimeout(refsSearchTimer);
            const query = $(this).val();
            refsSearchTimer = setTimeout(function () { searchRefs(query); }, 220);
        });

        $refsInput.on('focus', function () {
            // Show recent artifacts on focus even before typing.
            if (!$refsDropdown.children().length) {
                searchRefs('');
            } else {
                $refsDropdown.attr('hidden', false);
            }
        });

        // Click outside dropdown closes it.
        $(document).on('click.zcaRefs', function (e) {
            if (!$(e.target).closest('#zca-refs').length) {
                $refsDropdown.attr('hidden', true);
            }
        });

        // Click a result toggles its selection.
        $refsDropdown.on('click', '.zca-refs-result', function (e) {
            e.stopPropagation();
            const id    = parseInt($(this).data('id'), 10);
            const title = $(this).data('title');
            const type  = $(this).data('type');
            const idx = selectedRefs.findIndex(function (r) { return parseInt(r.id, 10) === id; });
            if (idx >= 0) {
                selectedRefs.splice(idx, 1);
            } else {
                if (selectedRefs.length >= 8) {
                    window.alert('Maximum 8 reference artifacts. Remove one to add another.');
                    return;
                }
                selectedRefs.push({ id: id, title: title, type: type });
            }
            renderRefsPills();
            // Re-render the dropdown so the check mark / selected state updates.
            $(this).toggleClass('selected');
            const $check = $(this).find('.zca-refs-result-check');
            if ($(this).hasClass('selected')) {
                if (!$check.length) $(this).append('<span class="zca-refs-result-check">✓</span>');
            } else {
                $check.remove();
            }
        });

        // Remove a pill via its × button.
        $refsPills.on('click', '.zca-ref-pill-remove', function () {
            const id = parseInt($(this).closest('.zca-ref-pill').data('id'), 10);
            selectedRefs = selectedRefs.filter(function (r) { return parseInt(r.id, 10) !== id; });
            renderRefsPills();
        });

        function addChatMessage(role, content) {
            const div = document.createElement('div');
            div.className = 'zca-msg zca-msg-' + role;
            div.textContent = content;
            $chat.append(div);
            $chat[0].scrollTop = $chat[0].scrollHeight;
            return div;
        }

        $chatInput.on('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendChat();
            }
        });
        $chatSend.on('click', sendChat);

        async function sendChat() {
            if (chatStreaming) return;
            const text = $chatInput.val().trim();
            if (!text) return;

            addChatMessage('user', text);
            chatMessages.push({ role: 'user', content: text });
            $chatInput.val('');
            chatStreaming = true;
            $chatSend.prop('disabled', true);

            const bubble = addChatMessage('assistant', '…');
            let assistantText = '';

            try {
                const response = await fetch(cfg.artifactChatRestUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': cfg.restNonce
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        messages:            chatMessages,
                        type:                $type.val() || '',
                        title:               $title.val() || '',
                        reference_artifacts: selectedRefs.map(function (r) { return parseInt(r.id, 10); }),
                        exclude_id:          parseInt($idField.val() || 0, 10)
                    })
                });

                if (!response.ok || !response.body) {
                    bubble.textContent = 'Request failed (' + response.status + ').';
                    chatStreaming = false;
                    $chatSend.prop('disabled', false);
                    return;
                }

                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;
                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop();

                    for (const line of lines) {
                        if (!line.startsWith('data: ')) continue;
                        const data = line.slice(6);
                        if (data === '[DONE]') continue;
                        try {
                            const event = JSON.parse(data);
                            if (event.type === 'content_block_delta' && event.delta && event.delta.text) {
                                assistantText += event.delta.text;
                                bubble.textContent = assistantText;
                                $chat[0].scrollTop = $chat[0].scrollHeight;
                            } else if (event.type === 'error') {
                                bubble.textContent = 'Error: ' + (event.error || 'unknown');
                            }
                        } catch (e) { /* skip non-JSON SSE keepalives */ }
                    }
                }

                if (assistantText) {
                    chatMessages.push({ role: 'assistant', content: assistantText });
                } else {
                    bubble.textContent = '(no response)';
                }
            } catch (err) {
                bubble.textContent = 'Connection error.';
            }

            chatStreaming = false;
            $chatSend.prop('disabled', false);
            $chatInput.focus();
        }

        $formArtifact.on('click', function () {
            if (!chatMessages.length) {
                window.alert('Have a chat with the AI first, then click Form Artifact.');
                return;
            }
            const $btn = $(this).prop('disabled', true);
            const oldText = $btn.text();
            $btn.text('Synthesizing draft…');

            ajax('zen_cortext_artifact_synthesize_from_chat', {
                messages:              JSON.stringify(chatMessages),
                type:                  $type.val(),
                title:                 $title.val(),
                exclude_id:            $idField.val() || 0,
                'reference_artifacts': selectedRefs.map(function (r) { return parseInt(r.id, 10); })
            }).done(function (resp) {
                if (!resp.success) {
                    window.alert(resp.data && resp.data.message ? resp.data.message : 'Synthesis failed');
                    return;
                }
                $raw.val(resp.data.draft || '');
                $source.val('chat');
                $builderPanel.hide();
                $('html, body').animate({ scrollTop: $raw.offset().top - 60 }, 200);
            }).fail(function () {
                window.alert('Request failed');
            }).always(function () {
                $btn.prop('disabled', false).text(oldText);
            });
        });

        // Initial load
        loadList();
    }

})(jQuery);
