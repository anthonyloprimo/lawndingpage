// Parse server-provided header data from data attributes.
(function () {
    var body = document.body;
    if (!body) {
        return;
    }
    var raw = body.getAttribute('data-header-json') || '';
    if (!raw) {
        return;
    }
    try {
        window.headerData = JSON.parse(raw);
    } catch (err) {
        window.headerData = {};
    }
})();
