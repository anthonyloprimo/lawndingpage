// Event Scraper admin UI.
//
// Loads on every page that emits head_assets (CSP-strict; external file).
// Self-noops on the public site since .eventScraperConfig only renders inside
// the admin SITE CONFIG eventList section.

(function () {
    'use strict';

    var root = document.querySelector('.eventScraperConfig');
    if (!root) {
        return;
    }

    var PROXY_BASE = '/res/scr/plugin-endpoint.php?plugin=eventScraper&endpoint=';
    var adapterId = root.getAttribute('data-adapter-id') || 'furrycons-na';
    var cronUrl = root.getAttribute('data-cron-url') || '';
    var cronToken = root.getAttribute('data-cron-token') || '';

    var statusText = root.querySelector('.eventScraperStatusText');
    var refreshBtn = root.querySelector('.eventScraperRefreshBtn');
    var targetPaneSel = root.querySelector('.eventScraperTargetPane');
    var defaultCatSel = root.querySelector('.eventScraperDefaultCategory');
    var filterText = root.querySelector('.eventScraperFilterText');
    var filterRegion = root.querySelector('.eventScraperFilterRegion');
    var listEl = root.querySelector('.eventScraperList');
    var selectionCount = root.querySelector('.eventScraperSelectionCount');
    var saveBtn = root.querySelector('.eventScraperSaveBtn');
    var saveStatus = root.querySelector('.eventScraperSaveStatus');
    var cronLineEl = root.querySelector('.eventScraperCronLine');
    var cronCopyBtn = root.querySelector('.eventScraperCronCopyBtn');
    var cronRevealBtn = root.querySelector('.eventScraperCronRevealBtn');
    var cronRotateBtn = root.querySelector('.eventScraperCronRotateBtn');
    var cronStatus = root.querySelector('.eventScraperCronStatus');

    var state = {
        events: [],          // catalogue events
        selected: {},        // sourceUid -> true
        lastReviewedAt: '',  // ISO timestamp
        fetchedAt: '',
        cronTokenRevealed: false
    };

    function csrf() {
        return (window.appConfig && window.appConfig.csrfToken) || '';
    }

    function buildCronLine(showToken) {
        var token = showToken && cronToken
            ? cronToken
            : (cronToken ? '••••••••••••••••••••••••' : 'YOUR_TOKEN_HERE');
        return '0 3 * * * curl -fsS -H "X-Scraper-Cron-Token: ' + token + '" "' + cronUrl + '" > /dev/null';
    }

    function renderCronLine() {
        cronLineEl.textContent = buildCronLine(state.cronTokenRevealed);
        cronRevealBtn.textContent = state.cronTokenRevealed ? 'Hide token' : 'Reveal token';
        var hasToken = !!cronToken;
        cronRevealBtn.disabled = !hasToken;
        cronCopyBtn.disabled = !hasToken;
        // Rotate stays enabled regardless of prior state — clicking it cold
        // is a valid bootstrap path (the endpoint mints a token unconditionally).
        cronRotateBtn.disabled = false;
    }

    // Build the region <option>s from the cached catalogue (post-load).
    function rebuildRegionFilter() {
        var seen = {};
        var regions = [];
        state.events.forEach(function (e) {
            // Pull the trailing region from "Venue, City, REGION" if present.
            var parts = (e.location || '').split(',').map(function (s) { return s.trim(); });
            if (parts.length >= 1) {
                var maybe = parts[parts.length - 1];
                if (maybe && !seen[maybe]) {
                    seen[maybe] = true;
                    regions.push(maybe);
                }
            }
        });
        regions.sort();
        // Preserve current selection if still valid.
        var current = filterRegion.value;
        filterRegion.innerHTML = '<option value="">All</option>';
        regions.forEach(function (r) {
            var opt = document.createElement('option');
            opt.value = r;
            opt.textContent = r;
            filterRegion.appendChild(opt);
        });
        if (current && regions.indexOf(current) !== -1) {
            filterRegion.value = current;
        }
    }

    function fmtDate(iso) {
        // Browser-local rendering of the ISO date. furrycons sometimes ships
        // single-digit day/month; Date is forgiving.
        if (!iso) return '';
        var d = new Date(iso + 'T00:00:00');
        if (isNaN(d.getTime())) return iso;
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function fmtRange(start, end) {
        if (end && end !== start) {
            return fmtDate(start) + ' – ' + fmtDate(end);
        }
        return fmtDate(start);
    }

    function isNew(event) {
        if (!state.lastReviewedAt) return false;
        // Naive: an event is "NEW" if our lastReviewedAt is older than the
        // catalogue's fetchedAt AND the event isn't in our saved selections.
        // Stricter logic would require the server to track a per-event
        // firstSeen timestamp; defer until needed.
        return state.lastReviewedAt < state.fetchedAt && !state.selected[event.sourceUid];
    }

    function applyFilters(events) {
        var q = (filterText.value || '').trim().toLowerCase();
        var r = filterRegion.value || '';
        return events.filter(function (e) {
            if (q) {
                var hay = (e.name + ' ' + (e.location || '')).toLowerCase();
                if (hay.indexOf(q) === -1) return false;
            }
            if (r) {
                var parts = (e.location || '').split(',').map(function (s) { return s.trim(); });
                if (parts[parts.length - 1] !== r) return false;
            }
            return true;
        });
    }

    function renderList() {
        var visible = applyFilters(state.events);

        if (state.events.length === 0) {
            listEl.innerHTML = '<p class="eventScraperListEmpty">No conventions cached. Click "Refresh now" to fetch the current list.</p>';
            updateSelectionCount();
            return;
        }
        if (visible.length === 0) {
            listEl.innerHTML = '<p class="eventScraperListEmpty">No conventions match your filter.</p>';
            updateSelectionCount();
            return;
        }

        // Sort by start date ascending; events without a start date last.
        visible.sort(function (a, b) {
            var sa = a.startDate || '9999';
            var sb = b.startDate || '9999';
            return sa < sb ? -1 : (sa > sb ? 1 : 0);
        });

        var table = document.createElement('table');
        table.className = 'eventScraperTable';
        var thead = document.createElement('thead');
        thead.innerHTML = '<tr><th scope="col"><span class="visuallyHidden">Selected</span></th><th scope="col">Convention</th><th scope="col">Location</th><th scope="col">Dates</th></tr>';
        table.appendChild(thead);
        var tbody = document.createElement('tbody');

        visible.forEach(function (e) {
            var tr = document.createElement('tr');
            tr.className = 'eventScraperRow';
            if (isNew(e)) {
                tr.classList.add('eventScraperRowNew');
            }
            var checked = !!state.selected[e.sourceUid];
            if (checked) tr.classList.add('eventScraperRowSelected');

            var cellCheck = document.createElement('td');
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.checked = checked;
            cb.className = 'eventScraperCheckbox';
            cb.setAttribute('data-uid', e.sourceUid);
            cb.setAttribute('aria-label', 'Include ' + e.name + ' in calendar');
            cellCheck.appendChild(cb);
            tr.appendChild(cellCheck);

            var cellName = document.createElement('td');
            cellName.className = 'eventScraperRowName';
            cellName.textContent = e.name;
            if (isNew(e)) {
                var badge = document.createElement('span');
                badge.className = 'eventScraperBadge eventScraperBadgeNew';
                badge.textContent = 'NEW';
                cellName.appendChild(document.createTextNode(' '));
                cellName.appendChild(badge);
            }
            tr.appendChild(cellName);

            var cellLoc = document.createElement('td');
            cellLoc.textContent = e.location || '';
            tr.appendChild(cellLoc);

            var cellDates = document.createElement('td');
            cellDates.textContent = fmtRange(e.startDate, e.endDate);
            tr.appendChild(cellDates);

            tbody.appendChild(tr);
        });

        table.appendChild(tbody);
        listEl.innerHTML = '';
        listEl.appendChild(table);

        updateSelectionCount();
    }

    function updateSelectionCount() {
        var total = state.events.length;
        var selected = Object.keys(state.selected).length;
        selectionCount.textContent = selected + ' of ' + total + ' selected';
    }

    function setStatus(text) {
        statusText.textContent = text;
    }

    function setSaveStatus(text, kind) {
        saveStatus.textContent = text;
        saveStatus.className = 'eventScraperSaveStatus' + (kind ? ' eventScraperSaveStatus--' + kind : '');
    }

    // ---- Server interactions ----

    function loadCatalogue() {
        return fetch(PROXY_BASE + 'catalogue&adapter=' + encodeURIComponent(adapterId), {
            credentials: 'same-origin'
        }).then(function (resp) {
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            return resp.json();
        }).then(function (data) {
            state.events = (data.catalogue && data.catalogue.events) || [];
            state.fetchedAt = (data.catalogue && data.catalogue.fetchedAt) || '';
            state.lastReviewedAt = (data.config && data.config.lastReviewedAt) || '';
            // Restore prior selections.
            state.selected = {};
            var allow = data.config && data.config.allowlist && data.config.allowlist[adapterId];
            if (allow && typeof allow === 'object') {
                Object.keys(allow).forEach(function (uid) {
                    state.selected[uid] = true;
                });
            }
            // Pre-populate selects if user hadn't picked yet.
            if (data.config && data.config.targetPaneId && !targetPaneSel.value) {
                targetPaneSel.value = data.config.targetPaneId;
            }
            if (data.config && data.config.defaultCategoryId && !defaultCatSel.value) {
                defaultCatSel.value = data.config.defaultCategoryId;
            }
            // Status line.
            if (data.lastScrape && data.lastScrape.ranAt) {
                var when = new Date(data.lastScrape.ranAt);
                setStatus('Last refresh: ' + when.toLocaleString() + ' · ' + state.events.length + ' conventions cached');
            } else {
                setStatus(state.events.length + ' conventions cached');
            }
            refreshBtn.disabled = false;
            saveBtn.disabled = false;
            rebuildRegionFilter();
            renderList();
        }).catch(function (err) {
            setStatus('Error loading catalogue: ' + err.message);
        });
    }

    function postForm(endpoint, payload) {
        var body = new URLSearchParams();
        body.append('csrf_token', csrf());
        Object.keys(payload).forEach(function (k) {
            body.append(k, payload[k]);
        });
        return fetch(PROXY_BASE + endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (resp) {
            return resp.json().then(function (data) {
                return { ok: resp.ok, status: resp.status, data: data };
            });
        });
    }

    // ---- Event handlers ----

    refreshBtn.addEventListener('click', function () {
        refreshBtn.disabled = true;
        setStatus('Refreshing…');
        postForm('refresh', { adapter: adapterId }).then(function (r) {
            if (!r.ok || (r.data && r.data.status === 'error')) {
                setStatus('Refresh failed: ' + (r.data && r.data.error ? r.data.error : 'HTTP ' + r.status));
                refreshBtn.disabled = false;
                return;
            }
            return loadCatalogue();
        }).catch(function (err) {
            setStatus('Refresh failed: ' + err.message);
            refreshBtn.disabled = false;
        });
    });

    listEl.addEventListener('change', function (ev) {
        var t = ev.target;
        if (!t || !t.classList || !t.classList.contains('eventScraperCheckbox')) return;
        var uid = t.getAttribute('data-uid');
        if (!uid) return;
        if (t.checked) state.selected[uid] = true;
        else delete state.selected[uid];
        var row = t.closest('tr');
        if (row) row.classList.toggle('eventScraperRowSelected', t.checked);
        updateSelectionCount();
    });

    filterText.addEventListener('input', renderList);
    filterRegion.addEventListener('change', renderList);

    saveBtn.addEventListener('click', function () {
        var paneId = targetPaneSel.value || '';
        if (!paneId) {
            setSaveStatus('Pick a target pane first.', 'error');
            targetPaneSel.focus();
            return;
        }
        saveBtn.disabled = true;
        setSaveStatus('Saving…');
        postForm('save-selections', {
            adapter: adapterId,
            targetPaneId: paneId,
            defaultCategoryId: defaultCatSel.value || '',
            selections: JSON.stringify(Object.keys(state.selected))
        }).then(function (r) {
            saveBtn.disabled = false;
            if (!r.ok || !r.data || r.data.error) {
                setSaveStatus('Save failed: ' + ((r.data && r.data.error) || ('HTTP ' + r.status)), 'error');
                return;
            }
            var ing = r.data.ingest || {};
            var msg = 'Saved. ';
            if (ing.created || ing.updated || ing.deleted) {
                var bits = [];
                if (ing.created) bits.push(ing.created + ' added');
                if (ing.updated) bits.push(ing.updated + ' updated');
                if (ing.deleted) bits.push(ing.deleted + ' removed');
                msg += bits.join(', ') + ' on the calendar.';
            } else {
                msg += 'No calendar changes.';
            }
            setSaveStatus(msg, 'ok');
            // Mark NEW badges as no longer new on the client (server side
            // updated lastReviewedAt; restore via reload of catalogue).
            loadCatalogue();
        }).catch(function (err) {
            saveBtn.disabled = false;
            setSaveStatus('Save failed: ' + err.message, 'error');
        });
    });

    // ---- Cron section ----

    cronRevealBtn.addEventListener('click', function () {
        state.cronTokenRevealed = !state.cronTokenRevealed;
        renderCronLine();
    });

    cronCopyBtn.addEventListener('click', function () {
        var text = buildCronLine(true); // copy always uses real token
        if (!cronToken) {
            cronStatus.textContent = 'Save selections first to generate a token.';
            return;
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                cronStatus.textContent = 'Copied to clipboard.';
            }).catch(function () {
                cronStatus.textContent = 'Copy failed — select the line and copy manually.';
            });
        } else {
            cronStatus.textContent = 'Clipboard unavailable — select the line and copy manually.';
        }
    });

    cronRotateBtn.addEventListener('click', function () {
        if (!window.confirm('Rotate the cron token? The old token stops working immediately and you will need to update your crontab.')) {
            return;
        }
        cronRotateBtn.disabled = true;
        cronStatus.textContent = 'Rotating…';
        postForm('rotate-cron-token', {}).then(function (r) {
            cronRotateBtn.disabled = false;
            if (!r.ok || !r.data || r.data.error) {
                cronStatus.textContent = 'Rotate failed: ' + ((r.data && r.data.error) || ('HTTP ' + r.status));
                return;
            }
            cronToken = r.data.cronToken || '';
            root.setAttribute('data-cron-token', cronToken);
            state.cronTokenRevealed = true; // show the new token so the admin can copy it once
            renderCronLine();
            cronStatus.textContent = 'New token generated. Copy the command above and update your crontab.';
        }).catch(function (err) {
            cronRotateBtn.disabled = false;
            cronStatus.textContent = 'Rotate failed: ' + err.message;
        });
    });

    // ---- Bootstrap ----

    renderCronLine();
    loadCatalogue();
})();
