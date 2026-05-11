// Shared modal-shell manager. Each surface (admin, public) creates its own
// via lpModalFactory(opts). Drag is default-on; opt out per-modal via the
// 'lpModalNoDrag' class. Broadcasts 'lp:modalOpened' / 'lp:modalClosed' on
// document with payload {modal, dialog}.

(function (window) {
    'use strict';

    function createModalManager(opts) {
        opts = opts || {};
        var $ = window.jQuery;

        var dialogSelector  = opts.dialogSelector  || '.userModal';
        var handleSelector  = opts.handleSelector  || '.lpModalHandle, .userModalHandle';
        var closeSelector   = opts.closeSelector   || '.userModalClose, [data-modal-cancel]';
        var confirmSelectors = opts.confirmSelectors || [
            '[data-modal-confirm]',
            'button[type="submit"]',
            'button:not(.userModalClose)'
        ];
        // 'class' = addClass(openClass) + aria-hidden toggle.
        // 'hidden' = toggle the [hidden] attribute (used by public modals).
        var visibility = opts.visibility || 'class';
        var openClass  = opts.openClass  || 'isOpen';
        // null = inert every direct child of <body> except the modal itself.
        // array = inert each matching node.
        var backgroundSelectors  = opts.backgroundSelectors || null;
        var noticeBannerSelector = opts.noticeBannerSelector || '.adminNotices';
        var noDragMarker         = opts.noDragMarker || 'lpModalNoDrag';

        var modalStack = [];
        var activeModal = null;
        var lastFocused = null;
        var inertedNodes = [];

        function setBackgroundInert(isOpen, modalEl) {
            if (isOpen) {
                inertedNodes = [];
                if (backgroundSelectors) {
                    backgroundSelectors.forEach(function (selector) {
                        document.querySelectorAll(selector).forEach(function (node) {
                            node.setAttribute('inert', '');
                            node.setAttribute('aria-hidden', 'true');
                            inertedNodes.push(node);
                        });
                    });
                } else {
                    var children = document.body.children;
                    for (var i = 0; i < children.length; i += 1) {
                        var sib = children[i];
                        if (sib === modalEl || sib.tagName === 'SCRIPT') continue;
                        if (!sib.hasAttribute('inert')) {
                            sib.setAttribute('inert', '');
                            sib.setAttribute('aria-hidden', 'true');
                            inertedNodes.push(sib);
                        }
                    }
                }
            } else {
                inertedNodes.forEach(function (node) {
                    node.removeAttribute('inert');
                    node.removeAttribute('aria-hidden');
                });
                inertedNodes = [];
            }
        }

        function getFocusableElements($modal) {
            return $modal
                .find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')
                .filter(':visible')
                .filter(function () { return !this.disabled; });
        }

        function focusModal($modal) {
            var $autoFocus = $modal.find('[autofocus]').filter(':visible').first();
            var $dialog = $modal.find(dialogSelector).first();
            if ($autoFocus.length) { $autoFocus.focus(); return; }
            var $focusable = getFocusableElements($modal);
            if ($focusable.length) { $focusable.first().focus(); return; }
            if ($dialog.length) { $dialog.attr('tabindex', '-1').focus(); }
        }

        function findModalConfirm($modal) {
            for (var i = 0; i < confirmSelectors.length; i += 1) {
                var $candidate = $modal.find(confirmSelectors[i]).filter(':visible').filter(function () {
                    return !this.disabled;
                });
                if ($candidate.length) return $candidate.first();
            }
            return $();
        }

        function handleKeydown(event) {
            if (!activeModal) return;
            var $modal = activeModal;
            if (event.key === 'Escape') {
                event.preventDefault();
                var $cancel = $modal.find(closeSelector).filter(':visible').first();
                if ($cancel.length) { $cancel.trigger('click'); }
                else { close($modal); }
                return;
            }
            if (event.key === 'Enter' && !event.isComposing) {
                var t = event.target;
                if (t && (t.tagName === 'TEXTAREA' || t.isContentEditable)) return;
                if (t && t.closest && t.closest('button, a[href], input[type="submit"], input[type="button"]')) return;
                var $confirm = findModalConfirm($modal);
                if ($confirm.length) { event.preventDefault(); $confirm.trigger('click'); }
                return;
            }
            if (event.key === 'Tab') {
                var $focusable = getFocusableElements($modal);
                if (!$focusable.length) { event.preventDefault(); return; }
                var first = $focusable.first().get(0);
                var last = $focusable.last().get(0);
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault(); last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault(); first.focus();
                }
            }
        }

        // Auto-detect handle: explicit class wins; else direct-child <header>
        // or heading. Restricting to direct children avoids grabbing nested
        // headings inside the modal body.
        function findHandle($dialog) {
            var $explicit = $dialog.find(handleSelector).filter(function () {
                return $(this).parent().is($dialog);
            }).first();
            if ($explicit.length) return $explicit;
            return $dialog.children('header, h1, h2, h3, h4, h5, h6').first();
        }

        function bindDrag($modal) {
            var $dialog = $modal.find(dialogSelector).first();
            if (!$dialog.length || $dialog.data('lpDragBound')) return;
            if ($modal.hasClass(noDragMarker) || $dialog.hasClass(noDragMarker)) return;
            // Mobile view: modals fill most of the viewport so movement isn't
            // useful, and touch drag fights body scroll. 979px matches the
            // project's responsive breakpoint used elsewhere.
            if (window.matchMedia && window.matchMedia('(max-width: 979px)').matches) return;
            var $handle = findHandle($dialog);
            if (!$handle.length) return;
            $handle.addClass('lpModalHandle');
            $dialog.data('lpDragBound', true);

            var dialog = $dialog.get(0);
            var handle = $handle.get(0);
            var baseX = 0, baseY = 0, startX = 0, startY = 0;
            var pointerId = null;
            // Frozen at pointerdown — projecting against the live rect during
            // a drag double-counts the already-applied transform.
            var handleStart = null;

            function readTransform() {
                var m = /translate\(([-\d.]+)px,\s*([-\d.]+)px\)/.exec(dialog.style.transform || '');
                return m ? [parseFloat(m[1]), parseFloat(m[2])] : [0, 0];
            }

            // .adminNotices (z-index 3000) paints above the modal overlay (2000)
            // and is inert while a modal is open — handle dragged behind it
            // becomes ungrabbable. Re-read on every move (notice stack grows).
            function topClamp() {
                var $notices = $(noticeBannerSelector);
                if (!$notices.length) return 0;
                var rect = $notices.get(0).getBoundingClientRect();
                if (rect.height <= 0 || rect.bottom <= 0) return 0;
                return rect.bottom + 8;
            }

            function clampToViewport(x, y) {
                if (!handleStart) return [x, y];
                var minVisible = 48;
                var vw = window.innerWidth;
                var vh = window.innerHeight;
                var top = topClamp();
                var projectedLeft = handleStart.left + (x - baseX);
                var projectedTop  = handleStart.top  + (y - baseY);
                var cx = x, cy = y;
                if (projectedLeft + handleStart.width < minVisible) {
                    cx = x + (minVisible - (projectedLeft + handleStart.width));
                } else if (projectedLeft > vw - minVisible) {
                    cx = x - (projectedLeft - (vw - minVisible));
                }
                if (projectedTop < top) {
                    cy = y + (top - projectedTop);
                } else if (projectedTop > vh - minVisible) {
                    cy = y - (projectedTop - (vh - minVisible));
                }
                return [cx, cy];
            }

            $handle.on('pointerdown', function (event) {
                if (event.button !== 0) return;
                if (event.target.closest('button, a, input, select, textarea')) return;
                var t = readTransform();
                baseX = t[0]; baseY = t[1];
                startX = event.clientX; startY = event.clientY;
                pointerId = event.pointerId;
                var r = handle.getBoundingClientRect();
                handleStart = { left: r.left, top: r.top, width: r.width };
                $dialog.addClass('isDragging');
                try { handle.setPointerCapture(pointerId); } catch (_) {}
                event.preventDefault();
            });
            $handle.on('pointermove', function (event) {
                if (event.pointerId !== pointerId) return;
                var x = baseX + (event.clientX - startX);
                var y = baseY + (event.clientY - startY);
                var clamped = clampToViewport(x, y);
                dialog.style.transform = 'translate(' + clamped[0] + 'px, ' + clamped[1] + 'px)';
            });
            function endDrag(event) {
                if (event.pointerId !== pointerId) return;
                pointerId = null;
                $dialog.removeClass('isDragging');
            }
            $handle.on('pointerup pointercancel', endDrag);
        }

        function resetDrag($modal) {
            var $dialog = $modal.find(dialogSelector).first();
            if ($dialog.length) {
                $dialog.get(0).style.transform = '';
                $dialog.removeClass('isDragging');
            }
        }

        function applyVisibility($modal, isOpen) {
            if (visibility === 'hidden') {
                $modal.prop('hidden', !isOpen).attr('aria-hidden', isOpen ? 'false' : 'true');
            } else {
                if (isOpen) { $modal.addClass(openClass).attr('aria-hidden', 'false'); }
                else        { $modal.removeClass(openClass).attr('aria-hidden', 'true'); }
            }
        }

        function broadcast(name, $modal) {
            var modalEl = $modal && $modal.length ? $modal.get(0) : null;
            var $dialog = $modal && $modal.length ? $modal.find(dialogSelector).first() : $();
            $(document).trigger(name, [{
                modal: modalEl,
                dialog: $dialog.length ? $dialog.get(0) : null
            }]);
        }

        function open($modal) {
            if (!$modal || !$modal.length) return;
            var modalEl = $modal.get(0);
            if (modalStack.length === 0) {
                lastFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            }
            if (modalStack.indexOf(modalEl) === -1) modalStack.push(modalEl);
            activeModal = $modal;
            applyVisibility($modal, true);
            bindDrag($modal);
            if (modalStack.length === 1) setBackgroundInert(true, modalEl);
            focusModal($modal);
            $(document).off('keydown.lpModal').on('keydown.lpModal', handleKeydown);
            broadcast('lp:modalOpened', $modal);
        }

        function close($modal) {
            var $target = $modal && $modal.length ? $modal : activeModal;
            if (!$target || !$target.length) return;
            var targetEl = $target.get(0);
            applyVisibility($target, false);
            resetDrag($target);
            var index = modalStack.indexOf(targetEl);
            if (index !== -1) modalStack.splice(index, 1);
            if (modalStack.length === 0) {
                activeModal = null;
                setBackgroundInert(false);
                $(document).off('keydown.lpModal');
                if (lastFocused && typeof lastFocused.focus === 'function') {
                    lastFocused.focus();
                }
                lastFocused = null;
            } else {
                activeModal = $(modalStack[modalStack.length - 1]);
                focusModal(activeModal);
            }
            broadcast('lp:modalClosed', $target);
        }

        function reset() {
            // Drain the entire stack — used by tutorial cleanup or any caller
            // that needs to forcibly clear modal state.
            $(modalStack.slice().reverse()).each(function () {
                var $m = $(this);
                applyVisibility($m, false);
                resetDrag($m);
            });
            modalStack.length = 0;
            activeModal = null;
            setBackgroundInert(false);
            $(document).off('keydown.lpModal');
            if (lastFocused && typeof lastFocused.focus === 'function') {
                lastFocused.focus();
            }
            lastFocused = null;
        }

        return {
            open: open,
            close: close,
            reset: reset,
            bindDrag: bindDrag,
            resetDrag: resetDrag
        };
    }

    window.lpModalFactory = createModalManager;
}(window));
