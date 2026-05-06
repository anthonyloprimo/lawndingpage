// Event list admin behavior. Per-pane: read snapshot from JSON island,
// track local edits + deleted ids, emit additive-merge changeset payload
// for Save All, predict server-assigned ids on save success.
//
// Concurrency limitation: predicted ids assume no concurrent admin saved
// between snapshot capture and Save click. Solo admin -> never bites.
// For the multi-admin future, server should return assignments instead.

(function ($) {
    'use strict';

    const paneApis = new Map();
    let pendingDelete = null;

    // Source: SITE CONFIG fieldset's data-categories (canonical) or first
    // pane's data-snapshot (fallback when admin lacks canEditSite). Runtime
    // updates flow via the lp:eventListCategoriesUpdated jQuery event.
    let currentCategories = [];

    // Orphan ids fall back to (none) in the dropdown; the stored categoryId
    // is preserved so the renderer can pick the default flag color.
    function populateCategorySelect($select, categories, selectedId) {
        const safeSelected = String(selectedId == null ? '' : selectedId);
        $select.empty();
        $select.append($('<option>').attr('value', '').text('(none)'));
        categories.forEach((cat) => {
            const id = cat && cat.id != null ? String(cat.id) : '';
            const name = cat && cat.name != null ? String(cat.name) : '';
            if (id === '') { return; }
            $select.append($('<option>').attr('value', id).text(name));
        });
        $select.val(safeSelected);
    }

    function setupPane($pane) {
        const paneId = $pane.data('pane-id') || $pane.attr('id') || '';
        const $list = $pane.find('.eventList');
        const $payload = $pane.find('.eventListPayload');

        // Snapshot of server state at last load/save. Mutated on save success.
        let snapshot = readSnapshot($pane);
        const deletedIds = new Set();

        function readSnapshot($p) {
            // Snapshot lives on data-snapshot of the pane root (set by admin.php).
            // Attribute form sidesteps CSP script-src; jQuery's .data() would
            // auto-parse but cache aggressively, so we use .attr() + JSON.parse.
            try {
                const parsed = JSON.parse($p.attr('data-snapshot') || '{}');
                return Array.isArray(parsed.events) ? parsed.events.slice() : [];
            } catch (err) {
                return [];
            }
        }

        function getBrowserTimeZone() {
            if (typeof Intl !== 'undefined' && Intl.DateTimeFormat) {
                return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
            }
            return '';
        }

        function getEventCards() {
            return $list.find('.eventCard');
        }

        function readEventFromCard($card) {
            const allDay = $card.find('.eventAllDayInput').is(':checked');
            return {
                id: $card.attr('data-event-id') || '',
                name: ($card.find('.eventNameInput').val() || '').trim(),
                categoryId: ($card.find('.eventCategoryInput').val() || '').trim(),
                startDate: ($card.find('.eventStartDateInput').val() || '').trim(),
                startTime: allDay ? '' : ($card.find('.eventStartTimeInput').val() || '').trim(),
                endDate: ($card.find('.eventEndDateInput').val() || '').trim(),
                endTime: allDay ? '' : ($card.find('.eventEndTimeInput').val() || '').trim(),
                timeZone: ($card.find('.eventTimezoneInput').val() || '').trim() || getBrowserTimeZone(),
                address: ($card.find('.eventAddressInput').val() || '').trim(),
                description: ($card.find('.eventDescriptionInput').val() || '').trim(),
                allDay: allDay
            };
        }

        function eventFieldsEqual(a, b) {
            const fields = ['name', 'categoryId', 'startDate', 'startTime', 'endDate', 'endTime',
                'timeZone', 'address', 'description'];
            for (const f of fields) {
                if ((a[f] || '') !== (b[f] || '')) { return false; }
            }
            return !!a.allDay === !!b.allDay;
        }

        function snapshotById(id) {
            return snapshot.find((ev) => ev && String(ev.id || '') === String(id)) || null;
        }

        function computeChangeset() {
            const create = [];
            const update = [];
            getEventCards().each(function () {
                const event = readEventFromCard($(this));
                if (!event.id) {
                    const { id: _, ...payload } = event;
                    create.push(payload);
                } else {
                    const orig = snapshotById(event.id);
                    if (!orig || !eventFieldsEqual(orig, event)) {
                        update.push(event);
                    }
                }
            });
            return {
                create,
                update,
                delete: Array.from(deletedIds)
            };
        }

        function updatePayload() {
            $payload.val(JSON.stringify({ changes: computeChangeset() }));
        }

        function isEndBeforeStart(startDate, startTime, endDate, endTime) {
            if (!startDate || !startTime || !endDate || !endTime) {
                return false;
            }
            return `${endDate} ${endTime}` < `${startDate} ${startTime}`;
        }

        function validateEventCards() {
            let isValid = true;
            const seen = new Set();
            getEventCards().each(function () {
                const $card = $(this);
                const $message = $card.find('.eventValidation');
                $message.text('');
                const event = readEventFromCard($card);
                const errors = [];
                if (!event.name) { errors.push('Name is required.'); }
                if (!event.startDate) { errors.push('Start date is required.'); }
                if (!event.allDay && !event.startTime) { errors.push('Start time is required.'); }
                if (!event.allDay
                    && ((event.endDate && !event.endTime) || (!event.endDate && event.endTime))) {
                    errors.push('End date and time must both be set or both be blank.');
                }
                if (event.allDay && event.endDate && event.endDate < event.startDate) {
                    errors.push('End date cannot be earlier than start date.');
                } else if (!event.allDay && event.endDate && event.endTime
                    && isEndBeforeStart(event.startDate, event.startTime, event.endDate, event.endTime)) {
                    errors.push('End date/time cannot be earlier than start date/time.');
                }
                if (!event.address) { errors.push('Address is required.'); }
                if (!event.description) { errors.push('Description is required.'); }
                const dedupeKey = event.allDay
                    ? `${event.name.toLowerCase()}|${event.startDate}|allday`
                    : `${event.name.toLowerCase()}|${event.startDate}|${event.startTime}`;
                if (event.name && event.startDate && (event.allDay || event.startTime)) {
                    if (seen.has(dedupeKey)) {
                        errors.push('Duplicate event (name + date + time).');
                    } else {
                        seen.add(dedupeKey);
                    }
                }
                if (errors.length) {
                    $message.text(errors.join(' '));
                    isValid = false;
                }
            });
            return isValid;
        }

        function refreshValidation() {
            updatePayload();
            return validateEventCards();
        }

        function ensureEmptyState() {
            const hasCards = getEventCards().length > 0;
            $list.find('.eventEmpty').remove();
            if (!hasCards) {
                $list.append('<div class="eventEmpty">No events yet. Click Add Event to create one.</div>');
            }
        }

        function renderCard(data, prepend) {
            const markdownToolbar = window.buildMarkdownToolbarHtml
                ? window.buildMarkdownToolbarHtml() : '';
            const cardId = data && data.id ? String(data.id) : '';
            const safeId = cardId.replace(/[^A-Za-z0-9_-]/g, '');
            const template = `
                <div class="eventCard" data-event-id="${safeId}">
                    <div class="eventNameRow">
                        <label class="eventNameLabel">
                            <span class="eventFieldTitle">Event Name</span>
                            <input type="text" class="eventNameInput" placeholder="Event name">
                        </label>
                        <div class="eventCardActions">
                            <button class="deleteLink iconButton" type="button" title="Remove event" aria-label="Remove event">${$('.linksConfig .deleteLink').first().html() || ''}</button>
                        </div>
                    </div>
                    <div class="eventCategoryFieldRow">
                        <label class="eventCategoryFieldLabel">
                            <span class="eventFieldTitle">Category</span>
                            <select class="eventCategoryInput" aria-label="Category"></select>
                        </label>
                    </div>
                    <div class="eventSectionDivider" aria-hidden="true"></div>
                    <div class="eventAllDayRow">
                        <label class="eventAllDayLabel">
                            <input type="checkbox" class="eventAllDayInput">
                            <span>All day</span>
                        </label>
                    </div>
                    <div class="eventTimeRow">
                        <div class="eventFieldTitle eventFieldTitleRow">When</div>
                        <div class="eventTimeFields">
                            <div class="eventTimeGroup">
                                <span class="eventTimeLabel">From</span>
                                <input type="date" class="eventStartDateInput" aria-label="Start date">
                                <input type="time" class="eventStartTimeInput" aria-label="Start time">
                            </div>
                            <div class="eventTimeDash">-</div>
                            <div class="eventTimeGroup">
                                <span class="eventTimeLabel">To</span>
                                <input type="date" class="eventEndDateInput" aria-label="End date">
                                <input type="time" class="eventEndTimeInput" aria-label="End time">
                            </div>
                        </div>
                    </div>
                    <div class="eventSectionDivider" aria-hidden="true"></div>
                    <div class="eventTimeZoneRow">
                        <span class="eventFieldTitle">Time Zone</span>
                        <input type="text" class="eventTimezoneInput" placeholder="America/New_York" aria-label="Time zone">
                    </div>
                    <div class="eventSectionDivider" aria-hidden="true"></div>
                    <div class="eventAddressRow">
                        <div class="eventFieldTitle">Address</div>
                        <input type="text" class="eventAddressInput" placeholder="123 Main St, City, State" aria-label="Address">
                    </div>
                    <div class="eventSectionDivider" aria-hidden="true"></div>
                    <div class="eventDescriptionLabel">
                        <span class="eventFieldTitle">Description</span>
                        <div class="markdownEditor">
                            ${markdownToolbar}
                            <textarea class="eventDescriptionInput markdownTextarea" rows="4" placeholder="Details, host, venue, etc."></textarea>
                            <div class="markdownPreview" aria-live="polite" hidden></div>
                        </div>
                    </div>
                    <div class="eventValidation" aria-live="polite"></div>
                </div>
            `;
            const $card = $(template);
            // Dropdown options: render unconditionally — even a new card
            // (data === null) needs the (none) + categories <option>s.
            populateCategorySelect(
                $card.find('.eventCategoryInput'),
                currentCategories,
                data && data.categoryId ? data.categoryId : ''
            );
            if (data) {
                $card.find('.eventNameInput').val(data.name || '');
                $card.find('.eventStartDateInput').val(data.startDate || '');
                $card.find('.eventStartTimeInput').val(data.startTime || '');
                $card.find('.eventEndDateInput').val(data.endDate || '');
                $card.find('.eventEndTimeInput').val(data.endTime || '');
                $card.find('.eventTimezoneInput').val(data.timeZone || getBrowserTimeZone());
                $card.find('.eventAddressInput').val(data.address || '');
                $card.find('.eventDescriptionInput').val(data.description || '');
                if (data.allDay) {
                    $card.find('.eventAllDayInput').prop('checked', true);
                    $card.find('.eventTimeRow').addClass('isAllDay');
                }
            } else {
                $card.find('.eventTimezoneInput').val(getBrowserTimeZone());
            }
            if (prepend) {
                $list.prepend($card);
            } else {
                $list.append($card);
            }
            const $scroll = $pane.find('.eventListScroll');
            if ($scroll.length) {
                $scroll.scrollTop(0);
            }
            ensureEmptyState();
            refreshValidation();
        }

        function compareEventsDesc(a, b) {
            const aKey = `${a.startDate || ''} ${a.startTime || ''}`;
            const bKey = `${b.startDate || ''} ${b.startTime || ''}`;
            return bKey.localeCompare(aKey);
        }

        // Post-save: predict server ids for new cards (sequential from snapshot
        // max), refresh snapshot, clear deletedIds, re-render in date order.
        // Server applies deletes BEFORE creates, so deletedIds must be excluded
        // from the maxId calculation — otherwise deleting the highest-id event
        // and creating a new one in the same save desyncs client and server.
        function applyPostSaveState() {
            let maxId = 0;
            snapshot.forEach((ev) => {
                const idStr = String(ev && ev.id ? ev.id : '');
                if (deletedIds.has(idStr)) { return; }
                const n = parseInt(idStr, 10);
                if (Number.isFinite(n) && n > maxId) { maxId = n; }
            });
            getEventCards().each(function () {
                const $card = $(this);
                if (!($card.attr('data-event-id') || '')) {
                    maxId += 1;
                    $card.attr('data-event-id', String(maxId));
                }
            });

            const newSnapshot = [];
            getEventCards().each(function () {
                const event = readEventFromCard($(this));
                if (event.id) { newSnapshot.push(event); }
            });
            snapshot = newSnapshot;
            deletedIds.clear();

            const sorted = newSnapshot.slice().sort(compareEventsDesc);
            $list.empty();
            if (!sorted.length) {
                ensureEmptyState();
                refreshValidation();
                return;
            }
            sorted.forEach((event) => renderCard(event, false));
            ensureEmptyState();
            refreshValidation();
        }

        function deleteCard($card) {
            const cid = $card.attr('data-event-id') || '';
            if (cid) { deletedIds.add(cid); }
            $card.remove();
            ensureEmptyState();
            refreshValidation();
        }

        $pane.on('click', '.eventAddButton', function () {
            renderCard(null, true);
        });

        $pane.on('click', '.eventCard .deleteLink', function () {
            const $card = $(this).closest('.eventCard');
            pendingDelete = { paneId, $card };
            if (window.openAdminModal) {
                window.openAdminModal($('#eventDeleteConfirmModal'));
            }
        });

        $pane.on('change', '.eventAllDayInput', function () {
            const $card = $(this).closest('.eventCard');
            $card.find('.eventTimeRow').toggleClass('isAllDay', this.checked);
        });

        $pane.on('input change',
            '.eventCard input, .eventCard textarea, .eventCard select',
            function () {
                refreshValidation();
            });

        refreshValidation();

        return { applyPostSaveState, deleteCard };
    }

    // SITE CONFIG categories CRUD UI. Soft-delete + batched save: nothing
    // hits the server until "Save Categories." Server response is the
    // post-merge truth; broadcast via lp:eventListCategoriesUpdated.
    function setupCategoriesEditor() {
        const $fieldset = $('.eventListCategoriesConfig');
        if (!$fieldset.length) { return; }
        const $list = $fieldset.find('.eventCategoriesList');
        const $addBtn = $fieldset.find('.eventCategoriesAdd');
        const $saveBtn = $fieldset.find('.eventCategoriesSave');
        const $status = $fieldset.find('.eventCategoriesStatus');

        let serverCategories = currentCategories.slice();

        function renderEmpty() {
            $list.html('<div class="eventCategoriesEmpty">No categories yet. Click Add Category to create one.</div>');
        }

        function renderRow(category) {
            const id = category && category.id != null ? String(category.id) : '';
            const name = category && category.name != null ? String(category.name) : '';
            const color = category && category.color ? String(category.color) : '#7ec7ed';
            const $row = $('<div class="eventCategoryRow"></div>').attr('data-category-id', id);
            $row.append($('<input type="color" class="eventCategoryColorInput" aria-label="Category color">').val(color));
            $row.append($('<input type="text" class="eventCategoryNameInput" placeholder="Category name" aria-label="Category name">').val(name));
            const deleteIcon = $('.linksConfig .deleteLink').first().html() || '×';
            $row.append('<button type="button" class="eventCategoryDelete iconButton" aria-label="Remove category" title="Remove category">' + deleteIcon + '</button>');
            $list.find('.eventCategoriesEmpty').remove();
            $list.append($row);
            return $row;
        }

        function renderAll() {
            $list.empty();
            if (!serverCategories.length) { renderEmpty(); return; }
            serverCategories.forEach((cat) => renderRow(cat));
        }

        function readLocalState() {
            const rows = [];
            $list.find('.eventCategoryRow').each(function () {
                const $row = $(this);
                rows.push({
                    id: $row.attr('data-category-id') || '',
                    name: ($row.find('.eventCategoryNameInput').val() || '').trim(),
                    color: ($row.find('.eventCategoryColorInput').val() || '').trim(),
                    isDeleted: $row.hasClass('isDeleted')
                });
            });
            return rows;
        }

        function computeChangeset() {
            const create = [], update = [], deleteIds = [];
            readLocalState().forEach((row) => {
                if (row.id === '') {
                    if (!row.isDeleted) { create.push({ name: row.name, color: row.color }); }
                    return;
                }
                if (row.isDeleted) { deleteIds.push(row.id); return; }
                const orig = serverCategories.find((c) => String(c.id) === String(row.id));
                if (!orig || orig.name !== row.name || orig.color !== row.color) {
                    update.push({ id: row.id, name: row.name, color: row.color });
                }
            });
            return { create, update, delete: deleteIds };
        }

        function refreshSaveButton() {
            const cs = computeChangeset();
            $saveBtn.prop('disabled', !(cs.create.length || cs.update.length || cs.delete.length));
        }

        function save() {
            const changeset = computeChangeset();
            $saveBtn.prop('disabled', true);
            $status.text('Saving…');
            const csrf = (window.appConfig && window.appConfig.csrfToken) || '';
            const body = 'csrf_token=' + encodeURIComponent(csrf)
                + '&changes=' + encodeURIComponent(JSON.stringify(changeset));
            fetch('/res/scr/module-endpoint.php?module=eventList&endpoint=categories', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            }).then(async (res) => {
                const json = await res.json().catch(() => ({}));
                if (!res.ok) { throw new Error(json.error || ('HTTP ' + res.status)); }
                return json;
            }).then((json) => {
                const cats = Array.isArray(json.categories) ? json.categories : [];
                serverCategories = cats.slice();
                currentCategories = cats.slice();
                renderAll();
                refreshSaveButton();
                $(document).trigger('lp:eventListCategoriesUpdated', [{ categories: cats.slice() }]);
                $status.text('Saved.');
                setTimeout(() => $status.text(''), 2000);
            }).catch((err) => {
                $status.text('');
                const msg = (err && err.message) || 'Failed to save categories.';
                if (window.addAdminNotice) {
                    window.addAdminNotice('danger', 'Categories: ' + msg);
                }
                refreshSaveButton();
            });
        }

        $addBtn.on('click', function () { renderRow(null); refreshSaveButton(); });
        $list.on('click', '.eventCategoryDelete', function () {
            const $row = $(this).closest('.eventCategoryRow');
            if ($row.attr('data-category-id') === '') {
                $row.remove();
                if (!$list.find('.eventCategoryRow').length) { renderEmpty(); }
            } else {
                $row.toggleClass('isDeleted');
            }
            refreshSaveButton();
        });
        $list.on('input change', '.eventCategoryRow input', refreshSaveButton);
        $saveBtn.on('click', save);

        renderAll();
        refreshSaveButton();
    }

    function init() {
        // Reparent the shared delete-confirm modal to <body> so its
        // position:fixed isn't anchored to a transformed/contained ancestor
        // (per ARCHITECTURE.md trait #9). Idempotent — only moves once.
        const $modal = $('#eventDeleteConfirmModal');
        if ($modal.length && !$modal.parent().is('body')) {
            $modal.appendTo('body');
        }

        // Must run before setupPane: renderCard reads currentCategories.
        const $catFieldset = $('.eventListCategoriesConfig');
        const sourceAttr = $catFieldset.length ? $catFieldset.attr('data-categories') : null;
        if (sourceAttr) {
            try {
                const parsed = JSON.parse(sourceAttr);
                currentCategories = Array.isArray(parsed.categories) ? parsed.categories.slice() : [];
            } catch (err) { currentCategories = []; }
        } else {
            const $firstPane = $('[data-pane-type="eventList"]').first();
            try {
                const snap = JSON.parse($firstPane.attr('data-snapshot') || '{}');
                currentCategories = Array.isArray(snap.categories) ? snap.categories.slice() : [];
            } catch (err) { currentCategories = []; }
        }

        $('[data-pane-type="eventList"]').each(function () {
            const $pane = $(this);
            const api = setupPane($pane);
            const paneId = $pane.data('pane-id') || $pane.attr('id') || '';
            if (paneId) { paneApis.set(paneId, api); }
        });

        setupCategoriesEditor();

        // Live broadcast: refresh every event-card dropdown's options when
        // the categories list changes. Selection preserved where possible;
        // orphan ids fall back to (none) per populateCategorySelect.
        $(document).on('lp:eventListCategoriesUpdated', function (event, payload) {
            const cats = payload && Array.isArray(payload.categories) ? payload.categories : [];
            currentCategories = cats.slice();
            $('.eventCategoryInput').each(function () {
                const $select = $(this);
                populateCategorySelect($select, currentCategories, $select.val());
            });
        });

        $(document).on('click', '#eventDeleteConfirmYes', function () {
            if (pendingDelete) {
                const api = paneApis.get(pendingDelete.paneId);
                if (api) { api.deleteCard(pendingDelete.$card); }
                pendingDelete = null;
            }
            if (window.closeAdminModal) {
                window.closeAdminModal($('#eventDeleteConfirmModal'));
            }
        });

        $(document).on('click', '#eventDeleteConfirmModal .userModalClose', function () {
            pendingDelete = null;
            if (window.closeAdminModal) {
                window.closeAdminModal($('#eventDeleteConfirmModal'));
            }
        });

        // Save All success handler in config.js calls this to refresh sort
        // and apply predicted ids to newly-saved cards.
        window.refreshEventListUIs = function () {
            paneApis.forEach((api) => api.applyPostSaveState());
        };
    }

    $(init);
})(jQuery);
