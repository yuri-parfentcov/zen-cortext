/*
 * Zen Cortext admin — Chat "side rail / quick links" emoji inserter.
 * Moved out of an inline <script> block (no server data needed).
 */
(function(){
    var $ta = document.getElementById('zen-cortext-quick-links');
    if (!$ta) return;
    document.querySelectorAll('.zcql-emoji-btn').forEach(function(btn){
        btn.addEventListener('click', function(e){
            e.preventDefault();
            var emoji = btn.getAttribute('data-emoji') || '';
            if (!emoji) return;
            var start = $ta.selectionStart, end = $ta.selectionEnd;
            var val   = $ta.value;
            // Insert at the cursor. If the cursor is at the
            // start of a non-empty line and the line doesn't
            // already start with an icon, follow up with " | "
            // so the admin can type prefix/label/url directly.
            var before     = val.slice(0, start);
            var after      = val.slice(end);
            var lineStart  = before.lastIndexOf('\n') + 1;
            var atLineHead = (start === lineStart);
            var insert     = atLineHead && after.charAt(0) !== '|' ? (emoji + ' | ') : emoji;
            $ta.value      = before + insert + after;
            var caret      = start + insert.length;
            $ta.focus();
            $ta.setSelectionRange(caret, caret);
        });
    });
})();
