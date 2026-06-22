/*
 * Zen Cortext admin — Saved Chats list actions (delete / restore).
 * Moved out of an inline <script> block; ajaxUrl + nonce come from the
 * localized zenCortextAdmin object (see class-zen-cortext-admin.php).
 */
(function () {
    var ZC = window.zenCortextAdmin || {};
    var ajaxUrl = ZC.ajaxUrl;
    var nonce   = ZC.nonce;

    function postAction(action, id, confirmMsg) {
        if (confirmMsg && !confirm(confirmMsg)) return Promise.resolve(false);
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', nonce);
        fd.append('id', id);
        return fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp && resp.success) return true;
                alert((resp && resp.data && resp.data.message) || 'Request failed');
                return false;
            })
            .catch(function () { alert('Request failed'); return false; });
    }

    document.querySelectorAll('.zcc-delete').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var tr = a.closest('tr');
            postAction('zen_cortext_chat_delete', tr.dataset.id,
                'Permanently delete this saved chat? This cannot be undone.')
                .then(function (ok) { if (ok) tr.remove(); });
        });
    });

    document.querySelectorAll('.zcc-restore').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var tr = a.closest('tr');
            postAction('zen_cortext_chat_restore', tr.dataset.id, null)
                .then(function (ok) { if (ok) window.location.reload(); });
        });
    });
})();
