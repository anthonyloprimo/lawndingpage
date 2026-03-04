// Deprecated: do not use for new code. This client-side version redirect cache-busting
// flow is being phased out in favor of standard cache revalidation headers.
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
