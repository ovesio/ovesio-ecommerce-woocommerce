jQuery(document).ready(function($) {
    $('.ovesio-copy-btn').on('click', function(e) {
        e.preventDefault();
        var targetId = $(this).data('target');
        var input = $('#' + targetId);
        input.select();
        document.execCommand("copy");
        
        // Visual feedback
        var btn = $(this);
        var originalText = btn.text();
        btn.text('Copied!');
        setTimeout(function() {
            btn.text(originalText);
        }, 1500);
    });
});
