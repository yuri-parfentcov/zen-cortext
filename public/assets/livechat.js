/* Zen Cortext — Live Chat Admin (PWA)
 * Reads window.zlcConfig = { restRoot, homeUrl, version }
 * Auth via Bearer session tokens stored in localStorage.
 */
(function () {
    'use strict';

    var cfg = window.zlcConfig || {};
    var rest = (cfg.restRoot || '').replace(/\/$/, '');
    var app = document.getElementById('zlc-app');
    if (!app || !rest) return;

    var STORAGE_KEY = 'zenLivechatSession';
    var STORAGE_USER = 'zenLivechatUser';
    var sessionToken = '';
    var currentUser = null;
    var chats = [];
    var activeChatUid = '';
    var activeChatData = null;
    var pollTimer = null;
    var listTimer = null;
    var lastEventId = 0;
    var helperOpen = false;
    var helperText = '';

    /* ---------- Helpers ---------- */

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }
    function truncate(s, n) {
        s = String(s || '');
        return s.length > n ? s.slice(0, n) + '…' : s;
    }
    function timeAgo(dt) {
        if (!dt) return '';
        var diff = Math.floor((Date.now() - new Date(dt.replace(' ', 'T') + 'Z').getTime()) / 1000);
        if (diff < 0) diff = 0;
        if (diff < 60) return diff + 's ago';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return Math.floor(diff / 86400) + 'd ago';
    }

    function api(method, path, body) {
        var opts = {
            method: method,
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        };
        if (sessionToken) {
            opts.headers['Authorization'] = 'Bearer ' + sessionToken;
        }
        if (body !== undefined) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        return fetch(rest + path, opts).then(function (r) {
            if (r.status === 401) {
                logout();
                throw new Error('Session expired');
            }
            return r.json();
        });
    }

    /* ---------- Auth ---------- */

    function loadSession() {
        try {
            sessionToken = localStorage.getItem(STORAGE_KEY) || '';
            var u = localStorage.getItem(STORAGE_USER);
            currentUser = u ? JSON.parse(u) : null;
        } catch (e) {}
    }
    function saveSession(token, user) {
        sessionToken = token;
        currentUser = user;
        try {
            localStorage.setItem(STORAGE_KEY, token);
            localStorage.setItem(STORAGE_USER, JSON.stringify(user));
        } catch (e) {}
    }
    function logout() {
        sessionToken = '';
        currentUser = null;
        try {
            localStorage.removeItem(STORAGE_KEY);
            localStorage.removeItem(STORAGE_USER);
        } catch (e) {}
        renderLogin();
    }

    /* ---------- Login screen ---------- */

    function renderLogin() {
        stopPolling();
        app.innerHTML =
            '<div class="zlc-login"><div class="zlc-login-card">' +
            '<h1>Zen Cortext</h1>' +
            '<p>Live Chat Console</p>' +
            '<input type="email" id="zlc-email" placeholder="Your email" autocomplete="email" />' +
            '<button class="zlc-btn" id="zlc-login-btn">Send login link</button>' +
            '<div class="zlc-login-status" id="zlc-login-status"></div>' +
            '</div></div>';

        var emailInput = document.getElementById('zlc-email');
        var btn = document.getElementById('zlc-login-btn');
        var status = document.getElementById('zlc-login-status');

        btn.addEventListener('click', function () {
            var email = emailInput.value.trim();
            if (!email) { status.textContent = 'Enter your email.'; status.className = 'zlc-login-status err'; return; }
            btn.disabled = true;
            status.textContent = 'Sending…';
            status.className = 'zlc-login-status';
            api('POST', '/livechat/auth/request', { email: email })
                .then(function () {
                    status.textContent = 'Check your inbox for the login link.';
                })
                .catch(function () {
                    status.textContent = 'Request failed.';
                    status.className = 'zlc-login-status err';
                })
                .finally(function () { btn.disabled = false; });
        });
        emailInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') btn.click();
        });
    }

    /* ---------- Main layout ---------- */

    function renderApp() {
        app.innerHTML =
            // Mobile hamburger
            '<button class="zlc-hamburger" id="zlc-hamburger">☰</button>' +
            '<div class="zlc-sidebar-overlay" id="zlc-sidebar-overlay"></div>' +
            '<div class="zlc-layout">' +
                // Sidebar
                '<div class="zlc-sidebar" id="zlc-sidebar">' +
                    '<div class="zlc-sidebar-header">' +
                        '<h2>Chats</h2>' +
                        '<select id="zlc-status-select" class="zlc-status-select" title="Your status">' +
                            '<option value="online">🟢 Online</option>' +
                            '<option value="away">🟡 Away</option>' +
                            '<option value="offline">⚫ Offline</option>' +
                        '</select>' +
                        '<button class="zlc-btn zlc-btn-sm zlc-btn-outline" id="zlc-schedule-btn" title="Auto-availability schedule">⏰</button>' +
                        '<button class="zlc-btn zlc-btn-sm zlc-btn-outline" id="zlc-logout">Logout</button>' +
                    '</div>' +
                    '<div class="zlc-sidebar-list" id="zlc-sidebar-list"></div>' +
                    '<div class="zlc-sidebar-footer">' +
                        'Logged in as ' + esc(currentUser ? currentUser.display_name : '?') +
                        ' · <a href="' + esc(cfg.homeUrl || '/') + '" target="_blank">Main site</a>' +
                    '</div>' +
                '</div>' +
                // Main
                '<div class="zlc-main" id="zlc-main">' +
                    '<div class="zlc-main-empty">Select a chat from the list</div>' +
                '</div>' +
            '</div>';

        document.getElementById('zlc-logout').addEventListener('click', function () {
            // Set status to offline on logout.
            api('POST', '/livechat/status', { status: 'offline' }).catch(function () {});
            logout();
        });

        // Status selector
        var statusSelect = document.getElementById('zlc-status-select');
        if (statusSelect) {
            statusSelect.addEventListener('change', function () {
                api('POST', '/livechat/status', { status: statusSelect.value }).catch(function () {});
            });
            // Fetch initial status
            api('GET', '/livechat/status').then(function (data) {
                if (data.my_status && statusSelect) {
                    statusSelect.value = data.my_status;
                }
            }).catch(function () {});
        }

        // Schedule modal trigger
        var scheduleBtn = document.getElementById('zlc-schedule-btn');
        if (scheduleBtn) scheduleBtn.addEventListener('click', openScheduleModal);

        // Mobile drawer
        var sidebar = document.getElementById('zlc-sidebar');
        var overlay = document.getElementById('zlc-sidebar-overlay');
        var hamburger = document.getElementById('zlc-hamburger');
        hamburger.addEventListener('click', function () {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('open');
        });
        overlay.addEventListener('click', function () {
            sidebar.classList.remove('open');
            overlay.classList.remove('open');
        });

        loadChatList();
        listTimer = setInterval(loadChatList, 10000);

        // Request notification permission early so we can notify on background messages.
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }
    }

    /* ---------- Schedule modal ---------- */

    var DAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']; // index 0..6 → ISO 1..7

    function openScheduleModal() {
        var browserTz = 'UTC';
        try { browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'; } catch (e) {}

        api('GET', '/livechat/schedule').then(function (data) {
            var sched = data.schedule || {};
            var zones = data.available_zones || [browserTz];
            var isConfigured = !!data.is_configured;

            // If user has never saved a schedule, pre-select their browser
            // timezone instead of the PHP default ('UTC').
            var selectedTz = (!isConfigured && zones.indexOf(browserTz) !== -1)
                ? browserTz
                : (sched.tz || browserTz);

            var tzOptions = zones.map(function (z) {
                var sel = (selectedTz === z) ? ' selected' : '';
                return '<option value="' + esc(z) + '"' + sel + '>' + esc(z) + '</option>';
            }).join('');

            var detectAvailable = zones.indexOf(browserTz) !== -1 && browserTz !== selectedTz;
            var detectButton = detectAvailable
                ? '<button type="button" class="zlc-tz-detect" id="zlc-sched-tz-detect">' +
                  'Use my timezone (' + esc(browserTz) + ')</button>'
                : '<span class="zlc-tz-detected">Detected: ' + esc(browserTz) + '</span>';

            var dayBoxes = DAY_LABELS.map(function (label, idx) {
                var iso = idx + 1;
                var checked = (sched.days || []).indexOf(iso) !== -1 ? ' checked' : '';
                return '<label class="zlc-day-box"><input type="checkbox" value="' + iso + '"' + checked + '> ' + label + '</label>';
            }).join('');

            var enabledChecked = sched.enabled ? ' checked' : '';

            var html =
                '<div class="zlc-modal-backdrop" id="zlc-sched-backdrop">' +
                  '<div class="zlc-modal" role="dialog" aria-label="Availability schedule">' +
                    '<div class="zlc-modal-header">' +
                      '<h3>Auto-availability schedule</h3>' +
                      '<button class="zlc-modal-close" id="zlc-sched-close" aria-label="Close">×</button>' +
                    '</div>' +
                    '<div class="zlc-modal-body">' +
                      '<p class="zlc-modal-hint">Outside this window you are shown <strong>offline</strong> once you go idle. While you are actively online here, your manual status still shows to visitors. Inside the window, normal online/away/reachable rules apply.</p>' +
                      '<label class="zlc-field-row">' +
                        '<input type="checkbox" id="zlc-sched-enabled"' + enabledChecked + '> ' +
                        '<span>Enable automatic schedule</span>' +
                      '</label>' +
                      '<label class="zlc-field"><span>Timezone</span>' +
                        '<select id="zlc-sched-tz">' + tzOptions + '</select>' +
                        '<div class="zlc-tz-helper">' + detectButton + '</div>' +
                      '</label>' +
                      '<div class="zlc-field-grid">' +
                        '<label class="zlc-field"><span>Start</span>' +
                          '<input type="time" id="zlc-sched-start" value="' + esc(sched.start || '09:00') + '">' +
                        '</label>' +
                        '<label class="zlc-field"><span>End</span>' +
                          '<input type="time" id="zlc-sched-end" value="' + esc(sched.end || '17:00') + '">' +
                        '</label>' +
                      '</div>' +
                      '<div class="zlc-field"><span>Days of week</span>' +
                        '<div class="zlc-day-grid">' + dayBoxes + '</div>' +
                      '</div>' +
                      '<p class="zlc-modal-hint zlc-modal-hint-sm">If end time is earlier than start (e.g. 22:00 → 02:00), the window crosses midnight into the next day.</p>' +
                    '</div>' +
                    '<div class="zlc-modal-footer">' +
                      '<button class="zlc-btn zlc-btn-outline" id="zlc-sched-cancel">Cancel</button>' +
                      '<button class="zlc-btn" id="zlc-sched-save">Save</button>' +
                    '</div>' +
                  '</div>' +
                '</div>';

            var wrap = document.createElement('div');
            wrap.innerHTML = html;
            document.body.appendChild(wrap.firstChild);

            function close() {
                var el = document.getElementById('zlc-sched-backdrop');
                if (el) el.parentNode.removeChild(el);
            }

            document.getElementById('zlc-sched-close').addEventListener('click', close);
            document.getElementById('zlc-sched-cancel').addEventListener('click', close);
            document.getElementById('zlc-sched-backdrop').addEventListener('click', function (e) {
                if (e.target.id === 'zlc-sched-backdrop') close();
            });

            var detectBtn = document.getElementById('zlc-sched-tz-detect');
            if (detectBtn) {
                detectBtn.addEventListener('click', function () {
                    var sel = document.getElementById('zlc-sched-tz');
                    if (sel && Array.prototype.some.call(sel.options, function (o) { return o.value === browserTz; })) {
                        sel.value = browserTz;
                        var helper = detectBtn.parentNode;
                        if (helper) helper.innerHTML = '<span class="zlc-tz-detected">Detected: ' + esc(browserTz) + '</span>';
                    }
                });
            }

            document.getElementById('zlc-sched-save').addEventListener('click', function () {
                var days = Array.prototype.slice
                    .call(document.querySelectorAll('.zlc-day-grid input[type=checkbox]:checked'))
                    .map(function (cb) { return parseInt(cb.value, 10); });
                var payload = {
                    enabled: document.getElementById('zlc-sched-enabled').checked,
                    tz:      document.getElementById('zlc-sched-tz').value,
                    start:   document.getElementById('zlc-sched-start').value,
                    end:     document.getElementById('zlc-sched-end').value,
                    days:    days
                };
                api('POST', '/livechat/schedule', payload).then(function () {
                    close();
                    // Refresh status select to reflect any auto-offline.
                    api('GET', '/livechat/status').then(function (s) {
                        var sel = document.getElementById('zlc-status-select');
                        if (sel && s.my_status) sel.value = s.my_status;
                    }).catch(function () {});
                }).catch(function (err) {
                    alert('Could not save schedule: ' + (err && err.message ? err.message : 'unknown error'));
                });
            });
        }).catch(function () {
            alert('Could not load schedule.');
        });
    }

    /* ---------- Chat list ---------- */

    function loadChatList() {
        api('GET', '/livechat/chats').then(function (data) {
            chats = data.chats || [];
            renderChatList();
        }).catch(function () {});
    }

    function renderChatList() {
        var list = document.getElementById('zlc-sidebar-list');
        if (!list) return;
        if (!chats.length) {
            list.innerHTML = '<div class="zlc-sidebar-empty">No active chats yet.</div>';
            return;
        }
        var html = '';
        chats.forEach(function (c) {
            var isActive = c.chat_uid === activeChatUid;
            var badges = '';
            var vs = c.visitor_status || 'offline';
            badges += '<span class="zlc-visitor-dot ' + vs + '" title="Visitor ' + vs + '"></span>';
            if (c.is_invited && !c.admin_user_id) badges += '<span class="zlc-badge-invited">invited</span> ';
            if (c.admin_user_id) badges += '<span class="zlc-badge-attached" title="Admin attached"></span> ';
            html += '<div class="zlc-sidebar-item' + (isActive ? ' active' : '') + '" data-uid="' + esc(c.chat_uid) + '">' +
                '<div class="zlc-sidebar-item-preview">' + badges + esc(truncate(c.first_message || '(empty)', 80)) + '</div>' +
                '<div class="zlc-sidebar-item-meta">' +
                    '<span>' + c.message_count + ' msgs</span>' +
                    '<span>' + esc(timeAgo(c.updated_at)) + '</span>' +
                '</div>' +
            '</div>';
        });
        list.innerHTML = html;

        // Click handler
        list.querySelectorAll('.zlc-sidebar-item').forEach(function (el) {
            el.addEventListener('click', function () {
                openChat(el.dataset.uid);
            });
        });
    }

    /* ---------- Open a chat ---------- */

    function openChat(uid) {
        var isSameChat = (activeChatUid === uid);
        activeChatUid = uid;
        if (!isSameChat) {
            lastEventId = 0;
            helperText = '';
            helperOpen = false;
        }
        stopChatPolling();
        renderChatList(); // highlight
        // Mobile: show main, hide sidebar
        var layout = document.querySelector('.zlc-layout');
        if (layout) layout.classList.add('chat-open');

        api('GET', '/livechat/chat/' + encodeURIComponent(uid)).then(function (data) {
            activeChatData = data;
            // Seed poll from the latest event so we don't replay old attach/detach history.
            if (data.last_event_id) lastEventId = Math.max(lastEventId, data.last_event_id);
            renderChat();
            startChatPolling();
        }).catch(function () {
            var main = document.getElementById('zlc-main');
            if (main) main.innerHTML = '<div class="zlc-main-empty">Failed to load chat.</div>';
        });
    }

    /* ---------- Render chat detail ---------- */

    function renderChat() {
        var main = document.getElementById('zlc-main');
        if (!main || !activeChatData) return;
        var d = activeChatData;
        var isAttachedByMe = d.admin_user_id === (currentUser ? currentUser.id : -1);
        var isAttached = d.admin_user_id !== null;

        var attachBtnLabel = isAttachedByMe ? 'Release chat' : (isAttached ? 'Another admin is in this chat' : 'Take over chat');
        var attachBtnClass = isAttachedByMe ? 'zlc-btn zlc-btn-sm zlc-btn-danger' : 'zlc-btn zlc-btn-sm';
        var attachDisabled = isAttached && !isAttachedByMe ? ' disabled' : '';

        // Build context badges for the header.
        var headerBadges = '';
        var dvs = d.visitor_status || 'offline';
        if (d.was_invited) {
            headerBadges += '<span class="zlc-badge-invited">you were invited</span> ';
        } else {
            var invitedIds = d.invited_user_ids || [];
            if (invitedIds.length > 0) {
                headerBadges += '<span class="zlc-badge-invited">invited</span> ';
            }
        }
        if (d.referrer) {
            var ref = d.referrer.replace(/^https?:\/\//, '').split('/')[0];
            headerBadges += '<span class="zlc-badge-ref" title="' + esc(d.referrer) + '">from ' + esc(ref) + '</span> ';
        }
        if (d.utm_source) {
            headerBadges += '<span class="zlc-badge-ref">utm: ' + esc(d.utm_source) + (d.utm_campaign ? ' / ' + esc(d.utm_campaign) : '') + '</span> ';
        }

        main.innerHTML =
            '<div class="zlc-chat-header">' +
                '<div class="zlc-chat-header-row">' +
                    '<div class="zlc-chat-header-info">' +
                        '<button class="zlc-back-btn" id="zlc-back" title="Back to chats">← Chats</button>' +
                        '<span class="zlc-visitor-dot ' + dvs + '"></span> Visitor ' + dvs +
                    '</div>' +
                    '<div class="zlc-chat-header-actions">' +
                        '<button class="' + attachBtnClass + '" id="zlc-attach"' + attachDisabled + '>' + attachBtnLabel + '</button>' +
                    '</div>' +
                '</div>' +
                '<div class="zlc-chat-header-meta">' +
                    'Chat <code>' + esc(d.chat_uid.slice(0, 8)) + '…</code> · ' + d.message_count + ' msgs' +
                '</div>' +
                (headerBadges ? '<div class="zlc-chat-header-badges">' + headerBadges + '</div>' : '') +
            '</div>' +
            '<div class="zlc-messages" id="zlc-messages"></div>' +
            '<div class="zlc-response" id="zlc-response"' + (isAttachedByMe ? '' : ' style="display:none"') + '>' +
                '<div class="zlc-response-row">' +
                    '<textarea id="zlc-response-input" placeholder="Type your response to the visitor…" rows="2"></textarea>' +
                    '<button class="zlc-btn zlc-btn-sm" id="zlc-response-send">Send</button>' +
                '</div>' +
                '<div class="zlc-response-status" id="zlc-response-status"></div>' +
            '</div>' +
            '<div class="zlc-helper" id="zlc-helper"' + (isAttachedByMe ? '' : ' style="display:none"') + '>' +
                '<button class="zlc-helper-toggle" id="zlc-helper-toggle">🤖 AI Helper — ask the AI for help drafting a response</button>' +
                '<div class="zlc-helper-panel" id="zlc-helper-panel" style="display:none">' +
                    '<div class="zlc-helper-row">' +
                        '<textarea id="zlc-helper-input" placeholder="Ask the AI…" rows="2"></textarea>' +
                        '<button class="zlc-btn zlc-btn-sm zlc-btn-outline" id="zlc-helper-ask">Ask AI</button>' +
                    '</div>' +
                    '<div class="zlc-helper-response" id="zlc-helper-response"></div>' +
                    '<div class="zlc-helper-actions">' +
                        '<button class="zlc-btn zlc-btn-sm zlc-btn-outline" id="zlc-helper-copy">↑ Copy to response</button>' +
                    '</div>' +
                '</div>' +
            '</div>';

        renderMessages(d.messages || []);
        wireChat();
    }

    function renderMessages(messages) {
        var container = document.getElementById('zlc-messages');
        if (!container) return;
        var html = '';
        messages.forEach(function (m) {
            if (!m || !m.role || !m.content) return;
            var cls, label;
            if (m.role === 'user') { cls = 'zlc-msg-visitor'; label = 'VISITOR'; }
            else if (m.role === 'admin') { cls = 'zlc-msg-admin'; label = 'ADMIN' + (m.admin_name ? ' (' + esc(m.admin_name) + ')' : ''); }
            else { cls = 'zlc-msg-ai'; label = 'AI'; }
            html += '<div class="zlc-msg ' + cls + '">' +
                '<div class="zlc-msg-label">' + label + '</div>' +
                esc(m.content) +
            '</div>';
        });
        container.innerHTML = html;
        container.scrollTop = container.scrollHeight;
    }

    function closeChat() {
        activeChatUid = '';
        activeChatData = null;
        stopChatPolling();
        var layout = document.querySelector('.zlc-layout');
        if (layout) layout.classList.remove('chat-open');
        var main = document.getElementById('zlc-main');
        if (main) main.innerHTML = '<div class="zlc-main-empty">Select a chat from the list</div>';
        renderChatList();
    }

    function wireChat() {
        // Back button (mobile)
        var backBtn = document.getElementById('zlc-back');
        if (backBtn) {
            backBtn.addEventListener('click', closeChat);
        }

        // Attach / Detach
        var attachBtn = document.getElementById('zlc-attach');
        if (attachBtn && !attachBtn.disabled) {
            attachBtn.addEventListener('click', function () {
                var isAttachedByMe = activeChatData && activeChatData.admin_user_id === (currentUser ? currentUser.id : -1);
                if (isAttachedByMe) {
                    api('POST', '/livechat/chat/' + encodeURIComponent(activeChatUid) + '/detach')
                        .then(function () { openChat(activeChatUid); })
                        .catch(function () { alert('Detach failed'); });
                } else {
                    api('POST', '/livechat/chat/' + encodeURIComponent(activeChatUid) + '/attach')
                        .then(function (r) {
                            if (r.attached) openChat(activeChatUid);
                            else alert(r.message || 'Could not attach');
                        })
                        .catch(function () { alert('Attach failed'); });
                }
            });
        }

        // Send response
        var sendBtn = document.getElementById('zlc-response-send');
        var responseInput = document.getElementById('zlc-response-input');
        var responseStatus = document.getElementById('zlc-response-status');
        if (sendBtn && responseInput) {
            sendBtn.addEventListener('click', function () {
                var content = responseInput.value.trim();
                if (!content) return;
                sendBtn.disabled = true;
                responseStatus.textContent = 'Sending…';
                api('POST', '/livechat/chat/' + encodeURIComponent(activeChatUid) + '/send', { content: content })
                    .then(function (r) {
                        if (r.sent) {
                            responseInput.value = '';
                            responseStatus.textContent = '';
                            // Append locally for immediate feedback
                            var msgs = document.getElementById('zlc-messages');
                            if (msgs) {
                                var div = document.createElement('div');
                                div.className = 'zlc-msg zlc-msg-admin';
                                div.innerHTML = '<div class="zlc-msg-label">ADMIN (' + esc(currentUser ? currentUser.display_name : '') + ')</div>' + esc(content);
                                msgs.appendChild(div);
                                msgs.scrollTop = msgs.scrollHeight;
                            }
                        } else {
                            responseStatus.textContent = r.message || 'Send failed';
                        }
                    })
                    .catch(function () { responseStatus.textContent = 'Request failed'; })
                    .finally(function () { sendBtn.disabled = false; });
            });
            responseInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendBtn.click();
                }
            });
        }

        // AI Helper toggle
        var helperToggle = document.getElementById('zlc-helper-toggle');
        var helperPanel = document.getElementById('zlc-helper-panel');
        if (helperToggle && helperPanel) {
            helperToggle.addEventListener('click', function () {
                helperOpen = !helperOpen;
                helperPanel.style.display = helperOpen ? '' : 'none';
            });
        }

        // AI Helper ask
        var helperAskBtn = document.getElementById('zlc-helper-ask');
        var helperInput = document.getElementById('zlc-helper-input');
        var helperResponse = document.getElementById('zlc-helper-response');
        if (helperAskBtn && helperInput && helperResponse) {
            helperAskBtn.addEventListener('click', function () {
                var question = helperInput.value.trim();
                if (!question) return;
                helperAskBtn.disabled = true;
                helperResponse.textContent = '…';
                helperText = '';
                streamAiHelper(question, helperResponse, function () {
                    helperAskBtn.disabled = false;
                });
            });
            helperInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    helperAskBtn.click();
                }
            });
        }

        // Copy AI helper response to the response textarea
        var copyBtn = document.getElementById('zlc-helper-copy');
        if (copyBtn && responseInput) {
            copyBtn.addEventListener('click', function () {
                if (helperText) {
                    responseInput.value = helperText;
                    responseInput.focus();
                }
            });
        }
    }

    /* ---------- AI Helper streaming ---------- */

    function streamAiHelper(question, targetEl, onDone) {
        fetch(rest + '/livechat/ai-helper', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + sessionToken
            },
            credentials: 'same-origin',
            body: JSON.stringify({ chat_uid: activeChatUid, question: question })
        }).then(function (response) {
            if (!response.ok || !response.body) {
                targetEl.textContent = 'Request failed (' + response.status + ')';
                onDone();
                return;
            }
            var reader = response.body.getReader();
            var decoder = new TextDecoder();
            var buffer = '';
            helperText = '';

            function read() {
                reader.read().then(function (result) {
                    if (result.done) { onDone(); return; }
                    buffer += decoder.decode(result.value, { stream: true });
                    var lines = buffer.split('\n');
                    buffer = lines.pop();
                    lines.forEach(function (line) {
                        if (line.indexOf('data: ') !== 0) return;
                        var data = line.slice(6);
                        if (data === '[DONE]') return;
                        try {
                            var event = JSON.parse(data);
                            if (event.type === 'content_block_delta' && event.delta && event.delta.text) {
                                helperText += event.delta.text;
                                targetEl.textContent = helperText;
                            }
                        } catch (e) {}
                    });
                    read();
                }).catch(function () { onDone(); });
            }
            read();
        }).catch(function () {
            targetEl.textContent = 'Connection error';
            onDone();
        });
    }

    /* ---------- Polling ---------- */

    function startChatPolling() {
        stopChatPolling();
        pollTimer = setInterval(pollChat, 2000);
    }
    function stopChatPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }
    function stopPolling() {
        stopChatPolling();
        if (listTimer) { clearInterval(listTimer); listTimer = null; }
    }

    function pollChat() {
        if (!activeChatUid) return;
        api('GET', '/livechat/chat/' + encodeURIComponent(activeChatUid) + '/poll?since_id=' + lastEventId)
            .then(function (data) {
                var events = data.events || [];
                if (!events.length) return;

                var msgs = document.getElementById('zlc-messages');
                var hasNewVisitorMsg = false;

                events.forEach(function (e) {
                    lastEventId = Math.max(lastEventId, parseInt(e.id, 10));
                    var p = e.payload || {};

                    if (e.event_type === 'message_visitor' && msgs) {
                        var div = document.createElement('div');
                        div.className = 'zlc-msg zlc-msg-visitor';
                        div.innerHTML = '<div class="zlc-msg-label">VISITOR</div>' + esc(p.content || '');
                        msgs.appendChild(div);
                        msgs.scrollTop = msgs.scrollHeight;
                        hasNewVisitorMsg = true;
                    } else if (e.event_type === 'admin_attached') {
                        // Another admin attached — update header + hide response
                        // area inline. Do NOT call openChat() (would reset
                        // lastEventId and cause an infinite loop).
                        updateAttachState(p.user_id || null, true);
                    } else if (e.event_type === 'admin_detached') {
                        updateAttachState(null, false);
                    } else if (e.event_type === 'admin_invited') {
                        loadChatList();
                    }
                    // Silently skip heartbeat events — they exist only for
                    // the auto-detach timeout check.
                });

                if (hasNewVisitorMsg) {
                    playNotificationSound();
                    if (document.hidden) notifyNewMessage();
                }
            })
            .catch(function () {});
    }

    /**
     * Update the attach/detach UI inline without re-rendering the whole
     * chat. Prevents the infinite-loop bug where openChat() resets
     * lastEventId and the same event is picked up again.
     */
    function updateAttachState(adminUserId, isAttached) {
        if (activeChatData) {
            activeChatData.admin_user_id = adminUserId;
        }
        var myId = currentUser ? currentUser.id : -1;
        var isMe = (adminUserId === myId);

        // Update the attach/detach button.
        var btn = document.getElementById('zlc-attach');
        if (btn) {
            if (isAttached && isMe) {
                btn.textContent = 'Release chat';
                btn.className = 'zlc-btn zlc-btn-sm zlc-btn-danger';
                btn.disabled = false;
            } else if (isAttached) {
                btn.textContent = 'Another admin is in this chat';
                btn.className = 'zlc-btn zlc-btn-sm';
                btn.disabled = true;
            } else {
                btn.textContent = 'Take over chat';
                btn.className = 'zlc-btn zlc-btn-sm';
                btn.disabled = false;
            }
        }

        // Show/hide the response area and AI helper.
        var responseEl = document.getElementById('zlc-response');
        var helperEl = document.getElementById('zlc-helper');
        if (responseEl) responseEl.style.display = (isAttached && isMe) ? '' : 'none';
        if (helperEl) helperEl.style.display = (isAttached && isMe) ? '' : 'none';
    }

    /* ---------- Sound notification ---------- */

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

    /* ---------- In-page notification for background tab ---------- */

    var originalTitle = document.title;
    var titleFlashTimer = null;

    function notifyNewMessage() {
        playNotificationSound();
        // Flash the browser tab title.
        if (!titleFlashTimer) {
            var on = true;
            titleFlashTimer = setInterval(function () {
                document.title = on ? '💬 New message!' : originalTitle;
                on = !on;
            }, 1000);
            // Stop flashing when the page becomes visible again.
            document.addEventListener('visibilitychange', function handler() {
                if (!document.hidden) {
                    clearInterval(titleFlashTimer);
                    titleFlashTimer = null;
                    document.title = originalTitle;
                    document.removeEventListener('visibilitychange', handler);
                }
            });
        }

        // Browser notification (if permission was granted).
        if ('Notification' in window && Notification.permission === 'granted') {
            try {
                new Notification('New visitor message', {
                    body: 'A visitor sent a message in the live chat.',
                    icon: (window.zlcConfig && zlcConfig.icon) || undefined,
                    tag: 'zlc-msg-' + activeChatUid
                });
            } catch (e) {}
        }
    }

    /* ---------- Boot ---------- */

    function boot() {
        loadSession();

        // Check for magic link auth token in URL.
        var urlParams = new URLSearchParams(window.location.search);
        var authToken = urlParams.get('auth_token');
        var authUserId = urlParams.get('user_id');

        if (authToken && authUserId) {
            // Strip auth params from URL.
            try {
                var u = new URL(window.location.href);
                u.searchParams.delete('auth_token');
                u.searchParams.delete('user_id');
                window.history.replaceState({}, '', u.toString());
            } catch (e) {}

            app.innerHTML = '<div class="zlc-login"><div class="zlc-login-card"><p>Logging in…</p></div></div>';
            api('POST', '/livechat/auth/verify', { token: authToken, user_id: parseInt(authUserId, 10) })
                .then(function (data) {
                    if (data.session_token && data.user) {
                        saveSession(data.session_token, data.user);
                        renderApp();

                        // Auto-open chat if open_chat param is present.
                        var openUid = urlParams.get('open_chat');
                        if (openUid) setTimeout(function () { openChat(openUid); }, 500);
                    } else {
                        renderLogin();
                    }
                })
                .catch(function () { renderLogin(); });
            return;
        }

        // Normal boot: check existing session.
        if (sessionToken) {
            // Quick validation: try loading chats.
            api('GET', '/livechat/chats')
                .then(function (data) {
                    chats = data.chats || [];
                    renderApp();

                    var openUid = urlParams.get('open_chat');
                    if (openUid) setTimeout(function () { openChat(openUid); }, 300);
                })
                .catch(function () { renderLogin(); });
        } else {
            renderLogin();
        }
    }

    /* ---------- PWA: Service Worker + Push subscription ---------- */

    function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) return;
        navigator.serviceWorker.register('/livechat-sw.js', { scope: '/' })
            .then(function (reg) {
                // Once registered, subscribe to push if we have a session.
                if (sessionToken && 'PushManager' in window) {
                    subscribeToPush(reg);
                }
            })
            .catch(function (err) {
                // SW registration failed — non-critical, push won't work but chat still does.
            });
    }

    function subscribeToPush(registration) {
        // Fetch the VAPID public key from the server.
        fetch(rest + '/livechat/push/vapid-public-key', {
            headers: { 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.publicKey) return;

            var rawKey = urlBase64ToUint8Array(data.publicKey);
            return registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: rawKey
            });
        })
        .then(function (subscription) {
            if (!subscription) return;
            var key = subscription.getKey('p256dh');
            var auth = subscription.getKey('auth');

            // Send subscription to server.
            return fetch(rest + '/livechat/push/subscribe', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + sessionToken
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    endpoint: subscription.endpoint,
                    p256dh:   arrayBufferToBase64url(key),
                    auth:     arrayBufferToBase64url(auth)
                })
            });
        })
        .catch(function () {
            // Push subscription failed — non-critical.
        });
    }

    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - base64String.length % 4) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var rawData = atob(base64);
        var outputArray = new Uint8Array(rawData.length);
        for (var i = 0; i < rawData.length; i++) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    function arrayBufferToBase64url(buffer) {
        var bytes = new Uint8Array(buffer);
        var binary = '';
        for (var i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    boot();

    // Register SW after boot (non-blocking).
    registerServiceWorker();
})();
