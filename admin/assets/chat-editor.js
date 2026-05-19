/* Zen Cortext — Chat Template Editor admin page (Template Code + Help).
   The Colors panel that used to live here moved to its own admin page
   (Zen Cortext → Design); see admin/assets/design.js. */
(function () {
    'use strict';

    var cfg = (typeof window.zenCortextChatEditor === 'object' && window.zenCortextChatEditor) || {};

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('zen-cortext-chat-editor');
        if (!root) return;
        initTabs(root);
        initCodePanel(root);
    });

    /* ============================================================
       Tabs
       ============================================================ */
    function initTabs(root) {
        var tabs   = root.querySelectorAll('.zce-tab');
        var panels = root.querySelectorAll('.zce-panel');
        function activate(name) {
            tabs.forEach(function (t) {
                t.classList.toggle('is-active', t.getAttribute('data-zce-tab') === name);
            });
            panels.forEach(function (p) {
                var match = p.getAttribute('data-zce-panel') === name;
                p.hidden = !match;
            });
            try { localStorage.setItem('zce_active_tab', name); } catch (e) {}
        }
        tabs.forEach(function (t) {
            t.addEventListener('click', function () {
                activate(t.getAttribute('data-zce-tab'));
            });
        });
        var saved = '';
        try { saved = localStorage.getItem('zce_active_tab') || ''; } catch (e) {}
        // Default to Code, only honor a saved 'help' or 'code' (legacy
        // 'colors' from before the Design split would hit this fallback).
        activate(saved === 'help' ? 'help' : 'code');
    }

    function setStatus(el, text, cls) {
        if (!el) return;
        el.classList.remove('is-ok', 'is-err');
        if (cls) el.classList.add(cls);
        el.textContent = text;
    }

    /* ============================================================
       Template Code panel
       ============================================================ */
    // Varnish on this host has a regex that treats any URL ending in
    // .css / .js / .png / etc. as a static file and strips cookies
    // before forwarding. When the editor fetches `/source?file=chat.css`
    // the URL ends in `.css` and Varnish strips the WP_logged_in cookie,
    // which makes the REST nonce check return 403. Appending a trailing
    // cache-buster param shifts the URL's tail so Varnish's regex no
    // longer matches — cookies pass through, auth succeeds.
    function bust(url) {
        return url + (url.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now();
    }

    function initCodePanel(root) {
        var fileSelect    = document.getElementById('zce-file-select');
        var versionSelect = document.getElementById('zce-version-select');
        var deviceSelect  = document.getElementById('zce-device-select');
        var sourceArea    = document.getElementById('zce-source');
        var iframe        = document.getElementById('zce-preview-iframe');
        var previewPane   = iframe ? iframe.parentElement : null;
        var saveBtn       = document.getElementById('zce-save');
        var saveStatus    = document.getElementById('zce-save-status');
        var restoreBtn    = document.getElementById('zce-restore-version');
        var discardBtn    = document.getElementById('zce-discard-preview');
        var resetBtn      = document.getElementById('zce-reset-factory');
        var aiForm        = document.getElementById('zce-ai-form');
        var aiInput       = document.getElementById('zce-ai-input');
        var aiHistory     = document.getElementById('zce-ai-history');
        var aiSendBtn     = document.getElementById('zce-ai-send');
        var aiPane        = document.getElementById('zce-ai-pane');
        var aiTabs        = aiPane ? aiPane.querySelectorAll('.zce-ai-tab') : [];
        var aiPanels      = aiPane ? aiPane.querySelectorAll('.zce-ai-tabpanel') : [];
        var expandBtn     = document.getElementById('zce-ai-expand');

        /* ----- AI-pane tabs (Chat / Code) + expand/fold ----- */
        function activateAiTab(name) {
            aiTabs.forEach(function (t) {
                t.classList.toggle('is-active', t.getAttribute('data-zce-ai-tab') === name);
            });
            aiPanels.forEach(function (p) {
                p.hidden = p.getAttribute('data-zce-ai-panel') !== name;
            });
            // The first time the inner Code tab becomes visible, boot
            // CodeMirror against the now-visible textarea — initialising
            // earlier (while hidden) leaves the gutter blank. After init,
            // a refresh keeps layout stable across subsequent re-shows.
            if (name === 'code') {
                setTimeout(function () {
                    ensureCodeEditor();
                    if (cm) {
                        try { cm.refresh(); } catch (e) {}
                        applyEditorModeFor(currentFile);
                    }
                }, 0);
            }
        }
        aiTabs.forEach(function (t) {
            t.addEventListener('click', function () {
                activateAiTab(t.getAttribute('data-zce-ai-tab'));
            });
        });

        if (expandBtn) {
            // The grid container — same class flips alongside the AI pane
            // so the column rule is keyed on a class on .zce-code-grid
            // (avoids needing :has() and dodges the "AI pane lands in
            // column 1 at 0px width" trap when preview is removed).
            var grid = aiPane ? aiPane.closest('.zce-code-grid') : null;
            expandBtn.addEventListener('click', function () {
                var expanded = aiPane.classList.toggle('is-expanded');
                if (grid) grid.classList.toggle('is-expanded', expanded);
                expandBtn.setAttribute('aria-pressed', expanded ? 'true' : 'false');
                expandBtn.setAttribute(
                    'aria-label',
                    expanded ? 'Collapse panel back to default size' : 'Expand panel'
                );
                expandBtn.title = expanded ? 'Collapse panel' : 'Expand to full width';
                // Resize fallout: CodeMirror needs a tick to re-measure
                // after the parent grows or shrinks.
                if (cm) setTimeout(function () { cm.refresh(); }, 0);
            });
        }

        // Populate file dropdown.
        var files = cfg.editableFiles || {};
        Object.keys(files).forEach(function (name) {
            var opt = document.createElement('option');
            opt.value = name;
            opt.textContent = files[name].label || name;
            fileSelect.appendChild(opt);
        });

        var cm = null;          // CodeMirror instance
        var currentFile = '';
        var convoHistory = [];  // AI conversation history
        var stagedSource = '';  // last source the iframe is showing
        var stagedFor    = '';  // file the staged source belongs to
        var pendingPreviewTimer = null;

        // Light up CodeMirror via wp.codeEditor if WP gave us settings.
        // Always initialise — even on a hidden parent — and call refresh
        // every time the host becomes visible. The earlier offsetParent
        // gate ended up never booting CodeMirror in production because
        // the outer Code panel is `hidden` on first paint.
        function ensureCodeEditor() {
            if (cm) return cm;
            if (!sourceArea) {
                console.error('[chat-editor] sourceArea textarea is missing');
                return null;
            }
            if (!(window.wp && window.wp.codeEditor && cfg.codeMirror)) {
                console.warn('[chat-editor] wp.codeEditor unavailable, falling back to plain textarea',
                    { hasWp: !!window.wp, hasCodeEditor: !!(window.wp && window.wp.codeEditor), cfgCm: !!cfg.codeMirror });
                sourceArea.addEventListener('input', onSourceChange);
                return null;
            }
            try {
                var inst = window.wp.codeEditor.initialize(sourceArea, cfg.codeMirror);
                cm = inst.codemirror;
                cm.on('change', onSourceChange);
                cm.setSize('100%', '100%');
                if (sourceArea.value && cm.getValue() !== sourceArea.value) {
                    cm.setValue(sourceArea.value);
                }
                console.log('[chat-editor] CodeMirror initialized; existing textarea len =',
                    (sourceArea.value || '').length);
            } catch (err) {
                console.error('[chat-editor] CodeMirror initialize threw:', err);
            }
            return cm;
        }

        // Switch CodeMirror's syntax mode to match the editable file —
        // .tpl.html files render as HTML, chat.css renders as CSS.
        // Falls back to the file's registered MIME type from editableFiles.
        function applyEditorModeFor(file) {
            if (!cm) return;
            var meta = (cfg.editableFiles || {})[file] || {};
            var mode = meta.mode || (file && file.indexOf('.css') !== -1 ? 'text/css' : 'text/html');
            try { cm.setOption('mode', mode); } catch (e) {}
        }

        function getSource() {
            return cm ? cm.getValue() : sourceArea.value;
        }
        function setSource(value) {
            // Always seed the underlying textarea — when CodeMirror has
            // not booted yet (e.g. its inner tab was hidden at page load),
            // ensureCodeEditor() will pick this content up the moment the
            // tab becomes visible.
            sourceArea.value = value || '';
            if (cm) {
                cm.setValue(value || '');
                setTimeout(function () { try { cm.refresh(); } catch (e) {} }, 0);
            }
        }

        function onSourceChange() {
            schedulePreviewUpdate();
        }

        function schedulePreviewUpdate() {
            if (pendingPreviewTimer) clearTimeout(pendingPreviewTimer);
            pendingPreviewTimer = setTimeout(function () {
                // CSS edits don't go through the placeholder engine, so
                // there's no per-edit preview to stage. The iframe shows
                // the saved CSS — the user has to click Save to see CSS
                // changes. Templates keep the live-staged preview.
                var meta = (cfg.editableFiles || {})[currentFile] || {};
                if (meta.mode === 'text/css') return;
                var src = getSource();
                if (src === stagedSource && stagedFor === currentFile) {
                    reloadIframe();
                    return;
                }
                stagePreview(currentFile, src).then(reloadIframe).catch(function () {});
            }, 600);
        }

        function stagePreview(file, source) {
            return fetch(bust(cfg.restRoot + '/preview-source'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   cfg.restNonce
                },
                body: JSON.stringify({ file: file, source: source })
            }).then(function (r) {
                if (!r.ok) throw new Error('stage failed');
                stagedSource = source;
                stagedFor    = file;
            });
        }

        function discardPreview() {
            return fetch(bust(cfg.restRoot + '/preview-source?file=' + encodeURIComponent(currentFile)), {
                method: 'DELETE',
                headers: { 'X-WP-Nonce': cfg.restNonce }
            }).finally(function () {
                stagedSource = '';
                stagedFor = '';
            });
        }

        function reloadIframe() {
            var url = cfg.previewUrl + '?' + cfg.previewParam + '=1&_=' + Date.now();
            iframe.src = url;
        }

        function applyDeviceCap() {
            var v = deviceSelect.value;
            if (v === 'full' || !previewPane) {
                previewPane.classList.remove('has-width-cap');
                previewPane.style.removeProperty('--zce-preview-cap');
            } else {
                previewPane.classList.add('has-width-cap');
                previewPane.style.setProperty('--zce-preview-cap', v + 'px');
            }
        }
        deviceSelect.addEventListener('change', applyDeviceCap);

        function loadFile(name) {
            currentFile = name;
            console.log('[chat-editor] loadFile START', name);
            fetch(bust(cfg.restRoot + '/source?file=' + encodeURIComponent(name)), {
                headers: { 'X-WP-Nonce': cfg.restNonce },
                credentials: 'same-origin'
            })
            .then(function (r) {
                return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; });
            })
            .then(function (res) {
                console.log('[chat-editor] loadFile RESP', name, 'ok=', res.ok, 'status=', res.status,
                    'src_len=', (res.body && res.body.source ? res.body.source.length : 0));
                if (!res.ok) {
                    var msg = (res.body && res.body.message)
                        ? res.body.message + ' (HTTP ' + res.status + ')'
                        : 'Load failed (HTTP ' + res.status + ')';
                    setStatus(saveStatus, '✗ ' + msg, 'is-err');
                    console.error('[chat-editor] /source failed:', res);
                    return;
                }
                var data = res.body || {};
                ensureCodeEditor();
                applyEditorModeFor(name);
                // Only treat the preview as "present" if it actually has
                // content. An empty-string preview (from a stale prior
                // staging) was being chosen over data.source and showing
                // a blank editor even though the server returned the
                // real source.
                var hasPreview = typeof data.preview === 'string' && data.preview !== '';
                var src = hasPreview ? data.preview : (data.source || '');
                setSource(src);
                console.log('[chat-editor] loadFile SET', name, 'final_len=', src.length,
                    'cm_present=', !!cm, 'textarea_len=', (sourceArea.value || '').length,
                    'cm_value_len=', cm ? cm.getValue().length : null);
                stagedSource = hasPreview ? data.preview : '';
                stagedFor    = hasPreview ? name : '';
                refreshVersions();
                if (stagedFor === name) reloadIframe();
                else stagePreview(name, src || '').then(reloadIframe).catch(function () {});
            })
            .catch(function (err) {
                setStatus(saveStatus, '✗ Network error loading ' + name, 'is-err');
                console.error('[chat-editor] /source network error:', err);
            });
        }

        fileSelect.addEventListener('change', function () { loadFile(fileSelect.value); });

        function refreshVersions() {
            fetch(bust(cfg.restRoot + '/versions?file=' + encodeURIComponent(currentFile)), {
                headers: { 'X-WP-Nonce': cfg.restNonce }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                versionSelect.innerHTML = '';
                var versions = data.versions || [];
                if (!versions.length) {
                    // Empty state — make it visible in the dropdown itself
                    // and lock both the dropdown and the Restore button so
                    // the admin sees there's nothing to restore yet.
                    var none = document.createElement('option');
                    none.value = '';
                    none.textContent = '(no saved versions yet)';
                    versionSelect.appendChild(none);
                    versionSelect.disabled = true;
                    if (restoreBtn) restoreBtn.disabled = true;
                    return;
                }
                versionSelect.disabled = false;
                var current = document.createElement('option');
                current.value = '';
                current.textContent = 'Currently published';
                versionSelect.appendChild(current);
                versions.forEach(function (ts) {
                    var opt = document.createElement('option');
                    opt.value = ts;
                    opt.textContent = formatTs(ts);
                    versionSelect.appendChild(opt);
                });
                // The Restore button should only be live when a *prior*
                // version is actually selected — restoring "Currently
                // published" to itself is a no-op that produced a
                // confusing "Pick a prior version first" error message.
                if (restoreBtn) restoreBtn.disabled = !versionSelect.value;
            })
            .catch(function () {});
        }

        function formatTs(ts) {
            // YYYYMMDD-HHMMSS → "YYYY-MM-DD HH:MM:SS"
            var m = /^(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})$/.exec(ts);
            return m ? (m[1] + '-' + m[2] + '-' + m[3] + ' ' + m[4] + ':' + m[5] + ':' + m[6]) : ts;
        }

        versionSelect.addEventListener('change', function () {
            var ts = versionSelect.value;
            // Restore is only meaningful when a prior version is picked.
            if (restoreBtn) restoreBtn.disabled = !ts;
            if (!ts) { loadFile(currentFile); return; }
            fetch(bust(cfg.restRoot + '/version-content?file=' + encodeURIComponent(currentFile)
                  + '&timestamp=' + encodeURIComponent(ts)), {
                headers: { 'X-WP-Nonce': cfg.restNonce }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && typeof data.source === 'string') {
                    setSource(data.source);
                    schedulePreviewUpdate();
                }
            });
        });

        restoreBtn.addEventListener('click', function () {
            var ts = versionSelect.value;
            if (!ts) {
                setStatus(saveStatus, 'Pick a prior version first', 'is-err');
                return;
            }
            if (!window.confirm('Restore version ' + formatTs(ts) + ' as the published file?')) return;
            setStatus(saveStatus, 'Restoring…', '');
            fetch(bust(cfg.restRoot + '/restore'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce },
                body: JSON.stringify({ file: currentFile, timestamp: ts })
            })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
            .then(function (res) {
                if (res.ok && res.body && res.body.restored) {
                    setStatus(saveStatus, '✓ Restored', 'is-ok');
                    loadFile(currentFile);
                } else {
                    var msg = (res.body && res.body.message) ? res.body.message : 'Restore failed';
                    setStatus(saveStatus, '✗ ' + msg, 'is-err');
                }
            });
        });

        discardBtn.addEventListener('click', function () {
            discardPreview().then(function () {
                loadFile(currentFile);
                setStatus(saveStatus, '✓ Discarded preview', 'is-ok');
            });
        });

        resetBtn.addEventListener('click', function () {
            if (!window.confirm(
                'Reset ' + currentFile + ' to the factory default?\n\n' +
                'Your current published version will be saved to history first, ' +
                'so you can restore it via the Version dropdown.'
            )) return;
            setStatus(saveStatus, 'Resetting…', '');
            resetBtn.disabled = true;
            fetch(bust(cfg.restRoot + '/reset'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce },
                body: JSON.stringify({ file: currentFile })
            })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
            .then(function (res) {
                resetBtn.disabled = false;
                if (res.ok && res.body && res.body.reset) {
                    setStatus(saveStatus, '✓ Reset to factory', 'is-ok');
                    loadFile(currentFile);
                } else {
                    var msg = (res.body && res.body.message) ? res.body.message : 'Reset failed';
                    setStatus(saveStatus, '✗ ' + msg, 'is-err');
                }
            })
            .catch(function () { resetBtn.disabled = false; setStatus(saveStatus, '✗ Network error', 'is-err'); });
        });

        saveBtn.addEventListener('click', function () {
            var src = getSource();
            setStatus(saveStatus, 'Saving…', '');
            saveBtn.disabled = true;
            fetch(bust(cfg.restRoot + '/save'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce },
                body: JSON.stringify({ file: currentFile, source: src })
            })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
            .then(function (res) {
                saveBtn.disabled = false;
                if (res.ok && res.body && res.body.saved) {
                    setStatus(saveStatus, '✓ Saved', 'is-ok');
                    refreshVersions();
                    stagedSource = '';
                    stagedFor    = '';
                    reloadIframe();
                } else {
                    var msg = (res.body && res.body.message) ? res.body.message : 'Save failed';
                    setStatus(saveStatus, '✗ ' + msg, 'is-err');
                }
            })
            .catch(function () { saveBtn.disabled = false; setStatus(saveStatus, '✗ Network error', 'is-err'); });
        });

        /* ----- AI chat ----- */
        aiForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var msg = (aiInput.value || '').trim();
            if (!msg) return;
            aiInput.value = '';
            appendAiMsg('user', msg);
            convoHistory.push({ role: 'user', content: msg });
            aiSendBtn.disabled = true;
            var thinkingNode = appendAiMsg('assistant', 'Thinking…');

            var aiStarted = Date.now();
            var aiTicker = setInterval(function () {
                var s = Math.round((Date.now() - aiStarted) / 1000);
                thinkingNode.textContent = 'Thinking… (' + s + 's)';
            }, 1000);

            // 90-second client-side ceiling. Server can take 15-30s for
            // a model call; AbortController makes the timeout explicit
            // rather than waiting on browser/proxy defaults that vary.
            var ctrl  = new AbortController();
            var abort = setTimeout(function () { ctrl.abort(); }, 90000);

            fetch(bust(cfg.restRoot + '/ai'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce },
                body: JSON.stringify({
                    file:    currentFile,
                    message: msg,
                    history: convoHistory.slice(0, -1),
                    source:  getSource()
                }),
                signal: ctrl.signal,
                credentials: 'same-origin'
            })
            .then(function (r) {
                clearTimeout(abort);
                return r.text().then(function (text) {
                    var body;
                    try { body = JSON.parse(text); } catch (e) { body = { message: text || ('HTTP ' + r.status) }; }
                    return { ok: r.ok, status: r.status, body: body };
                });
            })
            .then(function (res) {
                clearInterval(aiTicker);
                aiSendBtn.disabled = false;
                if (!res.ok || !res.body) {
                    thinkingNode.classList.add('error');
                    thinkingNode.textContent =
                        '✗ ' + (res.body && res.body.message ? res.body.message : 'AI request failed') +
                        ' (HTTP ' + (res.status || '?') + ')';
                    return;
                }
                var summary = res.body.summary || 'Edit applied.';
                var newSrc  = res.body.source || '';
                thinkingNode.innerHTML = '<div class="zce-ai-summary"></div><div class="zce-ai-body"></div>';
                thinkingNode.querySelector('.zce-ai-summary').textContent = summary;
                thinkingNode.querySelector('.zce-ai-body').textContent =
                    newSrc ? 'Editor and preview updated. Save when you\'re happy.' : 'No code change suggested.';
                if (newSrc) {
                    setSource(newSrc);
                    schedulePreviewUpdate();
                }
                convoHistory.push({ role: 'assistant', content: summary });
            })
            .catch(function (err) {
                clearTimeout(abort);
                clearInterval(aiTicker);
                aiSendBtn.disabled = false;
                thinkingNode.classList.add('error');
                var name = (err && err.name) || '';
                var msg  = (err && err.message) || '';
                if (name === 'AbortError') {
                    thinkingNode.textContent = '✗ Timed out after 90s — the model didn\'t respond. Try again or shorten the request.';
                } else {
                    thinkingNode.textContent = '✗ Network error: ' + (msg || name || 'unknown');
                }
                if (window.console) console.error('[zen-cortext chat-editor] /ai fetch failed:', err);
            });
        });

        function appendAiMsg(role, text) {
            var d = document.createElement('div');
            d.className = 'zce-ai-msg ' + role;
            d.textContent = text;
            aiHistory.appendChild(d);
            aiHistory.scrollTop = aiHistory.scrollHeight;
            return d;
        }

        // Boot — load first file by default.
        var firstFile = Object.keys(files)[0];
        if (firstFile) {
            fileSelect.value = firstFile;
            loadFile(firstFile);
        }
        applyDeviceCap();
    }
})();
