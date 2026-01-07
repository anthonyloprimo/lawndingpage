// Cache-busting helper for site version changes.
(function () {
    var root = document.documentElement;
    if (!root) {
        return;
    }
    var serverVersion = root.getAttribute('data-site-version') || '';
    if (!serverVersion) {
        return;
    }
    var encodedVersion = encodeURIComponent(serverVersion);
    var stored = '';
    try {
        stored = window.localStorage ? (localStorage.getItem('site_version') || '') : '';
    } catch (err) {
        stored = '';
    }

    if (stored === serverVersion) {
        return;
    }

    if (window.location.search.indexOf('__v=' + encodedVersion) !== -1) {
        try {
            if (window.localStorage) {
                localStorage.setItem('site_version', serverVersion);
            }
        } catch (err) {
            // Ignore storage errors.
        }
        return;
    }

    try {
        if (window.localStorage) {
            localStorage.setItem('site_version', serverVersion);
        }
    } catch (err) {
        // Ignore storage errors.
    }

    var sep = window.location.search ? '&' : '?';
    var target = window.location.pathname + window.location.search + sep
        + '__v=' + encodedVersion + '&__t=' + Date.now() + window.location.hash;
    window.location.replace(target);
})();
