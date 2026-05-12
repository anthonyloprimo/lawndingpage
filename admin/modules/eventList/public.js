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
        const categories = Array.isArray(parsed.categories) ? parsed.categories : [];
        // Lookup table for O(1) event-to-color resolution at bar render time.
        // Orphan ids (referencing deleted categories) miss the lookup → default
        // flag color via CSS var fallback.
        const categoriesById = {};
        categories.forEach((cat) => {
            if (cat && cat.id != null && cat.color) {
                categoriesById[String(cat.id)] = cat;
            }
        });
        const now = new Date();
        const nowTime = now.getTime();
        let calendarMonth = new Date(now.getFullYear(), now.getMonth(), 1);

        // Construct the absolute moment for dateStr+timeStr interpreted in
        // ianaName. Used so events display in the viewer's local zone
        // regardless of where the admin authored them.
        function makeDateInZone(dateStr, timeStr, ianaName) {
            if (!dateStr || !timeStr || !ianaName) { return null; }
            const tentative = new Date(`${dateStr}T${timeStr}:00Z`);
            if (isNaN(tentative.getTime())) { return null; }
            try {
                const fmt = new Intl.DateTimeFormat('en-US', {
                    timeZone: ianaName,
                    year: 'numeric', month: '2-digit', day: '2-digit',
                    hour: '2-digit', minute: '2-digit', second: '2-digit',
                    hourCycle: 'h23'
                });
                const parts = fmt.formatToParts(tentative);
                const get = (type) => {
                    const p = parts.find((x) => x.type === type);
                    return p ? p.value : '';
                };
                const inZoneIso = `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}:${get('second')}Z`;
                const inZoneMs = new Date(inZoneIso).getTime();
                if (isNaN(inZoneMs)) { return null; }
                const offset = inZoneMs - tentative.getTime();
                return new Date(tentative.getTime() - offset);
            } catch (err) {
                return null;
            }
        }

        function parseEventDate(event) {
            const date = event.startDate || event.date || '';
            if (!date) { return null; }
            if (event.allDay) {
                const parsed = new Date(`${date}T00:00:00`);
                return isNaN(parsed.getTime()) ? null : parsed;
            }
            const start = event.startTime || '';
            if (!start) { return null; }
            const tz = event.timeZone || '';
            if (tz) {
                const zoned = makeDateInZone(date, start, tz);
                if (zoned) { return zoned; }
            }
            const parsed = new Date(`${date}T${start}`);
            return isNaN(parsed.getTime()) ? null : parsed;
        }

        function eventEnd(event) {
            const startDate = parseEventDate(event);
            if (!startDate) { return null; }
            if (event.allDay) {
                const endDateValue = event.endDate || event.startDate || event.date || '';
                const parsed = new Date(`${endDateValue}T23:59:59`);
                return isNaN(parsed.getTime()) ? null : parsed;
            }
            if (event.endTime) {
                const endDateValue = event.endDate || event.startDate || event.date || '';
                const tz = event.timeZone || '';
                if (tz) {
                    const zoned = makeDateInZone(endDateValue, event.endTime, tz);
                    if (zoned) { return zoned; }
                }
                const endDate = new Date(`${endDateValue}T${event.endTime}`);
                if (!isNaN(endDate.getTime())) { return endDate; }
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
        // All-day events drop the time half: "All day, Sat Mar 14 2026" (single)
        // or "All day, Sat Mar 14 2026 - Mon Mar 16 2026" (multi-day).
        function formatEventRange(event) {
            const startDateTime = parseEventDate(event);
            if (!startDateTime) {
                return '';
            }
            if (event.allDay) {
                const dateOpts = { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' };
                const startDateText = startDateTime.toLocaleDateString(undefined, dateOpts);
                const endDateValue = event.endDate || event.startDate || event.date || '';
                if (endDateValue && endDateValue !== (event.startDate || event.date)) {
                    const endDateTime = new Date(`${endDateValue}T00:00:00`);
                    if (!isNaN(endDateTime.getTime())) {
                        const endDateText = endDateTime.toLocaleDateString(undefined, dateOpts);
                        return `All day, ${startDateText} - ${endDateText}`;
                    }
                }
                return `All day, ${startDateText}`;
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
            const cat = event.categoryId ? categoriesById[String(event.categoryId)] : null;
            const catAttrs = cat && cat.color ? ` data-cat-color=\"${lpEscapeHtml(cat.color)}\"` : '';
            const host = event.host || '';
            const sourceUrl = event.sourceUrl || '';
            const badges = Array.isArray(event.keywordBadges) ? event.keywordBadges : [];
            const badgeHtml = badges.length
                ? `<div class=\"eventItemBadges\">${badges.map((b) => `<span class=\"eventItemBadge\" title=\"${lpEscapeHtml(b.label || '')}\"><span class=\"eventItemBadgeIcon\" aria-hidden=\"true\">${lpEscapeHtml(b.icon || '')}</span><span class=\"eventItemBadgeLabel\">${lpEscapeHtml(b.label || '')}</span></span>`).join('')}</div>`
                : '';
            const sourceHtml = sourceUrl
                ? `<a class=\"eventItemSourceLink\" href=\"${lpEscapeHtml(sourceUrl)}\" target=\"_blank\" rel=\"noopener noreferrer\">Visit Site ↗</a>`
                : '';
            return `
                <div class=\"eventItem\" data-event-id=\"${lpEscapeHtml(event.id || '')}\" data-pane-id=\"${lpEscapeHtml(paneId)}\"${catAttrs}>
                    <div class=\"eventItemTitleRow\">
                        <div class=\"eventItemTitle\">${lpEscapeHtml(event.name || 'Untitled')}</div>
                        ${sourceHtml}
                    </div>
                    <div class=\"eventItemMeta\">${lpEscapeHtml(timeRange)}</div>
                    ${address ? `<div class=\"eventItemMeta\"><a href=\"${lpEscapeHtml(addressLink)}\" target=\"_blank\" rel=\"noopener\">${lpEscapeHtml(address)}</a></div>` : ''}
                    ${host ? `<div class=\"eventItemMeta eventItemHost\">Hosted by ${lpEscapeHtml(host)}</div>` : ''}
                    ${badgeHtml}
                    ${details ? `<div class=\"eventItemMeta\">${lpEscapeHtml(truncated)}</div>` : ''}
                </div>
            `;
        }

        // renderEventItem returns an HTML string, so colors arrive via
        // data-cat-color (CSP-safe). Post-process applies the CSS var.
        function applyEventItemCategoryColors($scope) {
            $scope.find('.eventItem[data-cat-color]').each(function () {
                const $item = $(this);
                $item.css('--bar-accent', $item.attr('data-cat-color'));
                $item.removeAttr('data-cat-color');
            });
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
        // 12h clock with single-letter am/pm, minutes only when non-zero ("7p", "7:30p").
        function formatBarTime(dateObj) {
            if (!dateObj) { return ''; }
            const h = dateObj.getHours();
            const m = dateObj.getMinutes();
            const ampm = h >= 12 ? 'p' : 'a';
            const h12 = ((h + 11) % 12) + 1;
            return m === 0 ? `${h12}${ampm}` : `${h12}:${padDatePart(m)}${ampm}`;
        }
        // Visible tracks per cell capped at 3. Events at higher tracks roll
        // up into a "+ N more" overflow link per day.
        const TRACKS_VISIBLE_MAX = 3;
        // Allocate each row event a stable track index 0..N so multi-day spans
        // render at the same Y position in every cell they cross. Three tiers:
        //   1. allDay events       (top of stack)
        //   2. timed multi-day     (middle)
        //   3. timed single-day    (bottom, ascending by start time)
        function allocateRowTracks(rowEvents) {
            const isMultiDay = (re) => re.rowStartDay !== re.rowEndDay
                || re.continuesFromPriorRow
                || re.continuesToNextRow;
            const tierOf = (re) => re.event.allDay ? 1 : (isMultiDay(re) ? 2 : 3);
            const sorted = rowEvents.slice().sort((a, b) => {
                const aTier = tierOf(a);
                const bTier = tierOf(b);
                if (aTier !== bTier) { return aTier - bTier; }
                if (aTier <= 2) {
                    // Tier 1 (allDay) or 2 (multi-day): longer spans first.
                    const aLen = a.rowEndDay - a.rowStartDay;
                    const bLen = b.rowEndDay - b.rowStartDay;
                    if (bLen !== aLen) { return bLen - aLen; }
                }
                const aStart = parseEventDate(a.event);
                const bStart = parseEventDate(b.event);
                return (aStart ? aStart.getTime() : 0) - (bStart ? bStart.getTime() : 0);
            });
            const occupied = [];
            sorted.forEach((re) => {
                let track = 0;
                for (;;) {
                    if (!occupied[track]) {
                        occupied[track] = [false, false, false, false, false, false, false];
                    }
                    let conflict = false;
                    for (let d = re.rowStartDay; d <= re.rowEndDay; d++) {
                        if (occupied[track][d]) { conflict = true; break; }
                    }
                    if (!conflict) {
                        for (let d = re.rowStartDay; d <= re.rowEndDay; d++) {
                            occupied[track][d] = true;
                        }
                        re.track = track;
                        break;
                    }
                    track++;
                }
            });
            return sorted;
        }
        function buildRowAllocation(weekStart) {
            const weekEnd = addDays(weekStart, 6);
            const weekEndExclusive = addDays(weekEnd, 1);
            const rowEvents = [];
            calendarEvents.forEach((event) => {
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
        function renderCalendarBars($cell, dayIndex, allocated) {
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
                // Continuation cells of a multi-cell row segment: render only a slot.
                // The bar lives in the first cell, sized to span the whole segment.
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
                const accessibleLabel = isAllDay
                    ? `${title}, all day`
                    : (isMultiDayEvent
                        ? `${title}, multi-day event`
                        : (time ? `${time} ${title}` : title));
                const $bar = $('<button type="button" class="eventCalendarBar"></button>')
                    .attr('data-event-id', re.event && re.event.id ? re.event.id : '')
                    .attr('aria-label', accessibleLabel)
                    .attr('title', accessibleLabel);
                // Category color via runtime CSS-var set (CSP-safe; no inline
                // style attribute). Default flag color stays in CSS for
                // uncategorized + orphan-id events.
                const cat = re.event && re.event.categoryId ? categoriesById[String(re.event.categoryId)] : null;
                if (cat && cat.color) { $bar.css('--bar-accent', cat.color); }
                if (isAllDay) { $bar.addClass('isAllDay'); }
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
        // Static once per page (categories list is fixed at render time).
        // Runtime CSS-var set on the swatch keeps the markup CSP-safe.
        function renderLegend() {
            if (!showCalendar || !categories.length) { return; }
            const $view = $pane.find('.eventCalendarView');
            if (!$view.length || $view.find('.eventCategoryLegend').length) { return; }
            const $legend = $('<div class="eventCategoryLegend" aria-label="Event category legend"></div>');
            categories.forEach((cat) => {
                const $chip = $('<span class="eventCategoryLegendChip"></span>');
                $chip.append($('<span class="eventCategoryLegendSwatch" aria-hidden="true"></span>').css('--bar-accent', cat.color));
                $chip.append($('<span class="eventCategoryLegendName"></span>').text(cat.name));
                $legend.append($chip);
            });
            $view.prepend($legend);
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
                    const dayTitleLabel = `${formatCalendarDayTitle(cellDayStart)}${count ? `, ${count} event${count === 1 ? '' : 's'}` : ''}`;
                    if (count > 0) {
                        const $header = $('<button type="button" class="eventCalendarDayHeader"></button>')
                            .attr('aria-label', dayTitleLabel);
                        $header.append($('<span class="eventCalendarDateNumber"></span>').text(cellDate.getDate()));
                        $inner.append($header);
                    } else {
                        $inner.append($('<span class="eventCalendarDateNumber"></span>').text(cellDate.getDate()));
                    }
                    $cell.append($inner);
                    renderCalendarBars($cell, day, allocated);
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

        applyEventItemCategoryColors($pane);

        if (!showPast) {
            $pane.find('.eventSplit').addClass('eventSplitSingle');
        } else {
            $pane.find('.eventSplit').removeClass('eventSplitSingle');
        }

        // Mobile (<600px): force EVENTS view; calendar tab is hidden by CSS too.
        const calendarSuppressedByViewport = window.matchMedia
            && window.matchMedia('(max-width: 600px)').matches;
        if (showCalendar && !calendarSuppressedByViewport) {
            renderLegend();
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
        const $host = $('#eventModalHost');
        const $badges = $('#eventModalBadges');
        const $sourceLink = $('#eventModalSourceLink');
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

        // Reparent to <body> so position:fixed escapes <main>'s stacking
        // context; otherwise the page-level <nav> sibling paints over the
        // modal on small screens. Same pattern as admin.js (trait #9).
        if ($overlay.length && !$overlay.parent().is('body')) {
            $overlay.appendTo('body');
        }
        if ($dayOverlay.length && !$dayOverlay.parent().is('body')) {
            $dayOverlay.appendTo('body');
        }
        let calendarToastTimer = null;

        function closeDayModal() {
            closeCalendarMenus();
            if (window.closePublicModal) { window.closePublicModal($dayOverlay); }
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
            applyEventItemCategoryColors($dayBody);
            if (window.openPublicModal) { window.openPublicModal($dayOverlay); }
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
            const host = event.host || '';
            if (host) {
                $host.text('Hosted by ' + host).attr('hidden', null);
            } else {
                $host.text('').attr('hidden', 'hidden');
            }
            const badges = Array.isArray(event.keywordBadges) ? event.keywordBadges : [];
            if (badges.length) {
                $badges.html(badges.map((b) => {
                    const icon = lpEscapeHtml(b.icon || '');
                    const label = lpEscapeHtml(b.label || '');
                    return `<span class=\"eventItemBadge\"><span class=\"eventItemBadgeIcon\" aria-hidden=\"true\">${icon}</span><span class=\"eventItemBadgeLabel\">${label}</span></span>`;
                }).join('')).attr('hidden', null);
            } else {
                $badges.empty().attr('hidden', 'hidden');
            }
            const sourceUrl = event.sourceUrl || '';
            if (sourceUrl) {
                $sourceLink.attr('href', sourceUrl).removeClass('hidden');
            } else {
                $sourceLink.attr('href', '#').addClass('hidden');
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
            if (window.openPublicModal) { window.openPublicModal($overlay); }
        }

        function closeModal() {
            closeCalendarMenus();
            if (window.closePublicModal) { window.closePublicModal($overlay); }
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

        function openDayModalFromCell(el) {
            const dateValue = $(el).closest('.eventCalendarCell').attr('data-date') || '';
            const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(dateValue);
            if (!match) { return; }
            openDayModal(new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
        }
        $pane.off('click.eventCalendarDay').on('click.eventCalendarDay',
            '.eventCalendarDayHeader, .eventCalendarBarMore', function() {
            openDayModalFromCell(this);
        });
        $pane.off('click.eventCalendarBar').on('click.eventCalendarBar', '.eventCalendarBar', function() {
            const eventId = $(this).attr('data-event-id') || '';
            const match = events.find((item) => item && item.id === eventId);
            if (!match) { return; }
            openModal(match, eventCanBeSaved(match), paneId);
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
