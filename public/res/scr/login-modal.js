(function () {
    'use strict';

    var modal = document.getElementById('lpLoginModal');
    if (!modal) return;

    var formEl = modal.querySelector('[data-lp-login-form]');
    var usernameEl = modal.querySelector('input[name="username"]');
    var passwordEl = modal.querySelector('input[name="password"]');
    var rememberEl = modal.querySelector('input[name="remember"]');
    var errorEl = modal.querySelector('[data-lp-login-error]');
    var submitEl = modal.querySelector('.lpLoginModal__submit');

    // Telegram-button click + OAuth redirect lives in tg-login.js (shared with
    // the admin login page).
    var csrfToken = modal.dataset.csrfToken || '';
    var loginEndpoint = modal.dataset.loginEndpoint || '';

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

})();
