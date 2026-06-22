/*
 * Zen Cortext — author bio card email click-to-copy with toast.
 * Enqueued via wp_enqueue_script alongside author-bio.css.
 */
(function(){
    document.addEventListener('click',function(e){
        var el=e.target.closest('.zen-ab-email');
        if(!el)return;
        var email=el.getAttribute('data-email');
        if(!email)return;
        if(navigator.clipboard&&navigator.clipboard.writeText){
            e.preventDefault();
            navigator.clipboard.writeText(email).then(function(){showToast(el)});
        }
    });
    function showToast(el){
        var t=el.querySelector('.zen-ab-toast');
        if(!t)return;
        t.classList.add('zen-ab-show');
        setTimeout(function(){t.classList.remove('zen-ab-show')},1500);
    }
})();
