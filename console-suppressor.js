/**
 * IMCollectibles — Production Console Suppressor
 * Replaces console.log/info/debug with no-ops in production.
 * console.error and console.warn are intentionally preserved.
 *
 * To enable debug logging on any page:
 *   localStorage.setItem('imcDebug', '1') then reload
 */
(function () {
    var isLocal = (
        window.location.hostname === 'localhost' ||
        window.location.hostname === '127.0.0.1' ||
        window.location.hostname.endsWith('.local')
    );
    var isDebug = isLocal || localStorage.getItem('imcDebug') === '1';

    window.isDebug = isDebug;

    if (!isDebug) {
        var noop = function () {};
        console.log   = noop;
        console.info  = noop;
        console.debug = noop;
    }
})();