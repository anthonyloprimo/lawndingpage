// Pure utilities shared between the public surface (app.js + public-side
// modules) and the admin surface (config.js + admin-side modules). Each
// surface previously kept its own copy of formatBytes / escapeHtml and
// re-derived basePath inline at every URL-building site.
//
// Loaded before notice-core.js so any downstream code (notices, app, config,
// modules) can use these helpers.

(function (window) {
    'use strict';

    window.lpFormatBytes = function (bytes) {
        if (!bytes || bytes <= 0) { return '0 B'; }
        const units = ['B', 'KB', 'MB', 'GB'];
        const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        const val = bytes / Math.pow(1024, i);
        return (i === 0 ? val : val.toFixed(1)) + ' ' + units[i];
    };

    window.lpEscapeHtml = function (value) {
        if (value === null || value === undefined) { return ''; }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    };

    window.lpGetBasePath = function () {
        return window.appConfig && typeof window.appConfig.basePath === 'string'
            ? window.appConfig.basePath.replace(/\/$/, '')
            : '';
    };
}(window));
