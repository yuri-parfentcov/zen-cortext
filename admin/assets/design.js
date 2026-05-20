/* Zen Cortext — Design admin page (color configurator).
   Extracted from the old Chat Template Editor's Colors tab so a
   design-time surface can ship without the code-editor bundle. */
(function () {
    'use strict';

    var cfg = (typeof window.zenCortextDesign === 'object' && window.zenCortextDesign) || {};

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('zen-cortext-design');
        if (!root) return;
        initColorsPanel(root);
        initFloatButton(root);
    });

    function initColorsPanel(root) {
        var rows     = root.querySelectorAll('.zce-color-row');
        var saved    = (cfg.savedColors && typeof cfg.savedColors === 'object') ? cfg.savedColors : {};
        var miniChat = root.querySelector('.zce-mini-chat');
        var statusEl = document.getElementById('zce-colors-status');

        rows.forEach(function (row) {
            var token   = row.getAttribute('data-token');
            var picker  = row.querySelector('.zce-color-picker');
            var hexInp  = row.querySelector('.zce-color-hex');
            var resetBt = row.querySelector('.zce-color-reset');
            var def     = picker.getAttribute('data-default') || '#000000';
            var initial = saved[token] || def;
            applyToInputs(initial);

            picker.addEventListener('input', function () {
                hexInp.value = picker.value;
                applyToPreview(token, picker.value);
            });
            hexInp.addEventListener('input', function () {
                if (/^#[0-9a-fA-F]{3,8}$/.test(hexInp.value)) {
                    picker.value = hexInp.value.length === 4 ? hexInp.value : hexInp.value.slice(0, 7);
                    applyToPreview(token, hexInp.value);
                }
            });
            resetBt.addEventListener('click', function () {
                applyToInputs(def);
                applyToPreview(token, def);
            });

            applyToPreview(token, initial);

            function applyToInputs(value) {
                hexInp.value  = value;
                picker.value  = value.length === 4 ? value : value.slice(0, 7);
            }
        });

        // Live preview: write tokens straight onto the mini-chat root
        // so adjustments repaint instantly without the round-trip to
        // the server.
        function applyToPreview(token, value) {
            if (!miniChat) return;
            miniChat.style.setProperty(token, value);
        }

        document.getElementById('zce-colors-save').addEventListener('click', saveColors);
        document.getElementById('zce-colors-reset-all').addEventListener('click', function () {
            rows.forEach(function (row) {
                row.querySelector('.zce-color-reset').click();
            });
        });

        // Hover-highlight: when the admin hovers an element in the live
        // preview, light up the picker rows on the left that actually
        // drive that element's colors. Mapping lives here (not in HTML
        // data-attributes) so the preview markup stays clean and the
        // map is one canonical source — updating chat.css color rules
        // means updating this map.
        //
        // Selectors are ordered most-specific-first so a `.zc-chip.selected`
        // match wins over the plain `.zc-chip` rule below it.
        var HOVER_MAP = [
            { sel: '.zcp-rail-btn-prefix',                          tokens: ['--zc-text-muted'] },
            { sel: '.zcp-rail-btn-label',                           tokens: ['--zc-text-strong'] },
            { sel: '.zcp-rail-btn',                                 tokens: ['--zc-surface', '--zc-border', '--zc-text', '--zc-accent', '--zc-assistant-bg'] },
            { sel: '.zc-chip.selected, .zc-message-chip.selected',  tokens: ['--zc-primary', '--zc-chip-text'] },
            { sel: '.zc-chip, .zc-message-chip',                    tokens: ['--zc-accent', '--zc-accent-hover', '--zc-chip-text'] },
            { sel: '.zc-send',                                      tokens: ['--zc-primary', '--zc-primary-hover'] },
            { sel: '.zc-input',                                     tokens: ['--zc-surface', '--zc-text', '--zc-border', '--zc-primary'] },
            { sel: '.zc-share-button, .zc-email-button, .zc-delete-button', tokens: ['--zc-accent', '--zc-accent-hover', '--zc-primary-hover'] },
            { sel: '.zc-typing-bubble, .zc-typing',                 tokens: ['--zc-assistant-bg'] },
            { sel: '.zc-message.user .zc-bubble',                   tokens: ['--zc-user-bg', '--zc-user-text'] },
            { sel: '.zc-message.assistant .zc-bubble',              tokens: ['--zc-assistant-bg', '--zc-text'] },
            { sel: '.zc-intro-name',                                tokens: ['--zc-text-strong'] },
            { sel: '.zc-intro-role',                                tokens: ['--zc-text-muted'] },
            { sel: '.zc-intro-body',                                tokens: ['--zc-text'] },
            { sel: '.zc-intro-link',                                tokens: ['--zc-accent'] },
            { sel: '.zc-intro-card',                                tokens: ['--zc-surface', '--zc-border', '--zc-text'] },
            { sel: '.zc-hero .accent',                              tokens: ['--zc-accent', '--zc-user-text'] },
            { sel: '.zc-hero h2',                                   tokens: ['--zc-text-strong'] },
            { sel: '.zc-hero',                                      tokens: ['--zc-text', '--zc-text-strong', '--zc-accent'] },
        ];

        // Cache row lookups by token for O(1) highlight toggling. Tokens
        // referenced in HOVER_MAP that aren't in color_tokens() (typos,
        // future-proofing) silently miss — that's fine, no errors.
        var rowByToken = {};
        rows.forEach(function (row) {
            rowByToken[row.getAttribute('data-token')] = row;
        });

        function highlightTokens(tokens, on) {
            tokens.forEach(function (t) {
                var row = rowByToken[t];
                if (row) row.classList.toggle('is-zce-highlight', on);
            });
        }

        function findMatch(el) {
            // Walk up from the hovered element to the preview root,
            // checking each selector in HOVER_MAP order. First match
            // wins so most-specific rules can shadow generic ones.
            while (el && el !== miniChat && el.nodeType === 1) {
                for (var i = 0; i < HOVER_MAP.length; i++) {
                    if (el.matches(HOVER_MAP[i].sel)) return HOVER_MAP[i];
                }
                el = el.parentElement;
            }
            return null;
        }

        var lastMatch = null;
        if (miniChat) {
            miniChat.addEventListener('mouseover', function (e) {
                var m = findMatch(e.target);
                if (m === lastMatch) return;
                if (lastMatch) highlightTokens(lastMatch.tokens, false);
                if (m) highlightTokens(m.tokens, true);
                lastMatch = m;
            });
            miniChat.addEventListener('mouseleave', function () {
                if (lastMatch) {
                    highlightTokens(lastMatch.tokens, false);
                    lastMatch = null;
                }
            });
        }

        function saveColors() {
            var payload = {};
            rows.forEach(function (row) {
                var token = row.getAttribute('data-token');
                var value = row.querySelector('.zce-color-hex').value.trim();
                if (/^#[0-9a-fA-F]{3,8}$/.test(value)) payload[token] = value;
            });
            setStatus(statusEl, 'Saving…', '');
            // Cache-bust the REST URL — same Varnish workaround the old
            // chat-editor.js used: Varnish on this host strips cookies
            // for URLs that look static, so add a no-store query param.
            var url = cfg.restRoot + '/colors';
            url += (url.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now();
            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   cfg.restNonce
                },
                body: JSON.stringify({ colors: payload })
            })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
            .then(function (res) {
                if (res.ok && res.body && res.body.saved) {
                    setStatus(statusEl, '✓ Saved', 'is-ok');
                } else {
                    setStatus(statusEl, '✗ Save failed', 'is-err');
                }
            })
            .catch(function () { setStatus(statusEl, '✗ Network error', 'is-err'); });
        }
    }

    function setStatus(el, text, cls) {
        if (!el) return;
        el.classList.remove('is-ok', 'is-err');
        if (cls) el.classList.add(cls);
        el.textContent = text;
    }

    /* ============================================================
       Float button section
       ============================================================ */
    function initFloatButton(root) {
        if (!document.getElementById('zcfb-enabled')) return;

        var iconInput   = document.getElementById('zcfb-icon-url');
        var defaults    = cfg.floatDefaults || {};

        // ---- Live preview ---------------------------------------------
        // The .zcfb-preview-viewport block in _design-tab.php mirrors the
        // actual rendered float button. Every input that affects visuals
        // (icon URL, color, position radios, padding) calls refresh().
        var previewBtn = document.getElementById('zcfb-preview-btn');
        var previewImg = document.getElementById('zcfb-preview-img');

        function pickRadio(name) {
            var el = root.querySelector('input[name="' + name + '"]:checked');
            return el ? el.value : '';
        }

        function refreshPreview() {
            if (!previewBtn) return;
            var enabled    = document.getElementById('zcfb-enabled').checked;
            var vertical   = pickRadio('zcfb-vertical')   || defaults.vertical   || 'bottom';
            var horizontal = pickRadio('zcfb-horizontal') || defaults.horizontal || 'right';
            var paddingRaw = parseInt(document.getElementById('zcfb-padding').value, 10);
            var padding    = isNaN(paddingRaw) ? (defaults.padding || 24) : Math.max(0, Math.min(200, paddingRaw));
            var hexVal = colorHex ? (colorHex.value || '').trim() : '';
            if (!/^#[0-9a-fA-F]{3,8}$/.test(hexVal)) hexVal = (defaults.button_color || '#ffffff');
            var iconUrl = (iconInput.value || '').trim() || (defaults.icon_url || '');

            // Reset position edges so a switch from top→bottom or
            // left→right doesn't leave a stale rule pinning the button.
            previewBtn.style.top    = '';
            previewBtn.style.bottom = '';
            previewBtn.style.left   = '';
            previewBtn.style.right  = '';
            previewBtn.style.transform = '';

            // Scale padding into the preview viewport: real range is
            // 0-200px against the actual page; the preview frame is only
            // 180px tall, so 1:1 would let the button escape. Halve the
            // value, clamp to 60px so it always stays inside.
            var p = Math.min(60, Math.round(padding / 2));

            if (vertical === 'top')         previewBtn.style.top    = p + 'px';
            else if (vertical === 'bottom') previewBtn.style.bottom = p + 'px';
            else { previewBtn.style.top = '50%'; previewBtn.style.transform = 'translateY(-50%)'; }

            if (horizontal === 'left') previewBtn.style.left  = p + 'px';
            else                       previewBtn.style.right = p + 'px';

            previewBtn.style.background = hexVal;
            previewBtn.classList.toggle('is-disabled', !enabled);

            if (iconUrl) {
                previewImg.src = iconUrl;
                previewImg.style.display = '';
            } else {
                previewImg.removeAttribute('src');
                previewImg.style.display = 'none';
            }
        }

        // WP media uploader for the icon picker. Reuses the global
        // wp.media() that wp_enqueue_media() lit up server-side, so the
        // admin gets the standard library modal — no custom upload UI.
        document.getElementById('zcfb-icon-pick').addEventListener('click', function (e) {
            e.preventDefault();
            if (!window.wp || !window.wp.media) {
                alert('Media library not available. Paste a URL directly.');
                return;
            }
            var frame = window.wp.media({
                title: 'Pick float-button icon',
                button: { text: 'Use this image' },
                multiple: false,
                library: { type: 'image' }
            });
            frame.on('select', function () {
                var att = frame.state().get('selection').first().toJSON();
                iconInput.value = att.url;
                refreshPreview();
            });
            frame.open();
        });

        document.getElementById('zcfb-icon-default').addEventListener('click', function (e) {
            e.preventDefault();
            iconInput.value = defaults.icon_url || '';
            refreshPreview();
        });

        document.getElementById('zcfb-save').addEventListener('click', saveFloatButton);

        // Two-way sync between the native color picker and the hex
        // text input — picker only emits #RRGGBB, text input lets the
        // admin paste any valid 3/6/8-digit hex (including alpha).
        var colorPick = document.getElementById('zcfb-button-color');
        var colorHex  = document.getElementById('zcfb-button-color-hex');
        if (colorPick && colorHex) {
            colorPick.addEventListener('input', function () {
                colorHex.value = colorPick.value;
                refreshPreview();
            });
            colorHex.addEventListener('input', function () {
                if (/^#[0-9a-fA-F]{3,8}$/.test(colorHex.value)) {
                    colorPick.value = colorHex.value.length === 4
                        ? colorHex.value
                        : colorHex.value.slice(0, 7);
                }
                refreshPreview();
            });
        }

        // Wire every other field that influences the preview.
        iconInput.addEventListener('input', refreshPreview);
        document.getElementById('zcfb-enabled').addEventListener('change', refreshPreview);
        document.getElementById('zcfb-padding').addEventListener('input', refreshPreview);
        root.querySelectorAll('input[name="zcfb-vertical"], input[name="zcfb-horizontal"]').forEach(function (el) {
            el.addEventListener('change', refreshPreview);
        });

        // First paint from current option state.
        refreshPreview();

        function collect() {
            var pickRadio = function (name) {
                var el = root.querySelector('input[name="' + name + '"]:checked');
                return el ? el.value : '';
            };
            var hexVal = colorHex ? (colorHex.value || '').trim() : '';
            if (!/^#[0-9a-fA-F]{3,8}$/.test(hexVal)) {
                hexVal = (defaults.button_color || '#ffffff');
            }
            return {
                enabled:        document.getElementById('zcfb-enabled').checked ? 1 : 0,
                vertical:       pickRadio('zcfb-vertical')   || defaults.vertical   || 'bottom',
                horizontal:     pickRadio('zcfb-horizontal') || defaults.horizontal || 'right',
                padding:        parseInt(document.getElementById('zcfb-padding').value, 10) || 0,
                button_color:   hexVal,
                icon_url:       (iconInput.value || '').trim(),
                hover_text:     document.getElementById('zcfb-hover-text').value || '',
                target_page_id: parseInt(document.getElementById('zcfb-target').value, 10) || 0
            };
        }

        function saveFloatButton() {
            var statusEl = document.getElementById('zcfb-status');
            setStatus(statusEl, 'Saving…', '');
            var url = cfg.restRoot + '/float-button';
            url += (url.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now();
            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   cfg.restNonce
                },
                body: JSON.stringify({ settings: collect() })
            })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
            .then(function (res) {
                if (res.ok && res.body && res.body.saved) {
                    setStatus(statusEl, '✓ Saved', 'is-ok');
                } else {
                    setStatus(statusEl, '✗ Save failed', 'is-err');
                }
            })
            .catch(function () { setStatus(statusEl, '✗ Network error', 'is-err'); });
        }
    }
})();
