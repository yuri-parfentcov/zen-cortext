/*
 * Zen Cortext admin — Chat detail page "restore" button.
 * Moved out of an inline <script> block; ajaxUrl + nonce come from the
 * localized zenCortextAdmin object (see class-zen-cortext-admin.php).
 */
(function () {
    var ZC = window.zenCortextAdmin || {};
    var btn = document.getElementById('zcd-restore');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var fd = new FormData();
        fd.append('action', 'zen_cortext_chat_restore');
        fd.append('nonce', ZC.nonce);
        fd.append('id', btn.dataset.id);
        fetch(ZC.ajaxUrl, {
            method: 'POST', body: fd, credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
            if (resp && resp.success) { window.location.reload(); }
            else { alert((resp && resp.data && resp.data.message) || 'Restore failed'); }
        })
        .catch(function () { alert('Request failed'); });
    });
})();
