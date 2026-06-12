/**
 * Shared utility functions for MyTube frontend.
 * Load this script before any other MyTube JS files.
 */
var MyTubeUtils = (function() {
    'use strict';

    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    function sanitizeUrl(url) {
        if (!url) return '#';
        var normalized = String(url).trim();
        if (/^javascript:/i.test(normalized)) return '#';
        if (/^data:/i.test(normalized)) return '#';
        return normalized;
    }

    function debounce(fn, delay) {
        var timer;
        return function() {
            var args = arguments;
            var ctx = this;
            clearTimeout(timer);
            timer = setTimeout(function() { fn.apply(ctx, args); }, delay);
        };
    }

    return {
        escapeHtml: escapeHtml,
        sanitizeUrl: sanitizeUrl,
        debounce: debounce
    };
})();
