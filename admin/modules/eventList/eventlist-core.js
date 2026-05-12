// Shared eventList calendar helpers used by admin.js and public.js. Pure
// functions only — no DOM, no jQuery, no per-pane state. Loaded as its own
// <script defer> in both admin.php and public.php so it runs before the
// surface JS that destructures from window.lpEventListCore.
//
// makeDateInZone is the cross-timezone moment-builder: an event authored as
// 7p in America/New_York renders at the viewer's local zone offset for the
// same absolute moment. parseEventDate/eventEnd consult it when the event
// carries a timeZone field; otherwise they fall back to local interpretation.

(function (window) {
    'use strict';

    function padDatePart(value) {
        return String(value).padStart(2, '0');
    }

    function startOfDay(dateObj) {
        return new Date(dateObj.getFullYear(), dateObj.getMonth(), dateObj.getDate());
    }

    function addDays(dateObj, days) {
        return new Date(dateObj.getFullYear(), dateObj.getMonth(), dateObj.getDate() + days);
    }

    function dateKey(dateObj) {
        return dateObj.getFullYear() + '-' + padDatePart(dateObj.getMonth() + 1) + '-' + padDatePart(dateObj.getDate());
    }

    // Construct the absolute moment for dateStr+timeStr interpreted in
    // ianaName. Returns a Date that, when displayed in the viewer's local
    // zone, points to the same wall-clock moment in the authoring zone.
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

    // 12h clock with single-letter am/pm, minutes only when non-zero ("7p", "7:30p").
    function formatBarTime(dateObj) {
        if (!dateObj) { return ''; }
        const h = dateObj.getHours();
        const m = dateObj.getMinutes();
        const ampm = h >= 12 ? 'p' : 'a';
        const h12 = ((h + 11) % 12) + 1;
        return m === 0 ? `${h12}${ampm}` : `${h12}:${padDatePart(m)}${ampm}`;
    }

    // Visible tracks per cell cap before bars roll into "+ N more" overflow.
    // Surface render code filters occupants by this; the engine itself does
    // not enforce it.
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

    window.lpEventListCore = {
        TRACKS_VISIBLE_MAX: TRACKS_VISIBLE_MAX,
        padDatePart: padDatePart,
        startOfDay: startOfDay,
        addDays: addDays,
        dateKey: dateKey,
        makeDateInZone: makeDateInZone,
        parseEventDate: parseEventDate,
        eventEnd: eventEnd,
        formatBarTime: formatBarTime,
        allocateRowTracks: allocateRowTracks
    };
}(window));
