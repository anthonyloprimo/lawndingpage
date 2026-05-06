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

    var lastFocused = null;
    var inertSiblings = [];

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

    function getFocusables() {
        var sel = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]),' +
                  ' select:not([disabled]), textarea:not([disabled]),' +
                  ' [tabindex]:not([tabindex="-1"])';
        var nodes = modal.querySelectorAll(sel);
        var out = [];
        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];
            if (!el.hidden && el.offsetParent !== null) out.push(el);
        }
        return out;
    }

    function setBackgroundInert(active) {
        if (active) {
            var children = document.body.children;
            for (var i = 0; i < children.length; i++) {
                var sib = children[i];
                if (sib === modal || sib.tagName === 'SCRIPT') continue;
                if (!sib.hasAttribute('inert')) {
                    sib.setAttribute('inert', '');
                    inertSiblings.push(sib);
                }
            }
        } else {
            for (var j = 0; j < inertSiblings.length; j++) {
                inertSiblings[j].removeAttribute('inert');
            }
            inertSiblings = [];
        }
    }

    function openModal() {
        if (!modal.hidden) return;
        lastFocused = document.activeElement;
        clearError();
        modal.hidden = false;
        setBackgroundInert(true);
        if (usernameEl) {
            try { usernameEl.focus(); } catch (e) { /* swallow */ }
        }
    }

    function closeModal() {
        if (modal.hidden) return;
        modal.hidden = true;
        setBackgroundInert(false);
        if (lastFocused && typeof lastFocused.focus === 'function') {
            try { lastFocused.focus(); } catch (e) { /* swallow */ }
        }
        lastFocused = null;
    }

    document.addEventListener('keydown', function (ev) {
        if (modal.hidden) return;
        if (ev.key === 'Escape') {
            ev.preventDefault();
            closeModal();
            return;
        }
        if (ev.key === 'Tab') {
            var focusables = getFocusables();
            if (focusables.length === 0) {
                ev.preventDefault();
                return;
            }
            var first = focusables[0];
            var last = focusables[focusables.length - 1];
            if (ev.shiftKey && document.activeElement === first) {
                ev.preventDefault();
                last.focus();
            } else if (!ev.shiftKey && document.activeElement === last) {
                ev.preventDefault();
                first.focus();
            }
        }
    });

    document.addEventListener('click', function (ev) {
        var t = ev.target;
        if (!t || typeof t.closest !== 'function') return;
        var trigger = t.closest('[data-lp-login-trigger]');
        if (trigger) {
            ev.preventDefault();
            openModal();
            return;
        }
        var dismiss = t.closest('[data-lp-login-dismiss]');
        if (dismiss && modal.contains(dismiss)) {
            ev.preventDefault();
            closeModal();
        }
    });

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
