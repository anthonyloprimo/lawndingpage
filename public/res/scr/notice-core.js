// Shared notice-banner manager used by both the public site (app.js) and the
// admin panel (config.js). Each surface keeps its own thin wrapper
// (lpAddNotice, addAdminNotice) so existing call sites don't change. Behaviour
// — timing ladder, hover-pause, progress bar, server-flash bind — lives here so
// the two surfaces can't drift again. (The notice CSS in style.css /
// config.css is still duplicated and must stay visually in sync separately.)

(function (window) {
    'use strict';

    var DEFAULT_TIMINGS = { danger: 30000, warning: 15000, ok: 5000 };

    function createNoticeManager(opts) {
        opts = opts || {};
        var $ = window.jQuery;
        var containerSelector = opts.containerSelector || '#adminNotices';
        var skipActionBearing = !!opts.skipActionBearing;
        var timings = opts.timings || DEFAULT_TIMINGS;

        function timeoutFor(safeType) {
            return timings[safeType] != null ? timings[safeType] : timings.ok;
        }

        function classifyFromDom($notice) {
            if ($notice.hasClass('adminNotice--danger')) { return 'danger'; }
            if ($notice.hasClass('adminNotice--warning')) { return 'warning'; }
            return 'ok';
        }

        function hasActionElements($notice) {
            if (!$notice || !$notice.length) {
                return false;
            }
            return $notice.find('form, button:not(.adminNoticeClose), input[type="submit"], input[type="button"]').length > 0;
        }

        function add(type, text, options) {
            var $notices = $(containerSelector);
            if (!$notices.length) {
                return;
            }
            var safeType = type || 'ok';
            var $notice = $(
                '<div class="adminNotice adminNotice--' + safeType + '">' +
                '<span class="adminNoticeText"></span>' +
                '<button type="button" class="adminNoticeClose" aria-label="Dismiss notification">×</button>' +
                '</div>'
            );
            $notice.find('.adminNoticeText').text(text);
            $notices.append($notice);
            var persist = options && options.persist;
            if (!persist) {
                scheduleTimeout($notice, timeoutFor(safeType));
            }
        }

        function bind() {
            $(document)
                .off('click.adminNoticeClose')
                .on('click.adminNoticeClose', '.adminNoticeClose', function () {
                    var $notice = $(this).closest('.adminNotice');
                    clearTimer($notice);
                    $notice.remove();
                });
            $(containerSelector + ' .adminNotice').each(function () {
                var $notice = $(this);
                if ($notice.data('persist')) { return; }
                if (skipActionBearing && hasActionElements($notice)) { return; }
                scheduleTimeout($notice, timeoutFor(classifyFromDom($notice)));
            });
        }

        function scheduleTimeout($notice, durationMs) {
            if (!$notice.length || durationMs <= 0) {
                return;
            }
            if ($notice.data('noticeTimerActive')) {
                return;
            }
            $notice.data('noticeTimerActive', true);

            var remainingMs = durationMs;
            var startTime = Date.now();
            var rafId = null;
            var paused = false;

            var $progress = $notice.find('.adminNoticeProgress');
            if (!$progress.length) {
                $progress = $('<div class="adminNoticeProgress"></div>');
                $notice.append($progress);
            }

            function updateProgress() {
                if (paused) {
                    return;
                }
                var elapsed = Date.now() - startTime;
                var left = Math.max(remainingMs - elapsed, 0);
                var percent = (left / durationMs) * 100;
                $progress.css('width', percent + '%');

                if (left <= 0) {
                    $notice.addClass('isClosing');
                    $notice.fadeOut(200, function () {
                        clearTimer($notice);
                        $notice.remove();
                    });
                    return;
                }
                rafId = requestAnimationFrame(updateProgress);
                $notice.data('noticeRafId', rafId);
            }

            function pauseTimer() {
                if (paused) {
                    return;
                }
                paused = true;
                remainingMs = Math.max(remainingMs - (Date.now() - startTime), 0);
                if (rafId) {
                    cancelAnimationFrame(rafId);
                    rafId = null;
                }
            }

            function resumeTimer() {
                if (!paused) {
                    return;
                }
                paused = false;
                startTime = Date.now();
                rafId = requestAnimationFrame(updateProgress);
                $notice.data('noticeRafId', rafId);
            }

            $notice.on('mouseenter.noticeTimer', pauseTimer);
            $notice.on('mouseleave.noticeTimer', resumeTimer);

            rafId = requestAnimationFrame(updateProgress);
            $notice.data('noticeRafId', rafId);
            $notice.data('noticePause', pauseTimer);
            $notice.data('noticeResume', resumeTimer);
        }

        function clearTimer($notice) {
            if (!$notice || !$notice.length) {
                return;
            }
            var rafId = $notice.data('noticeRafId');
            if (rafId) {
                cancelAnimationFrame(rafId);
            }
            $notice.off('mouseenter.noticeTimer');
            $notice.off('mouseleave.noticeTimer');
            $notice.removeData('noticeRafId');
            $notice.removeData('noticeTimerActive');
            $notice.removeData('noticePause');
            $notice.removeData('noticeResume');
        }

        return {
            add: add,
            bind: bind,
            scheduleTimeout: scheduleTimeout,
            clearTimer: clearTimer,
            hasActionElements: hasActionElements
        };
    }

    window.lpNoticeFactory = createNoticeManager;
}(window));
