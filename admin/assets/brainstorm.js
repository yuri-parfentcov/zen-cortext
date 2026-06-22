/**
 * Zen Cortext — Admin Brainstorm chat (vanilla JS, no jQuery).
 *
 * Streams Claude Opus 4.6 with extended thinking enabled. Reads SSE from the
 * /admin-brainstorm REST endpoint and renders thinking deltas into a
 * collapsible panel above each assistant message; text deltas stream into
 * the visible bubble. Conversations are persisted server-side per admin —
 * the sidebar lists past brainstorms and lets the user load, continue, or
 * delete them.
 */
(function () {
    'use strict';

    function init() {
        var root = document.getElementById('zcb-root');
        if (!root) return;

        var cfg = (typeof window.zenCortextBrainstorm === 'object' && window.zenCortextBrainstorm) || {};
        var restUrl   = cfg.restUrl || '';
        var restNonce = cfg.restNonce || '';
        var listUrl   = restUrl ? restUrl + '/chats' : '';

        var chat    = document.getElementById('zcb-chat');
        var input   = document.getElementById('zcb-input');
        var sendBtn = document.getElementById('zcb-send');
        var newBtn  = document.getElementById('zcb-new');
        var status  = document.getElementById('zcb-status');
        var usageEl = document.getElementById('zcb-usage');
        var listEl  = document.getElementById('zcb-list');

        var messages       = [];   // [{role:'user'|'assistant', content:string}]
        var streaming      = false;
        var currentChatUid = '';   // empty == new, unsaved chat

        /* ---------- helpers ---------- */

        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML;
        }

        // Lightweight markdown — same shape as the public chat renderer at
        // public/assets/chat.js:138, kept compatible so output looks similar
        // across the visitor and admin chats.
        function renderMarkdown(text) {
            var html = escapeHtml(text);
            html = html.replace(/```([\s\S]*?)```/g, function (_, code) {
                return '<pre><code>' + code.replace(/&lt;/g, '&lt;') + '</code></pre>';
            });
            html = html.replace(/^### (.+)$/gm, '<h3>$1</h3>');
            html = html.replace(/^## (.+)$/gm, '<h2>$1</h2>');
            html = html.replace(/^# (.+)$/gm, '<h1>$1</h1>');
            html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            html = html.replace(/(^|[^\*])\*([^\*\n]+)\*(?!\*)/g, '$1<em>$2</em>');
            html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
            html = html.replace(/^- (.+)$/gm, '<li>$1</li>');
            html = html.replace(/(<li>.*<\/li>\n?)+/g, '<ul>$&</ul>');
            html = html.replace(/\n\n/g, '</p><p>');
            html = '<p>' + html + '</p>';
            html = html.replace(/<p>\s*<\/p>/g, '');
            html = html.replace(/<p>\s*(<[hupl])/g, '$1');
            html = html.replace(/(<\/[hupl]l?>)\s*<\/p>/g, '$1');
            return html;
        }

        function scrollToBottom() {
            chat.scrollTop = chat.scrollHeight;
        }

        function setStatus(text, isError) {
            status.textContent = text || '';
            status.className = 'zcb-status' + (isError ? ' is-error' : '');
        }

        function showUsage(usage) {
            if (!usage || !usageEl) return;
            var parts = [];
            if (usage.input_tokens != null)         parts.push('in ' + usage.input_tokens);
            if (usage.cache_read_input_tokens)      parts.push('cache-read ' + usage.cache_read_input_tokens);
            if (usage.cache_creation_input_tokens)  parts.push('cache-write ' + usage.cache_creation_input_tokens);
            if (usage.output_tokens != null)        parts.push('out ' + usage.output_tokens);
            usageEl.textContent = parts.join(' · ');
            usageEl.hidden = parts.length === 0;
        }

        // Format an ISO/MySQL datetime as a short relative label.
        function relativeTime(iso) {
            if (!iso) return '';
            // Treat MySQL "YYYY-MM-DD HH:MM:SS" as local time (matches WP).
            var d = new Date(iso.replace(' ', 'T'));
            if (isNaN(d.getTime())) return iso;
            var diff = (Date.now() - d.getTime()) / 1000;
            if (diff < 60)        return Math.round(diff) + 's ago';
            if (diff < 3600)      return Math.round(diff / 60) + 'm ago';
            if (diff < 86400)     return Math.round(diff / 3600) + 'h ago';
            if (diff < 86400 * 7) return Math.round(diff / 86400) + 'd ago';
            return d.toLocaleDateString();
        }

        /* ---------- chat rendering ---------- */

        function clearChat() {
            chat.innerHTML = '';
        }

        function addSystemMessage(text) {
            var div = document.createElement('div');
            div.className = 'zcb-msg zcb-msg-system';
            div.textContent = text;
            chat.appendChild(div);
            scrollToBottom();
        }

        function addUserMessage(content) {
            var div = document.createElement('div');
            div.className = 'zcb-msg zcb-msg-user';
            div.textContent = content;
            chat.appendChild(div);
            scrollToBottom();
        }

        // Final, fully-rendered assistant bubble (used when replaying history).
        function addAssistantMessage(content) {
            var wrap = document.createElement('div');
            wrap.className = 'zcb-msg zcb-msg-assistant';
            var bubble = document.createElement('div');
            bubble.className = 'zcb-bubble';
            bubble.innerHTML = renderMarkdown(content);
            wrap.appendChild(bubble);
            chat.appendChild(wrap);
            scrollToBottom();
        }

        // Streaming-ready container — returns refs for live updates.
        function addAssistantContainer() {
            var wrap = document.createElement('div');
            wrap.className = 'zcb-msg zcb-msg-assistant';

            var thinkingDetails = document.createElement('details');
            thinkingDetails.className = 'zcb-thinking';
            thinkingDetails.hidden = true;
            var summary = document.createElement('summary');
            summary.textContent = '🧠 Thinking…';
            var thinkingBody = document.createElement('div');
            thinkingBody.className = 'zcb-thinking-body';
            thinkingDetails.appendChild(summary);
            thinkingDetails.appendChild(thinkingBody);

            var bubble = document.createElement('div');
            bubble.className = 'zcb-bubble';
            bubble.innerHTML = '<span class="zcb-cursor">▍</span>';

            wrap.appendChild(thinkingDetails);
            wrap.appendChild(bubble);
            chat.appendChild(wrap);
            scrollToBottom();
            return {
                thinkingDetails: thinkingDetails,
                thinkingSummary: summary,
                thinkingBody: thinkingBody,
                bubble: bubble,
                container: wrap
            };
        }

        /* ---------- sidebar ---------- */

        function renderList(chats) {
            listEl.innerHTML = '';
            if (!chats || !chats.length) {
                var empty = document.createElement('div');
                empty.className = 'zcb-list-empty';
                empty.textContent = 'No saved brainstorms yet.';
                listEl.appendChild(empty);
                return;
            }
            chats.forEach(function (c) {
                var item = document.createElement('div');
                item.className = 'zcb-list-item';
                if (c.uid === currentChatUid) item.classList.add('is-active');
                item.dataset.uid = c.uid;

                var title = document.createElement('div');
                title.className = 'zcb-list-title';
                title.textContent = c.title || '(untitled)';
                item.appendChild(title);

                var meta = document.createElement('div');
                meta.className = 'zcb-list-meta';
                var msgs = (c.message_count != null) ? (c.message_count + ' msg · ') : '';
                meta.textContent = msgs + relativeTime(c.updated_at);
                item.appendChild(meta);

                var del = document.createElement('button');
                del.type = 'button';
                del.className = 'zcb-list-delete';
                del.title = 'Delete this brainstorm';
                del.setAttribute('aria-label', 'Delete brainstorm: ' + (c.title || 'untitled'));
                del.textContent = '×';
                del.addEventListener('click', function (e) {
                    e.stopPropagation();
                    deleteChat(c.uid, c.title || '');
                });
                item.appendChild(del);

                item.addEventListener('click', function () {
                    if (streaming) return;
                    if (c.uid === currentChatUid) return;
                    loadChat(c.uid);
                });
                listEl.appendChild(item);
            });
        }

        function refreshList() {
            if (!listUrl) return Promise.resolve();
            return fetch(listUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-WP-Nonce': restNonce }
            }).then(function (r) {
                if (!r.ok) throw new Error('list failed: ' + r.status);
                return r.json();
            }).then(function (data) {
                renderList(data && data.chats ? data.chats : []);
            }).catch(function () {
                listEl.innerHTML = '<div class="zcb-list-empty">Failed to load chats.</div>';
            });
        }

        function loadChat(uid) {
            if (!uid || streaming) return;
            setStatus('Loading…');
            fetch(listUrl + '/' + encodeURIComponent(uid), {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-WP-Nonce': restNonce }
            }).then(function (r) {
                if (!r.ok) throw new Error('load failed: ' + r.status);
                return r.json();
            }).then(function (data) {
                currentChatUid = data.uid || uid;
                messages = Array.isArray(data.messages) ? data.messages.slice() : [];
                clearChat();
                if (!messages.length) {
                    addSystemMessage('Empty chat — start typing to continue.');
                } else {
                    messages.forEach(function (m) {
                        if (!m || !m.role || !m.content) return;
                        if (m.role === 'user')      addUserMessage(m.content);
                        else if (m.role === 'assistant') addAssistantMessage(m.content);
                    });
                }
                if (usageEl) { usageEl.hidden = true; usageEl.textContent = ''; }
                setStatus('');
                input.focus();
                refreshList(); // re-render to highlight the active item
            }).catch(function () {
                setStatus('Could not load that chat.', true);
            });
        }

        function deleteChat(uid, title) {
            if (!uid) return;
            var label = title ? '"' + title + '"' : 'this brainstorm';
            if (!window.confirm('Delete ' + label + '? This cannot be undone.')) return;
            fetch(listUrl + '/' + encodeURIComponent(uid), {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-WP-Nonce': restNonce }
            }).then(function (r) {
                if (!r.ok) throw new Error('delete failed: ' + r.status);
                return r.json();
            }).then(function () {
                if (uid === currentChatUid) {
                    resetChat(/*silent=*/true);
                }
                refreshList();
            }).catch(function () {
                setStatus('Delete failed.', true);
            });
        }

        /* ---------- send + stream ---------- */

        function send() {
            if (streaming) return;
            var text = input.value.trim();
            if (!text) return;
            if (!restUrl) {
                setStatus('REST URL missing — page misconfigured.', true);
                return;
            }

            messages.push({ role: 'user', content: text });
            addUserMessage(text);
            input.value = '';
            input.style.height = 'auto';
            streaming = true;
            sendBtn.disabled = true;
            setStatus('Thinking…');

            var ui = addAssistantContainer();
            var assistantText = '';
            var thinkingText = '';
            var currentBlockType = null;
            var errorMsg = '';

            var requestBody = { messages: messages };
            if (currentChatUid) requestBody.chat_uid = currentChatUid;
            var modelSel = document.getElementById('zcb-model');
            if (modelSel && modelSel.value) requestBody.model = modelSel.value;

            fetch(restUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': restNonce,
                    'Accept': 'text/event-stream'
                },
                body: JSON.stringify(requestBody)
            }).then(function (response) {
                if (!response.ok || !response.body) {
                    ui.bubble.textContent = 'Request failed (' + response.status + ').';
                    streaming = false;
                    sendBtn.disabled = false;
                    setStatus('Request failed.', true);
                    return;
                }
                var reader = response.body.getReader();
                var decoder = new TextDecoder();
                var buffer = '';

                function pump() {
                    return reader.read().then(function (result) {
                        if (result.done) {
                            finishStream();
                            return;
                        }
                        buffer += decoder.decode(result.value, { stream: true });
                        var lines = buffer.split('\n');
                        buffer = lines.pop();
                        for (var i = 0; i < lines.length; i++) {
                            var line = lines[i];
                            if (line.indexOf('data: ') !== 0) continue;
                            var data = line.slice(6);
                            if (data === '[DONE]') continue;
                            try {
                                handleEvent(JSON.parse(data));
                            } catch (e) { /* keepalive or non-JSON line */ }
                        }
                        return pump();
                    });
                }

                function handleEvent(ev) {
                    if (!ev || !ev.type) return;

                    // Server-emitted custom event — tells us the uid this
                    // conversation is now bound to (always emitted before
                    // the Anthropic stream begins).
                    if (ev.type === 'chat_meta' && ev.chat_uid) {
                        currentChatUid = ev.chat_uid;
                        return;
                    }

                    if (ev.type === 'error') {
                        var msg = 'unknown';
                        if (typeof ev.error === 'string') {
                            msg = ev.error;
                        } else if (ev.error && typeof ev.error === 'object') {
                            msg = ev.error.message || ev.error.type || JSON.stringify(ev.error);
                        }
                        errorMsg = msg;
                        ui.bubble.textContent = 'Error: ' + msg;
                        setStatus('Error: ' + msg, true);
                        return;
                    }

                    if (ev.type === 'content_block_start') {
                        var blockType = ev.content_block && ev.content_block.type;
                        currentBlockType = blockType;
                        if (blockType === 'thinking') {
                            ui.thinkingDetails.hidden = false;
                            ui.thinkingDetails.open = true;
                            ui.thinkingSummary.textContent = '🧠 Thinking…';
                        }
                        return;
                    }

                    if (ev.type === 'content_block_delta' && ev.delta) {
                        if (ev.delta.type === 'thinking_delta' && typeof ev.delta.thinking === 'string') {
                            thinkingText += ev.delta.thinking;
                            ui.thinkingBody.textContent = thinkingText;
                            scrollToBottom();
                            return;
                        }
                        if (ev.delta.type === 'text_delta' && typeof ev.delta.text === 'string') {
                            assistantText += ev.delta.text;
                            ui.bubble.textContent = assistantText;
                            scrollToBottom();
                            return;
                        }
                        if (typeof ev.delta.text === 'string') {
                            assistantText += ev.delta.text;
                            ui.bubble.textContent = assistantText;
                            scrollToBottom();
                        }
                        return;
                    }

                    if (ev.type === 'content_block_stop') {
                        if (currentBlockType === 'thinking') {
                            ui.thinkingSummary.textContent = '🧠 Thinking (done)';
                            ui.thinkingDetails.open = false;
                        }
                        currentBlockType = null;
                        return;
                    }

                    if (ev.type === 'message_delta' && ev.usage) {
                        showUsage(ev.usage);
                        return;
                    }

                    if (ev.type === 'message_start' && ev.message && ev.message.usage) {
                        showUsage(ev.message.usage);
                        return;
                    }
                }

                function finishStream() {
                    if (assistantText) {
                        ui.bubble.innerHTML = renderMarkdown(assistantText);
                        messages.push({ role: 'assistant', content: assistantText });
                        setStatus('');
                    } else if (errorMsg) {
                        // Keep the error visible — don't overwrite with the
                        // generic placeholder. Status already reflects it.
                    } else {
                        ui.bubble.textContent = '(no response — stream ended without text or error event)';
                        setStatus('');
                    }
                    streaming = false;
                    sendBtn.disabled = false;
                    input.focus();
                    // The server has just persisted this turn — refresh the
                    // sidebar so the (possibly new) chat appears at the top
                    // and the current item gets the active highlight.
                    refreshList();
                }

                pump().catch(function (err) {
                    ui.bubble.textContent = 'Stream error: ' + (err && err.message ? err.message : 'unknown');
                    streaming = false;
                    sendBtn.disabled = false;
                    setStatus('Stream error.', true);
                });
            }).catch(function (err) {
                ui.bubble.textContent = 'Connection error: ' + (err && err.message ? err.message : 'unknown');
                streaming = false;
                sendBtn.disabled = false;
                setStatus('Connection error.', true);
            });
        }

        function resetChat(silent) {
            if (streaming) return;
            messages = [];
            currentChatUid = '';
            clearChat();
            if (!silent) {
                addSystemMessage('New brainstorm — what do you want to think through?');
            } else {
                addSystemMessage('Pick a brainstorm from the sidebar or start a new one.');
            }
            if (usageEl) { usageEl.hidden = true; usageEl.textContent = ''; }
            setStatus('');
            input.focus();
            refreshList(); // clear active highlight
        }

        /* ---------- wiring ---------- */

        sendBtn.addEventListener('click', send);
        newBtn.addEventListener('click', function () { resetChat(false); });

        input.addEventListener('keydown', function (e) {
            // Cmd/Ctrl + Enter sends. Plain Enter inserts a newline (multi-line
            // brainstorming prompts are common).
            if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
                e.preventDefault();
                send();
            }
        });

        input.addEventListener('input', function () {
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 280) + 'px';
        });

        // Initial sidebar load.
        refreshList();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
