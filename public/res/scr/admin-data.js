// Parse server-provided admin config data from data attributes.
(function () {
    var body = document.body;
    if (!body) {
        return;
    }
    var headerRaw = body.getAttribute('data-header-json') || '';
    var configRaw = body.getAttribute('data-app-config-json') || '';

    if (headerRaw) {
        try {
            window.headerData = JSON.parse(headerRaw);
        } catch (err) {
            window.headerData = {};
        }
    }

    if (configRaw) {
        try {
            window.appConfig = JSON.parse(configRaw);
        } catch (err) {
            window.appConfig = {};
        }
    }
})();
