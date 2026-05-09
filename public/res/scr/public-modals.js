// Public-side modal manager. Wraps lpModalFactory with the public surface's
// selectors and inert strategy; exposes open/close globals for login-modal.js
// and the changelog trigger to call.

(function (window) {
    'use strict';
    if (!window.lpModalFactory) return;

    var manager = window.lpModalFactory({
        dialogSelector:  '.lpLoginModal__dialog, .changelogModalInner',
        closeSelector:   '[data-lp-login-dismiss], .changelogModalClose',
        // Public modals toggle [hidden] rather than an .isOpen class.
        visibility:      'hidden',
        // Inert every body sibling; matches login-modal.js's pre-factory behavior.
        backgroundSelectors: null,
    });

    window.openPublicModal  = manager.open;
    window.closePublicModal = manager.close;
    window.resetPublicModal = manager.reset;
}(window));
