// Telegram-login button handler. Wires every [data-lp-login-telegram] button
// on the page: reads bot_id + auth_endpoint from data-* attrs, lazy-loads the
// Telegram widget on first click, then redirects via the plugin auth endpoint.
// Errors write to the nearest [data-lp-login-error] in scope.

(function () {
    'use strict';

    var buttons = document.querySelectorAll('[data-lp-login-telegram]');
    if (!buttons.length) return;

    var widgetLoaded = false;
    var widgetLoading = null;

    function ensureTgWidget() {
        if (widgetLoaded) return Promise.resolve();
        if (widgetLoading) return widgetLoading;
        widgetLoading = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = 'https://telegram.org/js/telegram-widget.js?22';
            s.async = true;
            s.onload = function () { widgetLoaded = true; resolve(); };
            s.onerror = function () { reject(new Error('telegram-widget load failed')); };
            document.head.appendChild(s);
        });
        return widgetLoading;
    }

    function findErrorEl(button) {
        var scope = button.closest('.userModal, .lpLoginModal') || document;
        return scope.querySelector('[data-lp-login-error]');
    }

    function showError(button, msg) {
        var el = findErrorEl(button);
        if (el) {
            el.textContent = msg;
            el.hidden = false;
        } else if (window.console && console.error) {
            console.error('[tg-login]', msg);
        }
    }

    function clearError(button) {
        var el = findErrorEl(button);
        if (el) {
            el.textContent = '';
            el.hidden = true;
        }
    }

    Array.prototype.forEach.call(buttons, function (button) {
        var botId = button.dataset.tgBotId || '';
        var authEndpoint = button.dataset.tgAuthEndpoint || '';
        if (!botId || !authEndpoint) return;
        button.addEventListener('click', function (ev) {
            ev.preventDefault();
            clearError(button);
            ensureTgWidget().then(function () {
                if (!window.Telegram || !window.Telegram.Login ||
                    typeof window.Telegram.Login.auth !== 'function') {
                    showError(button, 'Telegram login is unavailable right now.');
                    return;
                }
                window.Telegram.Login.auth({
                    bot_id: parseInt(botId, 10),
                    request_access: 'write'
                }, function (authData) {
                    if (!authData) return;
                    var params = new URLSearchParams();
                    Object.keys(authData).forEach(function (key) {
                        params.append(key, String(authData[key]));
                    });
                    params.append('return', window.location.pathname || '/');
                    var sep = authEndpoint.indexOf('?') === -1 ? '?' : '&';
                    window.location.href = authEndpoint + sep + params.toString();
                });
            }).catch(function () {
                showError(button, 'Could not load Telegram login. Check your network connection.');
            });
        });
    });
}());
