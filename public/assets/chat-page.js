/*
 * Zen Cortext — standalone full-page chat template interactions.
 *
 * Two small, dependency-free behaviors for the "Full-page client chat"
 * template chrome: the mobile quick-actions modal and the team-card
 * email click-to-copy toast. Enqueued via wp_enqueue_script on the
 * chat-page template only. The chat itself (send/stream) lives in chat.js.
 */

/* Mobile quick-actions modal */
(function () {
    var trigger = document.getElementById('zcp-mobile-trigger');
    var modal   = document.getElementById('zcp-modal');
    var closeBtn = document.getElementById('zcp-modal-close');
    if (!trigger || !modal || !closeBtn) return;

    function open() {
        modal.hidden = false;
        document.documentElement.style.overflow = 'hidden';
    }
    function close() {
        modal.hidden = true;
        document.documentElement.style.overflow = '';
    }
    trigger.addEventListener('click', open);
    closeBtn.addEventListener('click', close);
    modal.addEventListener('click', function (e) {
        // click on the backdrop (not the card) closes
        if (e.target === modal) close();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) close();
    });
})();

/* Email click-to-copy with toast */
(function () {
    document.addEventListener('click', function (e) {
        var el = e.target.closest('.zcp-team-btn-email');
        if (!el) return;
        var email = el.getAttribute('data-email');
        if (!email) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            e.preventDefault();
            navigator.clipboard.writeText(email).then(function () {
                var t = el.querySelector('.zcp-team-toast');
                if (!t) return;
                t.classList.add('zcp-show');
                setTimeout(function () { t.classList.remove('zcp-show'); }, 1500);
            });
        }
    });
})();
