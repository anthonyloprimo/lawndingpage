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

    function setupPane($pane) {
        const paneId = $pane.data('pane-id') || $pane.attr('id') || '';
        const $list = $pane.find('.eventList');
        const $payload = $pane.find('.eventListPayload');
        const $toggle = $pane.find('.eventShowPast');
        const $showCalendar = $pane.find('.eventShowCalendar');
        const $calendarDefault = $pane.find('.eventCalendarDefault');

        // Snapshot of server state at last load/save. Mutated on save success.
        let snapshot = readSnapshot($pane);
        const deletedIds = new Set();

        function readSnapshot($p) {
            const $island = $p.find('.eventListAdminData').first();
            try {
                const parsed = JSON.parse($island.text() || '{}');
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
            return {
                id: $card.attr('data-event-id') || '',
                name: ($card.find('.eventNameInput').val() || '').trim(),
                startDate: ($card.find('.eventStartDateInput').val() || '').trim(),
                startTime: ($card.find('.eventStartTimeInput').val() || '').trim(),
                endDate: ($card.find('.eventEndDateInput').val() || '').trim(),
                endTime: ($card.find('.eventEndTimeInput').val() || '').trim(),
                timeZone: ($card.find('.eventTimezoneInput').val() || '').trim() || getBrowserTimeZone(),
                address: ($card.find('.eventAddressInput').val() || '').trim(),
                description: ($card.find('.eventDescriptionInput').val() || '').trim()
            };
        }

        function eventFieldsEqual(a, b) {
            const fields = ['name', 'startDate', 'startTime', 'endDate', 'endTime',
                'timeZone', 'address', 'description'];
            return fields.every((f) => (a[f] || '') === (b[f] || ''));
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
            const payload = {
                showPast: $toggle.is(':checked'),
                showCalendar: $showCalendar.is(':checked'),
                calendarDefault: $calendarDefault.is(':checked'),
                changes: computeChangeset()
            };
            $payload.val(JSON.stringify(payload));
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
                if (!event.startTime) { errors.push('Start time is required.'); }
                if ((event.endDate && !event.endTime) || (!event.endDate && event.endTime)) {
                    errors.push('End date and time must both be set or both be blank.');
                }
                if (event.endDate && event.endTime
                    && isEndBeforeStart(event.startDate, event.startTime, event.endDate, event.endTime)) {
                    errors.push('End date/time cannot be earlier than start date/time.');
                }
                if (!event.address) { errors.push('Address is required.'); }
                if (!event.description) { errors.push('Description is required.'); }
                const dedupeKey = `${event.name.toLowerCase()}|${event.startDate}|${event.startTime}`;
                if (event.name && event.startDate && event.startTime) {
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
                    <div class="eventSectionDivider" aria-hidden="true"></div>
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
            if (data) {
                $card.find('.eventNameInput').val(data.name || '');
                $card.find('.eventStartDateInput').val(data.startDate || '');
                $card.find('.eventStartTimeInput').val(data.startTime || '');
                $card.find('.eventEndDateInput').val(data.endDate || '');
                $card.find('.eventEndTimeInput').val(data.endTime || '');
                $card.find('.eventTimezoneInput').val(data.timeZone || getBrowserTimeZone());
                $card.find('.eventAddressInput').val(data.address || '');
                $card.find('.eventDescriptionInput').val(data.description || '');
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

        $pane.on('input change',
            '.eventCard input, .eventCard textarea, .eventShowPast, .eventShowCalendar, .eventCalendarDefault',
            function () {
                refreshValidation();
            });

        refreshValidation();

        return { applyPostSaveState, deleteCard };
    }

    function init() {
        $('[data-pane-type="eventList"]').each(function () {
            const $pane = $(this);
            const api = setupPane($pane);
            const paneId = $pane.data('pane-id') || $pane.attr('id') || '';
            if (paneId) { paneApis.set(paneId, api); }
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
