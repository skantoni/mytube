/**
 * Global Avatar Fallback Handler
 * Intercepts broken avatar images and replaces with default.jpg
 * Works for both static PHP-rendered images and dynamically injected ones (feed, comments, etc.)
 */
(function() {
    var DEFAULT_AVATAR = 'assets/images/avatars/default.jpg';

    document.addEventListener('error', function(e) {
        var img = e.target;
        if (img.tagName !== 'IMG') return;
        var src = img.getAttribute('src') || '';
        // Only handle avatar images
        if (src.indexOf('assets/images/avatars/') === -1) return;
        // Prevent infinite loop
        if (src.indexOf('default.jpg') !== -1 || img.dataset.fallbackApplied) return;
        img.dataset.fallbackApplied = '1';
        img.src = DEFAULT_AVATAR;
    }, true); // useCapture = true to catch errors before they bubble
})();
