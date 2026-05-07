// Event Scraper admin UI — multi-feed picker + per-pane modal contribution.
// Loads via head_assets on every page; bails when not on an admin SITE
// CONFIG render (no .eventScraperConfig in DOM) and silently noops on the
// public site.

(function () {
    'use strict';

    var root = document.querySelector('.eventScraperConfig');
    var PROXY = '/res/scr/plugin-endpoint.php?plugin=eventScraper&endpoint=';

    // Plugin-wide state. Catalogue payload + caches admin.js consumes.
    var state = {
        loaded: false,
        feeds: {},                  // feedId -> {label, isConfigured, adapter, defaultCategoryId, allowlist, catalogue, lastScrape}
        siteDefaultSubscriptions: [],
        paneSubscriptions: {},      // paneId -> {override:[], useSiteDefaults}
        // per-feed UI state, keyed by feedId
        feedUi: {}                  // feedId -> {selected: {uid: true}, filterText, filterRegion}
    };

    function csrf() {
        return (window.appConfig && window.appConfig.csrfToken) || '';
    }

    function fetchCatalogue() {
        return fetch(PROXY + 'catalogue', { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                state.feeds = data.feeds || {};
                state.siteDefaultSubscriptions = data.siteDefaultSubscriptions || [];
                state.paneSubscriptions = data.paneSubscriptions || {};
                state.loaded = true;
                return data;
            });
    }

    function postForm(endpoint, payload) {
        var body = new URLSearchParams();
        body.append('csrf_token', csrf());
        Object.keys(payload).forEach(function (k) { body.append(k, payload[k]); });
        return fetch(PROXY + endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) {
            return r.json().then(function (data) {
                return { ok: r.ok, status: r.status, data: data };
            });
        });
    }

    function fmtDate(iso) {
        if (!iso) return '';
        var d = new Date(iso + 'T00:00:00');
        return isNaN(d.getTime()) ? iso : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function fmtRange(start, end) {
        return end && end !== start ? fmtDate(start) + ' – ' + fmtDate(end) : fmtDate(start);
    }

    // ---- Per-feed block rendering ----

    function feedUi(feedId) {
        if (!state.feedUi[feedId]) {
            state.feedUi[feedId] = { selected: {}, filterText: '', filterRegion: '' };
        }
        return state.feedUi[feedId];
    }

    function rebuildRegionFilter(blockEl, feed) {
        var seen = {};
        var regions = [];
        ((feed.catalogue && feed.catalogue.events) || []).forEach(function (e) {
            var parts = (e.location || '').split(',').map(function (s) { return s.trim(); });
            var maybe = parts[parts.length - 1];
            if (maybe && !seen[maybe]) { seen[maybe] = true; regions.push(maybe); }
        });
        regions.sort();
        var sel = blockEl.querySelector('.eventScraperFilterRegion');
        var current = sel.value;
        sel.innerHTML = '<option value="">All</option>';
        regions.forEach(function (r) {
            var opt = document.createElement('option');
            opt.value = r; opt.textContent = r;
            sel.appendChild(opt);
        });
        if (current && regions.indexOf(current) !== -1) sel.value = current;
    }

    function renderFeedList(blockEl, feed, ui) {
        var listEl = blockEl.querySelector('.eventScraperList');
        var events = (feed.catalogue && feed.catalogue.events) || [];

        if (!feed.isConfigured && events.length === 0) {
            listEl.innerHTML = '<p class="eventScraperListEmpty">Click "Refresh now" to fetch this feed for the first time.</p>';
            return;
        }
        if (events.length === 0) {
            listEl.innerHTML = '<p class="eventScraperListEmpty">No events cached.</p>';
            return;
        }

        var q = (ui.filterText || '').toLowerCase();
        var r = ui.filterRegion || '';
        var currentYear = String(new Date().getFullYear());
        var visible = events.filter(function (e) {
            // Current calendar year only — keeps next-year cons out of the picker.
            if (!e.startDate || e.startDate.indexOf(currentYear + '-') !== 0) return false;
            if (q) {
                var hay = (e.name + ' ' + (e.location || '')).toLowerCase();
                if (hay.indexOf(q) === -1) return false;
            }
            if (r) {
                var parts = (e.location || '').split(',').map(function (s) { return s.trim(); });
                if (parts[parts.length - 1] !== r) return false;
            }
            return true;
        }).sort(function (a, b) {
            return (a.startDate || '9999') < (b.startDate || '9999') ? -1
                : (a.startDate || '9999') > (b.startDate || '9999') ? 1 : 0;
        });

        if (visible.length === 0) {
            listEl.innerHTML = '<p class="eventScraperListEmpty">No events match your filter.</p>';
            return;
        }

        var table = document.createElement('table');
        table.className = 'eventScraperTable';
        var thead = document.createElement('thead');
        thead.innerHTML = '<tr><th><span class="visuallyHidden">Selected</span></th><th>Event</th><th>Location</th><th>Dates</th></tr>';
        table.appendChild(thead);
        var tbody = document.createElement('tbody');
        visible.forEach(function (e) {
            var tr = document.createElement('tr');
            tr.className = 'eventScraperRow';
            if (ui.selected[e.sourceUid]) tr.classList.add('eventScraperRowSelected');

            var cb = document.createElement('input');
            cb.type = 'checkbox'; cb.className = 'eventScraperCheckbox';
            cb.setAttribute('data-uid', e.sourceUid);
            cb.setAttribute('aria-label', 'Include ' + e.name);
            cb.checked = !!ui.selected[e.sourceUid];
            var tdCb = document.createElement('td'); tdCb.appendChild(cb); tr.appendChild(tdCb);

            var tdName = document.createElement('td');
            tdName.className = 'eventScraperRowName';
            tdName.textContent = e.name;
            tr.appendChild(tdName);

            var tdLoc = document.createElement('td');
            tdLoc.textContent = e.location || '';
            tr.appendChild(tdLoc);

            var tdDates = document.createElement('td');
            tdDates.textContent = fmtRange(e.startDate, e.endDate);
            tr.appendChild(tdDates);

            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        listEl.innerHTML = '';
        listEl.appendChild(table);
    }

    function updateFeedSelectionCount(blockEl, feed, ui) {
        // Count within visible scope (current year) so it matches the table.
        var currentYear = String(new Date().getFullYear());
        var inScope = ((feed.catalogue && feed.catalogue.events) || [])
            .filter(function (e) { return e.startDate && e.startDate.indexOf(currentYear + '-') === 0; });
        var selected = inScope.filter(function (e) { return ui.selected[e.sourceUid]; }).length;
        blockEl.querySelector('.eventScraperSelectionCount').textContent = selected + ' of ' + inScope.length + ' selected';
    }

    function bindFeedBlock(blockEl) {
        var feedId = blockEl.getAttribute('data-feed-id');
        if (!feedId || !state.feeds[feedId]) return;

        var feed = state.feeds[feedId];
        var ui = feedUi(feedId);

        // Restore saved selections from allowlist into the per-feed UI state.
        ui.selected = {};
        Object.keys(feed.allowlist || {}).forEach(function (uid) { ui.selected[uid] = true; });
        ui.dirty = false;

        // Status line.
        var statusText = blockEl.querySelector('.eventScraperFeedStatusText');
        if (feed.lastScrape && feed.lastScrape.ranAt) {
            var when = new Date(feed.lastScrape.ranAt);
            var count = (feed.catalogue && feed.catalogue.events && feed.catalogue.events.length) || 0;
            statusText.textContent = 'Last refresh: ' + when.toLocaleString() + ' · ' + count + ' events cached';
        } else if (feed.isConfigured) {
            statusText.textContent = 'No refresh yet — click "Refresh now".';
        }

        rebuildRegionFilter(blockEl, feed);
        renderFeedList(blockEl, feed, ui);
        updateFeedSelectionCount(blockEl, feed, ui);

        function markDirty() {
            ui.dirty = true;
            var indicator = blockEl.querySelector('.eventScraperDirtyIndicator');
            if (indicator) indicator.removeAttribute('hidden');
        }
        function clearDirty() {
            ui.dirty = false;
            var indicator = blockEl.querySelector('.eventScraperDirtyIndicator');
            if (indicator) indicator.setAttribute('hidden', 'hidden');
        }
        blockEl.querySelector('.eventScraperFeedLabel').addEventListener('input', markDirty);
        blockEl.querySelector('.eventScraperDefaultCategory').addEventListener('change', markDirty);

        // Wire handlers (delegated would be cleaner, but per-block keeps state local).
        blockEl.querySelector('.eventScraperRefreshBtn').addEventListener('click', function () {
            this.disabled = true;
            statusText.textContent = 'Refreshing…';
            statusText.className = 'eventScraperFeedStatusText';
            postForm('refresh', { feed: feedId }).then(function (r) {
                if (!r.ok || (r.data && r.data.status === 'error')) {
                    statusText.textContent = '✗ Refresh failed: ' + ((r.data && r.data.error) || ('HTTP ' + r.status));
                    statusText.className = 'eventScraperFeedStatusText eventScraperFeedStatusText--error';
                    return;
                }
                var d = r.data;
                var msg = '✓ Refreshed ' + d.count + ' events';
                if (d.diff) {
                    var bits = [];
                    if (d.diff.new && d.diff.new.length) bits.push(d.diff.new.length + ' new');
                    if (d.diff.changed && d.diff.changed.length) bits.push(d.diff.changed.length + ' changed');
                    if (d.diff.removed && d.diff.removed.length) bits.push(d.diff.removed.length + ' removed');
                    if (bits.length) msg += ' (' + bits.join(', ') + ')';
                }
                statusText.textContent = msg + '.';
                statusText.className = 'eventScraperFeedStatusText eventScraperFeedStatusText--ok';
                // Hold success message ~4s before rerenderAll overwrites it.
                return fetchCatalogue().then(function () {
                    setTimeout(function () { rerenderAll(); }, 4000);
                });
            }).catch(function (err) {
                statusText.textContent = '✗ Refresh failed: ' + err.message;
                statusText.className = 'eventScraperFeedStatusText eventScraperFeedStatusText--error';
            }).finally(function () {
                blockEl.querySelector('.eventScraperRefreshBtn').disabled = false;
            });
        });

        blockEl.querySelector('.eventScraperFilterText').addEventListener('input', function () {
            ui.filterText = this.value;
            renderFeedList(blockEl, feed, ui);
        });
        blockEl.querySelector('.eventScraperFilterRegion').addEventListener('change', function () {
            ui.filterRegion = this.value;
            renderFeedList(blockEl, feed, ui);
        });

        blockEl.querySelector('.eventScraperList').addEventListener('change', function (ev) {
            var t = ev.target;
            if (!t.classList || !t.classList.contains('eventScraperCheckbox')) return;
            var uid = t.getAttribute('data-uid');
            if (t.checked) ui.selected[uid] = true;
            else delete ui.selected[uid];
            var row = t.closest('tr');
            if (row) row.classList.toggle('eventScraperRowSelected', t.checked);
            updateFeedSelectionCount(blockEl, feed, ui);
            markDirty();
        });

        blockEl.querySelector('.eventScraperSaveBtn').addEventListener('click', function () {
            var btn = this;
            var statusEl = blockEl.querySelector('.eventScraperSaveStatus');
            var label = (blockEl.querySelector('.eventScraperFeedLabel').value || '').trim();
            var defaultCat = blockEl.querySelector('.eventScraperDefaultCategory').value || '';
            btn.disabled = true;
            statusEl.textContent = 'Saving…';
            statusEl.className = 'eventScraperSaveStatus';
            postForm('save-selections', {
                feed: feedId,
                label: label,
                defaultCategoryId: defaultCat,
                selections: JSON.stringify(Object.keys(ui.selected))
            }).then(function (r) {
                btn.disabled = false;
                if (!r.ok || !r.data || r.data.error) {
                    statusEl.textContent = '✗ Save failed: ' + ((r.data && r.data.error) || ('HTTP ' + r.status));
                    statusEl.className = 'eventScraperSaveStatus eventScraperSaveStatus--error';
                    return;
                }
                var paneCount = (r.data.ingest && r.data.ingest.panes && r.data.ingest.panes.length) || 0;
                statusEl.textContent = '✓ Saved (' + r.data.selectedCount + ' selected, applied to ' + paneCount + ' pane' + (paneCount === 1 ? '' : 's') + ').';
                statusEl.className = 'eventScraperSaveStatus eventScraperSaveStatus--ok';
                clearDirty();
                return fetchCatalogue().then(function () { rerenderAll(); });
            }).catch(function (err) {
                btn.disabled = false;
                statusEl.textContent = 'Save failed: ' + err.message;
                statusEl.className = 'eventScraperSaveStatus eventScraperSaveStatus--error';
            });
        });
    }

    // ---- Default-subscriptions block ----

    function renderDefaultSubsBlock() {
        if (!root) return;
        var listEl = root.querySelector('.eventScraperDefaultSubsList');
        var saveBtn = root.querySelector('.eventScraperSaveDefaultSubsBtn');
        if (!listEl || !saveBtn) return;
        listEl.innerHTML = '';
        var configured = Object.keys(state.feeds).filter(function (id) { return state.feeds[id].isConfigured; });
        if (!configured.length) {
            listEl.innerHTML = '<p class="eventScraperListEmpty">No feeds configured yet — configure feeds below first.</p>';
            saveBtn.disabled = true;
            return;
        }
        configured.forEach(function (feedId) {
            var feed = state.feeds[feedId];
            var label = document.createElement('label');
            label.className = 'siteConfigToggle';
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.setAttribute('data-feed-id', feedId);
            cb.checked = state.siteDefaultSubscriptions.indexOf(feedId) !== -1;
            var span = document.createElement('span');
            span.textContent = feed.label;
            label.appendChild(cb);
            label.appendChild(span);
            listEl.appendChild(label);
        });
        saveBtn.disabled = false;
    }

    function bindDefaultSubsBlock() {
        if (!root) return;
        var saveBtn = root.querySelector('.eventScraperSaveDefaultSubsBtn');
        var statusEl = root.querySelector('.eventScraperSaveDefaultSubsStatus');
        if (!saveBtn) return;
        saveBtn.addEventListener('click', function () {
            var checked = Array.prototype.slice.call(
                root.querySelectorAll('.eventScraperDefaultSubsList input[type=checkbox]:checked')
            ).map(function (cb) { return cb.getAttribute('data-feed-id'); });
            saveBtn.disabled = true;
            statusEl.textContent = 'Saving…';
            postForm('save-default-subscriptions', {
                subscriptions: JSON.stringify(checked)
            }).then(function (r) {
                saveBtn.disabled = false;
                if (!r.ok || !r.data || r.data.error) {
                    statusEl.textContent = 'Save failed: ' + ((r.data && r.data.error) || ('HTTP ' + r.status));
                    return;
                }
                statusEl.textContent = 'Saved.';
                state.siteDefaultSubscriptions = checked;
            });
        });
    }

    // ---- Cron section ----

    function bindCronSection() {
        if (!root) return;
        var cronUrl = root.getAttribute('data-cron-url') || '';
        var cronToken = root.getAttribute('data-cron-token') || '';
        var revealed = false;
        var lineEl = root.querySelector('.eventScraperCronLine');
        var revealBtn = root.querySelector('.eventScraperCronRevealBtn');
        var copyBtn = root.querySelector('.eventScraperCronCopyBtn');
        var rotateBtn = root.querySelector('.eventScraperCronRotateBtn');
        var statusEl = root.querySelector('.eventScraperCronStatus');
        if (!lineEl) return;

        function buildLine(showToken) {
            var t = showToken && cronToken ? cronToken
                : (cronToken ? '••••••••••••••••••••••••' : 'YOUR_TOKEN_HERE');
            return '0 3 * * * curl -fsS -H "X-Scraper-Cron-Token: ' + t + '" "' + cronUrl + '" > /dev/null';
        }
        function paint() {
            lineEl.textContent = buildLine(revealed);
            revealBtn.textContent = revealed ? 'Hide token' : 'Reveal token';
            revealBtn.disabled = !cronToken;
            copyBtn.disabled = !cronToken;
        }
        paint();

        revealBtn.addEventListener('click', function () { revealed = !revealed; paint(); });
        copyBtn.addEventListener('click', function () {
            if (!cronToken) { statusEl.textContent = 'Save a feed first to generate a token.'; return; }
            (navigator.clipboard ? navigator.clipboard.writeText(buildLine(true))
                : Promise.reject(new Error('clipboard unavailable')))
                .then(function () { statusEl.textContent = 'Copied to clipboard.'; })
                .catch(function () { statusEl.textContent = 'Copy failed — select the line manually.'; });
        });
        rotateBtn.addEventListener('click', function () {
            if (!window.confirm('Rotate the cron token? The old one stops working immediately.')) return;
            rotateBtn.disabled = true; statusEl.textContent = 'Rotating…';
            postForm('rotate-cron-token', {}).then(function (r) {
                rotateBtn.disabled = false;
                if (!r.ok || !r.data || r.data.error) {
                    statusEl.textContent = 'Rotate failed: ' + ((r.data && r.data.error) || ('HTTP ' + r.status));
                    return;
                }
                cronToken = r.data.cronToken || '';
                root.setAttribute('data-cron-token', cronToken);
                revealed = true;
                paint();
                statusEl.textContent = 'New token generated. Copy + update your crontab.';
            });
        });
    }

    // ---- Per-pane modal contribution ----

    function injectPerPaneSection($modal, paneId, useSiteDefaults) {
        // Strip any prior injection so reopen replaces stale state.
        var modalEl = $modal && $modal[0] ? $modal[0] : null;
        if (!modalEl) return;
        var existing = modalEl.querySelector('.eventScraperPerPaneSection');
        if (existing && existing.parentNode) existing.parentNode.removeChild(existing);

        var configured = Object.keys(state.feeds).filter(function (id) { return state.feeds[id].isConfigured; });
        if (!configured.length) return; // nothing to subscribe to

        var paneSubs = state.paneSubscriptions[paneId] || { override: [], useSiteDefaults: true };
        var effective = paneSubs.useSiteDefaults
            ? state.siteDefaultSubscriptions.slice()
            : paneSubs.override.slice();

        var section = document.createElement('section');
        section.className = 'panePerPaneSettingsModuleSection eventScraperPerPaneSection';
        section.innerHTML = '<h3 class="paneSettingsHeading">Subscribed feeds</h3>';
        var hint = document.createElement('p');
        hint.className = 'paneHint';
        hint.textContent = useSiteDefaults
            ? 'Currently using site defaults. Untick "Use site defaults" above to override per pane.'
            : 'Tick the feeds this pane should ingest from.';
        section.appendChild(hint);
        var list = document.createElement('div');
        list.className = 'eventScraperPerPaneList';
        configured.forEach(function (feedId) {
            var feed = state.feeds[feedId];
            var label = document.createElement('label');
            label.className = 'siteConfigToggle';
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.className = 'eventScraperPerPaneCheckbox';
            cb.setAttribute('data-feed-id', feedId);
            cb.checked = effective.indexOf(feedId) !== -1;
            cb.disabled = useSiteDefaults; // mirrors core's disabled-overrides behavior
            var span = document.createElement('span');
            span.textContent = feed.label;
            label.appendChild(cb);
            label.appendChild(span);
            list.appendChild(label);
        });
        section.appendChild(list);

        // Save handled by the modal's main Save button via
        // lp:per-pane-settings-saved (single click saves icon + bool flags +
        // subscriptions). No separate Save button here.

        // Module section is the right anchor; insert after it.
        var anchor = modalEl.querySelector('.panePerPaneSettingsModuleSection');
        if (anchor && anchor.parentNode) {
            anchor.parentNode.insertBefore(section, anchor.nextSibling);
        } else {
            // Fallback: append to modal body.
            var body = modalEl.querySelector('.adminModalBody') || modalEl;
            body.appendChild(section);
        }

        // Mirror core's "Use site defaults" toggle — when ticked, lock our
        // checkboxes too so the modal feels like one cohesive override.
        var useSiteDefaultsInput = modalEl.querySelector('#panePerPaneSettingsUseDefaultsInput');
        if (useSiteDefaultsInput) {
            useSiteDefaultsInput.addEventListener('change', function () {
                var locked = useSiteDefaultsInput.checked;
                section.querySelectorAll('.eventScraperPerPaneCheckbox').forEach(function (cb) { cb.disabled = locked; });
                hint.textContent = locked
                    ? 'Currently using site defaults. Untick "Use site defaults" above to override per pane.'
                    : 'Tick the feeds this pane should ingest from.';
            });
        }
    }

    function bindPerPaneModal() {
        if (typeof window.jQuery === 'undefined') return;
        var $j = window.jQuery;
        $j(document).on('lp:perPaneModalOpening', function (ev, ctx) {
            if (!ctx || ctx.moduleId !== 'eventList') return;
            if (!state.loaded) {
                fetchCatalogue().then(function () {
                    injectPerPaneSection(ctx.$modal, ctx.paneId, ctx.useSiteDefaults);
                }).catch(function () {});
            } else {
                injectPerPaneSection(ctx.$modal, ctx.paneId, ctx.useSiteDefaults);
            }
        });
        // Piggyback on core's pane-settings-saved fire — single Save click in
        // the modal also persists our subscribedFeeds + re-runs ingest.
        $j(document).on('lp:per-pane-settings-saved', function (ev, ctx) {
            if (!ctx || ctx.module !== 'eventList') return;
            var section = document.querySelector('.eventScraperPerPaneSection');
            if (!section) return;
            var useSiteDefaultsNow = !!ctx.useSiteDefaults;
            var checked = Array.prototype.slice.call(
                section.querySelectorAll('.eventScraperPerPaneCheckbox:checked')
            ).map(function (cb) { return cb.getAttribute('data-feed-id'); });
            postForm('save-pane-subscriptions', {
                paneId: ctx.paneId,
                useSiteDefaults: useSiteDefaultsNow ? '1' : '0',
                subscriptions: JSON.stringify(checked)
            }).then(function (r) {
                if (r.ok && r.data && !r.data.error) {
                    state.paneSubscriptions[ctx.paneId] = {
                        override: checked,
                        useSiteDefaults: useSiteDefaultsNow
                    };
                }
            });
        });
    }

    // ---- Top-level orchestration ----

    function rerenderAll() {
        if (!root) return;
        renderDefaultSubsBlock();
        Array.prototype.slice.call(root.querySelectorAll('.eventScraperFeedBlock')).forEach(function (b) {
            // Re-bind handlers? Already bound; just re-render the dynamic bits.
            var feedId = b.getAttribute('data-feed-id');
            if (!feedId || !state.feeds[feedId]) return;
            var feed = state.feeds[feedId];
            var ui = feedUi(feedId);
            ui.selected = {};
            Object.keys(feed.allowlist || {}).forEach(function (uid) { ui.selected[uid] = true; });
            rebuildRegionFilter(b, feed);
            renderFeedList(b, feed, ui);
            updateFeedSelectionCount(b, feed, ui);
            var statusText = b.querySelector('.eventScraperFeedStatusText');
            if (feed.lastScrape && feed.lastScrape.ranAt) {
                var when = new Date(feed.lastScrape.ranAt);
                var count = (feed.catalogue && feed.catalogue.events && feed.catalogue.events.length) || 0;
                statusText.textContent = 'Last refresh: ' + when.toLocaleString() + ' · ' + count + ' events cached';
            } else if (feed.isConfigured) {
                statusText.textContent = 'No refresh yet — click "Refresh now".';
            } else {
                statusText.textContent = 'Not configured yet — click "Refresh now" to fetch and start picking events.';
            }
        });
    }

    function init() {
        bindPerPaneModal(); // safe to bind even when there's no SITE CONFIG fieldset on this page

        if (!root) return; // not on the SITE CONFIG admin page; per-pane modal hook stays armed

        // Bind feed blocks first (handlers attached before async catalogue load).
        Array.prototype.slice.call(root.querySelectorAll('.eventScraperFeedBlock')).forEach(bindFeedBlock);
        bindDefaultSubsBlock();
        bindCronSection();

        var globalStatus = root.querySelector('.eventScraperGlobalStatusText');
        var refreshAllBtn = root.querySelector('.eventScraperRefreshAllBtn');

        fetchCatalogue().then(function () {
            globalStatus.textContent = Object.keys(state.feeds).length + ' adapters discovered.';
            refreshAllBtn.disabled = false;
            rerenderAll();
            // re-bind handlers for blocks that didn't have feed state at first bind
            Array.prototype.slice.call(root.querySelectorAll('.eventScraperFeedBlock')).forEach(bindFeedBlock);
        }).catch(function (err) {
            globalStatus.textContent = 'Error loading catalogue: ' + err.message;
        });

        if (refreshAllBtn) {
            refreshAllBtn.addEventListener('click', function () {
                refreshAllBtn.disabled = true;
                globalStatus.textContent = 'Refreshing all feeds…';
                globalStatus.className = 'eventScraperGlobalStatusText';
                postForm('refresh', {}).then(function (r) {
                    refreshAllBtn.disabled = false;
                    if (!r.ok || !r.data) {
                        globalStatus.textContent = '✗ Refresh failed: HTTP ' + r.status;
                        globalStatus.className = 'eventScraperGlobalStatusText eventScraperGlobalStatusText--error';
                        return;
                    }
                    var feedResults = r.data.feeds || [];
                    var ok = feedResults.filter(function (f) { return f.status === 'ok'; }).length;
                    var fail = feedResults.length - ok;
                    if (r.data.status === 'ok') {
                        globalStatus.textContent = '✓ Refreshed ' + ok + ' feed' + (ok === 1 ? '' : 's') + ' successfully.';
                        globalStatus.className = 'eventScraperGlobalStatusText eventScraperGlobalStatusText--ok';
                    } else {
                        globalStatus.textContent = '✗ ' + fail + ' of ' + feedResults.length + ' feeds failed (see Diagnostics).';
                        globalStatus.className = 'eventScraperGlobalStatusText eventScraperGlobalStatusText--error';
                    }
                    return fetchCatalogue().then(function () { rerenderAll(); });
                });
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
