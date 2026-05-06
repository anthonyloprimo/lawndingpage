(function() {
function renderEventLists() {
    const $panes = $('.eventListPublic');
    if (!$panes.length) {
        return;
    }

    $panes.each(function() {
        const $pane = $(this);
        const $container = $pane.closest('[data-pane-type="eventList"]');
        const raw = $container.find('.eventListData').text() || '{}';
        let parsed = {};
        try {
            parsed = JSON.parse(raw);
        } catch (err) {
            parsed = {};
        }
        const showPast = !!parsed.showPast;
        const showCalendar = !!parsed.showCalendar;
        const calendarDefault = !Object.prototype.hasOwnProperty.call(parsed, 'calendarDefault') || !!parsed.calendarDefault;
        const events = Array.isArray(parsed.events) ? parsed.events : [];
        const now = new Date();
        const nowTime = now.getTime();
        let calendarMonth = new Date(now.getFullYear(), now.getMonth(), 1);

        function parseEventDate(event) {
            const date = event.startDate || event.date || '';
            const start = event.startTime || '';
            if (!date || !start) {
                return null;
            }
            const iso = `${date}T${start}`;
            const parsedDate = new Date(iso);
            return isNaN(parsedDate.getTime()) ? null : parsedDate;
        }

        function eventEnd(event) {
            const startDate = parseEventDate(event);
            if (!startDate) {
                return null;
            }
            if (event.endTime) {
                const endDateValue = event.endDate || event.startDate || event.date || '';
                const iso = `${endDateValue}T${event.endTime}`;
                const endDate = new Date(iso);
                if (!isNaN(endDate.getTime())) {
                    return endDate;
                }
            }
            return new Date(startDate.getTime() + 60 * 60 * 1000);
        }

        function formatDateTime(dateObj) {
            if (!dateObj || isNaN(dateObj.getTime())) {
                return '';
            }
            const opts = { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' };
            const dateText = dateObj.toLocaleDateString(undefined, opts);
            const timeText = dateObj.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
            return `${dateText} @ ${timeText}`;
        }

        // Format event date/time range with same-day and overnight handling.
        function formatEventRange(event) {
            const startDateTime = parseEventDate(event);
            if (!startDateTime) {
                return '';
            }
            const startLabel = formatDateTime(startDateTime);
            if (!event.endTime) {
                return startLabel;
            }
            const endDateValue = event.endDate || event.startDate || event.date || '';
            const endDateTime = new Date(`${endDateValue}T${event.endTime}`);
            if (isNaN(endDateTime.getTime())) {
                return startLabel;
            }
            const sameDay = startDateTime.toDateString() === endDateTime.toDateString();
            if (sameDay) {
                const endTimeText = endDateTime.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
                return `${startLabel} - ${endTimeText}`;
            }
            const endLabel = formatDateTime(endDateTime);
            return `${startLabel} - ${endLabel}`;
        }

        function truncateDescription(value) {
            const text = String(value || '').replace(/\s+/g, ' ').trim();
            if (text.length <= 32) {
                return text;
            }
            return text.slice(0, 32) + '...';
        }

        function buildMapsUrl(address) {
            return `https://maps.google.com/?q=${encodeURIComponent(address)}`;
        }

        function sanitizeId(value) {
            return String(value || '').replace(/[^A-Za-z0-9]/g, '');
        }

        function icsEscape(value) {
            return String(value || '')
                .replace(/\\/g, '\\\\')
                .replace(/\r\n|\r|\n/g, '\\n')
                .replace(/,/g, '\\,')
                .replace(/;/g, '\\;');
        }

        function icsFold(line) {
            const limit = 75;
            let out = '';
            let remaining = line;
            while (remaining.length > limit) {
                out += `${remaining.slice(0, limit)}\r\n `;
                remaining = remaining.slice(limit);
            }
            return out + remaining;
        }

        function padDatePart(value) {
            return String(value).padStart(2, '0');
        }

        function formatUtcDateTime(date) {
            return date.getUTCFullYear() +
                padDatePart(date.getUTCMonth() + 1) +
                padDatePart(date.getUTCDate()) + 'T' +
                padDatePart(date.getUTCHours()) +
                padDatePart(date.getUTCMinutes()) +
                padDatePart(date.getUTCSeconds()) + 'Z';
        }

        function formatOutlookLocal(date) {
            return date.getFullYear() + '-' +
                padDatePart(date.getMonth() + 1) + '-' +
                padDatePart(date.getDate()) + 'T' +
                padDatePart(date.getHours()) + ':' +
                padDatePart(date.getMinutes()) + ':' +
                padDatePart(date.getSeconds());
        }

        function formatDateOnly(date) {
            return date.getFullYear() +
                padDatePart(date.getMonth() + 1) +
                padDatePart(date.getDate());
        }

        function makeDateFromEvent(dateText, timeText) {
            if (!dateText || !timeText) {
                return null;
            }
            const date = new Date(`${dateText}T${timeText}:00`);
            return isNaN(date.getTime()) ? null : date;
        }

        function normalizeCalendarEvent(event) {
            const startDate = event.startDate || event.date || '';
            const startTime = event.startTime || '';
            let endDate = event.endDate || '';
            const endTime = event.endTime || '';
            const startDt = makeDateFromEvent(startDate, startTime);
            let endDt = null;

            if (endTime && !endDate) {
                endDate = startDate;
            }
            if (endDate && endTime) {
                endDt = makeDateFromEvent(endDate, endTime);
            }
            if (!startDt) {
                return null;
            }
            if (!endDt) {
                endDt = new Date(startDt);
                endDt.setHours(endDt.getHours() + 1);
            }

            return {
                id: event.id || '',
                name: typeof event.name === 'string' ? event.name.trim() : '',
                address: typeof event.address === 'string' ? event.address.trim() : '',
                description: typeof event.description === 'string' ? event.description.trim() : '',
                startDate,
                startTime,
                endDate,
                endTime,
                timeZone: typeof event.timeZone === 'string' ? event.timeZone.trim() : '',
                startDt,
                endDt,
                isAllDay: !!event.isAllDay
            };
        }

        function buildIcsDownloadUrl(paneId, eventId) {
            if (!paneId || !eventId) {
                return '';
            }
            return `/res/scr/module-endpoint.php?module=eventList&endpoint=ical&pane=${encodeURIComponent(paneId)}&event=${encodeURIComponent(eventId)}`;
        }

        function buildGoogleUrl(event) {
            const params = new URLSearchParams();
            params.set('action', 'TEMPLATE');
            if (event.isAllDay) {
                params.set('dates', `${formatDateOnly(event.startDt)}/${formatDateOnly(event.endDt)}`);
            } else {
                params.set('dates', `${formatUtcDateTime(event.startDt)}/${formatUtcDateTime(event.endDt)}`);
            }
            params.set('details', event.description);
            params.set('location', event.address);
            params.set('text', event.name);
            if (!event.isAllDay && event.timeZone) {
                params.set('ctz', event.timeZone);
            }
            return `https://calendar.google.com/calendar/render?${params.toString()}`;
        }

        function buildOutlookUrl(event, host) {
            const params = new URLSearchParams();
            params.set('allday', event.isAllDay ? 'true' : 'false');
            params.set('body', event.description);
            params.set('enddt', formatOutlookLocal(event.endDt));
            params.set('location', event.address);
            params.set('path', '/calendar/action/compose');
            params.set('rru', 'addevent');
            params.set('startdt', formatOutlookLocal(event.startDt));
            params.set('subject', event.name);
            return `https://${host}/calendar/0/action/compose?${params.toString()}`;
        }

        function buildCalendarActionUrl(provider, event, paneId) {
            const normalized = normalizeCalendarEvent(event);
            if (!normalized) {
                return '';
            }
            if (provider === 'ics') {
                return buildIcsDownloadUrl(paneId, normalized.id);
            }
            if (provider === 'google') {
                return buildGoogleUrl(normalized);
            }
            if (provider === 'outlook') {
                return buildOutlookUrl(normalized, 'outlook.live.com');
            }
            if (provider === 'm365') {
                return buildOutlookUrl(normalized, 'outlook.office.com');
            }
            return '';
        }

        function performCalendarAction(provider, event, paneId) {
            const url = buildCalendarActionUrl(provider, event, paneId);
            if (!url) {
                return;
            }
            if (provider === 'ics') {
                window.location.href = url;
                return;
            }
            window.open(url, '_blank', 'noopener');
        }

        function renderEventItem(event, paneId) {
            const timeRange = formatEventRange(event);
            const details = event.descriptionHtml ? event.descriptionHtml : '';
            const address = event.address || '';
            const addressLink = address ? buildMapsUrl(address) : '';
            const rawDescription = event.description || '';
            const truncated = truncateDescription(rawDescription);
            return `
                <div class=\"eventItem\" data-event-id=\"${lpEscapeHtml(event.id || '')}\" data-pane-id=\"${lpEscapeHtml(paneId)}\">
                    <div class=\"eventItemTitle\">${lpEscapeHtml(event.name || 'Untitled')}</div>
                    <div class=\"eventItemMeta\">${lpEscapeHtml(timeRange)}</div>
                    ${address ? `<div class=\"eventItemMeta\"><a href=\"${lpEscapeHtml(addressLink)}\" target=\"_blank\" rel=\"noopener\">${lpEscapeHtml(address)}</a></div>` : ''}
                    ${details ? `<div class=\"eventItemMeta\">${lpEscapeHtml(truncated)}</div>` : ''}
                </div>
            `;
        }

        const happening = [];
        const upcoming = [];
        const past = [];

        events.forEach((event) => {
            const startDate = parseEventDate(event);
            if (!startDate) {
                return;
            }
            const endDate = eventEnd(event);
            const startTime = startDate.getTime();
            const endTime = endDate ? endDate.getTime() : startTime;

            const isHappening = startTime <= nowTime && endTime >= nowTime;
            if (isHappening) {
                happening.push(event);
            } else if (startTime > nowTime) {
                upcoming.push(event);
            } else {
                past.push(event);
            }
        });

        const sortByStart = (a, b) => {
            const aDate = parseEventDate(a);
            const bDate = parseEventDate(b);
            return (aDate ? aDate.getTime() : 0) - (bDate ? bDate.getTime() : 0);
        };
        const sortByStartDesc = (a, b) => -sortByStart(a, b);

        happening.sort(sortByStart);
        upcoming.sort(sortByStart);
        past.sort(sortByStartDesc);

        function startOfDay(dateObj) {
            return new Date(dateObj.getFullYear(), dateObj.getMonth(), dateObj.getDate());
        }
        function addDays(dateObj, days) {
            return new Date(dateObj.getFullYear(), dateObj.getMonth(), dateObj.getDate() + days);
        }
        function dateKey(dateObj) {
            return dateObj.getFullYear() + '-' + padDatePart(dateObj.getMonth() + 1) + '-' + padDatePart(dateObj.getDate());
        }
        function formatCalendarDayTitle(dateObj) {
            return dateObj.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
        }
        function eventOverlapsDay(event, dayStart) {
            const start = parseEventDate(event);
            if (!start) { return false; }
            const end = eventEnd(event) || start;
            const dayEnd = addDays(dayStart, 1);
            return start.getTime() < dayEnd.getTime() && end.getTime() > dayStart.getTime();
        }
        const allCurrentEvents = happening.concat(upcoming);
        const calendarEvents = showPast ? happening.concat(upcoming, past) : allCurrentEvents;
        function getDayEvents(dayStart) {
            return calendarEvents
                .filter((event) => eventOverlapsDay(event, dayStart))
                .sort((a, b) => {
                    const aStart = parseEventDate(a);
                    const bStart = parseEventDate(b);
                    return (aStart ? aStart.getTime() : 0) - (bStart ? bStart.getTime() : 0);
                });
        }
        function eventCanBeSaved(event) {
            const end = eventEnd(event);
            return end ? end.getTime() >= nowTime : false;
        }
        function setEventView(view) {
            const nextView = showCalendar && view === 'calendar' ? 'calendar' : 'events';
            $pane.find('.eventViewTab').each(function() {
                const isActive = ($(this).data('event-view') || '') === nextView;
                $(this).toggleClass('isActive', isActive).attr('aria-selected', isActive ? 'true' : 'false');
            });
            $pane.find('[data-event-view-panel]').each(function() {
                const isActive = ($(this).data('event-view-panel') || '') === nextView;
                $(this).toggleClass('hidden', !isActive);
            });
        }
        function renderCalendar() {
            if (!showCalendar) { return; }
            const $monthLabel = $pane.find('.eventCalendarMonthText');
            const $todayButton = $pane.find('.eventCalendarTodayButton');
            const $body = $pane.find('.eventCalendarBody');
            if (!$monthLabel.length || !$body.length) { return; }
            $monthLabel.text(calendarMonth.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }));
            const isCurrentMonth = calendarMonth.getFullYear() === now.getFullYear() && calendarMonth.getMonth() === now.getMonth();
            $todayButton.toggleClass('hidden', isCurrentMonth);
            $body.empty();
            const firstOfMonth = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth(), 1);
            const gridStart = addDays(firstOfMonth, -firstOfMonth.getDay());
            const todayKey = dateKey(now);
            for (let week = 0; week < 6; week++) {
                const $row = $('<tr></tr>');
                for (let day = 0; day < 7; day++) {
                    const cellDate = addDays(gridStart, week * 7 + day);
                    const cellDayStart = startOfDay(cellDate);
                    const key = dateKey(cellDayStart);
                    const dayEvents = getDayEvents(cellDayStart);
                    const inCurrentMonth = cellDate.getMonth() === calendarMonth.getMonth();
                    const isToday = key === todayKey;
                    const count = dayEvents.length;
                    const countLabel = count > 99 ? '99+' : String(count);
                    const $cell = $('<td></td>')
                        .addClass('eventCalendarCell')
                        .toggleClass('isAdjacentMonth', !inCurrentMonth)
                        .toggleClass('isToday', isToday)
                        .toggleClass('hasEvents', count > 0)
                        .attr('data-date', key);
                    const $button = $('<button type="button" class="eventCalendarDayButton"></button>')
                        .attr('aria-label', `${formatCalendarDayTitle(cellDayStart)}${count ? `, ${count} event${count === 1 ? '' : 's'}` : ''}`);
                    const $inner = $('<span class="eventCalendarCellInner"></span>');
                    $inner.append($('<span class="eventCalendarDateNumber"></span>').text(cellDate.getDate()));
                    if (count > 0) {
                        $inner.append($('<span class="eventCalendarEventBadge"></span>').text(countLabel));
                    }
                    $button.append($inner);
                    $cell.append($button);
                    $row.append($cell);
                }
                $body.append($row);
            }
        }

        const $happening = $pane.find('.eventHappening');
        const $upcomingBody = $pane.find('.eventUpcoming .eventSectionBody');
        const $pastColumn = $pane.find('.eventPast');
        const $pastBody = $pane.find('.eventPast .eventSectionBody');

        $happening.empty();
        $upcomingBody.empty();
        $pastBody.empty();

        const paneId = $pane.data('pane-id') || $container.attr('id') || '';

        if (happening.length) {
            happening.forEach((event) => $happening.append(renderEventItem(event, paneId)));
        } else {
            $happening.append('<div class=\"eventItem\">No events happening now.</div>');
        }

        if (upcoming.length) {
            upcoming.forEach((event) => $upcomingBody.append(renderEventItem(event, paneId)));
        } else {
            $upcomingBody.append('<div class=\"eventItem\">No upcoming events.</div>');
        }

        if (showPast && past.length) {
            past.slice(0, 5).forEach((event) => $pastBody.append(renderEventItem(event, paneId)));
            $pastColumn.removeClass('hidden');
        } else {
            $pastColumn.addClass('hidden');
        }

        if (!showPast) {
            $pane.find('.eventSplit').addClass('eventSplitSingle');
        } else {
            $pane.find('.eventSplit').removeClass('eventSplitSingle');
        }

        if (showCalendar) {
            renderCalendar();
            setEventView(calendarDefault ? 'calendar' : 'events');
        } else {
            setEventView('events');
        }

        // Wire modal open/close for event details.
        const $overlay = $('#eventModalOverlay');
        const $title = $('#eventModalTitle');
        const $meta = $('#eventModalMeta');
        const $address = $('#eventModalAddress');
        const $description = $('#eventModalDescription');
        const $calendarMenu = $('#eventModalCalendarMenu');
        const $calendarToggle = $('#eventModalCalendarToggle');
        const $calendarDropdown = $('#eventModalCalendarDropdown');
        const $close = $('#eventModalClose');

        function closeCalendarMenus() {
            $('.eventCalendarMenu').removeClass('isOpen');
            $('.eventCalendarDropdown')
                .addClass('hidden')
                .removeClass('eventCalendarDropdownFixed')
                .removeAttr('style');
            $('.eventCalendarToggle').attr('aria-expanded', 'false');
        }

        const $dayOverlay = $('#eventCalendarDayModalOverlay');
        const $dayTitle = $('#eventCalendarDayModalTitle');
        const $dayBody = $('#eventCalendarDayModalBody');
        const $dayClose = $('#eventCalendarDayModalClose');
        const $calendarToast = $('#eventCalendarToast');
        let calendarToastTimer = null;

        function closeDayModal() {
            closeCalendarMenus();
            $dayOverlay.addClass('hidden');
        }

        function showCalendarToast() {
            if (!$calendarToast.length) { return; }
            if (calendarToastTimer) { window.clearTimeout(calendarToastTimer); }
            $calendarToast.removeClass('hidden isFading');
            calendarToastTimer = window.setTimeout(() => {
                $calendarToast.addClass('isFading');
                calendarToastTimer = window.setTimeout(() => {
                    $calendarToast.addClass('hidden').removeClass('isFading');
                    calendarToastTimer = null;
                }, 450);
            }, 2200);
        }

        function renderDayEventSection(title, sectionEvents) {
            if (!sectionEvents.length) { return ''; }
            return `<div class="eventCalendarDaySection"><h4>${lpEscapeHtml(title)}</h4><div class="eventSectionBody">${sectionEvents.map((event) => renderEventItem(event, paneId)).join('')}</div></div>`;
        }

        function openDayModal(dayStart) {
            const dayEvents = getDayEvents(dayStart);
            if (!dayEvents.length) { showCalendarToast(); return; }
            const current = [], future = [], completed = [];
            dayEvents.forEach((event) => {
                const start = parseEventDate(event);
                const end = eventEnd(event);
                const startTime = start ? start.getTime() : 0;
                const endTime = end ? end.getTime() : startTime;
                if (startTime <= nowTime && endTime >= nowTime) { current.push(event); }
                else if (endTime >= nowTime) { future.push(event); }
                else { completed.push(event); }
            });
            $dayTitle.text(formatCalendarDayTitle(dayStart));
            $dayBody.html(
                renderDayEventSection('Happening Now', current) +
                renderDayEventSection('Upcoming', future) +
                renderDayEventSection('Past Events', completed)
            );
            $dayOverlay.removeClass('hidden');
        }

        function openModal(event, allowCalendar, paneId) {
            if (!event) {
                return;
            }
            const timeRange = formatEventRange(event);
            $title.text(event.name || 'Untitled');
            $meta.text(timeRange);
            if (event.address) {
                const link = buildMapsUrl(event.address);
                $address.html(`<a href=\"${lpEscapeHtml(link)}\" target=\"_blank\" rel=\"noopener\">${lpEscapeHtml(event.address)}</a>`);
            } else {
                $address.text('');
            }
            $description.html(event.descriptionHtml || '');
            if (allowCalendar) {
                $calendarMenu.removeClass('hidden');
                $calendarToggle.prop('disabled', false).removeClass('hidden');
                $calendarToggle.data('pane-id', paneId || '');
                $calendarToggle.attr('data-event-id', event.id || '');
            } else {
                closeCalendarMenus();
                $calendarMenu.addClass('hidden');
                $calendarToggle.prop('disabled', true).addClass('hidden');
                $calendarToggle.data('pane-id', '');
                $calendarToggle.attr('data-event-id', '');
            }
            closeCalendarMenus();
            $overlay.removeClass('hidden');
        }

        function closeModal() {
            closeCalendarMenus();
            $overlay.addClass('hidden');
        }

        $pane.off('click.eventModal').on('click.eventModal', '.eventItem', function() {
            const eventId = $(this).attr('data-event-id') || '';
            if (!eventId) {
                return;
            }
            const allEvents = [].concat(happening, upcoming, past);
            const match = allEvents.find((item) => item && item.id === eventId);
            const allowCalendar = happening.concat(upcoming).some((item) => item && item.id === eventId);
            const itemPaneId = $(this).data('pane-id') || paneId;
            openModal(match, allowCalendar, itemPaneId);
        });

        $pane.off('click.eventViewTab').on('click.eventViewTab', '.eventViewTab', function() {
            setEventView($(this).data('event-view') || 'events');
        });

        $pane.off('click.eventCalendarNav').on('click.eventCalendarNav', '.eventCalendarPrev, .eventCalendarNext', function() {
            const direction = $(this).hasClass('eventCalendarPrev') ? -1 : 1;
            calendarMonth = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() + direction, 1);
            renderCalendar();
        });

        $pane.off('click.eventCalendarToday').on('click.eventCalendarToday', '.eventCalendarTodayButton', function() {
            calendarMonth = new Date(now.getFullYear(), now.getMonth(), 1);
            renderCalendar();
        });

        $pane.off('click.eventCalendarDay').on('click.eventCalendarDay', '.eventCalendarDayButton', function() {
            const dateValue = $(this).closest('.eventCalendarCell').attr('data-date') || '';
            const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(dateValue);
            if (!match) { return; }
            openDayModal(new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
        });

        $dayOverlay.off('click.eventCalendarDayModalClose').on('click.eventCalendarDayModalClose', function(event) {
            if (event.target === this) { closeDayModal(); }
        });
        $dayClose.off('click.eventCalendarDayModalClose').on('click.eventCalendarDayModalClose', function() {
            closeDayModal();
        });

        $dayBody.off('click.eventCalendarDayItem').on('click.eventCalendarDayItem', '.eventItem', function() {
            const eventId = $(this).attr('data-event-id') || '';
            if (!eventId) { return; }
            const match = events.find((item) => item && item.id === eventId);
            openModal(match, eventCanBeSaved(match), paneId);
        });

        $pane.off('click.eventAddress').on('click.eventAddress', '.eventItem a', function(event) {
            event.stopPropagation();
        });

        $(document).off('click.eventCalendarDismiss').on('click.eventCalendarDismiss', function(event) {
            if ($(event.target).closest('.eventCalendarMenu').length || $(event.target).closest('.eventModal').length) {
                return;
            }
            closeCalendarMenus();
        });

        $overlay.off('click.eventModalClose').on('click.eventModalClose', function(event) {
            if (event.target === this) {
                closeModal();
            }
        });

        $close.off('click.eventModalClose').on('click.eventModalClose', function() {
            closeModal();
        });

        $calendarToggle.off('click.eventCalendarToggle').on('click.eventCalendarToggle', function(event) {
            event.stopPropagation();
            const isOpen = $calendarMenu.hasClass('isOpen');
            closeCalendarMenus();
            if (!isOpen && !$calendarToggle.prop('disabled')) {
                $calendarMenu.addClass('isOpen');
                $calendarDropdown.removeClass('hidden');
                $calendarToggle.attr('aria-expanded', 'true');
            }
        });

        $calendarDropdown.off('click.eventCalendarOption').on('click.eventCalendarOption', '.eventCalendarOption', function(event) {
            event.stopPropagation();
            const provider = $(this).data('calendar-provider') || '';
            const modalEventId = $calendarToggle.attr('data-event-id') || '';
            const modalPaneId = $calendarToggle.data('pane-id') || paneId;
            const match = [].concat(happening, upcoming).find((item) => item && item.id === modalEventId);
            closeCalendarMenus();
            if (match) {
                performCalendarAction(provider, match, modalPaneId);
            }
        });
    });
}

    document.addEventListener('DOMContentLoaded', function() {
        renderEventLists();
    });
})();
