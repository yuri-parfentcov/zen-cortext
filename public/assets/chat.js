/* Zen Cortext — frontend logic. Reads zenCortextConfig from wp_localize_script. */
(function () {
    'use strict';

    function init() {
        const root = document.getElementById('zen-cortext-root');
        if (!root) return;

        const cfg = (typeof window.zenCortextConfig === 'object' && window.zenCortextConfig) || {};
        const restUrl = cfg.restUrl || '';
        const restRoot = (cfg.restRoot || (restUrl ? restUrl.replace(/\/send\/?$/, '') : '')).replace(/\/$/, '');
        const attributionContextUrl = cfg.attributionContextUrl || (restRoot ? restRoot + '/attribution-context' : '');
        // Mutable: gets replaced by the matched attribution-context invite
        // before the intro typewriter runs (when there's a match).
        let secondMessage = (cfg.welcomeMessage || '').toString();
        // When a matched rule has BOTH an invite_message and an attached
        // survey, secondMessage stays as the rule's welcome and the survey's
        // first question is rendered as a follow-up bubble. When there's no
        // rule welcome (survey-only), the question takes secondMessage's slot
        // and surveyQuestion stays empty.
        let surveyQuestion = '';

        const chat = document.getElementById('zc-chat');
        const input = document.getElementById('zc-input');
        const sendBtn = document.getElementById('zc-send');
        const typing = document.getElementById('zc-typing');
        const chipsEl = document.getElementById('zc-chips');
        const shareWrap = document.getElementById('zc-share');
        const shareBtn = document.getElementById('zc-share-button');
        const shareStatus = document.getElementById('zc-share-status');
        const deleteBtn = document.getElementById('zc-delete-button');
        const emailBtn = document.getElementById('zc-email-button');
        const emailForm = document.getElementById('zc-email-form');
        const emailInput = document.getElementById('zc-email-input');
        const emailSubmit = document.getElementById('zc-email-submit');
        const emailCancel = document.getElementById('zc-email-cancel');
        const emailStatus = document.getElementById('zc-email-status');

        // Prefill cache for the "Email me a copy" form. Hydrated by the
        // chat replay endpoint when the visitor previously submitted a
        // contact form OR self-archived once already this session.
        let cachedLeadEmail = '';

        const messages = [];
        let streaming = false;
        let userScrolledAway = false;
        let leadFormRendered = false; // one inline form per chat session
        let leadSubmitted    = false; // becomes true after the lead endpoint confirms

        /* ---------- sound notification ---------- */
        var audioCtx = null;
        function playNotificationSound() {
            try {
                if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                var now = audioCtx.currentTime;
                var osc1 = audioCtx.createOscillator();
                var osc2 = audioCtx.createOscillator();
                var gain = audioCtx.createGain();
                osc1.type = 'sine'; osc1.frequency.value = 880;
                osc2.type = 'sine'; osc2.frequency.value = 1100;
                gain.gain.setValueAtTime(0.15, now);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.4);
                osc1.connect(gain); osc2.connect(gain); gain.connect(audioCtx.destination);
                osc1.start(now); osc1.stop(now + 0.15);
                osc2.start(now + 0.15); osc2.stop(now + 0.4);
            } catch (e) {}
        }

        /* ---------- admin takeover state ---------- */
        let adminMode = false;          // true when an admin has taken over
        let adminName = '';
        let pollTimer = null;
        let statusTimer = null;         // periodic status check (slower than event poll)
        let lastPollEventId = 0;
        let inviteSent = false;
        let invitableUsers = null;      // null = not yet fetched
        // If an invited admin doesn't take the chat over within this window,
        // we proactively offer the lead-capture form so the visitor isn't
        // left waiting indefinitely. 3 minutes — long enough that an admin
        // who saw the push can finish a sentence elsewhere and tap in,
        // short enough that an offline admin doesn't strand the visitor.
        const INVITE_FALLBACK_MS = 180000;
        let inviteFallbackTimer = null;
        let inviteTarget = null;        // user object passed to triggerAutoInvite

        /* ---------- chat session uid + attribution ---------- */
        // SLOTS_KEY stores a per-attribution-rule map of the visitor's own
        // most-recent uids: { "_general": "<uid>", "rule_5": "<uid>", ... }.
        // This keeps an attribution-context chat (e.g. UTM-tagged campaign)
        // separate from the visitor's general/no-UTM chat, so context the AI
        // sees on turn 1 of a chat doesn't drift on later turns.
        // OWNERS_KEY is a per-uid map of owner_tokens — only the originator
        // has a token for a given uid, so visitors who land on a ?chat= share
        // link without a matching local token are read-only.
        // INTRO_SHOWN_KEY's value is the uid that finished the typewriter,
        // so it's already self-scoped per uid (no slotting needed).
        const SLOTS_KEY         = 'zenCortextChats';
        const LEGACY_STORAGE_KEY = 'zenCortextChat';
        const OWNERS_KEY        = 'zenCortextOwners';

        function readSlotsMap() {
            try {
                const raw = localStorage.getItem(SLOTS_KEY) || '';
                if (!raw) return {};
                const obj = JSON.parse(raw);
                return (obj && typeof obj === 'object') ? obj : {};
            } catch (e) { return {}; }
        }
        function writeSlotsMap(map) {
            try { localStorage.setItem(SLOTS_KEY, JSON.stringify(map || {})); } catch (e) {}
        }
        function readSlot(key) {
            if (!key) return '';
            const v = readSlotsMap()[key];
            return (typeof v === 'string' && /^[a-zA-Z0-9_-]{8,64}$/.test(v)) ? v : '';
        }
        function writeSlot(key, uid) {
            if (!key || !uid) return;
            const map = readSlotsMap();
            map[key] = uid;
            writeSlotsMap(map);
        }
        function clearSlot(key) {
            if (!key) return;
            const map = readSlotsMap();
            if (map[key]) { delete map[key]; writeSlotsMap(map); }
        }

        // One-time migration from the old single-key storage. If the visitor
        // had a saved uid before this code shipped, we don't know which rule
        // it was originated under, so park it in `_general` — acceptable
        // hiccup for one page load.
        (function migrateLegacyStorage() {
            try {
                const legacy = (localStorage.getItem(LEGACY_STORAGE_KEY) || '').trim();
                if (legacy && /^[a-zA-Z0-9_-]{8,64}$/.test(legacy)) {
                    const map = readSlotsMap();
                    if (!map['_general']) {
                        map['_general'] = legacy;
                        writeSlotsMap(map);
                    }
                }
                localStorage.removeItem(LEGACY_STORAGE_KEY);
            } catch (e) {}
        })();

        function generateUid() {
            // 32 chars from a base36-ish space, no hyphens, URL-safe.
            const arr = new Uint8Array(24);
            (window.crypto || window.msCrypto).getRandomValues(arr);
            let s = '';
            for (let i = 0; i < arr.length; i++) {
                s += (arr[i] % 36).toString(36);
            }
            return s.slice(0, 32);
        }

        // 48 chars of high-entropy random; longer than the uid so it's
        // costly to guess even if an attacker knows a visitor's uid.
        function generateOwnerToken() {
            const arr = new Uint8Array(36);
            (window.crypto || window.msCrypto).getRandomValues(arr);
            let s = '';
            for (let i = 0; i < arr.length; i++) {
                s += (arr[i] % 36).toString(36);
            }
            return s.slice(0, 48);
        }

        function readOwnersMap() {
            try {
                const raw = localStorage.getItem(OWNERS_KEY) || '';
                if (!raw) return {};
                const obj = JSON.parse(raw);
                return (obj && typeof obj === 'object') ? obj : {};
            } catch (e) { return {}; }
        }
        function writeOwnersMap(map) {
            try { localStorage.setItem(OWNERS_KEY, JSON.stringify(map || {})); } catch (e) {}
        }
        function getOwnerToken(uid) {
            if (!uid) return '';
            const map = readOwnersMap();
            const v = map[uid];
            return (typeof v === 'string' && /^[a-zA-Z0-9_-]{16,128}$/.test(v)) ? v : '';
        }
        function setOwnerToken(uid, token) {
            if (!uid || !token) return;
            const map = readOwnersMap();
            map[uid] = token;
            writeOwnersMap(map);
        }
        function clearOwnerToken(uid) {
            if (!uid) return;
            const map = readOwnersMap();
            if (map[uid]) { delete map[uid]; writeOwnersMap(map); }
        }

        function readQueryParam(name) {
            try {
                const u = new URL(window.location.href);
                const v = u.searchParams.get(name);
                return v ? v.trim() : '';
            } catch (e) { return ''; }
        }

        function readCookie(name) {
            const re = new RegExp('(?:^|;\\s*)' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)');
            const m = document.cookie.match(re);
            return m ? decodeURIComponent(m[1]) : '';
        }

        // Resolve the chat uid:
        //   - ?chat= URL param wins (sync, here) and bypasses slots so a
        //     shared link always works regardless of the viewer's attribution.
        //   - Otherwise, the no-URL branch is deferred: chatUid is resolved
        //     after fetchAttributionContext() returns so we can pick the
        //     right slot (e.g. `_general` vs `rule_5`). See loadFromSlot().
        // currentSlotKey stays empty for the URL branch — URL-replay should
        // not overwrite any slot, since we don't know which rule the linked
        // chat was originally created under.
        let chatUid = '';
        let ownerToken = '';
        let isReplaying = false;
        let isReturningVisitor = false;
        let viewOnlyMode = false;
        let currentSlotKey = '';

        // chatReady resolves once chatUid + ownerToken are bound. send()
        // awaits this so a visitor who types and hits Enter within the
        // 400ms attribution-lookup window doesn't fire a request with an
        // empty chat_uid. URL-share visitors get a synchronous resolve;
        // no-URL visitors get an async resolve from loadFromSlot().
        let _chatReadyResolve;
        const chatReady = new Promise(function (r) { _chatReadyResolve = r; });
        function markChatReady() { _chatReadyResolve(); }

        const urlChatUid = readQueryParam('chat');
        if (urlChatUid && /^[a-zA-Z0-9_-]{8,64}$/.test(urlChatUid)) {
            chatUid = urlChatUid;
            isReplaying = true;
            // Token presence is the credential. A third party landing on
            // ?chat=ABC without a matching owner_token in localStorage is
            // read-only.
            ownerToken = getOwnerToken(chatUid);
            if (!ownerToken) viewOnlyMode = true;
            markChatReady();
        }

        // Attribution captured once per page load and sent with every request.
        // Server upserts and only fills empty fields, so resending is safe.
        const attribution = {
            referrer:     document.referrer || '',
            landing_page: window.location.href || '',
            utm_source:   readQueryParam('utm_source'),
            utm_medium:   readQueryParam('utm_medium'),
            utm_campaign: readQueryParam('utm_campaign'),
            utm_term:     readQueryParam('utm_term'),
            utm_content:  readQueryParam('utm_content'),
            gclid:        readQueryParam('gclid'),
            msclkid:      readQueryParam('msclkid'),
            fbc:          readCookie('_fbc'),
            fbp:          readCookie('_fbp'),
        };

        /* ---------- visitor session uid ----------
           Read-only here — the site-wide inline beacon printed in
           wp_footer (Zen_Cortext_Shortcode::print_session_beacon) is
           responsible for minting / extending the session and writing
           localStorage.zenCortextSession. The chat widget just reads
           the uid so /send payloads can carry it for attach_chat. */
        const SESSION_KEY = 'zenCortextSession';
        let sessionUid = '';

        function readSession() {
            try {
                const raw = localStorage.getItem(SESSION_KEY) || '';
                if (!raw) return null;
                const obj = JSON.parse(raw);
                return (obj && typeof obj === 'object' && typeof obj.uid === 'string') ? obj : null;
            } catch (e) { return null; }
        }
        function touchSession() {
            const s = readSession();
            if (!s) return;
            s.last_seen = Date.now();
            try { localStorage.setItem(SESSION_KEY, JSON.stringify(s)); } catch (e) {}
        }
        (function pickUpSession() {
            const stored = readSession();
            if (stored && stored.uid) sessionUid = stored.uid;
        })();

        // Strip ?chat= from the address bar so visitors don't see it; we
        // already pulled the value into chatUid above and persisted it.
        if (isReplaying && window.history && window.history.replaceState) {
            try {
                const u = new URL(window.location.href);
                u.searchParams.delete('chat');
                window.history.replaceState({}, '', u.toString());
            } catch (e) {}
        }

        /* ---------- helpers ---------- */
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function renderMarkdown(text) {
            let html = escapeHtml(text);
            html = html.replace(/^### (.+)$/gm, '<h3>$1</h3>');
            html = html.replace(/^## (.+)$/gm, '<h2>$1</h2>');
            html = html.replace(/^# (.+)$/gm, '<h1>$1</h1>');
            html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
            html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
            html = html.replace(/^- (.+)$/gm, '<li>$1</li>');
            html = html.replace(/(<li>.*<\/li>\n?)+/g, '<ul>$&</ul>');
            html = html.replace(/\n\n/g, '</p><p>');
            html = '<p>' + html + '</p>';
            html = html.replace(/<p>\s*<\/p>/g, '');
            html = html.replace(/<p>\s*(<[hul])/g, '$1');
            html = html.replace(/(<\/[hul]l?>)\s*<\/p>/g, '$1');
            return html;
        }

        // Parse AI directive markers out of a streamed assistant message.
        // Recognizes the marker types below, each on its own line:
        //   [chip] Some follow-up prompt              — follow-up chip
        //   [invite: Iuliia]                          — attempt to invite a team member
        //   [contact_form]                            — render the lead-capture form
        //   [contact_form: Iuliia]                    — form with a direct-invite button
        //   [survey_options: opt A | opt B | opt C]         — single-select chips
        //   [survey_options:multi: opt A | opt B | opt C]   — multi-select chips
        //                                                     (toggle + Done button)
        // Returns { body, chips, invite, contactForm, surveyOptions, surveyOptionsType }.
        // `body` is the text with all marker lines stripped.
        function extractChips(text) {
            const lines = text.split('\n');
            const chips = [];
            const surveyOptions = [];
            let surveyOptionsType = 'single';
            const bodyLines = [];
            let invite = '';
            let contactForm = false;
            for (const line of lines) {
                const mChip    = line.match(/^\[chip\]\s*(.+)$/);
                const mInvite  = line.match(/^\[invite:\s*([^\]]+)\]\s*$/i);
                const mForm    = line.match(/^\[contact_form(?::\s*([^\]]*))?\]\s*$/i);
                // Optional flag (multi/single) before the colon-separated options.
                // Defaults to 'single' when the flag is omitted, so legacy markers
                // keep their existing one-tap-submits behavior.
                const mSurvey  = line.match(/^\[survey_options(?::(\w+))?:\s*([^\]]+)\]\s*$/i);
                if (mChip)    { chips.push(mChip[1].trim()); continue; }
                if (mInvite)  { invite = mInvite[1].trim(); continue; }
                if (mForm)    {
                    contactForm = true;
                    // Optional target hint for contact_form takes precedence
                    // over a bare [invite:] on the same message, since the
                    // AI explicitly asked for the form flow here.
                    if (mForm[1] && mForm[1].trim() !== '') invite = mForm[1].trim();
                    continue;
                }
                if (mSurvey) {
                    const flag = (mSurvey[1] || '').toLowerCase();
                    if (flag === 'multi') surveyOptionsType = 'multi';
                    mSurvey[2].split('|').forEach(function (opt) {
                        const v = opt.trim();
                        if (v !== '') surveyOptions.push(v);
                    });
                    continue;
                }
                bodyLines.push(line);
            }
            while (bodyLines.length && bodyLines[bodyLines.length - 1].trim() === '') bodyLines.pop();
            return {
                body: bodyLines.join('\n'),
                chips: chips,
                invite: invite,
                contactForm: contactForm,
                surveyOptions: surveyOptions,
                surveyOptionsType: surveyOptionsType
            };
        }

        // Case-insensitive lookup of an invitable by display name or first
        // name. The AI writes markers like "[invite: Iuliia]" using the
        // Expertise notes in the system prompt, so we have to tolerate
        // both full names and first-name-only references.
        function findInvitable(nameQuery) {
            if (!nameQuery || !Array.isArray(invitableUsers)) return null;
            const q = nameQuery.trim().toLowerCase();
            if (!q) return null;
            for (const u of invitableUsers) {
                const display = (u.display_name || '').toLowerCase();
                if (!display) continue;
                if (display === q) return u;
                const first = display.split(/\s+/)[0] || '';
                if (first === q) return u;
                if (display.indexOf(q) !== -1 || q.indexOf(first) === 0) return u;
            }
            return null;
        }

        // Apply the non-visual directives (invite + contact_form) emitted
        // by the AI. Called after the assistant message finishes streaming
        // and its body + chips have rendered. Branches:
        //   - contact_form + offline/unknown target → render inline form
        //   - invite + online/away target          → auto-invite (no form)
        //   - invite + offline target              → inline form targeted at them
        function applyActions(actions) {
            if (leadSubmitted) return;
            if (!actions) return;
            const wantsForm   = actions.contactForm;
            const wantsInvite = actions.invite && actions.invite.trim() !== '';
            if (!wantsForm && !wantsInvite) return;

            const target = wantsInvite ? findInvitable(actions.invite) : null;
            const status = target ? (target.status || 'offline') : null;

            // Auto-invite path — only when AI said [invite: Name] WITHOUT
            // also saying [contact_form]. The form marker is the explicit
            // "capture their contact instead" override.
            if (!wantsForm && target && (status === 'online' || status === 'away' || status === 'reachable')) {
                triggerAutoInvite(target);
                return;
            }

            // Otherwise fall through to the inline form. If we have a
            // target but they're offline, the form explains that.
            renderLeadForm(target);
        }

        function triggerAutoInvite(user) {
            if (!restRoot || !chatUid) return;
            if (viewOnlyMode) return;
            if (inviteSent) return;
            inviteSent = true;
            inviteTarget = user;
            showInvitePendingBanner(user.display_name || 'a consultant');
            startPolling();
            scheduleInviteFallback();
            fetch(restRoot + '/chat/' + encodeURIComponent(chatUid) + '/invite', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ user_id: parseInt(user.id, 10), owner_token: ownerToken })
            }).catch(function () {
                inviteSent = false;
                clearInviteFallback();
            });
        }

        // Schedule the "admin didn't show up" fallback. Cleared by
        // enterAdminMode() / exitAdminMode() / a successful lead submit /
        // a fetch failure on the invite POST itself. No-op if already armed.
        function scheduleInviteFallback() {
            if (inviteFallbackTimer) return;
            inviteFallbackTimer = setTimeout(function () {
                inviteFallbackTimer = null;
                // If admin actually joined while the timer was pending,
                // do nothing — enterAdminMode normally clears the timer
                // first, but guard here too in case of a race.
                if (adminMode) return;
                if (leadSubmitted || leadFormRendered) return;
                showInviteFallbackNotice(inviteTarget && inviteTarget.display_name ? inviteTarget.display_name : 'they');
                renderLeadForm(inviteTarget);
            }, INVITE_FALLBACK_MS);
        }

        function clearInviteFallback() {
            if (inviteFallbackTimer) {
                clearTimeout(inviteFallbackTimer);
                inviteFallbackTimer = null;
            }
        }

        // Replace the "X has been invited" pending banner with a softer
        // line acknowledging the wait, then let renderLeadForm() append
        // the form right after. Idempotent — running it twice is harmless.
        function showInviteFallbackNotice(name) {
            var pending = document.getElementById('zc-invite-pending');
            if (pending) pending.remove();
            var notice = document.createElement('div');
            notice.id = 'zc-invite-fallback';
            notice.className = 'zc-invite-pending';
            notice.innerHTML = '<span class="zc-invite-pending-dot"></span>'
                + 'Looks like ' + escapeHtml(name) + " isn't able to jump in right now."
                + '<br><span class="zc-invite-pending-sub">Leave your contact below and we\'ll follow up directly.</span>';
            chat.insertBefore(notice, typing);
            scrollToBottom();
        }

        // Render the inline contact form as an assistant-style bubble. One
        // form per chat: `leadSubmitted` locks further form renders after
        // a successful submit so the AI can keep emitting markers without
        // flooding the chat with duplicate forms.
        function renderLeadForm(target) {
            if (leadFormRendered) return;
            leadFormRendered = true;

            let intro;
            const targetName = target ? escapeHtml(target.display_name || 'our team') : '';
            if (target && (target.status === 'online' || target.status === 'away')) {
                intro = 'Leave your contact below, or invite ' + targetName + ' to join this chat right now.';
            } else if (target && target.status === 'reachable') {
                intro = 'Leave your contact below, or ping ' + targetName + ' on their phone — they usually reply within a few minutes.';
            } else if (target) {
                intro = targetName + ' is offline right now. Leave your contact and we\'ll reply directly.';
            } else {
                intro = 'Leave your contact and we\'ll follow up shortly.';
            }

            const canInviteNow = target && (target.status === 'online' || target.status === 'away' || target.status === 'reachable');

            const div = document.createElement('div');
            div.className = 'zc-message assistant';
            div.innerHTML =
                '<div class="zc-bubble zc-lead-form">' +
                    '<p class="zc-lead-intro">' + intro + '</p>' +
                    '<form class="zc-lead-form-inner" novalidate>' +
                        '<input type="text"  name="name"     class="zc-lead-input" placeholder="Your name" autocomplete="name"  required>' +
                        '<input type="email" name="email"    class="zc-lead-input" placeholder="Email"     autocomplete="email" required>' +
                        '<input type="text"  name="whatsapp" class="zc-lead-input" placeholder="WhatsApp (optional)" autocomplete="tel">' +
                        '<div class="zc-lead-buttons">' +
                            '<button type="submit" class="zc-lead-submit">Send contact</button>' +
                            (canInviteNow
                                ? '<button type="button" class="zc-lead-invite" data-uid="' + parseInt(target.id, 10) + '">Invite ' + targetName + ' now</button>'
                                : '') +
                        '</div>' +
                        '<div class="zc-lead-status" aria-live="polite"></div>' +
                    '</form>' +
                '</div>';

            chat.insertBefore(div, typing);
            scrollToBottom();

            const form = div.querySelector('.zc-lead-form-inner');
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                submitLead(form, div);
            });

            const inviteBtn = div.querySelector('.zc-lead-invite');
            if (inviteBtn) {
                inviteBtn.addEventListener('click', function () {
                    if (!target) return;
                    triggerAutoInvite(target);
                    inviteBtn.disabled = true;
                    inviteBtn.textContent = 'Inviting ' + (target.display_name || 'them') + '…';
                });
            }
        }

        function submitLead(form, wrapper) {
            if (!restRoot || !chatUid) return;
            if (viewOnlyMode) return;
            const data = {
                name:        (form.elements['name'].value     || '').trim(),
                email:       (form.elements['email'].value    || '').trim(),
                whatsapp:    (form.elements['whatsapp'].value || '').trim(),
                owner_token: ownerToken
            };
            const statusEl = wrapper.querySelector('.zc-lead-status');
            if (!data.name || !data.email) {
                statusEl.textContent = 'Name and email are required.';
                return;
            }
            const submit = form.querySelector('button[type=submit]');
            submit.disabled = true;
            statusEl.textContent = 'Sending…';

            fetch(restRoot + '/chat/' + encodeURIComponent(chatUid) + '/lead', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(data)
            })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp && resp.saved) {
                    leadSubmitted = true;
                    // Mirror the just-submitted email into the prefill cache so
                    // an immediate "Email me a copy" click in the same session
                    // populates the form without waiting for a page reload to
                    // re-hydrate cachedLeadEmail from the replay endpoint.
                    if (data.email) cachedLeadEmail = data.email;
                    const bubble = wrapper.querySelector('.zc-bubble');
                    bubble.classList.add('zc-lead-done');
                    bubble.innerHTML = '<p>Thanks, ' + escapeHtml(data.name) + '! We\'ll follow up at <strong>' + escapeHtml(data.email) + '</strong>' +
                        (data.whatsapp ? ' or WhatsApp <strong>' + escapeHtml(data.whatsapp) + '</strong>' : '') + '.</p>';
                    scrollToBottom();
                } else {
                    statusEl.textContent = (resp && resp.message) ? resp.message : 'Could not save. Please try again.';
                    submit.disabled = false;
                }
            })
            .catch(function () {
                statusEl.textContent = 'Network error.';
                submit.disabled = false;
            });
        }

        function scrollToBottom() {
            if (userScrolledAway) return;
            window.scrollTo({
                top: document.documentElement.scrollHeight,
                behavior: 'instant' in window ? 'instant' : 'auto'
            });
        }

        window.addEventListener('scroll', function () {
            const distance = document.documentElement.scrollHeight - (window.scrollY + window.innerHeight);
            userScrolledAway = distance > 120;
        }, { passive: true });

        // Mark every existing in-message chip group as `.used` so only the
        // newest assistant message's chips stay clickable. CSS styles the
        // `.used` state as muted/disabled; the chat-level click handler
        // also bails on used chips so they can't be activated by JS hacks.
        function deactivatePreviousMessageChips() {
            const groups = chat.querySelectorAll('.zc-message-chips');
            groups.forEach(function (g) { g.classList.add('used'); });
        }

        function addMessage(role, content, withChips) {
            const div = document.createElement('div');
            div.className = 'zc-message ' + role;

            if (role === 'user') {
                div.innerHTML = '<div class="zc-bubble">' + escapeHtml(content) + '</div>';
            } else {
                // Shared-link viewers never get clickable chips — they can't
                // post anyway. Strip them at render time so the markup is
                // honest about state and CSS doesn't have to fight it.
                const allowChips = !!withChips && !viewOnlyMode;
                const fullParts = extractChips(content);
                const parts = allowChips
                    ? fullParts
                    : { body: fullParts.body, chips: [], surveyOptions: [] };
                let html = '<div class="zc-bubble">' + renderMarkdown(parts.body) + '</div>';
                if (parts.chips.length) {
                    deactivatePreviousMessageChips();
                    html += '<div class="zc-message-chips">';
                    parts.chips.forEach(function (c) {
                        html += '<button class="zc-message-chip" data-msg="' + escapeHtml(c) + '">' + escapeHtml(c) + '</button>';
                    });
                    html += '</div>';
                }
                // Survey option chips — same look, same click handling, but
                // tagged as an interview question's suggestions so CSS can
                // style them distinctly. Reuses .zc-message-chips so the
                // existing deactivator + chat-level click handler work.
                // Multi-select: chips toggle on click, a "Done" button submits
                // the joined selection. Single-select keeps the legacy
                // one-tap-submits behavior.
                if (parts.surveyOptions && parts.surveyOptions.length) {
                    deactivatePreviousMessageChips();
                    var multi = parts.surveyOptionsType === 'multi';
                    html += '<div class="zc-message-chips zc-survey-options' + (multi ? ' zc-survey-multi' : '') + '">';
                    parts.surveyOptions.forEach(function (c) {
                        html += '<button class="zc-message-chip" data-msg="' + escapeHtml(c) + '">' + escapeHtml(c) + '</button>';
                    });
                    if (multi) {
                        html += '<button class="zc-survey-submit" type="button">Done</button>';
                    }
                    html += '</div>';
                }
                div.innerHTML = html;
            }

            chat.insertBefore(div, typing);
            scrollToBottom();
            return div.querySelector('.zc-bubble');
        }

        /* ---------- share button ---------- */
        function shareUrl() {
            try {
                const u = new URL(window.location.href);
                u.searchParams.set('chat', chatUid);
                return u.toString();
            } catch (e) {
                return window.location.href + '?chat=' + chatUid;
            }
        }

        function showShareButton() {
            // In view-only mode the visitor is a third party on a shared
            // link — no share/save/delete affordances; they don't own this.
            if (viewOnlyMode) return;
            if (shareWrap) shareWrap.hidden = false;
        }

        // Read-only UI: disable the composer, hide chips, render a single
        // notice in the input area so the visitor knows why they can't post.
        // Idempotent — safe to call repeatedly.
        function applyViewOnlyMode() {
            if (!viewOnlyMode) return;
            if (input) {
                input.disabled = true;
                input.setAttribute('readonly', 'readonly');
                input.placeholder = 'Read-only — shared conversation';
            }
            if (sendBtn) sendBtn.disabled = true;
            if (chipsEl) chipsEl.style.display = 'none';
            if (shareWrap) shareWrap.hidden = true;

            const inputArea = document.querySelector('.zc-input-area');
            if (inputArea && !document.getElementById('zc-readonly-notice')) {
                const notice = document.createElement('div');
                notice.id = 'zc-readonly-notice';
                notice.className = 'zc-readonly-notice';
                notice.textContent = 'You are viewing a shared conversation. Only the original visitor can continue it.';
                inputArea.insertBefore(notice, inputArea.firstChild);
            }

            // If the status poll already painted the "Talk to a real person"
            // bar before applyViewOnlyMode ran, drop it now.
            const inviteBar = document.getElementById('zc-invite-bar');
            if (inviteBar) inviteBar.remove();
        }

        if (shareBtn) {
            shareBtn.addEventListener('click', async function () {
                const url = shareUrl();
                let copied = false;
                try {
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        await navigator.clipboard.writeText(url);
                        copied = true;
                    }
                } catch (e) {}
                if (!copied) {
                    // Fallback: prompt the user with the link.
                    try { window.prompt('Copy this link to come back to your conversation:', url); copied = true; } catch (e) {}
                }
                if (copied && shareStatus) {
                    shareStatus.textContent = '✓ Link copied';
                    setTimeout(function () { shareStatus.textContent = ''; }, 2400);
                }
            });
        }

        if (deleteBtn) {
            deleteBtn.addEventListener('click', async function () {
                if (!chatUid || !restRoot) return;
                if (!window.confirm('Delete this conversation? You won\'t be able to access it again from this link.')) return;

                deleteBtn.disabled = true;
                if (shareStatus) shareStatus.textContent = 'Deleting…';

                let ok = false;
                try {
                    const res = await fetch(restRoot + '/chat/' + encodeURIComponent(chatUid) + '/delete', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ owner_token: ownerToken })
                    });
                    ok = res.ok;
                } catch (e) {}

                if (!ok) {
                    if (shareStatus) shareStatus.textContent = '✗ Delete failed';
                    deleteBtn.disabled = false;
                    return;
                }

                // Wipe local state for this slot only (other attribution-rule
                // slots stay intact) and reload to a fresh chat session.
                clearOwnerToken(chatUid);
                if (currentSlotKey) clearSlot(currentSlotKey);
                try { localStorage.removeItem(INTRO_SHOWN_KEY); } catch (e) {}
                if (shareStatus) shareStatus.textContent = '✓ Deleted';
                setTimeout(function () {
                    try {
                        const u = new URL(window.location.href);
                        u.searchParams.delete('chat');
                        window.location.href = u.pathname + (u.search || '') + (u.hash || '');
                    } catch (e) {
                        window.location.reload();
                    }
                }, 600);
            });
        }

        /* ---------- "Email me a copy" — visitor self-archive ---------- */

        function showEmailForm() {
            if (!emailForm) return;
            emailForm.hidden = false;
            if (emailInput) {
                if (cachedLeadEmail && !emailInput.value) emailInput.value = cachedLeadEmail;
                try { emailInput.focus(); } catch (e) {}
            }
            if (emailStatus) emailStatus.textContent = '';
        }

        function hideEmailForm() {
            if (!emailForm) return;
            emailForm.hidden = true;
            if (emailStatus) emailStatus.textContent = '';
        }

        if (emailBtn) {
            emailBtn.addEventListener('click', function () {
                if (!chatUid || !restRoot) return;
                if (emailForm && emailForm.hidden) {
                    showEmailForm();
                } else {
                    hideEmailForm();
                }
            });
        }

        if (emailCancel) {
            emailCancel.addEventListener('click', function () { hideEmailForm(); });
        }

        if (emailForm) {
            emailForm.addEventListener('submit', async function (ev) {
                ev.preventDefault();
                if (!chatUid || !restRoot) return;

                const email = (emailInput && emailInput.value || '').trim();
                if (!email) return;

                if (emailSubmit) emailSubmit.disabled = true;
                if (emailStatus) emailStatus.textContent = 'Sending…';

                let ok = false;
                let serverMsg = '';
                try {
                    const res = await fetch(restRoot + '/chat/' + encodeURIComponent(chatUid) + '/email', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ owner_token: ownerToken, email: email })
                    });
                    ok = res.ok;
                    if (!ok) {
                        try {
                            const j = await res.json();
                            if (j && j.message) serverMsg = j.message;
                        } catch (e) {}
                    }
                } catch (e) {}

                if (emailSubmit) emailSubmit.disabled = false;

                if (!ok) {
                    if (emailStatus) emailStatus.textContent = '✗ ' + (serverMsg || 'Send failed');
                    return;
                }

                cachedLeadEmail = email;
                if (emailStatus) emailStatus.textContent = '✓ Sent';
                setTimeout(function () {
                    hideEmailForm();
                }, 1800);
            });
        }

        /* ---------- admin takeover: invite buttons + polling ---------- */

        function fetchInvitableUsers() {
            if (!restRoot || !chatUid) return;
            checkChatStatus();
            // Start periodic status checks so we detect admin presence even
            // if the visitor never clicked invite. 10s interval — lightweight
            // GET that returns ~200 bytes.
            if (!statusTimer) {
                statusTimer = setInterval(checkChatStatus, 10000);
            }
        }

        function checkChatStatus() {
            if (!restRoot || !chatUid) return;
            fetch(restRoot + '/chat/' + encodeURIComponent(chatUid) + '/status', {
                headers: { 'Accept': 'application/json' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                // Seed poll position from server so we don't replay old events.
                if (data.last_event_id && lastPollEventId === 0) {
                    lastPollEventId = data.last_event_id;
                }
                if (data.admin_attached && !adminMode) {
                    enterAdminMode(data.admin_name || 'A consultant');
                } else if (!data.admin_attached && adminMode) {
                    exitAdminMode();
                }
                invitableUsers = data.invitable_users || [];
                if (!adminMode) {
                    renderInviteButtons();
                }
            })
            .catch(function () {});
        }

        function renderInviteButtons() {
            if (!invitableUsers || !invitableUsers.length) return;
            // Don't show if admin is already attached.
            if (adminMode) return;
            // Third parties on a shared link are read-only — they don't get
            // to invite the team into someone else's conversation either.
            if (viewOnlyMode) return;
            // Don't show until the chat exists server-side (i.e., the visitor
            // has sent at least one message). Inviting a non-existent chat
            // returns "Chat not found".
            if (!messages.length) return;

            var bar = document.getElementById('zc-invite-bar');
            if (bar) bar.remove(); // remove stale

            var label = 'Talk to a real person:';
            var html = '<div class="zc-invite-bar" id="zc-invite-bar">'
                     + '<span class="zc-invite-label">' + escapeHtml(label) + '</span>';
            invitableUsers.forEach(function (u) {
                var statusDot = '<span class="zc-status-dot zc-status-' + (u.status || 'offline') + '"></span>';
                var statusLabel = u.status === 'online' ? ''
                                : (u.status === 'away' ? ' (away)'
                                : (u.status === 'reachable' ? ' (on phone)'
                                : ' (offline)'));
                html += '<button class="zc-invite-btn" data-user-id="' + u.id + '" data-user-name="' + escapeHtml(u.display_name) + '">'
                     + statusDot + escapeHtml(u.display_name) + statusLabel + '</button>';
            });
            html += '</div>';

            // Insert above the input area.
            var inputArea = root.querySelector('.zc-input-area');
            if (inputArea) {
                inputArea.insertAdjacentHTML('afterbegin', html);
            }

            // Bind click handlers.
            root.querySelectorAll('.zc-invite-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var userId = parseInt(btn.dataset.userId, 10);
                    var userName = btn.dataset.userName || 'team member';
                    btn.disabled = true;
                    btn.textContent = 'Inviting…';
                    fetch(restRoot + '/chat/' + encodeURIComponent(chatUid) + '/invite', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ user_id: userId, owner_token: ownerToken })
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.invited) {
                            btn.textContent = 'Invited ✓';
                            inviteSent = true;
                            inviteTarget = { id: userId, display_name: userName };
                            showInvitePendingBanner(userName);
                            startPolling();
                            scheduleInviteFallback();
                        } else {
                            btn.textContent = data.message || 'Failed';
                            setTimeout(function () { btn.disabled = false; btn.textContent = escapeHtml(userName); }, 3000);
                        }
                    })
                    .catch(function () {
                        btn.textContent = 'Failed';
                        btn.disabled = false;
                    });
                });
            });
        }

        function showInvitePendingBanner(userName) {
            // Remove any existing banners first.
            var old = document.getElementById('zc-admin-banner');
            if (old) old.remove();
            old = document.getElementById('zc-invite-pending');
            if (old) old.remove();

            var banner = document.createElement('div');
            banner.id = 'zc-invite-pending';
            banner.className = 'zc-invite-pending';
            banner.innerHTML = '<span class="zc-invite-pending-dot"></span>'
                + escapeHtml(userName) + ' has been invited and will join when available.'
                + '<br><span class="zc-invite-pending-sub">You can keep chatting with the AI in the meantime.</span>';
            chat.insertBefore(banner, typing);
            scrollToBottom();
        }

        function enterAdminMode(name) {
            adminMode = true;
            adminName = name || 'A consultant';
            startPolling();

            // Admin made it in time — cancel the lead-fallback timer.
            clearInviteFallback();

            // Remove pending banner if present.
            var pending = document.getElementById('zc-invite-pending');
            if (pending) pending.remove();
            var fallback = document.getElementById('zc-invite-fallback');
            if (fallback) fallback.remove();

            // Remove old admin banner if present.
            var existing = document.getElementById('zc-admin-banner');
            if (existing) existing.remove();

            // Show the "now chatting with" banner above the input area (fixed position,
            // not inside the message flow where it gets pushed around by new messages).
            var banner = document.createElement('div');
            banner.id = 'zc-admin-banner';
            banner.className = 'zc-admin-banner';
            banner.innerHTML = '<span class="zc-admin-banner-dot"></span>You\'re now chatting with <strong>' + escapeHtml(adminName) + '</strong>';
            var inputArea = root.querySelector('.zc-input-area');
            if (inputArea) {
                inputArea.parentNode.insertBefore(banner, inputArea);
            }

            // Hide invite buttons.
            var bar = document.getElementById('zc-invite-bar');
            if (bar) bar.style.display = 'none';

            scrollToBottom();
        }

        function exitAdminMode() {
            adminMode = false;
            adminName = '';

            var banner = document.getElementById('zc-admin-banner');
            if (banner) banner.remove();

            // Admin had been in the chat and left — don't fire the
            // "they didn't show up" fallback; they showed up already.
            // The visitor still has the share/email/delete toolbar +
            // can keep talking to the AI.
            clearInviteFallback();

            // If there was a pending invite, don't restore invite buttons — the
            // admin might rejoin. If no invite was sent, show buttons again.
            if (!inviteSent) {
                var bar = document.getElementById('zc-invite-bar');
                if (bar) bar.style.display = '';
            }

            // Don't stop polling — keep checking for admin re-attach.
        }

        function startPolling() {
            if (pollTimer) return;
            pollTimer = setInterval(pollForEvents, 3000);
        }

        function stopPolling() {
            if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        }

        function pollForEvents() {
            if (!restRoot || !chatUid) return;
            fetch(restRoot + '/chat/' + encodeURIComponent(chatUid) + '/poll?since_id=' + lastPollEventId, {
                headers: { 'Accept': 'application/json' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var events = data.events || [];
                events.forEach(function (e) {
                    lastPollEventId = Math.max(lastPollEventId, parseInt(e.id, 10));
                    var p = e.payload || {};

                    if (e.event_type === 'admin_attached') {
                        enterAdminMode(p.display_name || 'A consultant');
                    } else if (e.event_type === 'admin_detached') {
                        exitAdminMode();
                    } else if (e.event_type === 'message_admin') {
                        // Admin sent a message — render as assistant-style bubble with admin border.
                        var div = document.createElement('div');
                        div.className = 'zc-message assistant zc-admin-msg';
                        var nameLabel = p.admin_name ? '<div class="zc-admin-name">' + escapeHtml(p.admin_name) + '</div>' : '';
                        div.innerHTML = nameLabel + '<div class="zc-bubble">' + renderMarkdown(p.content || '') + '</div>';
                        chat.insertBefore(div, typing);
                        messages.push({ role: 'assistant', content: p.content || '' });
                        playNotificationSound();
                        scrollToBottom();
                    }
                });
            })
            .catch(function () {});
        }

        /* ---------- replay a saved chat ---------- */
        async function replaySavedChat() {
            if (!restRoot || !chatUid) return false;
            try {
                const res = await fetch(restRoot + '/chat/' + encodeURIComponent(chatUid), {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' }
                });
                if (res.status === 404) {
                    // The saved chat is gone (deleted, or never existed).
                    // Clear the owner token + intro-shown flag so the
                    // typewriter plays once more for whatever uid the
                    // caller mints. The caller decides which slot (if any)
                    // to bind a fresh uid to — we don't write SLOTS_KEY
                    // here because for the URL branch no slot is in play,
                    // and for the no-URL branch the slot may not be
                    // resolved yet when this runs in the URL-fallback path.
                    clearOwnerToken(chatUid);
                    if (currentSlotKey) clearSlot(currentSlotKey);
                    try { localStorage.removeItem(INTRO_SHOWN_KEY); } catch (e) {}
                    return false;
                }
                if (!res.ok) return false;
                const data = await res.json();
                if (!data || !Array.isArray(data.messages) || !data.messages.length) return false;

                // Rehydrate lead state from the server so a page reload
                // after a completed form doesn't render the form again
                // when the AI emits another [invite:] / [contact_form]
                // marker on subsequent turns.
                if (data.lead_submitted) {
                    leadSubmitted = true;
                }
                // Stash any email already on file so the "Email me a copy"
                // form can prefill on first reveal — saves the visitor from
                // retyping if they already gave us their address.
                if (typeof data.lead_email === 'string' && data.lead_email) {
                    cachedLeadEmail = data.lead_email;
                }

                // Keep the intro card visible so returning visitors still see
                // the welcome persona.

                // Re-render the explanation message at the top of the replayed chat,
                // statically (no typing animation) so it appears instantly before history.
                // When a matched rule had both an invite_message and a survey, the
                // survey question was a separate second bubble — re-render it too so
                // the replay matches what the visitor saw on the first paint.
                if (secondMessage) {
                    const intro = document.createElement('div');
                    intro.className = 'zc-message assistant';
                    intro.innerHTML = '<div class="zc-bubble">' + renderMarkdown(secondMessage) + '</div>';
                    chat.insertBefore(intro, typing);
                }
                if (surveyQuestion) {
                    const introSurvey = document.createElement('div');
                    introSurvey.className = 'zc-message assistant';
                    introSurvey.innerHTML = '<div class="zc-bubble">' + renderMarkdown(surveyQuestion) + '</div>';
                    chat.insertBefore(introSurvey, typing);
                }

                // Snapshot the welcome chips into the chat history as a
                // .used group BEFORE rendering the replayed messages, so the
                // visual order matches a post-first-send fresh session:
                //   welcome → starter chips (greyed) → user msg → ai → ...
                // No-ops when there are no chips to freeze (e.g., URL-share
                // visitors who haven't run fetchAttributionContext yet, or
                // open-ended survey first questions).
                freezeStarterChipsIntoHistory();

                // Render every turn with chips intact. addMessage() calls
                // deactivatePreviousMessageChips() before inserting a new
                // chip group, so as the loop progresses each previous group
                // gets marked `.used` (visible but greyed out, not clickable)
                // and only the most recent assistant message ends up active.
                data.messages.forEach(function (m) {
                    if (!m || !m.role || !m.content) return;
                    if (m.role === 'admin') {
                        // Render admin messages with the same style as live admin messages.
                        var div = document.createElement('div');
                        div.className = 'zc-message assistant zc-admin-msg';
                        var nameLabel = m.admin_name ? '<div class="zc-admin-name">' + escapeHtml(m.admin_name) + '</div>' : '';
                        div.innerHTML = nameLabel + '<div class="zc-bubble">' + renderMarkdown(m.content) + '</div>';
                        chat.insertBefore(div, typing);
                    } else {
                        addMessage(m.role === 'assistant' ? 'assistant' : 'user', m.content, m.role === 'assistant');
                    }
                    messages.push({ role: m.role, content: m.content });
                });
                // Catch the case where the final question's chip group was
                // followed by a chip-less assistant turn (e.g., a verdict).
                // The streaming-mode deactivator only fires when a NEW chip
                // group appears, so without this sweep the last answered
                // group would stay clickable on replay.
                chat.querySelectorAll('.zc-message-chips:not(.used)').forEach(function (group) {
                    var myMsg = group.closest('.zc-message');
                    if (!myMsg) return;
                    var sib = myMsg.nextElementSibling;
                    while (sib) {
                        if (sib.classList
                            && sib.classList.contains('zc-message')
                            && sib.classList.contains('user')) {
                            group.classList.add('used');
                            return;
                        }
                        sib = sib.nextElementSibling;
                    }
                });
                showShareButton();
                scrollToBottom();
                return true;
            } catch (e) {
                return false;
            }
        }

        /* ---------- intro flow ---------- */
        const INTRO_SHOWN_KEY = 'zenCortextChat_intro_shown';

        // Render `text` into a fresh assistant bubble. If `typewriter` is
        // true, animate it; otherwise paint statically. `onDone` fires once
        // the bubble holds the final markdown (used to chain a follow-up
        // bubble for the rule-welcome + survey-question case).
        function renderIntroBubble(text, typewriter, onDone) {
            if (!text) { if (onDone) onDone(); return; }
            const div = document.createElement('div');
            div.className = 'zc-message assistant';
            div.innerHTML = '<div class="zc-bubble"></div>';
            chat.insertBefore(div, typing);
            const bubble = div.querySelector('.zc-bubble');
            if (!typewriter) {
                bubble.innerHTML = renderMarkdown(text);
                scrollToBottom();
                if (onDone) onDone();
                return;
            }
            let i = 0;
            const speed = 4;
            (function tick() {
                if (i >= text.length) {
                    bubble.innerHTML = renderMarkdown(text);
                    scrollToBottom();
                    if (onDone) onDone();
                    return;
                }
                bubble.textContent = text.slice(0, ++i);
                scrollToBottom();
                setTimeout(tick, speed);
            })();
        }

        function renderExplanationMessage() {
            if (!secondMessage) return;

            // If the intro for this visitor uid was already typed out in a
            // previous page load / tab, render statically so tab-switching
            // doesn't re-animate it from scratch each time.
            let alreadyShown = false;
            try { alreadyShown = localStorage.getItem(INTRO_SHOWN_KEY) === chatUid; } catch (e) {}
            const useTypewriter = !alreadyShown;

            renderIntroBubble(secondMessage, useTypewriter, function () {
                if (useTypewriter) {
                    try { localStorage.setItem(INTRO_SHOWN_KEY, chatUid); } catch (e) {}
                }
                if (surveyQuestion) {
                    // Rule welcome was just shown; survey question follows as
                    // a second bubble. Type it out too so the visitor sees a
                    // natural beat between the welcome and the first prompt.
                    renderIntroBubble(surveyQuestion, useTypewriter);
                }
            });
        }
        function initIntro() {
            if (isReplaying) return; // skip the intro when we're replaying a saved chat
            if (!document.getElementById('zc-intro-card')) return;
            renderExplanationMessage();
        }

        /* ---------- attribution-context lookup ----------
           Public GET that returns a personalized invite + chips when a
           matching attribution rule exists. Bounded by a 400 ms timeout
           so a slow lookup never delays the intro typewriter.
           Chip rendering: matched-rule chips win; otherwise pick 4 random
           from the admin-curated defaultChips pool; otherwise hide. */
        function fetchAttributionContext() {
            return new Promise(function (resolve) {
                let chipsApplied = false;
                let matchedRuleId = null; // populated from the response
                const ensureDefaultChips = function () {
                    if (chipsApplied) return;
                    renderChips(pickRandom(Array.isArray(cfg.defaultChips) ? cfg.defaultChips : [], 4));
                    chipsApplied = true;
                };

                // Reveal the intro card AFTER the attribution-context
                // response settles so any rule-override swap completes
                // off-screen — that's what prevents the visible blink
                // when a UTM-tagged visitor would otherwise see the
                // server-rendered global card pop into the rule's card.
                // Safety net of 1500ms covers slow / failing requests so
                // we never leave the visitor staring at an empty space.
                let introRevealed = false;
                const revealIntroCard = function () {
                    if (introRevealed) return;
                    introRevealed = true;
                    const card = document.querySelector('.zc-intro-card');
                    if (card) card.style.opacity = '1';
                };
                setTimeout(revealIntroCard, 1500);

                if (!attributionContextUrl) { ensureDefaultChips(); revealIntroCard(); resolve({ rule_id: null }); return; }

                let settled = false;
                const finish = function () {
                    ensureDefaultChips();
                    if (!settled) { settled = true; resolve({ rule_id: matchedRuleId }); }
                };
                const timer = setTimeout(finish, 400);

                const qs = new URLSearchParams();
                Object.keys(attribution).forEach(function (k) {
                    if (attribution[k]) qs.set(k, attribution[k]);
                });
                // Bust upstream HTTP caches (Varnish) — the endpoint sets
                // Cache-Control: no-store but the live edge may still serve
                // a stale entry, especially after we add new fields like
                // `survey` to the response shape. Per-load timestamp is
                // enough because each visitor's payload is per-visitor.
                qs.set('_', Date.now());
                const url = attributionContextUrl + '?' + qs.toString();

                fetch(url, { method: 'GET', headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (data) {
                        if (data && data.matched) {
                            if (data.invite_message) secondMessage = String(data.invite_message);
                            if (Array.isArray(data.chips) && data.chips.length) {
                                renderChips(data.chips);
                                chipsApplied = true;
                            }
                            // Intro card override: when the matched rule
                            // supplies an intro_card object, swap the DOM
                            // rendered server-side from the global option.
                            // null/undefined = no override, leave page as-is.
                            if (data.intro_card && typeof data.intro_card === 'object') {
                                applyIntroCardOverride(data.intro_card);
                            }
                        }
                        // Capture the matched rule's id (or null) so the
                        // caller can slot the chat uid in localStorage. A
                        // null/missing rule_id always means the `_general`
                        // slot — including the survey-only branch below,
                        // since survey-only matches aren't pinned to a rule.
                        if (data && typeof data.rule_id !== 'undefined' && data.rule_id !== null) {
                            var ruleIdNum = parseInt(data.rule_id, 10);
                            if (!isNaN(ruleIdNum) && ruleIdNum > 0) matchedRuleId = ruleIdNum;
                        }
                        // Active survey override: the question becomes either
                        // the welcome (survey-only, no matched rule with an
                        // invite_message) OR a follow-up bubble after the
                        // matched rule's welcome (both present). Chips are
                        // always replaced with survey options. The interview
                        // literally starts on first paint — visitor sees
                        // question 1, its options become the starter chips.
                        if (data && data.survey && data.survey.first_question) {
                            if (data.matched && data.invite_message) {
                                // Rule welcome stays as secondMessage; survey
                                // question follows as a second bubble.
                                surveyQuestion = String(data.survey.first_question);
                            } else {
                                // Survey-only (no rule welcome) — the question
                                // is the only intro message.
                                secondMessage = String(data.survey.first_question);
                            }
                            var opts = Array.isArray(data.survey.first_options) ? data.survey.first_options : [];
                            if (opts.length) {
                                var chips = opts.map(function (o) {
                                    var s = String(o);
                                    return { emoji: '', label: s, message: s };
                                });
                                var firstIsMulti = String(data.survey.first_type || '').toLowerCase() === 'multi';
                                renderChips(chips, firstIsMulti);
                                chipsApplied = true;
                            } else {
                                // Open-ended first question → suppress the
                                // default chip pool; the visitor should
                                // free-text.
                                if (chipsEl) { chipsEl.innerHTML = ''; chipsEl.style.display = 'none'; }
                                chipsApplied = true;
                            }
                        }
                        clearTimeout(timer);
                        revealIntroCard();
                        finish();
                    })
                    .catch(function () { clearTimeout(timer); revealIntroCard(); finish(); });
            });
        }

        // Swap the server-rendered intro card with values from a matched
        // attribution rule. Fields are replace-only (not merged with the
        // global card) so the admin's per-rule intent is honored exactly:
        // a blank field renders blank for this visitor. The card lives at
        // `.zc-intro-card` rendered by public/views/chat.php; selectors
        // here mirror the template's structure.
        function applyIntroCardOverride(card) {
            const root = document.querySelector('.zc-intro-card');
            if (!root || !card) return;

            const name = String(card.name || '');
            const role = String(card.role || '');
            const body = String(card.body || '');
            const logo = String(card.logo_url || '');
            const site = String(card.site_url || '');

            const nameEl = root.querySelector('.zc-intro-name');
            const roleEl = root.querySelector('.zc-intro-role');
            const bodyEl = root.querySelector('.zc-intro-body');
            const logoA  = root.querySelector('.zc-intro-logo');
            const logoImg = logoA ? logoA.querySelector('img') : null;
            const actionsEl = root.querySelector('.zc-intro-actions');
            const linkEl    = actionsEl ? actionsEl.querySelector('a.zc-intro-link') : null;

            if (nameEl) nameEl.textContent = name;
            if (roleEl) roleEl.textContent = role;

            // Body: server pre-renders to safe HTML via
            // Zen_Cortext_Defaults::render_intro_body_html() (wpautop +
            // wp_kses with the intro-card tag whitelist — same as the
            // global card's chat.php render). So admins can use <ul>,
            // <li>, <b>, <a>, etc. and we drop the result into innerHTML
            // verbatim. Fall back to escaped-text paragraphs only if the
            // server didn't ship body_html for some reason (old cached JS
            // talking to new payload, etc).
            if (bodyEl) {
                if (typeof card.body_html === 'string') {
                    bodyEl.innerHTML = card.body_html;
                } else {
                    bodyEl.innerHTML = '';
                    const paragraphs = body.split(/\n{2,}/);
                    paragraphs.forEach(function (chunk) {
                        chunk = chunk.replace(/^\s+|\s+$/g, '');
                        if (!chunk) return;
                        const p = document.createElement('p');
                        const lines = chunk.split(/\n/);
                        lines.forEach(function (line, i) {
                            if (i > 0) p.appendChild(document.createElement('br'));
                            p.appendChild(document.createTextNode(line));
                        });
                        bodyEl.appendChild(p);
                    });
                }
            }

            // Logo: hide the anchor entirely when no logo URL AND no site URL,
            // matching the server template's has_logo_or_site gating.
            if (logoA) {
                if (logo) {
                    if (!logoImg) {
                        const img = document.createElement('img');
                        img.src = logo;
                        img.alt = name;
                        logoA.innerHTML = '';
                        logoA.appendChild(img);
                    } else {
                        logoImg.src = logo;
                        logoImg.alt = name;
                    }
                    logoA.style.display = '';
                } else if (logoImg) {
                    logoImg.remove();
                }
                logoA.href = site || '#';
                if (!logo && !site) logoA.style.display = 'none';
                else                logoA.style.display = '';
            }

            // Site-link action row: present when site_url set, hidden when blank.
            if (actionsEl) {
                if (site) {
                    actionsEl.style.display = '';
                    if (linkEl) {
                        linkEl.href = site;
                        const display = site.replace(/^https?:\/\//, '');
                        linkEl.textContent = display + ' ↗';
                    }
                } else {
                    actionsEl.style.display = 'none';
                }
            }
        }

        // Render an array of {emoji,label,message} as the visible chip
        // buttons. Hides the container if the array is empty — admin's
        // "no chips configured" intent is honored literally, no fallback.
        function renderChips(chips, multi) {
            if (!chipsEl) return;
            let html = '';
            (chips || []).forEach(function (c) {
                if (!c) return;
                const emoji = c.emoji ? (c.emoji + ' ') : '';
                const label = c.label || c.message || '';
                const msg   = c.message || c.label || '';
                if (!label) return;
                html += '<button class="zc-chip" data-msg="' + escapeHtml(msg) + '">'
                      + escapeHtml(emoji) + escapeHtml(label) + '</button>';
            });
            if (html && multi) {
                html += '<button class="zc-survey-submit" type="button">Done</button>';
            }
            chipsEl.classList.toggle('zc-survey-multi', !!(html && multi));
            chipsEl.innerHTML = html;
            chipsEl.style.display = html ? '' : 'none';
        }

        // Fisher-Yates: pick n items uniformly at random from arr without
        // replacement. Returns the whole array if it has fewer than n.
        function pickRandom(arr, n) {
            if (!Array.isArray(arr) || arr.length === 0) return [];
            if (arr.length <= n) return arr.slice();
            const copy = arr.slice();
            for (let i = copy.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                const tmp = copy[i]; copy[i] = copy[j]; copy[j] = tmp;
            }
            return copy.slice(0, n);
        }

        // Resolve the visitor's slot from attribution, then either replay a
        // saved uid for that slot or start fresh. The intro typewriter shows
        // the matched invite from the start (capped at 400 ms by the
        // fetchAttributionContext timer) — same UX latency budget as before.
        // For URL-share visitors (isReplaying), bypass slot resolution and
        // load the linked uid directly; on 404 fall back to slot resolution.
        function loadFromSlot() {
            fetchAttributionContext().then(function (info) {
                const ruleId = info && info.rule_id ? info.rule_id : null;
                currentSlotKey = ruleId ? ('rule_' + ruleId) : '_general';

                if (!chatUid) {
                    const fromSlot = readSlot(currentSlotKey);
                    if (fromSlot) {
                        chatUid = fromSlot;
                        isReturningVisitor = true;
                    } else {
                        chatUid = generateUid();
                    }
                }
                if (!ownerToken) {
                    ownerToken = getOwnerToken(chatUid);
                    if (!ownerToken) {
                        ownerToken = generateOwnerToken();
                        setOwnerToken(chatUid, ownerToken);
                    }
                }
                writeSlot(currentSlotKey, chatUid);
                markChatReady();

                if (isReturningVisitor) {
                    replaySavedChat().then(function (ok) {
                        if (!ok) {
                            // Slot pointed at a deleted row. replaySavedChat
                            // already cleared the owner token + slot + intro
                            // flag; mint a fresh uid bound to the same slot.
                            chatUid = generateUid();
                            ownerToken = generateOwnerToken();
                            setOwnerToken(chatUid, ownerToken);
                            writeSlot(currentSlotKey, chatUid);
                            isReturningVisitor = false;
                            initIntro();
                        } else {
                            showShareButton();
                        }
                        fetchInvitableUsers();
                    });
                } else {
                    initIntro();
                    // Don't fetch invite buttons yet — the chat row only
                    // exists on the server after the first message is sent.
                    // fetchInvitableUsers() is called from send() once the
                    // visitor actually writes something.
                }
            });
        }

        if (isReplaying) {
            // URL-share visitor — load the linked uid directly. No slot
            // bound until/unless the link 404s and we fall back to the
            // attribution-resolved slot.
            replaySavedChat().then(function (ok) {
                if (!ok) {
                    isReplaying = false;
                    chatUid = '';
                    ownerToken = '';
                    viewOnlyMode = false;
                    loadFromSlot();
                } else {
                    showShareButton();
                    fetchInvitableUsers();
                }
            });
        } else {
            loadFromSlot();
        }

        // Lock the composer immediately if this visitor is a third party on
        // a shared link — they're never going to be allowed to post, no need
        // to wait for the replay fetch to finish before disabling the UI.
        applyViewOnlyMode();

        /* ---------- input wiring ---------- */
        input.addEventListener('input', function () {
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 140) + 'px';
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                send();
            }
        });
        sendBtn.addEventListener('click', send);

        /* ============================================================
           Voice input (mobile only). Tap-to-toggle MediaRecorder →
           upload the audio blob to /transcribe → insert transcript
           into the textarea (visitor reviews + taps Send manually).
           Hidden entirely on desktop and when the admin hasn't turned
           voice on or the browser lacks MediaRecorder / getUserMedia.
           ============================================================ */
        (function initVoiceInput() {
            const micBtn = document.getElementById('zc-mic');
            if (!micBtn) return;

            const transcribeUrl = cfg.transcribeUrl ||
                (restRoot ? restRoot + '/transcribe' : '');
            const voiceEnabled = !!cfg.voiceEnabled;
            const voiceMaxSec = (typeof cfg.voiceMaxSec === 'number' && cfg.voiceMaxSec > 0)
                ? cfg.voiceMaxSec : 60;

            // Mobile = touch-primary AND a narrow viewport. The pointer:coarse
            // half rules out laptops with touchscreens; the width half rules out
            // tablets in landscape that already have plenty of typing room.
            const isMobile = window.matchMedia
                ? window.matchMedia('(pointer: coarse) and (max-width: 767px)').matches
                : false;
            const canRecord = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia &&
                                 typeof window.MediaRecorder !== 'undefined');

            if (!voiceEnabled || !isMobile || !canRecord || !transcribeUrl) {
                // Leave the button hidden — keeps the input row layout
                // identical to desktop on the off-chance CSS misses one
                // of the gating conditions.
                micBtn.style.display = 'none';
                return;
            }
            micBtn.style.display = '';

            let recorder = null;
            let chunks = [];
            let stream = null;
            let stopTimer = null;
            let state = 'idle';

            function setState(s) {
                state = s;
                micBtn.classList.remove('is-idle', 'is-recording', 'is-uploading');
                micBtn.classList.add('is-' + s);
                micBtn.disabled = (s === 'uploading');
                if (s === 'recording')      micBtn.setAttribute('aria-label', 'Stop recording');
                else if (s === 'uploading') micBtn.setAttribute('aria-label', 'Transcribing…');
                else                        micBtn.setAttribute('aria-label', 'Record voice message');
            }

            function cleanupStream() {
                if (stream) {
                    stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
                    stream = null;
                }
                if (stopTimer) { clearTimeout(stopTimer); stopTimer = null; }
                recorder = null;
                chunks = [];
            }

            function showError(msg) {
                let errEl = document.getElementById('zc-voice-error');
                if (!errEl) {
                    errEl = document.createElement('div');
                    errEl.id = 'zc-voice-error';
                    errEl.className = 'zc-voice-error';
                    micBtn.parentNode.parentNode.appendChild(errEl);
                }
                errEl.textContent = msg;
                errEl.style.display = '';
                setTimeout(function () { if (errEl) errEl.style.display = 'none'; }, 5000);
            }

            function pickMimeType() {
                const candidates = [
                    'audio/webm;codecs=opus',
                    'audio/webm',
                    'audio/mp4',
                    'audio/ogg;codecs=opus',
                ];
                if (window.MediaRecorder && MediaRecorder.isTypeSupported) {
                    for (let i = 0; i < candidates.length; i++) {
                        if (MediaRecorder.isTypeSupported(candidates[i])) return candidates[i];
                    }
                }
                return '';
            }

            async function startRecording() {
                try {
                    stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                } catch (e) {
                    showError('Microphone access denied.');
                    return;
                }
                const mime = pickMimeType();
                try {
                    recorder = mime ? new MediaRecorder(stream, { mimeType: mime })
                                    : new MediaRecorder(stream);
                } catch (e) {
                    cleanupStream();
                    showError('Recording is not supported on this device.');
                    return;
                }
                chunks = [];
                recorder.ondataavailable = function (e) {
                    if (e.data && e.data.size > 0) chunks.push(e.data);
                };
                recorder.onstop = onRecorderStop;
                recorder.onerror = function () {
                    cleanupStream();
                    setState('idle');
                    showError('Recording error.');
                };
                recorder.start();
                setState('recording');

                stopTimer = setTimeout(function () {
                    if (recorder && recorder.state === 'recording') {
                        try { recorder.stop(); } catch (e) {}
                    }
                }, voiceMaxSec * 1000);
            }

            function stopRecording() {
                if (recorder && recorder.state === 'recording') {
                    try { recorder.stop(); } catch (e) {}
                }
            }

            async function onRecorderStop() {
                const mime = (recorder && recorder.mimeType) || 'audio/webm';
                const blob = new Blob(chunks, { type: mime });
                cleanupStream();
                if (blob.size === 0) {
                    setState('idle');
                    showError('No audio captured — try again.');
                    return;
                }
                setState('uploading');
                try {
                    const text = await uploadForTranscription(blob, mime);
                    insertTranscript(text);
                } catch (e) {
                    showError(e && e.message ? e.message : 'Transcription failed.');
                }
                setState('idle');
            }

            async function uploadForTranscription(blob, mime) {
                const ext = (mime.indexOf('mp4') !== -1) ? 'm4a'
                          : (mime.indexOf('ogg') !== -1) ? 'ogg'
                          : 'webm';
                const fd = new FormData();
                fd.append('audio', blob, 'voice.' + ext);
                let res;
                try {
                    res = await fetch(transcribeUrl, { method: 'POST', body: fd });
                } catch (e) {
                    throw new Error('Network error.');
                }
                let json = null;
                try { json = await res.json(); } catch (e) { /* fall through */ }
                if (!res.ok) {
                    const msg = (json && (json.message || json.code)) || ('HTTP ' + res.status);
                    throw new Error(msg);
                }
                if (!json || typeof json.text !== 'string') {
                    throw new Error('Empty transcription.');
                }
                return json.text;
            }

            function insertTranscript(text) {
                const trimmed = String(text || '').trim();
                if (!trimmed) return;
                const existing = (input.value || '').replace(/\s+$/, '');
                input.value = existing === '' ? trimmed : existing + ' ' + trimmed;
                // Replay the textarea auto-grow handler so the field
                // resizes for the freshly inserted transcript.
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.focus();
                // Move caret to end so the visitor can continue typing.
                const len = input.value.length;
                try { input.setSelectionRange(len, len); } catch (e) {}
            }

            micBtn.addEventListener('click', function (e) {
                e.preventDefault();
                if (state === 'idle')           startRecording();
                else if (state === 'recording') stopRecording();
                /* uploading: button disabled, no-op */
            });

            setState('idle');
        })();

        // Helper: submit a multi-select group's joined selection. Returns
        // true when a submit happened (so the caller can early-exit).
        function submitMultiSelectGroup(group) {
            if (!group || group.classList.contains('used')) return false;
            const selected = Array.from(group.querySelectorAll('.zc-message-chip.selected, .zc-chip.selected'))
                .map(b => b.dataset.msg)
                .filter(Boolean);
            if (!selected.length) return false;
            group.classList.add('used');
            input.value = selected.join(', ');
            send();
            return true;
        }

        chipsEl.addEventListener('click', function (e) {
            // Multi-select submit
            if (e.target.closest('.zc-survey-submit')) {
                submitMultiSelectGroup(chipsEl);
                return;
            }
            const chip = e.target.closest('.zc-chip');
            if (!chip) return;
            // Multi-select toggle: don't send, just flip selection.
            if (chipsEl.classList.contains('zc-survey-multi')) {
                chip.classList.toggle('selected');
                return;
            }
            // Single-select: mark the picked chip selected so the
            // frozen-into-history copy shows which option was chosen.
            chip.classList.add('selected');
            input.value = chip.dataset.msg;
            send();
        });

        chat.addEventListener('click', function (e) {
            // Multi-select submit
            const submitBtn = e.target.closest('.zc-survey-submit');
            if (submitBtn) {
                if (streaming || viewOnlyMode) return;
                submitMultiSelectGroup(submitBtn.closest('.zc-message-chips'));
                return;
            }
            const chip = e.target.closest('.zc-message-chip');
            if (!chip || streaming) return;
            if (viewOnlyMode) return;
            const group = chip.closest('.zc-message-chips');
            // A `.used` group is from a previous assistant turn — its chips
            // are no longer the active follow-ups. CSS makes them look muted
            // and pointer-events:none, but a stray click here gets ignored
            // anyway as a safety net.
            if (group && group.classList.contains('used')) return;
            // Multi-select toggle: don't send, just flip selection.
            if (group && group.classList.contains('zc-survey-multi')) {
                chip.classList.toggle('selected');
                return;
            }
            // Single-select survey chip: persist the selection visually so
            // the visitor can scroll back and see which option they picked.
            // Regular follow-up chips don't get this — they're quick replies,
            // not survey answers.
            if (group && group.classList.contains('zc-survey-options')) {
                chip.classList.add('selected');
            }
            if (group) group.classList.add('used');
            input.value = chip.dataset.msg;
            send();
        });

        // Find any active multi-select group with at least one chip
        // selected. The main send button uses this so the visitor doesn't
        // have to discover the "Done" affordance — clicking send (or
        // hitting Enter) with an empty composer picks up the toggled chips.
        function findActiveMultiSelectGroup() {
            const groups = document.querySelectorAll('.zc-survey-multi:not(.used)');
            for (const g of groups) {
                if (g.querySelector('.zc-message-chip.selected, .zc-chip.selected')) {
                    return g;
                }
            }
            return null;
        }

        // Snapshot the starter chips and replicate them into the chat
        // history as a `.used` group, then clear the original container.
        // This way the first question's options stay visible (greyed) in
        // the scroll history, matching how per-message survey chips
        // behave on later questions. Idempotent — does nothing when the
        // starter chips are already empty/hidden.
        function freezeStarterChipsIntoHistory() {
            if (!chipsEl) return;
            const chips = Array.from(chipsEl.querySelectorAll('.zc-chip'));
            if (chips.length === 0) return;
            if (chipsEl.style.display === 'none') return;

            const wasMulti = chipsEl.classList.contains('zc-survey-multi');
            const wrap = document.createElement('div');
            wrap.className = 'zc-message assistant zc-history-chip-row';
            let html = '<div class="zc-message-chips zc-survey-options used'
                     + (wasMulti ? ' zc-survey-multi' : '') + '">';
            chips.forEach(function (c) {
                const isSelected = c.classList.contains('selected') ? ' selected' : '';
                const msg = c.dataset.msg || c.textContent.trim();
                html += '<button class="zc-message-chip' + isSelected
                     +  '" data-msg="' + escapeHtml(msg) + '" disabled>'
                     +  escapeHtml(c.textContent.trim()) + '</button>';
            });
            html += '</div>';
            wrap.innerHTML = html;
            chat.insertBefore(wrap, typing);

            chipsEl.innerHTML = '';
            chipsEl.classList.remove('zc-survey-multi');
            chipsEl.classList.add('used'); // belt-and-suspenders
            chipsEl.style.display = 'none';
        }

        /* ---------- send + stream ---------- */
        async function send() {
            // If the composer is empty but the visitor has toggled some
            // multi-select chips, treat send as "submit selection". Both
            // the Done button and this branch produce the same outcome.
            if (!input.value.trim()) {
                const multiGroup = findActiveMultiSelectGroup();
                if (multiGroup) {
                    const selected = Array.from(multiGroup.querySelectorAll('.zc-message-chip.selected, .zc-chip.selected'))
                        .map(b => b.dataset.msg)
                        .filter(Boolean);
                    if (selected.length) {
                        multiGroup.classList.add('used');
                        input.value = selected.join(', ');
                    }
                }
            }
            const text = input.value.trim();
            if (!text || streaming) return;
            // Defense-in-depth — server already enforces this with a 403,
            // but we keep the bad request from being made at all so a
            // third party who toggles `disabled` in DevTools still gets
            // a clear no-op rather than the server's error response.
            if (viewOnlyMode) return;

            // Move any visible starter chips into the chat history as a
            // frozen .used group. Keeps the first question's options
            // visible (greyed) above the user's reply, so the chat scroll
            // matches how per-message survey chips behave on later turns.
            freezeStarterChipsIntoHistory();

            // Visitor typed (or chip-toggled) something — any other
            // active multi-select group is now stale, drop it from the
            // active set so a stray click doesn't re-trigger send.
            document.querySelectorAll('.zc-survey-multi:not(.used)').forEach(g => g.classList.add('used'));

            chipsEl.style.display = 'none';
            addMessage('user', text);
            messages.push({ role: 'user', content: text });

            input.value = '';
            input.style.height = 'auto';
            streaming = true;
            sendBtn.disabled = true;
            typing.classList.add('active');
            scrollToBottom();

            // Block until chatUid + ownerToken are bound. For URL-share
            // visitors this is already resolved; for no-URL visitors who
            // typed and hit Enter inside the 400ms attribution-lookup
            // window, this awaits the slot resolution so the request goes
            // out with a real chat_uid.
            if (!chatUid) {
                try { await chatReady; } catch (e) {}
            }

            // Reveal the share button as soon as the visitor sends a real
            // message — they have a conversation worth saving from this point.
            showShareButton();

            // Fetch invitable users if not already fetched.
            if (invitableUsers === null) {
                fetchInvitableUsers();
            }

            // Refresh local last_seen so reconciling beacons on later
            // pageloads don't think this visitor's been idle for 30 min.
            touchSession();

            try {
                const response = await fetch(restUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        messages:    messages,
                        chat_uid:    chatUid,
                        owner_token: ownerToken,
                        attribution: attribution,
                        session_uid: sessionUid
                    })
                });

                typing.classList.remove('active');

                if (!response.ok || !response.body) {
                    addMessage('assistant', 'Sorry, something went wrong. Please try again.');
                    streaming = false; sendBtn.disabled = false;
                    return;
                }

                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';
                let assistantText = '';
                let bubble = null;

                // Smooth typewriter: server tokens accumulate in assistantText
                // (the "target"); a requestAnimationFrame loop advances the
                // visible length toward the target at a steady pace. This
                // decouples visual progress from chunk bursts, so the bubble
                // looks like it's being typed at 60 fps regardless of how the
                // server flushes SSE frames. Markdown is re-rendered on every
                // frame so formatting appears progressively.
                let displayedLen = 0;
                let rafId = null;
                let streamDone = false;

                function renderAt(len) {
                    if (!bubble) return;
                    const partsNow = extractChips(assistantText.slice(0, len));
                    bubble.innerHTML = renderMarkdown(partsNow.body);
                    scrollToBottom();
                }

                function typeFrame() {
                    rafId = null;
                    const backlog = assistantText.length - displayedLen;
                    if (backlog > 0) {
                        // Base ~4 chars/frame (≈240 cps at 60 fps). Accelerate
                        // if backlog grows so we never fall too far behind a
                        // fast-streaming server, and catch up faster once the
                        // stream has ended.
                        let step = 4;
                        if (backlog > 60)   step = Math.ceil(backlog / 30);
                        if (streamDone)     step = Math.max(step, Math.ceil(backlog / 20));
                        displayedLen = Math.min(assistantText.length, displayedLen + step);
                        renderAt(displayedLen);
                    }
                    if (displayedLen < assistantText.length || !streamDone) {
                        rafId = requestAnimationFrame(typeFrame);
                    }
                }

                function kickAnimator() {
                    if (rafId !== null) return;
                    rafId = requestAnimationFrame(typeFrame);
                }

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
                            if (event.type === 'admin_mode') {
                                // Admin has taken over — stop streaming, switch to polling.
                                typing.classList.remove('active');
                                enterAdminMode(event.admin_name || adminName || 'A consultant');
                                streaming = false;
                                sendBtn.disabled = false;
                                return; // exit the whole send() function
                            }
                            // AI returned an error (billing, rate limit,
                            // outage, transport, bad key, …). The server
                            // already emailed the admin team with details;
                            // here we replace whatever partial bubble we
                            // had with a clean "out of service" message
                            // and open the lead-capture form so the
                            // visitor isn't stranded.
                            if (event.type === 'service_unavailable' || event.type === 'error') {
                                typing.classList.remove('active');
                                if (bubble) {
                                    const msgDiv = bubble.closest('.zc-message');
                                    if (msgDiv && msgDiv.parentNode) {
                                        msgDiv.parentNode.removeChild(msgDiv);
                                    }
                                    bubble = null;
                                }
                                const fallback = event.message ||
                                    'The AI consultant is currently unavailable. Leave your contact below and our team will follow up shortly.';
                                addMessage('assistant', fallback);
                                if (typeof renderLeadForm === 'function') renderLeadForm(null);
                                streaming = false;
                                sendBtn.disabled = false;
                                if (typeof console !== 'undefined' && event.error) {
                                    console.error('[zen-cortext] AI service unavailable:', event.error);
                                }
                                return;
                            }
                            if (event.type === 'content_block_delta' && event.delta && event.delta.text) {
                                assistantText += event.delta.text;
                                if (!bubble) bubble = addMessage('assistant', '');
                                kickAnimator();
                            }
                        } catch (e) { /* skip */ }
                    }
                }

                // Stream ended — let the animator finish catching up so the
                // remaining text still types in rather than popping when we
                // swap to the final render below.
                streamDone = true;
                kickAnimator();
                while (displayedLen < assistantText.length) {
                    await new Promise(function (r) { setTimeout(r, 16); });
                }
                if (rafId !== null) { cancelAnimationFrame(rafId); rafId = null; }

                if (assistantText) {
                    messages.push({ role: 'assistant', content: assistantText });
                    if (bubble) {
                        const msgDiv = bubble.closest('.zc-message');
                        const parts = extractChips(assistantText);
                        let html = '<div class="zc-bubble">' + renderMarkdown(parts.body) + '</div>';
                        if (parts.chips.length && !viewOnlyMode) {
                            deactivatePreviousMessageChips();
                            html += '<div class="zc-message-chips">';
                            parts.chips.forEach(function (c) {
                                html += '<button class="zc-message-chip" data-msg="' + escapeHtml(c) + '">' + escapeHtml(c) + '</button>';
                            });
                            html += '</div>';
                        }
                        if (parts.surveyOptions && parts.surveyOptions.length && !viewOnlyMode) {
                            deactivatePreviousMessageChips();
                            var multi = parts.surveyOptionsType === 'multi';
                            html += '<div class="zc-message-chips zc-survey-options' + (multi ? ' zc-survey-multi' : '') + '">';
                            parts.surveyOptions.forEach(function (c) {
                                html += '<button class="zc-message-chip" data-msg="' + escapeHtml(c) + '">' + escapeHtml(c) + '</button>';
                            });
                            if (multi) {
                                html += '<button class="zc-survey-submit" type="button">Done</button>';
                            }
                            html += '</div>';
                        }
                        msgDiv.innerHTML = html;
                        scrollToBottom();
                        // Side-effect directives live OUTSIDE the rendered
                        // bubble — auto-invite a team member or append an
                        // inline lead-capture form to the chat flow.
                        applyActions(parts);
                    }
                }
            } catch (err) {
                typing.classList.remove('active');
                addMessage('assistant', 'Connection error. Please try again.');
            }

            streaming = false;
            sendBtn.disabled = false;
            input.focus();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
