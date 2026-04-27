// Admin diagnostics viewer.
//
// Polls /res/scr/errors-tail.php when the DIAGNOSTICS pane is active and
// renders entries as a click-to-expand list. Polling runs only while the
// "Auto-refresh" checkbox is on AND the tab is visible AND the pane is the
// active one in the admin shell. Loaded unconditionally; no-ops when the
// viewer element isn't present (i.e. the current user lacks edit_site).

(function () {
    'use strict';

    var viewer = document.getElementById('diagnosticsViewer');
    if (!viewer) {
        return;
    }

    var tailUrl = viewer.getAttribute('data-tail-url') || '';
    var statusEl = document.getElementById('diagnosticsStatus');
    var autoToggle = document.getElementById('diagnosticsAutoRefresh');
    var refreshBtn = document.getElementById('diagnosticsRefresh');

    var POLL_INTERVAL_MS = 5000;
    var pollTimer = null;
    var inFlight = false;

    function setStatus(text) {
        if (statusEl) {
            statusEl.textContent = text || '';
        }
    }

    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function summaryLine(entry) {
        var raw = entry.msg || entry.event || '';
        if (typeof raw !== 'string') {
            raw = String(raw);
        }
        // Collapse whitespace so multi-line stack traces don't break the row
        // layout. Visual truncation at the pane edge is handled by the CSS
        // (text-overflow: ellipsis + white-space: nowrap on .diagnosticsRowMsg).
        return raw.replace(/\s+/g, ' ').trim();
    }

    function severityClass(sev) {
        var s = (sev || 'info').toLowerCase();
        if (s !== 'error' && s !== 'warn' && s !== 'info' && s !== 'debug') {
            s = 'info';
        }
        return 'diagnosticsRow--' + s;
    }

    function renderEmpty() {
        viewer.innerHTML = '<p class="diagnosticsEmpty">No entries to show.</p>';
    }

    function renderEntries(entries) {
        if (!entries || entries.length === 0) {
            renderEmpty();
            return;
        }
        var html = '<ul class="diagnosticsList">';
        for (var i = 0; i < entries.length; i++) {
            var e = entries[i];
            var sev = e.sev || 'info';
            var src = e.src || '';
            var ts = e.ts || '';
            var summary = summaryLine(e);
            var fullMsg = e.msg ? String(e.msg) : '';
            var detailParts = [];
            if (e.event) {
                detailParts.push('Event: ' + e.event);
            }
            if (e.file) {
                detailParts.push('File: ' + e.file + (e.line ? ':' + e.line : ''));
            }
            if (e.uri) {
                detailParts.push('URI: ' + e.uri);
            }
            if (e.who) {
                detailParts.push('Who: ' + e.who);
            }
            if (e.ctx && typeof e.ctx === 'object' && Object.keys(e.ctx).length) {
                detailParts.push('Context: ' + JSON.stringify(e.ctx));
            }

            html += '<li class="diagnosticsRow ' + severityClass(sev) + '">';
            html += '<button type="button" class="diagnosticsRowSummary" aria-expanded="false">';
            html += '<span class="diagnosticsRowTs">' + escapeHtml(ts) + '</span>';
            html += '<span class="diagnosticsRowSev">' + escapeHtml(sev) + '</span>';
            html += '<span class="diagnosticsRowSrc">' + escapeHtml(src) + '</span>';
            html += '<span class="diagnosticsRowMsg">' + escapeHtml(summary) + '</span>';
            html += '</button>';
            html += '<div class="diagnosticsRowDetail" hidden>';
            if (fullMsg) {
                html += '<pre class="diagnosticsRowMessage">' + escapeHtml(fullMsg) + '</pre>';
            }
            for (var j = 0; j < detailParts.length; j++) {
                html += '<div class="diagnosticsRowField">' + escapeHtml(detailParts[j]) + '</div>';
            }
            html += '</div>';
            html += '</li>';
        }
        html += '</ul>';
        viewer.innerHTML = html;

        var summaries = viewer.querySelectorAll('.diagnosticsRowSummary');
        for (var k = 0; k < summaries.length; k++) {
            summaries[k].addEventListener('click', function (ev) {
                var btn = ev.currentTarget;
                var detail = btn.nextElementSibling;
                if (!detail) {
                    return;
                }
                if (detail.hasAttribute('hidden')) {
                    detail.removeAttribute('hidden');
                    btn.setAttribute('aria-expanded', 'true');
                } else {
                    detail.setAttribute('hidden', '');
                    btn.setAttribute('aria-expanded', 'false');
                }
            });
        }
    }

    function fetchTail() {
        if (inFlight || !tailUrl) {
            return;
        }
        inFlight = true;
        setStatus('Loading…');
        fetch(tailUrl + '?limit=100', { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }
                return r.json();
            })
            .then(function (data) {
                renderEntries(data.entries || []);
                var stamp = new Date().toLocaleTimeString();
                setStatus('Updated ' + stamp + ' (' + (data.count || 0) + ' entries)');
            })
            .catch(function (err) {
                setStatus('Error: ' + (err && err.message ? err.message : 'load failed'));
            })
            .finally(function () {
                inFlight = false;
            });
    }

    function isPaneActive() {
        var pane = document.getElementById('diagnostics');
        if (!pane) {
            return false;
        }
        // The admin shell hides inactive panes via the .hidden class.
        if (pane.classList.contains('hidden')) {
            return false;
        }
        if (document.hidden) {
            return false;
        }
        return true;
    }

    function startPolling() {
        if (pollTimer) {
            return;
        }
        if (!autoToggle || !autoToggle.checked) {
            return;
        }
        pollTimer = setInterval(function () {
            if (isPaneActive()) {
                fetchTail();
            }
        }, POLL_INTERVAL_MS);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    if (autoToggle) {
        autoToggle.addEventListener('change', function () {
            if (autoToggle.checked) {
                fetchTail();
                startPolling();
            } else {
                stopPolling();
            }
        });
    }
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            fetchTail();
        });
    }
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && autoToggle && autoToggle.checked && isPaneActive()) {
            fetchTail();
        }
    });

    // Initial load so opening the pane shows current entries without manual click.
    fetchTail();
}());
