(function () {
    'use strict';

    var modal = document.getElementById('lpLoginModal');
    if (!modal) return;

    var formEl = modal.querySelector('[data-lp-login-form]');
    var usernameEl = modal.querySelector('input[name="username"]');
    var passwordEl = modal.querySelector('input[name="password"]');
    var rememberEl = modal.querySelector('input[name="remember"]');
    var errorEl = modal.querySelector('[data-lp-login-error]');
    var tgButtonEl = modal.querySelector('[data-lp-login-telegram]');
    var submitEl = modal.querySelector('.lpLoginModal__submit');

    var csrfToken = modal.dataset.csrfToken || '';
    var loginEndpoint = modal.dataset.loginEndpoint || '';
    var tgAuthEndpoint = modal.dataset.tgAuthEndpoint || '';
    var tgBotId = modal.dataset.tgBotId || '';

    function showError(msg) {
        if (!errorEl) return;
        errorEl.textContent = msg;
        errorEl.hidden = false;
    }
    function clearError() {
        if (!errorEl) return;
        errorEl.textContent = '';
        errorEl.hidden = true;
    }

    // Modal shell (open/close, focus trap, Esc, inert siblings, focus restore)
    // is delegated to the public modal manager (public-modals.js → modal-core.js).
    var $ = window.jQuery;
    var $modal = $ ? $(modal) : null;

    document.addEventListener('click', function (ev) {
        var t = ev.target;
        if (!t || typeof t.closest !== 'function') return;
        var trigger = t.closest('[data-lp-login-trigger]');
        if (trigger && window.openPublicModal && $modal) {
            ev.preventDefault();
            window.openPublicModal($modal);
            return;
        }
        var dismiss = t.closest('[data-lp-login-dismiss]');
        if (dismiss && modal.contains(dismiss) && window.closePublicModal && $modal) {
            ev.preventDefault();
            window.closePublicModal($modal);
        }
    });

    // Reset error state on every open. Factory's focusModal() lands on the
    // username input automatically (first focusable in the form).
    if ($) {
        $(document).on('lp:modalOpened', function (e, data) {
            if (data && data.modal === modal) {
                clearError();
            }
        });
    }

    if (formEl) {
        formEl.addEventListener('submit', function (ev) {
            ev.preventDefault();
            clearError();
            var username = (usernameEl && usernameEl.value || '').trim();
            var password = (passwordEl && passwordEl.value) || '';
            if (!username || !password) {
                showError('Username and password are required.');
                return;
            }
            var body = new URLSearchParams();
            body.append('csrf_token', csrfToken);
            body.append('username', username);
            body.append('password', password);
            if (rememberEl && rememberEl.checked) {
                body.append('remember', '1');
            }
            if (submitEl) submitEl.disabled = true;
            fetch(loginEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: body.toString()
            }).then(function (resp) {
                return resp.json().then(function (data) {
                    return { ok: resp.ok, data: data };
                }).catch(function () {
                    return { ok: resp.ok, data: null };
                });
            }).then(function (result) {
                if (submitEl) submitEl.disabled = false;
                if (result.ok && result.data && result.data.ok) {
                    window.location.reload();
                    return;
                }
                var msg = (result.data && result.data.error) ||
                          'Sign-in failed. Please try again.';
                showError(msg);
                if (passwordEl) {
                    passwordEl.value = '';
                    try { passwordEl.focus(); } catch (e) { /* swallow */ }
                }
            }).catch(function () {
                if (submitEl) submitEl.disabled = false;
                showError('Could not reach the server. Please try again.');
            });
        });
    }

    if (tgButtonEl && tgBotId) {
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
        tgButtonEl.addEventListener('click', function (ev) {
            ev.preventDefault();
            clearError();
            ensureTgWidget().then(function () {
                if (!window.Telegram || !window.Telegram.Login ||
                    typeof window.Telegram.Login.auth !== 'function') {
                    showError('Telegram login is unavailable right now.');
                    return;
                }
                window.Telegram.Login.auth({
                    bot_id: parseInt(tgBotId, 10),
                    request_access: 'write'
                }, function (authData) {
                    if (!authData) return; // user cancelled — silent
                    var params = new URLSearchParams();
                    Object.keys(authData).forEach(function (key) {
                        params.append(key, String(authData[key]));
                    });
                    params.append('return', window.location.pathname || '/');
                    // tgAuthEndpoint already carries the proxy's plugin/endpoint
                    // query string, so append auth params with & rather than ?.
                    var sep = tgAuthEndpoint.indexOf('?') === -1 ? '?' : '&';
                    window.location.href = tgAuthEndpoint + sep + params.toString();
                });
            }).catch(function () {
                showError('Could not load Telegram login. Check your network connection.');
            });
        });
    }
})();
