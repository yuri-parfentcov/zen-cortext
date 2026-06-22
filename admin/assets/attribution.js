/* Zen Cortext — Attribution Context + Ads Sync admin bundle.
   Self-guards by root element so it can be enqueued on either page. */
(function ($) {
    'use strict';

    $(function () {
        if ($('#zen-cortext-attribution-root').length) initAttribution();
        if ($('#zen-cortext-ads-sync-root').length)    initAdsSync();
    });

    function ajaxUrl()  { return (window.zenCortextAdmin || {}).ajaxUrl || ''; }
    function nonce()    { return (window.zenCortextAdmin || {}).nonce   || ''; }
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    function or_(s, fallback) {
        s = (s == null ? '' : String(s)).trim();
        return s === '' ? fallback : s;
    }

    /* ============================================================
       Attribution Context page
       ============================================================ */
    function initAttribution() {
        var $list    = $('#zat-list-body');
        var $editor  = $('#zat-editor');
        var $status  = $('#zat-save-status');
        var $delete  = $('#zat-delete');

        // Cached lookups for synced GAds campaigns. Two indices because
        // utm_campaign in the wild may carry either the human-readable
        // name or the numeric campaign ID — we resolve from either.
        // null = not loaded yet, {} = loading or empty.
        var syncedByName = null; // lowercased campaign_name → row
        var syncedById   = null; // campaign_id (string) → row

        // Cached survey list for the per-rule "Attached survey" dropdown.
        // null = not loaded; an array (possibly empty) once fetched.
        var surveysCache = null;

        loadList();

        $('#zat-new').on('click', function () { openEditor(null); });
        $('#zat-cancel').on('click', function () { closeEditor(); });
        $('#zat-save').on('click', saveRule);
        $('#zat-delete').on('click', deleteRule);

        $list.on('click', '.zat-edit', function () {
            openEditor(parseInt($(this).data('id'), 10));
        });

        $list.on('click', '.zat-copy', function () {
            copyRule(parseInt($(this).data('id'), 10));
        });

        $list.on('click', '.zat-delete-row', function () {
            deleteRuleById(parseInt($(this).data('id'), 10), $(this).data('label') || '');
        });

        // Picker: select a synced GAds campaign → fill the input + show preview.
        // Picker option values are exactly what should land in the input —
        // either a campaign name or a campaign ID, depending on which
        // optgroup the admin picked from.
        $('#zat-synced-picker').on('change', function () {
            var value = $(this).val();
            if (!value) return;
            $('#zat-utm-campaign').val(value);
            showSyncedPreviewFor(value);
        });

        // Live preview: if the admin types something that matches a synced
        // row (by ID or name), show the preview without using the picker.
        $('#zat-utm-campaign').on('input', function () {
            var value = ($(this).val() || '').trim();
            if (!value) { hideSyncedPreview(); $('#zat-synced-picker').val(''); return; }
            var row = lookupSynced(value);
            if (row) {
                showSyncedPreviewFor(value);
                $('#zat-synced-picker').val(matchPickerValue(value));
            } else {
                hideSyncedPreview();
                $('#zat-synced-picker').val('');
            }
        });

        $('#zat-synced-insert').on('click', insertSummaryIntoContext);

        // Intro-card override: checkbox toggles the 5 fields, prefill button
        // copies the global intro card (localized via zenCortextAttribution)
        // into the inputs so the admin can tweak rather than start blank.
        $('#zat-intro-enabled').on('change', updateIntroFieldsVisibility);
        $('#zat-intro-prefill').on('click', prefillIntroFromGlobal);

        function loadList() {
            $list.html('<tr><td colspan="10"><em>Loading…</em></td></tr>');
            $.post(ajaxUrl(), { action: 'zen_cortext_attribution_list', nonce: nonce() })
                .done(function (resp) {
                    if (!resp || !resp.success) {
                        $list.html('<tr><td colspan="10">Failed to load.</td></tr>');
                        return;
                    }
                    renderList(resp.data.rows || []);
                })
                .fail(function () {
                    $list.html('<tr><td colspan="10">Network error.</td></tr>');
                });
        }

        function renderList(rows) {
            if (!rows.length) {
                $list.html('<tr><td colspan="10"><em>No rules yet. Click "+ New rule" to add one.</em></td></tr>');
                return;
            }
            var html = '';
            rows.forEach(function (r) {
                html += '<tr>'
                     + '<td><strong>' + escapeHtml(r.label) + '</strong></td>'
                     + '<td>' + escapeHtml(or_(r.match_utm_source,   '—')) + '</td>'
                     + '<td>' + escapeHtml(or_(r.match_utm_medium,   '—')) + '</td>'
                     + '<td>' + escapeHtml(or_(r.match_utm_campaign, '—')) + '</td>'
                     + '<td>' + escapeHtml(or_(r.match_referrer_host,'—')) + '</td>'
                     + '<td>' + (parseInt(r.match_gclid_present, 10) ? 'yes' : '—') + '</td>'
                     + '<td>' + (parseInt(r.priority, 10) || 0) + '</td>'
                     + '<td>' + (parseInt(r.enabled, 10) ? '✓' : '—') + '</td>'
                     + '<td>' + escapeHtml(r.updated_at || '') + '</td>'
                     + '<td>'
                     +     '<button type="button" class="button button-small zat-edit" data-id="' + parseInt(r.id, 10) + '">Edit</button> '
                     +     '<button type="button" class="button button-small zat-copy" data-id="' + parseInt(r.id, 10) + '">Copy</button> '
                     +     '<button type="button" class="button button-small button-link-delete zat-delete-row" data-id="' + parseInt(r.id, 10) + '" data-label="' + escapeHtml(r.label || '') + '">Delete</button>'
                     + '</td>'
                     + '</tr>';
            });
            $list.html(html);
        }

        function openEditor(id) {
            $status.text('').removeClass('error success');
            ensureSyncedLoaded();
            ensureSurveysLoaded();
            if (!id) {
                fillEditor({
                    id: 0, label: '', match_utm_source: '', match_utm_medium: '',
                    match_utm_campaign: '', match_referrer_host: '', match_gclid_present: 0,
                    priority: 0, enabled: 1, context_text: '', invite_message: '', chips: [],
                    survey_id: 0
                });
                $('#zat-editor-title').text('New rule');
                $delete.hide();
                $editor.show();
                $('html, body').animate({ scrollTop: $editor.offset().top - 40 }, 200);
                return;
            }
            $.post(ajaxUrl(), { action: 'zen_cortext_attribution_get', nonce: nonce(), id: id })
                .done(function (resp) {
                    if (!resp || !resp.success) {
                        alert((resp && resp.data && resp.data.message) || 'Load failed');
                        return;
                    }
                    fillEditor(resp.data.row);
                    $('#zat-editor-title').text('Edit rule: ' + (resp.data.row.label || ''));
                    $delete.show();
                    $editor.show();
                    $('html, body').animate({ scrollTop: $editor.offset().top - 40 }, 200);
                });
        }

        /* ---- surveys dropdown ---- */

        function ensureSurveysLoaded() {
            if (surveysCache !== null) {
                rebuildSurveyOptions();
                return;
            }
            surveysCache = []; // mark "in flight"
            $.post(ajaxUrl(), { action: 'zen_cortext_surveys_list', nonce: nonce(), only_enabled: 1 })
                .done(function (resp) {
                    if (!resp || !resp.success) {
                        surveysCache = [];
                        rebuildSurveyOptions();
                        return;
                    }
                    surveysCache = (resp.data && resp.data.rows) || [];
                    rebuildSurveyOptions();
                })
                .fail(function () {
                    surveysCache = [];
                    rebuildSurveyOptions();
                });
        }

        function rebuildSurveyOptions() {
            var $select = $('#zat-survey');
            if (!$select.length) return;
            var current = $select.val();
            // Keep the first "— None —" option, replace the rest.
            $select.find('option:not(:first)').remove();
            (surveysCache || []).forEach(function (s) {
                var label = s.label;
                if (s.question_count) label += ' (' + s.question_count + ' Q)';
                $select.append($('<option/>').val(s.id).text(label));
            });
            if (current) $select.val(current);
        }

        /* ---- synced GAds picker ---- */

        function ensureSyncedLoaded() {
            if (syncedByName !== null) { syncRefreshAfterFill(); return; }
            syncedByName = {}; syncedById = {}; // mark "in flight"
            $.post(ajaxUrl(), { action: 'zen_cortext_ads_campaigns_list', nonce: nonce() })
                .done(function (resp) {
                    if (!resp || !resp.success) { syncedByName = null; syncedById = null; return; }
                    var rows = (resp.data && resp.data.rows) || [];
                    var byName = {}, byId = {};
                    var $picker = $('#zat-synced-picker');
                    $picker.find('option:not(:first)').remove();
                    var $cName = $('<optgroup label="Campaigns — by name"/>');
                    var $cId   = $('<optgroup label="Campaigns — by ID"/>');
                    var $gName = $('<optgroup label="Ad groups — by name"/>');
                    var $gId   = $('<optgroup label="Ad groups — by ID"/>');

                    rows.forEach(function (r) {
                        var counts = ' (' + countList(r.top_headlines) + ' h, ' + countList(r.top_keywords) + ' k)';
                        if (r.type === 'group') {
                            var gname = r.ad_group_name || '';
                            var gid   = r.ad_group_id || '';
                            var label = (r.campaign_name || '') + ' › ' + gname;
                            if (gname) {
                                byName[gname.toLowerCase()] = r; // group wins on tie
                                $gName.append($('<option/>').val(gname).text(label + counts));
                            }
                            if (gid) {
                                byId[String(gid)] = r;
                                $gId.append($('<option/>').val(String(gid)).text(gid + ' — ' + label));
                            }
                        } else {
                            var name = r.campaign_name || '';
                            var id   = r.campaign_id || '';
                            if (name) {
                                if (!byName[name.toLowerCase()]) byName[name.toLowerCase()] = r;
                                $cName.append($('<option/>').val(name).text(name + counts));
                            }
                            if (id) {
                                if (!byId[String(id)]) byId[String(id)] = r;
                                $cId.append($('<option/>').val(String(id)).text(id + ' — ' + name));
                            }
                        }
                    });
                    if ($cName.children().length) $picker.append($cName);
                    if ($cId.children().length)   $picker.append($cId);
                    if ($gName.children().length) $picker.append($gName);
                    if ($gId.children().length)   $picker.append($gId);

                    syncedByName = byName;
                    syncedById   = byId;
                    syncRefreshAfterFill();
                })
                .fail(function () { syncedByName = null; syncedById = null; });
        }

        // Resolve a value (could be ID or name) to the synced row, or null.
        // ID is checked first — they're numeric and can't collide with
        // common name slugs.
        function lookupSynced(value) {
            if (!value) return null;
            value = String(value).trim();
            if (syncedById && syncedById[value]) return syncedById[value];
            if (syncedByName && syncedByName[value.toLowerCase()]) return syncedByName[value.toLowerCase()];
            return null;
        }

        // Called once the editor is filled OR once the synced data finishes
        // loading — auto-shows the preview if the loaded utm_campaign
        // matches any synced row (by ID or name).
        function syncRefreshAfterFill() {
            var value = ($('#zat-utm-campaign').val() || '').trim();
            if (!value || (syncedByName === null && syncedById === null)) { hideSyncedPreview(); return; }
            if (lookupSynced(value)) {
                showSyncedPreviewFor(value);
                $('#zat-synced-picker').val(matchPickerValue(value));
            } else {
                hideSyncedPreview();
                $('#zat-synced-picker').val('');
            }
        }

        // Find the picker option whose value equals the given input (case-
        // insensitive for names since the human typed it). Falls back to
        // empty string if no option matches — that just clears the picker.
        function matchPickerValue(value) {
            var v  = String(value);
            var lc = v.toLowerCase();
            var found = '';
            $('#zat-synced-picker option').each(function () {
                if (this.value === v || this.value.toLowerCase() === lc) {
                    found = this.value; return false;
                }
            });
            return found;
        }

        function showSyncedPreviewFor(value) {
            var row = lookupSynced(value);
            if (!row) { hideSyncedPreview(); return; }
            var label;
            if (row.type === 'group') {
                label = 'ad group "' + row.ad_group_name + '" (id ' + row.ad_group_id + ') · campaign "' + row.campaign_name + '"';
            } else {
                label = 'campaign "' + row.campaign_name + '" (id ' + row.campaign_id + ')';
            }
            $('#zat-synced-preview-name').text(label);
            var headlines = parseList(row.top_headlines);
            var keywords  = parseList(row.top_keywords);
            $('#zat-synced-headlines-count').text(headlines.length ? '(' + headlines.length + ')' : '');
            $('#zat-synced-keywords-count').text(keywords.length  ? '(' + keywords.length  + ')' : '');
            $('#zat-synced-headlines-list').html(headlines.length
                ? headlines.map(function (h) { return '<li>' + escapeHtml(h) + '</li>'; }).join('')
                : '<li><em>None — this campaign type may not expose this data (PMAX has no RSAs).</em></li>');
            $('#zat-synced-keywords-list').html(keywords.length
                ? keywords.map(function (k) { return '<li>' + escapeHtml(k) + '</li>'; }).join('')
                : '<li><em>None — PMAX has no keyword_view.</em></li>');
            $('#zat-synced-preview').show();
        }

        function hideSyncedPreview() {
            $('#zat-synced-preview').hide();
            $('#zat-synced-insert-status').text('').removeClass('success error');
        }

        function insertSummaryIntoContext() {
            var value = ($('#zat-utm-campaign').val() || '').trim();
            var row = lookupSynced(value);
            if (!row) return;
            var headlines = parseList(row.top_headlines);
            var keywords  = parseList(row.top_keywords);
            var lines = [];
            if (row.type === 'group') {
                lines.push('Visitors come from Google Ads ad group "' + row.ad_group_name + '" (id ' + row.ad_group_id + ') in campaign "' + row.campaign_name + '".');
            } else {
                lines.push('Visitors come from Google Ads campaign "' + row.campaign_name + '" (id ' + row.campaign_id + ').');
            }
            if (headlines.length) {
                lines.push('');
                lines.push('They were shown ad headlines including:');
                headlines.slice(0, 6).forEach(function (h) { lines.push('- ' + h); });
            }
            if (keywords.length) {
                lines.push('');
                lines.push('Keywords this campaign targets:');
                keywords.slice(0, 8).forEach(function (k) { lines.push('- ' + k); });
            }
            lines.push('');
            lines.push('[Edit this starter — describe the offer, target audience, and what to emphasize / avoid.]');
            var summary = lines.join('\n');
            var $ctx = $('#zat-context');
            var current = ($ctx.val() || '').trim();
            // Replace, don't prepend — seeding a starter should produce a clean
            // block, not stack on top of a previously-seeded one. Guard a
            // non-empty box with a confirm so hand-written context isn't lost.
            if (current && !window.confirm('Replace the current Context with this generated summary? Your existing text will be overwritten.')) {
                $('#zat-synced-insert-status').text('Cancelled — Context left unchanged').removeClass('success').addClass('error');
                return;
            }
            $ctx.val(summary);
            $('#zat-synced-insert-status').text('Inserted ✓ — now edit it to match your strategy').addClass('success').removeClass('error');
        }

        function parseList(jsonStr) {
            if (!jsonStr) return [];
            try {
                var arr = JSON.parse(jsonStr);
                return Array.isArray(arr) ? arr.filter(function (v) { return typeof v === 'string'; }) : [];
            } catch (e) { return []; }
        }
        function countList(jsonStr) { return parseList(jsonStr).length; }

        function fillEditor(row) {
            $('#zat-id').val(row.id || 0);
            $('#zat-label').val(row.label || '');
            $('#zat-utm-source').val(row.match_utm_source || '');
            $('#zat-utm-medium').val(row.match_utm_medium || '');
            $('#zat-utm-campaign').val(row.match_utm_campaign || '');
            $('#zat-referrer-host').val(row.match_referrer_host || '');
            $('#zat-gclid-present').prop('checked', parseInt(row.match_gclid_present, 10) === 1);
            $('#zat-priority').val(parseInt(row.priority, 10) || 0);
            $('#zat-enabled').prop('checked', parseInt(row.enabled, 10) !== 0);
            $('#zat-context').val(row.context_text || '');
            $('#zat-invite').val(row.invite_message || '');
            $('#zat-chips').val(chipsToTextarea(row.chips || []));
            // Survey dropdown — string value matches the <option value>. Null
            // / empty / 0 all collapse to "" so the "— None —" entry sticks.
            var sid = parseInt(row.survey_id, 10);
            $('#zat-survey').val(sid > 0 ? String(sid) : '');

            // Intro-card override: server decodes intro_card_json into row.intro_card
            // (object with 5 string fields) or leaves it null when there is no
            // override. Null = checkbox off, all fields blank. Otherwise = checkbox
            // on, fields populated from the stored override.
            var intro = row.intro_card && typeof row.intro_card === 'object' ? row.intro_card : null;
            $('#zat-intro-enabled').prop('checked', !!intro);
            $('#zat-intro-name').val(intro ? (intro.name || '') : '');
            $('#zat-intro-role').val(intro ? (intro.role || '') : '');
            $('#zat-intro-body').val(intro ? (intro.body || '') : '');
            $('#zat-intro-logo').val(intro ? (intro.logo_url || '') : '');
            $('#zat-intro-site').val(intro ? (intro.site_url || '') : '');
            updateIntroFieldsVisibility();

            // Reset picker; refresh preview based on the just-loaded value
            // (will be a no-op if synced data hasn't loaded yet — the
            // ensureSyncedLoaded callback retries this once it lands).
            $('#zat-synced-picker').val('');
            syncRefreshAfterFill();
        }

        function updateIntroFieldsVisibility() {
            $('#zat-intro-fields').toggle($('#zat-intro-enabled').is(':checked'));
        }

        function prefillIntroFromGlobal() {
            var src = (window.zenCortextAttribution && window.zenCortextAttribution.globalIntroCard) || {};
            $('#zat-intro-name').val(src.name || '');
            $('#zat-intro-role').val(src.role || '');
            $('#zat-intro-body').val(src.body || '');
            $('#zat-intro-logo').val(src.logo_url || '');
            $('#zat-intro-site').val(src.site_url || '');
        }

        function collectIntroCardJson() {
            // Return '' when the override is disabled so the server-side
            // normalizer treats it as "no override" and the rule falls back
            // to the global intro card on the chat page.
            if (!$('#zat-intro-enabled').is(':checked')) return '';
            return JSON.stringify({
                name:     $('#zat-intro-name').val() || '',
                role:     $('#zat-intro-role').val() || '',
                body:     $('#zat-intro-body').val() || '',
                logo_url: $('#zat-intro-logo').val() || '',
                site_url: $('#zat-intro-site').val() || ''
            });
        }

        // Mirror of Zen_Cortext_Admin::chips_to_textarea() / parse_chips_textarea()
        // so the attribution editor uses the same chip format as the Chat tab.
        function looksLikeEmoji(s) {
            s = (s || '').trim();
            if (s === '' || s.length > 16) return false;
            return !/[A-Za-z0-9]/.test(s);
        }

        function chipsToTextarea(chips) {
            if (!Array.isArray(chips)) return '';
            var lines = [];
            chips.forEach(function (chip) {
                if (!chip || typeof chip !== 'object') return;
                var emoji   = (chip.emoji   || '').trim();
                var label   = (chip.label   || '').trim();
                var message = (chip.message || '').trim();
                if (!label && !message) return;
                if (!emoji && label === message) {
                    lines.push(label);
                } else if (!emoji) {
                    lines.push(label + ' | ' + message);
                } else if (label === message) {
                    lines.push(emoji + ' | ' + label);
                } else {
                    lines.push(emoji + ' | ' + label + ' | ' + message);
                }
            });
            return lines.join('\n');
        }

        function parseChipsTextarea(text) {
            var clean = [];
            var lines = String(text || '').split(/\r\n|\r|\n/);
            lines.forEach(function (line) {
                line = line.trim();
                if (!line) return;
                var parts = line.split('|').map(function (s) { return s.trim(); });
                var emoji = '';
                if (parts.length >= 2 && looksLikeEmoji(parts[0])) {
                    emoji = parts.shift();
                }
                var label   = parts[0] || '';
                var message = parts.length >= 2 ? parts.slice(1).join(' | ').trim() : '';
                if (!label && !message) return;
                if (!message) message = label;
                if (!label)   label   = message;
                clean.push({ emoji: emoji, label: label, message: message });
            });
            return clean;
        }

        function collectChips() {
            return parseChipsTextarea($('#zat-chips').val());
        }

        function saveRule() {
            $status.text('Saving…').removeClass('error success');
            $.post(ajaxUrl(), {
                action:               'zen_cortext_attribution_save',
                nonce:                nonce(),
                id:                   parseInt($('#zat-id').val(), 10) || 0,
                label:                $('#zat-label').val() || '',
                match_utm_source:     $('#zat-utm-source').val() || '',
                match_utm_medium:     $('#zat-utm-medium').val() || '',
                match_utm_campaign:   $('#zat-utm-campaign').val() || '',
                match_referrer_host:  $('#zat-referrer-host').val() || '',
                match_gclid_present:  $('#zat-gclid-present').is(':checked') ? 1 : 0,
                priority:             parseInt($('#zat-priority').val(), 10) || 0,
                enabled:              $('#zat-enabled').is(':checked') ? 1 : 0,
                context_text:         $('#zat-context').val() || '',
                invite_message:       $('#zat-invite').val() || '',
                chips_json:           JSON.stringify(collectChips()),
                intro_card_json:      collectIntroCardJson(),
                survey_id:            parseInt($('#zat-survey').val(), 10) || 0
            })
            .done(function (resp) {
                if (!resp || !resp.success) {
                    $status.text((resp && resp.data && resp.data.message) || 'Save failed').addClass('error');
                    return;
                }
                $status.text('Saved ✓').addClass('success');
                if (resp.data && resp.data.row && resp.data.row.id) {
                    $('#zat-id').val(resp.data.row.id);
                    $delete.show();
                }
                loadList();
            })
            .fail(function () {
                $status.text('Network error').addClass('error');
            });
        }

        // Duplicate a rule: fetch the source row, then save a new rule (id 0)
        // with every attribute copied and " Copy" appended to the label. The
        // copy is created immediately and shows up in the list; the admin can
        // then edit it. No confirm — it's non-destructive (creates a new row).
        function copyRule(id) {
            if (!id) return;
            var $btn = $list.find('.zat-copy[data-id="' + id + '"]');
            $btn.prop('disabled', true).text('Copying…');
            $.post(ajaxUrl(), { action: 'zen_cortext_attribution_get', nonce: nonce(), id: id })
                .done(function (resp) {
                    if (!resp || !resp.success || !resp.data || !resp.data.row) {
                        alert((resp && resp.data && resp.data.message) || 'Copy failed — could not load the source rule.');
                        $btn.prop('disabled', false).text('Copy');
                        return;
                    }
                    var r = resp.data.row;
                    $.post(ajaxUrl(), {
                        action:               'zen_cortext_attribution_save',
                        nonce:                nonce(),
                        id:                   0, // 0 = create new
                        label:                ((r.label || '') + ' Copy').trim(),
                        match_utm_source:     r.match_utm_source     || '',
                        match_utm_medium:     r.match_utm_medium     || '',
                        match_utm_campaign:   r.match_utm_campaign   || '',
                        match_referrer_host:  r.match_referrer_host  || '',
                        match_gclid_present:  parseInt(r.match_gclid_present, 10) ? 1 : 0,
                        priority:             parseInt(r.priority, 10) || 0,
                        enabled:              parseInt(r.enabled, 10) ? 1 : 0,
                        context_text:         r.context_text   || '',
                        invite_message:       r.invite_message || '',
                        chips_json:           r.chips_json      || '[]',
                        intro_card_json:      r.intro_card_json || '',
                        survey_id:            parseInt(r.survey_id, 10) || 0
                    })
                    .done(function (saveResp) {
                        if (!saveResp || !saveResp.success) {
                            alert((saveResp && saveResp.data && saveResp.data.message) || 'Copy failed — could not save the new rule.');
                            $btn.prop('disabled', false).text('Copy');
                            return;
                        }
                        loadList(); // re-renders, replacing the disabled button
                    })
                    .fail(function () {
                        alert('Copy failed — network error while saving.');
                        $btn.prop('disabled', false).text('Copy');
                    });
                })
                .fail(function () {
                    alert('Copy failed — network error while loading the source rule.');
                    $btn.prop('disabled', false).text('Copy');
                });
        }

        // Delete from inside the editor (uses the loaded rule's id).
        function deleteRule() {
            var id = parseInt($('#zat-id').val(), 10) || 0;
            if (!id) return;
            if (!window.confirm('Delete this rule? This cannot be undone.')) return;
            postDelete(id, function () {
                closeEditor();
                loadList();
            });
        }

        // Delete straight from a list row (no need to open the editor first).
        function deleteRuleById(id, label) {
            if (!id) return;
            var name = label ? '"' + label + '"' : 'this rule';
            if (!window.confirm('Delete ' + name + '? This cannot be undone.')) return;
            var $btn = $list.find('.zat-delete-row[data-id="' + id + '"]');
            $btn.prop('disabled', true).text('Deleting…');
            postDelete(id, function (ok) {
                if (ok) {
                    loadList();
                } else {
                    $btn.prop('disabled', false).text('Delete');
                }
            });
        }

        // Shared delete request. Calls done(true) on success, done(false) on
        // any failure (after alerting). Both delete paths go through here.
        function postDelete(id, done) {
            $.post(ajaxUrl(), { action: 'zen_cortext_attribution_delete', nonce: nonce(), id: id })
                .done(function (resp) {
                    if (!resp || !resp.success) {
                        alert((resp && resp.data && resp.data.message) || 'Delete failed');
                        if (done) done(false);
                        return;
                    }
                    if (done) done(true);
                })
                .fail(function () {
                    alert('Delete failed — network error.');
                    if (done) done(false);
                });
        }

        function closeEditor() {
            $editor.hide();
            $status.text('').removeClass('error success');
            hideSyncedPreview();
        }
    }

    /* ============================================================
       Ads Sync page
       ============================================================ */
    function initAdsSync() {
        loadCampaigns();
        applyScriptStatusFilter();
        $('#zat-script-statuses').on('change.zatAds', applyScriptStatusFilter);

        // Rewrite the CAMPAIGN_STATUSES line in the generated script so the
        // copied script fetches the campaign statuses chosen in the dropdown.
        function applyScriptStatusFilter() {
            var $src = $('#zat-script-source');
            var $sel = $('#zat-script-statuses');
            if (!$src.length || !$sel.length) return;
            var val = $sel.val();
            var replacement;
            if (val === 'ALL') {
                replacement = "var CAMPAIGN_STATUSES   = [];";
            } else if (val === 'ENABLED,PAUSED') {
                replacement = "var CAMPAIGN_STATUSES   = ['ENABLED', 'PAUSED'];";
            } else {
                replacement = "var CAMPAIGN_STATUSES   = ['ENABLED'];";
            }
            $src.val($src.val().replace(/var CAMPAIGN_STATUSES\s*=\s*\[[^\]]*\];/, replacement));
        }

        $('#zat-key-regen').on('click', function () {
            var msg = $('#zat-key-regen').text().indexOf('Regenerate') === 0
                ? 'Regenerate the API key? The previous key will stop working immediately.'
                : 'Generate a new API key?';
            if (!window.confirm(msg)) return;
            var $btn = $(this); $btn.prop('disabled', true);
            $('#zat-key-status').text('Generating…').removeClass('error success');
            $.post(ajaxUrl(), { action: 'zen_cortext_apps_script_key_regenerate', nonce: nonce() })
                .done(function (resp) {
                    if (!resp || !resp.success) {
                        $('#zat-key-status').text('Failed').addClass('error');
                        $btn.prop('disabled', false);
                        return;
                    }
                    $('#zat-key-status').text('New key generated').addClass('success');
                    $('#zat-key-revealed').val(resp.data.key);
                    $('#zat-key-revealed-row').show();
                    $btn.prop('disabled', false).text('Regenerate key (invalidates the old one)');
                })
                .fail(function () {
                    $('#zat-key-status').text('Network error').addClass('error');
                    $btn.prop('disabled', false);
                });
        });

        $('#zat-key-copy').on('click', function () {
            var $input = $('#zat-key-revealed');
            $input[0].select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) {}
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText($input.val()).catch(function () {});
                ok = true;
            }
            $('#zat-key-status').text(ok ? 'Copied ✓' : 'Copy failed — select and copy manually').toggleClass('success', ok).toggleClass('error', !ok);
        });

        $('#zat-script-copy').on('click', function () {
            var $src = $('#zat-script-source');
            if (!$src.length) return;
            $src[0].focus();
            $src[0].select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) {}
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText($src.val()).then(function () {
                    $('#zat-script-status').text('Script copied ✓').addClass('success').removeClass('error');
                }).catch(function () {
                    if (!ok) $('#zat-script-status').text('Copy failed — select all and copy manually').addClass('error').removeClass('success');
                });
                return;
            }
            $('#zat-script-status').text(ok ? 'Script copied ✓' : 'Copy failed — select all and copy manually').toggleClass('success', ok).toggleClass('error', !ok);
        });

        function loadCampaigns() {
            var $body = $('#zat-ads-list-body');
            $body.html('<tr><td colspan="9"><em>Loading…</em></td></tr>');
            $.post(ajaxUrl(), { action: 'zen_cortext_ads_campaigns_list', nonce: nonce() })
                .done(function (resp) {
                    if (!resp || !resp.success) {
                        $body.html('<tr><td colspan="9">Failed to load.</td></tr>');
                        return;
                    }
                    renderCampaigns(resp.data.rows || []);
                })
                .fail(function () {
                    $body.html('<tr><td colspan="9">Network error.</td></tr>');
                });
        }

        // Clear-synced-data button: confirms, calls the truncate AJAX,
        // resets the table + summary line + disables itself when empty.
        $('#zat-ads-clear').on('click', function () {
            if (!window.confirm('Wipe every synced Google Ads campaign row? Attribution rules stay in place — they just lose the joined campaign metadata until the next sync. This cannot be undone.')) return;
            var $btn    = $(this);
            var $status = $('#zat-ads-clear-status').text('Clearing…').removeClass('error success');
            $btn.prop('disabled', true);
            $.post(ajaxUrl(), { action: 'zen_cortext_ads_campaigns_clear', nonce: nonce() })
                .done(function (resp) {
                    if (!resp || !resp.success) {
                        $status.text((resp && resp.data && resp.data.message) || 'Clear failed').addClass('error');
                        $btn.prop('disabled', false);
                        return;
                    }
                    var n = (resp.data && resp.data.deleted) || 0;
                    $status.text('Cleared ✓ (' + n + ' row' + (n === 1 ? '' : 's') + ' deleted)').addClass('success');
                    $('#zat-ads-summary').text('No campaigns synced yet. Run the script in Google Ads to populate this list.');
                    $('#zat-ads-list-body').html('<tr><td colspan="9"><em>No campaigns synced yet.</em></td></tr>');
                    // Button stays disabled — the table is empty, so there's
                    // nothing left to clear. Next sync will re-enable it
                    // implicitly on the next page load.
                })
                .fail(function () {
                    $status.text('Network error').addClass('error');
                    $btn.prop('disabled', false);
                });
        });

        function renderCampaigns(rows) {
            var $body = $('#zat-ads-list-body');
            if (!rows.length) {
                $body.html('<tr><td colspan="9"><em>No campaigns synced yet.</em></td></tr>');
                return;
            }
            var html = '';
            rows.forEach(function (r, i) {
                var headlines = parseList(r.top_headlines);
                var keywords  = parseList(r.top_keywords);
                var budget = '';
                if (r.budget_micros != null && r.budget_micros !== '') {
                    budget = (parseInt(r.budget_micros, 10) / 1000000).toFixed(2);
                }
                var isGroup   = (r.type === 'group');
                var ownId     = isGroup ? (r.ad_group_id || '') : (r.campaign_id || '');
                var typeLabel = isGroup ? 'Ad group' : 'Campaign';
                html += '<tr class="zat-ads-row" data-idx="' + i + '">'
                     + '<td>' + escapeHtml(typeLabel) + '</td>'
                     + '<td><strong>' + escapeHtml(r.campaign_name) + '</strong></td>'
                     + '<td>' + (isGroup ? escapeHtml(r.ad_group_name || '') : '—') + '</td>'
                     + '<td><code>' + escapeHtml(ownId) + '</code></td>'
                     + '<td>' + escapeHtml(r.status || '') + '</td>'
                     + '<td>' + (budget ? escapeHtml(budget) : '—') + '</td>'
                     + '<td>' + headlines.length + '</td>'
                     + '<td>' + keywords.length + '</td>'
                     + '<td>' + escapeHtml(r.synced_at || '')
                     +     ' <button type="button" class="button button-small zat-ads-toggle" data-idx="' + i + '">View</button>'
                     + '</td>'
                     + '</tr>'
                     + '<tr class="zat-ads-detail" data-idx="' + i + '" style="display:none;">'
                     + '<td colspan="9" class="zat-ads-detail-cell">' + renderDetail(headlines, keywords) + '</td>'
                     + '</tr>';
            });
            $body.html(html);
        }

        $(document).off('click.zatAds').on('click.zatAds', '.zat-ads-toggle', function (e) {
            e.preventDefault();
            var idx = $(this).data('idx');
            var $detail = $('#zat-ads-list-body .zat-ads-detail[data-idx="' + idx + '"]');
            var visible = $detail.is(':visible');
            $detail.toggle();
            $(this).text(visible ? 'View' : 'Hide');
        });

        function renderDetail(headlines, keywords) {
            var html = '<div class="zat-ads-detail-grid">';
            html += '<div><h4>Headlines (' + headlines.length + ')</h4>' + renderListBlock(headlines) + '</div>';
            html += '<div><h4>Keywords (' + keywords.length + ')</h4>'  + renderListBlock(keywords)  + '</div>';
            html += '</div>';
            return html;
        }

        function renderListBlock(items) {
            if (!items.length) return '<em>None — this campaign type may not expose this data (PMAX has no RSAs and no keyword_view).</em>';
            var out = '<ul class="zat-ads-detail-list">';
            items.forEach(function (s) { out += '<li>' + escapeHtml(s) + '</li>'; });
            out += '</ul>';
            return out;
        }

        function parseList(jsonStr) {
            if (!jsonStr) return [];
            try {
                var arr = JSON.parse(jsonStr);
                return Array.isArray(arr) ? arr.filter(function (v) { return typeof v === 'string'; }) : [];
            } catch (e) { return []; }
        }
    }
})(jQuery);
