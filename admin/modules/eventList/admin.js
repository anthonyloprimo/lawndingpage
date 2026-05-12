// Event list admin behavior — calendar-grid event entry.
// Per-pane: in-memory events[] is the source of truth; calendar is a pure
// render of it. Click "+" on a day → openModalForNew. Click an event bar
// → openModalForEdit (read-only for scraped events in V1). Modal save
// commits to events[], serializes the {create,update,delete} changeset
// into the hidden payload textarea, and re-renders.
//
// Concurrency limitation: predicted ids assume no concurrent admin saved
// between snapshot capture and Save click. Solo admin -> never bites.

(function ($) {
    'use strict';

    const paneApis = new Map();
    let pendingDelete = null;
    let currentCategories = [];
    let currentFeedLabels = {};

    // Shared calendar helpers — see admin/modules/eventList/eventlist-core.js
    const {
        TRACKS_VISIBLE_MAX,
        padDatePart,
        startOfDay,
        addDays,
        dateKey,
        parseEventDate,
        eventEnd,
        formatBarTime,
        allocateRowTracks
    } = window.lpEventListCore;

    function getBrowserTimeZone() {
        if (typeof Intl !== 'undefined' && Intl.DateTimeFormat) {
            return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
        }
        return '';
    }
    // Short timezone abbreviation (e.g. "EST", "PDT") for display next to
    // the time dropdowns. Falls back to "GMT-5" style for zones without
    // a registered three-letter form, or '' if Intl can't resolve it.
    // EDT → EST: user-requested label collapse; technically incorrect during
    // daylight-saving months but avoids confusion for admins who think of
    // Eastern time as "EST" year-round.
    function getTimezoneAbbreviation(ianaName) {
        try {
            const formatter = new Intl.DateTimeFormat('en-US', {
                timeZone: ianaName || undefined,
                timeZoneName: 'short'
            });
            const parts = formatter.formatToParts(new Date());
            const tzPart = parts.find((p) => p.type === 'timeZoneName');
            const abbr = tzPart ? tzPart.value : '';
            return abbr === 'EDT' ? 'EST' : abbr;
        } catch (err) {
            return '';
        }
    }
    function isEndBeforeStart(startDate, startTime, endDate, endTime) {
        if (!startDate || !startTime || !endDate || !endTime) { return false; }
        return `${endDate} ${endTime}` < `${startDate} ${startTime}`;
    }
    // Round HH:MM to nearest half-hour so off-grid times (e.g. legacy 14:23)
    // match a dropdown option on load. Empty/malformed input returns ''.
    function roundToHalfHour(timeStr) {
        if (!timeStr || !/^\d{2}:\d{2}$/.test(timeStr)) { return ''; }
        let h = parseInt(timeStr.slice(0, 2), 10);
        const m = parseInt(timeStr.slice(3, 5), 10);
        let mr;
        if (m >= 45) { mr = 0; h = (h + 1) % 24; }
        else if (m >= 15) { mr = 30; }
        else { mr = 0; }
        return padDatePart(h) + ':' + padDatePart(mr);
    }
    // HH:MM + hoursToAdd → HH:MM, wraps modulo 24.
    function addHoursToTime(timeStr, hoursToAdd) {
        if (!timeStr || !/^\d{2}:\d{2}$/.test(timeStr)) { return ''; }
        let h = parseInt(timeStr.slice(0, 2), 10) + hoursToAdd;
        const m = parseInt(timeStr.slice(3, 5), 10);
        h = ((h % 24) + 24) % 24;
        return padDatePart(h) + ':' + padDatePart(m);
    }
    function nearestHalfHourFromNow() {
        const now = new Date();
        return roundToHalfHour(padDatePart(now.getHours()) + ':' + padDatePart(now.getMinutes()));
    }
    function extractDomain(url) {
        try { return new URL(url).hostname.replace(/^www\./, ''); }
        catch (err) { return ''; }
    }

    // ========== Category dropdown population ==========

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

    // ========== Modal management (single shared modal across all panes) ==========

    let modalState = {
        $modal: null,
        paneApi: null,
        editingId: null,
        timeZone: ''
    };

    function ensureModal() {
        if (!modalState.$modal || !modalState.$modal.length) {
            const $m = $('#eventEditorModal');
            if ($m.length && !$m.parent().is('body')) {
                $m.appendTo('body');
            }
            modalState.$modal = $m;
        }
        return modalState.$modal;
    }

    function setAllDayVisibility($modal) {
        const allDay = $modal.find('.eventAllDayInput').is(':checked');
        const $start = $modal.find('.eventStartTimeInput');
        const $end = $modal.find('.eventEndTimeInput');
        $start.add($end).prop('disabled', allDay);
        if (allDay) {
            // Visually blank when all-day is on — the dropdowns land on the
            // hidden blank option. readModalEvent strips times on save anyway.
            $start.val('');
            $end.val('');
        } else {
            // Seed sane defaults when the time fields are blank — covers both
            // the user-just-unchecked-all-day case and opening an existing
            // all-day event then unchecking it.
            if (!$start.val()) {
                const defaultStart = nearestHalfHourFromNow();
                $start.val(defaultStart);
                if (!$end.val()) { $end.val(addHoursToTime(defaultStart, 2)); }
            } else if (!$end.val()) {
                $end.val(addHoursToTime($start.val(), 2));
            }
        }
    }

    function openModalForNew(paneApi, isoDate) {
        const $modal = ensureModal();
        if (!$modal.length) { return; }
        modalState.paneApi = paneApi;
        modalState.editingId = null;

        $modal.find('.userModalHandle').first().text('Add Event');
        $modal.find('.eventEditorScrapedBanner').prop('hidden', true);
        $modal.find('.eventEditorLockedBanner').prop('hidden', true);

        $modal.find('.eventNameInput').val('');
        populateCategorySelect($modal.find('.eventCategoryInput'), currentCategories, '');
        $modal.find('.eventAllDayInput').prop('checked', false);
        $modal.find('.eventStartDateInput').val(isoDate || '');
        const defaultStart = nearestHalfHourFromNow();
        $modal.find('.eventStartTimeInput').val(defaultStart);
        $modal.find('.eventEndDateInput').val(isoDate || '');
        $modal.find('.eventEndTimeInput').val(addHoursToTime(defaultStart, 2));
        const newTz = getBrowserTimeZone();
        modalState.timeZone = newTz;
        $modal.find('.eventTimezoneIndicator').text(getTimezoneAbbreviation(newTz));
        $modal.find('.eventAddressInput').val('');
        $modal.find('.eventDescriptionInput').val('');
        $modal.find('.eventValidation').text('');

        $modal.find('input, textarea, select').prop('disabled', false);
        setAllDayVisibility($modal);

        $modal.find('#eventEditorSave').prop('hidden', false);
        $modal.find('#eventEditorDelete').prop('hidden', true);
        $modal.find('.eventEditorActions').find('#eventEditorCancel').prop('hidden', false);

        if (window.openAdminModal) { window.openAdminModal($modal); }
        $modal.find('.eventNameInput').trigger('focus');
    }

    function openModalForEdit(paneApi, event) {
        const $modal = ensureModal();
        if (!$modal.length) { return; }
        modalState.paneApi = paneApi;
        modalState.editingId = String(event.id || '');
        const isScraped = !!event.sourceUid;
        const isLocked = isScraped && !!event.lockedLocally;

        $modal.find('.userModalHandle').first().text('Edit Event');
        if (isScraped) {
            const adapterId = String(event.sourceAdapter || '');
            const sourceUrl = event.sourceUrl || '';
            const feedLabel = currentFeedLabels[adapterId]
                || extractDomain(sourceUrl)
                || 'feed';
            const $feedLink = isLocked
                ? $modal.find('.eventEditorLockedFeed')
                : $modal.find('.eventEditorScrapedFeed');
            $feedLink.text(feedLabel);
            if (sourceUrl) {
                $feedLink.attr('href', sourceUrl);
            } else {
                $feedLink.removeAttr('href');
            }
            $modal.find('.eventEditorLockedBanner').prop('hidden', !isLocked);
            $modal.find('.eventEditorScrapedBanner').prop('hidden', isLocked);
        } else {
            $modal.find('.eventEditorScrapedBanner').prop('hidden', true);
            $modal.find('.eventEditorLockedBanner').prop('hidden', true);
        }

        const startDate = event.startDate || event.date || '';
        const endDate = event.endDate || '';

        $modal.find('.eventNameInput').val(event.name || '');
        populateCategorySelect($modal.find('.eventCategoryInput'), currentCategories, event.categoryId || '');
        $modal.find('.eventAllDayInput').prop('checked', !!event.allDay);
        $modal.find('.eventStartDateInput').val(startDate);
        $modal.find('.eventStartTimeInput').val(roundToHalfHour(event.startTime || ''));
        $modal.find('.eventEndDateInput').val(endDate || startDate);
        $modal.find('.eventEndTimeInput').val(roundToHalfHour(event.endTime || ''));
        const editTz = event.timeZone || getBrowserTimeZone();
        modalState.timeZone = editTz;
        $modal.find('.eventTimezoneIndicator').text(getTimezoneAbbreviation(editTz));
        $modal.find('.eventAddressInput').val(event.address || '');
        $modal.find('.eventDescriptionInput').val(event.description || '');
        $modal.find('.eventValidation').text('');

        $modal.find('input, textarea, select').prop('disabled', false);
        setAllDayVisibility($modal);

        // State 1 condition (synced scraped): fields render dimmed + disabled,
        // Save is hidden, the banner's lock toggle is the path into editing.
        // Clicking the toggle flips this in-session (see toggleScrapedLock).
        const isScrapedSynced = isScraped && !isLocked;
        const $userModalEl = $modal.find('.userModal');
        $userModalEl.toggleClass('isScrapedLocked', isScrapedSynced);
        if (isScrapedSynced) {
            $modal.find('.eventEditorForm').find('input, textarea, select').prop('disabled', true);
            $modal.find('.eventEditorScrapedLockIcon').prop('hidden', false);
            $modal.find('.eventEditorScrapedUnlockIcon').prop('hidden', true);
            $modal.find('.eventEditorScrapedHint').text('click to unlock and edit');
            $modal.find('.eventEditorScrapedLockToggle')
                .attr('aria-label', 'Unlock to edit')
                .attr('title', 'Unlock to edit');
        }

        // Scraped events route deletion through feed-config unchecking, not the
        // calendar modal — Delete button stays hidden whether the record is
        // synced or locally overridden.
        $modal.find('#eventEditorSave').prop('hidden', isScrapedSynced);
        $modal.find('#eventEditorDelete').prop('hidden', isScraped);

        if (window.openAdminModal) { window.openAdminModal($modal); }
    }

    // Toggle between scraped-locked (read-only view) and scraped-unlocked
    // (editable). Lock state stays modal-session-local; lockedLocally on
    // the record only flips when the admin actually Saves an edited event.
    function toggleScrapedLock() {
        const $modal = modalState.$modal;
        if (!$modal || !$modal.length) { return; }
        const $userModalEl = $modal.find('.userModal');
        const wasLocked = $userModalEl.hasClass('isScrapedLocked');
        $userModalEl.toggleClass('isScrapedLocked', !wasLocked);
        $modal.find('.eventEditorForm').find('input, textarea, select').prop('disabled', !wasLocked);
        // Re-apply all-day rule after enabling, so time inputs honor it.
        if (wasLocked) {
            setAllDayVisibility($modal);
        }
        $modal.find('.eventEditorScrapedLockIcon').prop('hidden', wasLocked);
        $modal.find('.eventEditorScrapedUnlockIcon').prop('hidden', !wasLocked);
        $modal.find('#eventEditorSave').prop('hidden', !wasLocked);
        const hint = wasLocked ? 'now editing the local copy' : 'click to unlock and edit';
        $modal.find('.eventEditorScrapedHint').text(hint);
        const label = wasLocked ? 'Lock — discard changes' : 'Unlock to edit';
        $modal.find('.eventEditorScrapedLockToggle')
            .attr('aria-label', label)
            .attr('title', label);
    }

    function closeEditorModal() {
        if (modalState.$modal && modalState.$modal.length) {
            modalState.$modal.find('.userModal').removeClass('isConfirmingDelete isScrapedLocked');
            modalState.$modal.find('.isInvalid').removeClass('isInvalid').removeAttr('aria-invalid');
            modalState.$modal.find('.eventValidation').text('');
            if (window.closeAdminModal) {
                window.closeAdminModal(modalState.$modal);
            }
        }
        modalState.paneApi = null;
        modalState.editingId = null;
    }

    function readModalEvent($modal) {
        const allDay = $modal.find('.eventAllDayInput').is(':checked');
        const startDate = ($modal.find('.eventStartDateInput').val() || '').trim();
        const endDateRaw = ($modal.find('.eventEndDateInput').val() || '').trim();
        // Single-day events store endDate as '' (matches existing data shape).
        // Multi-day-ness is implicit: endDate set and != startDate.
        const endDate = endDateRaw && endDateRaw !== startDate ? endDateRaw : '';
        return {
            name: ($modal.find('.eventNameInput').val() || '').trim(),
            categoryId: ($modal.find('.eventCategoryInput').val() || '').trim(),
            startDate,
            startTime: allDay ? '' : ($modal.find('.eventStartTimeInput').val() || '').trim(),
            endDate,
            endTime: allDay ? '' : ($modal.find('.eventEndTimeInput').val() || '').trim(),
            timeZone: modalState.timeZone || getBrowserTimeZone(),
            address: ($modal.find('.eventAddressInput').val() || '').trim(),
            description: ($modal.find('.eventDescriptionInput').val() || '').trim(),
            allDay
        };
    }

    // Returns [{fields: [classNames], message}] so the save handler can mark
    // each invalid field with a red border. message is for the aria-live
    // summary region (screen-reader announcement).
    function validateModalEvent(event, allEvents, editingId) {
        const errors = [];
        if (!event.name) { errors.push({ fields: ['.eventNameInput'], message: 'Name is required.' }); }
        if (!event.startDate) { errors.push({ fields: ['.eventStartDateInput'], message: 'Start date is required.' }); }
        if (!event.allDay && !event.startTime) { errors.push({ fields: ['.eventStartTimeInput'], message: 'Start time is required.' }); }
        if (!event.allDay && !event.endTime) {
            errors.push({ fields: ['.eventEndTimeInput'], message: 'End time is required.' });
        }
        if (event.allDay && event.endDate && event.endDate < event.startDate) {
            errors.push({ fields: ['.eventEndDateInput'], message: 'End date cannot be earlier than start date.' });
        } else if (!event.allDay && event.endDate && event.endTime
            && isEndBeforeStart(event.startDate, event.startTime, event.endDate, event.endTime)) {
            errors.push({ fields: ['.eventEndDateInput', '.eventEndTimeInput'], message: 'End cannot be earlier than start.' });
        }
        if (!event.address) { errors.push({ fields: ['.eventAddressInput'], message: 'Address is required.' }); }
        if (!event.description) { errors.push({ fields: ['.eventDescriptionInput'], message: 'Description is required.' }); }

        const dedupeKey = event.allDay
            ? `${event.name.toLowerCase()}|${event.startDate}|allday`
            : `${event.name.toLowerCase()}|${event.startDate}|${event.startTime}`;
        if (event.name && event.startDate && (event.allDay || event.startTime)) {
            const otherDup = allEvents.find((e) => {
                if (String(e.id || '') === String(editingId || '')) { return false; }
                const key = e.allDay
                    ? `${(e.name || '').toLowerCase()}|${e.startDate || e.date || ''}|allday`
                    : `${(e.name || '').toLowerCase()}|${e.startDate || e.date || ''}|${e.startTime || ''}`;
                return key === dedupeKey;
            });
            if (otherDup) {
                errors.push({
                    fields: ['.eventNameInput', '.eventStartDateInput', '.eventStartTimeInput'],
                    message: 'Duplicate event with same name + date + time.'
                });
            }
        }
        return errors;
    }

    // ========== Per-pane setup ==========

    function setupPane($pane) {
        const paneId = $pane.data('pane-id') || $pane.attr('id') || '';
        const $payload = $pane.find('.eventListPayload');
        const $calendar = $pane.find('.eventListAdminCalendar');
        const $body = $calendar.find('.eventCalendarBody');
        const $monthLabel = $calendar.find('.eventListAdminCalendarMonthText');
        const $todayButton = $calendar.find('.eventListAdminCalendarTodayButton');
        const $narrow = $calendar.find('.eventListAdminNarrow');

        // Snapshot of server state at last load/save.
        let snapshot = readSnapshot($pane);
        // In-memory working copy. New events get tmp-N ids until Save All resolves.
        let events = snapshot.map((e) => Object.assign({}, e));
        const deletedIds = new Set();
        let nextTempId = 1;

        const now = new Date();
        let calendarMonth = new Date(now.getFullYear(), now.getMonth(), 1);

        function readSnapshot($p) {
            try {
                const parsed = JSON.parse($p.attr('data-snapshot') || '{}');
                return Array.isArray(parsed.events) ? parsed.events.slice() : [];
            } catch (err) { return []; }
        }

        function snapshotById(id) {
            return snapshot.find((ev) => ev && String(ev.id || '') === String(id)) || null;
        }

        function isTempId(id) {
            return typeof id === 'string' && id.indexOf('tmp-') === 0;
        }

        function eventFieldsEqual(a, b) {
            const fields = ['name', 'categoryId', 'startDate', 'startTime', 'endDate', 'endTime',
                'timeZone', 'address', 'description'];
            for (const f of fields) {
                if ((a[f] || '') !== (b[f] || '')) { return false; }
            }
            // lockedLocally drift must emit an update so Resume sync (or an
            // implicit override on edit) reaches the server. Compare as
            // booleans so missing/false/null all collapse to the same state.
            if (!!a.lockedLocally !== !!b.lockedLocally) { return false; }
            return !!a.allDay === !!b.allDay;
        }

        // {create, update, delete} additive changeset, contract unchanged from
        // the form-card era. New events (tmp- ids) become create; mutated
        // existing events become update; deletedIds Set drives delete.
        function computeChangeset() {
            const create = [];
            const update = [];
            events.forEach((event) => {
                const id = String(event.id || '');
                if (!id || isTempId(id)) {
                    const { id: _, ...payload } = event;
                    create.push(payload);
                } else {
                    const orig = snapshotById(id);
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
            const changeset = computeChangeset();
            $payload.val(JSON.stringify({ changes: changeset }));
            // Broadcast pending-changes state so the global Save button can
            // surface a visual cue. Per-source key = moduleId:paneId so each
            // pane's dirty state is tracked independently by the listener.
            const hasPending = !!(changeset.create.length || changeset.update.length || changeset.delete.length);
            $(document).trigger('lp:adminDirty', [{ moduleId: 'eventList', paneId, hasPending }]);
        }

        function categoriesById() {
            const map = {};
            currentCategories.forEach((c) => {
                if (c && c.id != null) { map[String(c.id)] = c; }
            });
            return map;
        }

        // ---- Calendar grid render ----

        function buildRowAllocation(weekStart) {
            const weekEnd = addDays(weekStart, 6);
            const weekEndExclusive = addDays(weekEnd, 1);
            const rowEvents = [];
            events.forEach((event) => {
                if (deletedIds.has(String(event.id || ''))) { return; }
                const start = parseEventDate(event);
                if (!start) { return; }
                const end = eventEnd(event) || start;
                if (start.getTime() >= weekEndExclusive.getTime()) { return; }
                if (end.getTime() <= weekStart.getTime()) { return; }
                const startDay = startOfDay(start);
                const endDay = startOfDay(new Date(end.getTime() - 1));
                const rawStart = Math.round((startDay - weekStart) / 86400000);
                const rawEnd = Math.round((endDay - weekStart) / 86400000);
                const rowStartDay = Math.max(0, rawStart);
                const rowEndDay = Math.min(6, rawEnd);
                rowEvents.push({
                    event,
                    rowStartDay,
                    rowEndDay,
                    continuesFromPriorRow: rawStart < 0,
                    continuesToNextRow: rawEnd > 6,
                });
            });
            return allocateRowTracks(rowEvents);
        }

        function renderCalendarBars($cell, dayIndex, allocated, cats) {
            const occupants = allocated.filter((re) =>
                re.rowStartDay <= dayIndex && re.rowEndDay >= dayIndex
            );
            if (!occupants.length) { return; }
            const $bars = $('<div class="eventCalendarBars"></div>');
            const visibleOccupants = occupants.filter((re) => re.track < TRACKS_VISIBLE_MAX);
            const overflowOccupants = occupants.filter((re) => re.track >= TRACKS_VISIBLE_MAX);
            const visibleByTrack = {};
            visibleOccupants.forEach((re) => { visibleByTrack[re.track] = re; });
            const maxVisibleTrack = visibleOccupants.reduce((m, re) => Math.max(m, re.track), -1);
            for (let track = 0; track <= maxVisibleTrack; track++) {
                const re = visibleByTrack[track];
                if (!re) {
                    $bars.append('<div class="eventCalendarBarSlot" aria-hidden="true"></div>');
                    continue;
                }
                const isMultiCellInRow = re.rowStartDay !== re.rowEndDay;
                const isFirstInRow = re.rowStartDay === dayIndex;
                if (isMultiCellInRow && !isFirstInRow) {
                    $bars.append('<div class="eventCalendarBarSlot" aria-hidden="true"></div>');
                    continue;
                }
                const isAllDay = !!re.event.allDay;
                const isMultiDayEvent = isMultiCellInRow
                    || re.continuesFromPriorRow
                    || re.continuesToNextRow;
                const start = parseEventDate(re.event);
                const time = isAllDay ? '' : formatBarTime(start);
                const title = String(re.event && re.event.name ? re.event.name : 'Event');
                const isScraped = !!re.event.sourceUid;
                const accessibleLabel = (isScraped ? '[synced] ' : '') + (isAllDay
                    ? `${title}, all day`
                    : (isMultiDayEvent
                        ? `${title}, multi-day event`
                        : (time ? `${time} ${title}` : title)));
                const $bar = $('<button type="button" class="eventCalendarBar"></button>')
                    .attr('data-event-id', re.event && re.event.id ? re.event.id : '')
                    .attr('aria-label', accessibleLabel)
                    .attr('title', accessibleLabel);
                const cat = re.event && re.event.categoryId ? cats[String(re.event.categoryId)] : null;
                if (cat && cat.color) { $bar.css('--bar-accent', cat.color); }
                if (isAllDay) { $bar.addClass('isAllDay'); }
                if (isScraped) { $bar.addClass('isScraped'); }
                if (isMultiDayEvent) {
                    $bar.addClass('isSpan');
                    const spanCells = re.rowEndDay - re.rowStartDay + 1;
                    $bar.css('--span-cells', String(spanCells));
                    if (re.continuesFromPriorRow) { $bar.addClass('isContinuesFromPrior'); }
                    if (re.continuesToNextRow) { $bar.addClass('isContinuesToNext'); }
                    $bar.append($('<span class="eventCalendarBarTitle"></span>').text(title));
                } else {
                    if (time) {
                        $bar.append($('<span class="eventCalendarBarTime"></span>').text(time));
                    }
                    $bar.append($('<span class="eventCalendarBarTitle"></span>').text(title));
                }
                $bars.append($bar);
            }
            if (overflowOccupants.length) {
                const more = overflowOccupants.length;
                $bars.append(
                    $('<button type="button" class="eventCalendarBarMore"></button>')
                        .text(`+${more} More…`)
                        .attr('aria-label', `${more} more event${more === 1 ? '' : 's'}, view all`)
                );
            }
            $cell.find('.eventCalendarCellInner').append($bars);
        }

        function renderCalendar() {
            const cats = categoriesById();
            $monthLabel.text(calendarMonth.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }));
            const isCurrentMonth = calendarMonth.getFullYear() === now.getFullYear() && calendarMonth.getMonth() === now.getMonth();
            $todayButton.toggleClass('hidden', isCurrentMonth);
            $body.empty();
            const firstOfMonth = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth(), 1);
            const gridStart = addDays(firstOfMonth, -firstOfMonth.getDay());
            const todayKey = dateKey(now);
            for (let week = 0; week < 6; week++) {
                const weekStart = addDays(gridStart, week * 7);
                const allocated = buildRowAllocation(weekStart);
                const $row = $('<tr></tr>');
                for (let day = 0; day < 7; day++) {
                    const cellDate = addDays(weekStart, day);
                    const cellDayStart = startOfDay(cellDate);
                    const key = dateKey(cellDayStart);
                    const inCurrentMonth = cellDate.getMonth() === calendarMonth.getMonth();
                    const isToday = key === todayKey;
                    const count = allocated.filter((re) => re.rowStartDay <= day && re.rowEndDay >= day).length;
                    const $cell = $('<td></td>')
                        .addClass('eventCalendarCell')
                        .toggleClass('isAdjacentMonth', !inCurrentMonth)
                        .toggleClass('isToday', isToday)
                        .toggleClass('hasEvents', count > 0)
                        .attr('data-date', key);
                    const $inner = $('<div class="eventCalendarCellInner"></div>');
                    $inner.append($('<span class="eventCalendarDateNumber"></span>').text(cellDate.getDate()));
                    $inner.append(
                        $('<button type="button" class="eventCalendarAdminAddDay" aria-label="Add event on this day"></button>')
                            .text('+')
                            .attr('data-date', key)
                    );
                    $cell.append($inner);
                    renderCalendarBars($cell, day, allocated, cats);
                    $row.append($cell);
                }
                $body.append($row);
            }
            renderNarrow();
        }

        // ---- Narrow-width fallback (under ~700px) ----
        // CSS hides the calendar table and shows .eventListAdminNarrow at narrow widths.

        function compareEventsAsc(a, b) {
            const aKey = `${a.startDate || a.date || ''} ${a.startTime || ''}`;
            const bKey = `${b.startDate || b.date || ''} ${b.startTime || ''}`;
            return aKey.localeCompare(bKey);
        }

        function renderNarrow() {
            $narrow.empty();
            const live = events.filter((e) => !deletedIds.has(String(e.id || '')));
            const sorted = live.slice().sort(compareEventsAsc);
            // Group by start date.
            const groups = {};
            const orderKeys = [];
            sorted.forEach((event) => {
                const key = event.startDate || event.date || '';
                if (!groups[key]) {
                    groups[key] = [];
                    orderKeys.push(key);
                }
                groups[key].push(event);
            });
            if (!orderKeys.length) {
                const $today = $('<div class="eventListAdminNarrowGroup eventListAdminNarrowEmpty"></div>');
                const todayKey = dateKey(now);
                $today.append(
                    $('<div class="eventListAdminNarrowDateRow"></div>')
                        .append($('<span class="eventListAdminNarrowDate"></span>').text('No events yet'))
                        .append($('<button type="button" class="eventCalendarAdminAddDay"></button>')
                            .text('+ Add event today')
                            .attr('data-date', todayKey))
                );
                $narrow.append($today);
                return;
            }
            const cats = categoriesById();
            orderKeys.forEach((key) => {
                const $group = $('<div class="eventListAdminNarrowGroup"></div>');
                let dateLabel = key || '(no date)';
                if (key) {
                    try {
                        const d = new Date(`${key}T00:00:00`);
                        if (!isNaN(d.getTime())) {
                            dateLabel = d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
                        }
                    } catch (err) { /* keep raw key */ }
                }
                const $dateRow = $('<div class="eventListAdminNarrowDateRow"></div>')
                    .append($('<span class="eventListAdminNarrowDate"></span>').text(dateLabel));
                if (key) {
                    $dateRow.append(
                        $('<button type="button" class="eventCalendarAdminAddDay"></button>')
                            .text('+')
                            .attr('data-date', key)
                            .attr('aria-label', `Add event on ${dateLabel}`)
                    );
                }
                $group.append($dateRow);
                groups[key].forEach((event) => {
                    const isScraped = !!event.sourceUid;
                    const cat = event.categoryId ? cats[String(event.categoryId)] : null;
                    const $row = $('<button type="button" class="eventListAdminNarrowItem"></button>')
                        .attr('data-event-id', event.id || '');
                    if (isScraped) { $row.addClass('isScraped'); }
                    if (cat && cat.color) { $row.css('--bar-accent', cat.color); }
                    const timeStr = event.allDay
                        ? 'all day'
                        : (event.startTime || '');
                    $row.append($('<span class="eventListAdminNarrowItemTime"></span>').text(timeStr));
                    $row.append($('<span class="eventListAdminNarrowItemTitle"></span>').text(event.name || '(unnamed)'));
                    $group.append($row);
                });
                $narrow.append($group);
            });
        }

        // ---- Save / delete from modal ----

        function applyModalSave() {
            const $modal = modalState.$modal;
            if (!$modal || !$modal.length) { return; }
            const event = readModalEvent($modal);
            const errors = validateModalEvent(event, events, modalState.editingId);
            // Clear prior invalid markers regardless of result so a fixed field
            // doesn't keep its red border on the next save attempt.
            $modal.find('.isInvalid').removeClass('isInvalid').removeAttr('aria-invalid');
            if (errors.length) {
                errors.forEach((err) => {
                    err.fields.forEach((selector) => {
                        $modal.find(selector).addClass('isInvalid').attr('aria-invalid', 'true');
                    });
                });
                const messages = errors.map((e) => e.message).join(' ');
                $modal.find('.eventValidation').text(messages);
                return;
            }
            $modal.find('.eventValidation').text('');
            if (modalState.editingId) {
                const idx = events.findIndex((e) => String(e.id || '') === String(modalState.editingId));
                if (idx !== -1) {
                    const existing = events[idx];
                    const isScraped = !!existing.sourceUid;
                    // Implicit override: a scraped event becomes locally locked the
                    // first time the admin actually edits and saves it. Save-without-
                    // changes keeps the record syncing from the scraper. Already-locked
                    // records stay locked across saves until Resume sync clears it.
                    const lockedLocally = isScraped
                        ? (!!existing.lockedLocally || !eventFieldsEqual(existing, event))
                        : false;
                    const overlay = Object.assign({ id: modalState.editingId }, event);
                    if (isScraped) { overlay.lockedLocally = lockedLocally; }
                    events[idx] = Object.assign({}, existing, overlay);
                }
            } else {
                const tempId = `tmp-${Date.now()}-${nextTempId++}`;
                events.push(Object.assign({}, event, { id: tempId }));
            }
            updatePayload();
            renderCalendar();
            closeEditorModal();
        }

        // Clears lockedLocally; on next scraper refresh the catalogue values
        // overwrite the admin's local edits. Local values stay visible until
        // that refresh runs.
        function resumeSyncCurrent() {
            if (!modalState.editingId) { return; }
            const idx = events.findIndex((e) => String(e.id || '') === String(modalState.editingId));
            if (idx === -1) { return; }
            events[idx] = Object.assign({}, events[idx], { lockedLocally: false });
            updatePayload();
            renderCalendar();
            closeEditorModal();
        }

        function requestModalDelete() {
            if (!modalState.editingId) { return; }
            pendingDelete = { paneId, eventId: modalState.editingId };
            const $modal = modalState.$modal;
            if (!$modal || !$modal.length) { return; }
            $modal.find('.userModal').addClass('isConfirmingDelete');
            $modal.find('.eventListConfirmDeleteNo').trigger('focus');
        }

        function applyDeletion(eventId) {
            const id = String(eventId);
            if (snapshotById(id)) {
                deletedIds.add(id);
            }
            events = events.filter((e) => String(e.id || '') !== id);
            updatePayload();
            renderCalendar();
        }

        // ---- Post-Save All success: apply predicted ids + re-snapshot ----
        // Server applies deletes BEFORE creates, so deletedIds must be excluded
        // from the maxId calculation, otherwise deleting the highest-id event
        // and creating a new one in the same save desyncs client and server.
        function applyPostSaveState() {
            let maxId = 0;
            snapshot.forEach((ev) => {
                const idStr = String(ev && ev.id ? ev.id : '');
                if (deletedIds.has(idStr)) { return; }
                const n = parseInt(idStr, 10);
                if (Number.isFinite(n) && n > maxId) { maxId = n; }
            });
            events.forEach((event) => {
                if (isTempId(String(event.id || ''))) {
                    maxId += 1;
                    event.id = String(maxId);
                }
            });
            const newSnapshot = events.map((e) => Object.assign({}, e));
            snapshot = newSnapshot;
            deletedIds.clear();
            updatePayload();
            renderCalendar();
        }

        // ---- Wiring ----

        $calendar.on('click', '.eventCalendarAdminAddDay', function (e) {
            e.stopPropagation();
            e.preventDefault();
            const date = $(this).attr('data-date') || '';
            openModalForNew(api, date);
        });

        $calendar.on('click', '.eventCalendarBar, .eventListAdminNarrowItem', function (e) {
            e.stopPropagation();
            e.preventDefault();
            const eventId = $(this).attr('data-event-id') || '';
            const found = events.find((ev) => String(ev.id || '') === String(eventId));
            if (found) { openModalForEdit(api, found); }
        });

        $calendar.on('click', '.eventCalendarBarMore', function (e) {
            e.stopPropagation();
            e.preventDefault();
            const $cell = $(this).closest('.eventCalendarCell');
            const date = $cell.attr('data-date') || '';
            // V1 overflow: open the first hidden event for now. Future: list modal.
            const dayEvents = events
                .filter((ev) => !deletedIds.has(String(ev.id || '')))
                .filter((ev) => {
                    const k = ev.startDate || ev.date || '';
                    return k === date;
                });
            if (dayEvents.length > TRACKS_VISIBLE_MAX) {
                openModalForEdit(api, dayEvents[TRACKS_VISIBLE_MAX]);
            }
        });

        $calendar.on('click', '.eventCalendarPrev', function () {
            calendarMonth = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() - 1, 1);
            renderCalendar();
        });
        $calendar.on('click', '.eventCalendarNext', function () {
            calendarMonth = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() + 1, 1);
            renderCalendar();
        });
        $calendar.on('click', '.eventCalendarTodayButton', function () {
            calendarMonth = new Date(now.getFullYear(), now.getMonth(), 1);
            renderCalendar();
        });

        // Initial render + payload seed.
        updatePayload();
        renderCalendar();

        const api = {
            paneId,
            applyModalSave,
            requestModalDelete,
            applyDeletion,
            applyPostSaveState,
            resumeSyncCurrent,
            refreshCategoryDropdown: function () {
                if (modalState.paneApi === api && modalState.$modal && modalState.$modal.length) {
                    const sel = modalState.$modal.find('.eventCategoryInput').val();
                    populateCategorySelect(modalState.$modal.find('.eventCategoryInput'), currentCategories, sel || '');
                }
            }
        };
        return api;
    }

    // ========== SITE CONFIG categories editor (unchanged from form-card era) ==========

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

        function computeCategoriesChangeset() {
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
            const cs = computeCategoriesChangeset();
            $saveBtn.prop('disabled', !(cs.create.length || cs.update.length || cs.delete.length));
        }

        function save() {
            const changeset = computeCategoriesChangeset();
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

    // ========== Init ==========

    function init() {
        // Reparent shared modals to <body> so position:fixed isn't anchored
        // to a transformed/contained ancestor. Idempotent.
        const $editorModal = $('#eventEditorModal');
        if ($editorModal.length && !$editorModal.parent().is('body')) {
            $editorModal.appendTo('body');
        }

        // Bootstrap currentCategories before setupPane so modal dropdowns
        // populate from the right list. Source: SITE CONFIG fieldset's
        // data-categories (canonical) or first pane's data-snapshot fallback.
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

        const $firstPaneFL = $('[data-pane-type="eventList"]').first();
        try {
            const snap = JSON.parse($firstPaneFL.attr('data-snapshot') || '{}');
            currentFeedLabels = (snap.feedLabels && typeof snap.feedLabels === 'object')
                ? snap.feedLabels
                : {};
        } catch (err) { currentFeedLabels = {}; }

        $('[data-pane-type="eventList"]').each(function () {
            const $pane = $(this);
            const api = setupPane($pane);
            const paneId = $pane.data('pane-id') || $pane.attr('id') || '';
            if (paneId) { paneApis.set(paneId, api); }
        });

        setupCategoriesEditor();

        // Live broadcast: refresh the open modal's category dropdown when the
        // categories list changes mid-session. Pure render-only — no scope
        // for this listener to invent a per-module channel (memory:
        // feedback_shared_event_channel_for_module_updates.md).
        $(document).on('lp:eventListCategoriesUpdated', function (event, payload) {
            const cats = payload && Array.isArray(payload.categories) ? payload.categories : [];
            currentCategories = cats.slice();
            paneApis.forEach((api) => api.refreshCategoryDropdown && api.refreshCategoryDropdown());
        });

        // ---- Modal action wiring (single-binding, dispatches via modalState.paneApi) ----

        $(document).on('click', '#eventEditorSave', function () {
            if (modalState.paneApi && modalState.paneApi.applyModalSave) {
                modalState.paneApi.applyModalSave();
            }
        });
        $(document).on('click', '#eventEditorDelete', function () {
            if (modalState.paneApi && modalState.paneApi.requestModalDelete) {
                modalState.paneApi.requestModalDelete();
            }
        });
        $(document).on('click', '#eventEditorResumeSync', function () {
            if (modalState.paneApi && modalState.paneApi.resumeSyncCurrent) {
                modalState.paneApi.resumeSyncCurrent();
            }
        });
        $(document).on('click', '#eventEditorModal .eventEditorScrapedLockToggle', function () {
            toggleScrapedLock();
        });
        $(document).on('click', '#eventEditorCancel, .eventEditorModalOverlay .userModalClose', function () {
            closeEditorModal();
        });
        // Clear the red border + aria-invalid as the admin starts fixing each
        // field. Modal-scoped delegation; covers inputs, selects, textareas.
        $(document).on('input change', '#eventEditorModal .eventEditorForm :input', function () {
            $(this).removeClass('isInvalid').removeAttr('aria-invalid');
        });
        $(document).on('change', '#eventEditorModal .eventAllDayInput', function () {
            const $modal = ensureModal();
            if ($modal && $modal.length) { setAllDayVisibility($modal); }
        });
        $(document).on('change', '#eventEditorModal .eventStartDateInput', function () {
            const $modal = ensureModal();
            if (!$modal || !$modal.length) { return; }
            const start = $(this).val() || '';
            const $end = $modal.find('.eventEndDateInput');
            const end = $end.val() || '';
            // Auto-fill end when empty, or bump it forward if start has just
            // moved past it. Preserves an explicit multi-day end when start
            // moves but stays before it.
            if (!end || end < start) {
                $end.val(start);
            }
        });
        $(document).on('change', '#eventEditorModal .eventStartTimeInput', function () {
            const $modal = ensureModal();
            if (!$modal || !$modal.length) { return; }
            const start = $(this).val() || '';
            if (!start) { return; }
            // End defaults to start + 2 hours every time start changes; user
            // overrides by setting end AFTER start. Wraps via addHoursToTime.
            $modal.find('.eventEndTimeInput').val(addHoursToTime(start, 2));
        });

        // ---- In-modal delete-confirm handlers ----

        $(document).on('click', '#eventEditorModal .eventListConfirmDeleteYes', function () {
            if (pendingDelete) {
                const api = paneApis.get(pendingDelete.paneId);
                if (api && api.applyDeletion) {
                    api.applyDeletion(pendingDelete.eventId);
                }
                pendingDelete = null;
            }
            closeEditorModal();
        });
        $(document).on('click', '#eventEditorModal .eventListConfirmDeleteNo', function () {
            pendingDelete = null;
            const $modal = modalState.$modal;
            if ($modal && $modal.length) {
                $modal.find('.userModal').removeClass('isConfirmingDelete');
            }
        });

        // ---- Save All Changes interaction ----
        // If the user clicks the global Save while the editor modal is open,
        // close it and warn — discarding the modal's pending edits is the
        // most defensible default (auto-commit risks silent validation
        // failures; blocking is rude). Pre-bind on capture so the warning
        // fires before the global Save handler reads the payload.
        $(document).on('click', '.saveChanges', function () {
            if (modalState.$modal && modalState.$modal.hasClass('isOpen')) {
                closeEditorModal();
                if (window.addAdminNotice) {
                    window.addAdminNotice('warning', 'Discarded unsaved event-edit changes (modal was open during Save All).');
                }
            }
        });

        // Save All success handler in config.js calls this to apply predicted
        // ids and refresh the calendar render with the post-save snapshot.
        window.refreshEventListUIs = function () {
            paneApis.forEach((api) => api.applyPostSaveState && api.applyPostSaveState());
        };
    }

    $(init);
})(jQuery);
