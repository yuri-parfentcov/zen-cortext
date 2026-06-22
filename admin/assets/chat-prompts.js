/*
 * Zen Cortext admin — "Adapt system prompt" modal (Chat → Prompts tab).
 * Moved out of an inline <script> block; the REST URL + nonce come from
 * the localized zenCortextAdmin object (adaptSystemPromptUrl / restNonce).
 */
(function(){
    var ZC = window.zenCortextAdmin || {};
    var $modal    = document.getElementById('zc-adapt-modal');
    var $btn      = document.getElementById('zc-adapt-system-prompt');
    var $close    = document.getElementById('zc-adapt-close');
    var $loading  = document.getElementById('zc-adapt-loading');
    var $content  = document.getElementById('zc-adapt-content');
    var $error    = document.getElementById('zc-adapt-error');
    var $current  = document.getElementById('zc-adapt-current');
    var $proposed = document.getElementById('zc-adapt-proposed');
    var $apply    = document.getElementById('zc-adapt-apply');
    var $discard  = document.getElementById('zc-adapt-discard');
    var $status   = document.getElementById('zc-adapt-status');
    var $field    = document.getElementById('zen_cortext_system_prompt');

    if (!$btn) return;

    function openModal() {
        $modal.style.display = 'block';
        $loading.style.display = 'flex';
        $content.style.display = 'none';
        $error.style.display = 'none';
        $status.textContent = '';
        $apply.disabled = true;
    }
    function closeModal() { $modal.style.display = 'none'; }
    function showError(msg) {
        $loading.style.display = 'none';
        $content.style.display = 'none';
        $error.style.display = 'block';
        $error.textContent = msg;
    }

    $btn.addEventListener('click', function() {
        openModal();
        var url   = ZC.adaptSystemPromptUrl;
        var nonce = ZC.restNonce;

        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
            body: '{}'
        })
        .then(function(r) { return r.json().then(function(data) { return { ok: r.ok, status: r.status, data: data }; }); })
        .then(function(res) {
            if (!res.ok) {
                var msg = (res.data && (res.data.message || res.data.code)) || ('HTTP ' + res.status);
                showError(msg);
                return;
            }
            $loading.style.display = 'none';
            $content.style.display = 'block';
            $current.value  = res.data.current  || '';
            $proposed.value = res.data.proposed || '';
            $apply.disabled = !$proposed.value;
            var info = '';
            if (res.data.tokens_in)  info += res.data.tokens_in  + ' in';
            if (res.data.tokens_out) info += (info ? ' / ' : '') + res.data.tokens_out + ' out';
            if (res.data.kb_chars)   info += (info ? ' · ' : '') + Math.round(res.data.kb_chars / 1000) + 'k KB chars';
            $status.textContent = info;
        })
        .catch(function(err) { showError('Network error: ' + err.message); });
    });

    $close.addEventListener('click', closeModal);
    $discard.addEventListener('click', closeModal);
    $modal.addEventListener('click', function(e) { if (e.target === $modal) closeModal(); });

    $apply.addEventListener('click', function() {
        if (!$proposed.value || !$field) return;
        $field.value = $proposed.value;
        closeModal();
        // Scroll the textarea into view and flash a confirmation.
        $field.scrollIntoView({ behavior: 'smooth', block: 'center' });
        var orig = $field.style.boxShadow;
        $field.style.boxShadow = '0 0 0 3px #2271b1';
        setTimeout(function() { $field.style.boxShadow = orig; }, 1500);
    });
})();
