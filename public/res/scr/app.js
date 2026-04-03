// Lawnding Page JS scaffolding

// Define the width in which we toggle mobile or desktop view modes. Initialize the mode and currentPane variables.
const BREAKPOINT = 979;
let mode = null;
let currentPane = null;
let paneOrder = [];
const faviconCache = new Map();
const BACKGROUND_DEFAULT_SETTINGS = { mode: 'random_load', duration: 5 };
const BACKGROUND_NEXT_INDEX_KEY = 'lawnding_bg_next_index';
const BACKGROUND_FADE_MS = 450;
const backgroundState = {
    order: [],
    index: 0,
    mode: BACKGROUND_DEFAULT_SETTINGS.mode,
    duration: BACKGROUND_DEFAULT_SETTINGS.duration,
    backgrounds: [],
    timerId: null,
    fadeTimerId: null
};

// Helper: ensure we aren't trying to show the always-visible pane on desktop.
function ensureDesktopPaneSelection() {
    if (mode !== 'desktop') {
        return;
    }
    const linksHidden = document.body && document.body.classList.contains('linksHidden');
    const linksPaneExists = document.querySelector('#links');
    if (linksHidden || !linksPaneExists || !paneOrder.includes('links')) {
        return;
    }
    const alwaysVisiblePane = paneOrder.includes('links') ? 'links' : paneOrder[0];
    const secondPane = getDefaultDesktopPane();
    if (currentPane === alwaysVisiblePane && secondPane) {
        currentPane = secondPane;
    }
}

// Helper: toggle pane visibility based on a set of visible pane IDs.
function updatePaneVisibility(panes, visibleIds) {
    panes.each(function() {
        const pane = $(this);
        const id = pane.attr('id');
        if (visibleIds.has(id)) {
            pane.removeClass('hidden');
        } else {
            pane.addClass('hidden');
        }
    });
}

// Helper: collect visible nav links for pane order calculation.
function getVisibleNavLinks() {
    return $('.navLink').filter(function() {
        const $link = $(this);
        if ($link.attr('data-external') === 'true') {
            return false;
        }
        const $item = $link.closest('li');
        if ($link.hasClass('hidden')) {
            return false;
        }
        if ($item.hasClass('isHidden')) {
            return false;
        }
        if ($link.attr('data-pane-disabled')) {
            return false;
        }
        return true;
    });
}

function rebuildPaneOrder() {
    paneOrder = getVisibleNavLinks().map(function() {
        return $(this).data('pane');
    }).get();
}

function ensureValidCurrentPane() {
    const paneExists = currentPane && $(`#${currentPane}`).length > 0;
    if (paneExists) {
        return;
    }
    if ($('#noPane').length) {
        currentPane = 'noPane';
        return;
    }
    currentPane = mode === 'desktop' ? getDefaultDesktopPane() : getDefaultMobilePane();
}

window.lawndingRebuildPaneOrder = function() {
    rebuildPaneOrder();
    if (currentPane && !paneOrder.includes(currentPane)) {
        currentPane = mode === 'desktop' ? getDefaultDesktopPane() : getDefaultMobilePane();
    }
    ensureValidCurrentPane();
    ensureDesktopPaneSelection();
    applyLayout();
    updateNavActiveState();
    updateNavBarLayout();
};

// Helper: show/hide the Links nav item based on mode.
function toggleLinksNav(show) {
    if (document.body && document.body.classList.contains('linksHidden')) {
        return;
    }
    const linkItem = $('.navLink[data-pane="links"]');
    const linkItems = linkItem.closest('li');
    if (show) {
        linkItem.removeClass('hidden');
        linkItems.removeClass('isHidden');
    } else {
        linkItem.addClass('hidden');
        linkItems.addClass('isHidden');
    }
}

// Returns either 'desktop' or 'mobile' as the view mode.
function getMode() {
    return window.innerWidth > BREAKPOINT ? 'desktop' : 'mobile';
}

// On first run, set up layout, background, favicon, and event listeners.
function init() {
    // Store jquery references as constants.
    const panes = $('.pane');
    const navLinks = $('.navLink');

    // Hide the noscript warning if JS is enabled.
    $('#noJsWarning').hide();

    // Capture the pane order from visible nav links for default selection logic.
    rebuildPaneOrder();

    // Set the mode based on the getMode function and pick defaults:
    // - mobile: first pane
    // - desktop: second pane (first stays the "links" sidebar on main page, "bg" on config)
    mode = getMode();
    currentPane = mode === 'desktop' ? getDefaultDesktopPane() : getDefaultMobilePane();
    console.log(`init(): mode=${mode}, panes=${panes.length}, navLinks=${navLinks.length}`);

    // Apply the layout based on the above.
    applyLayout();
    updateNavActiveState();
    updateNavBarLayout();

    // Set the header logo background image from JSON-provided data.
    setLogoBackground();

    // Apply the body background image based on configured settings.
    setBackgroundFromSettings();

    // Set favicon-style icons on link buttons.
    setLinkFavicons();

    // Render event list panes (public view).
    renderEventLists();

    // Lock layout height to the visible viewport on iOS Safari.
    setAppHeight();
    window.addEventListener('resize', setAppHeight);
    window.addEventListener('orientationchange', setAppHeight);
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', setAppHeight);
        window.visualViewport.addEventListener('scroll', setAppHeight);
    }

    // Wire up nav clicks to drive pane switching in SPA style.
    navLinks.on('click', function(event) {
        const $link = $(this);
        if ($link.attr('data-external') === 'true') {
            return;
        }
        event.preventDefault();

        // Determine which pane this nav link is responsible for and update state.
        const targetPane = $link.data('pane');
        currentPane = targetPane;

        // Apply layout for the new pane and update nav highlighting.
        applyLayout();
        updateNavActiveState();
    });

    $('#navBar').on('scroll', updateNavBarFades);
    window.addEventListener('resize', updateNavBarFades);

    // Any time the window is resized, check to see if we're still in the same mode or not.
    $(window).on('resize', function() {
        // Check the mode we're in.
        const newMode = getMode();
        // Only update the layout if the mode actually changed.
        if (newMode !== mode) {
            const previousPane = currentPane;
            mode = newMode;

            if (mode === 'desktop') {
                // If we land on desktop while on the first pane, shift to the second pane.
                ensureDesktopPaneSelection();
            } else {
                // On mobile, keep current pane as-is.
                currentPane = previousPane;
            }

            console.log(`mode changed: ${mode}`);
            applyLayout();
            updateNavActiveState();
        }

        updateNavBarLayout();
    });
}

// Apply layout: show/hide panes depending on the active mode and pane.
function applyLayout() {
    // Easily store jquery reference to a constant.
    const panes = $('.pane');
    ensureValidCurrentPane();
    const linksHidden = document.body && document.body.classList.contains('linksHidden');
    const linksPaneExists = $('#links').length > 0;

    // check the mode and apply layout based on that.
    if (mode === 'desktop') {
        // If we change from mobile to desktop mode, and we're viewing the first nav pane, force to the second since the first is always visible.
        ensureDesktopPaneSelection();

        // Show the links pane plus the current content pane.
        if (linksHidden || !linksPaneExists) {
            updatePaneVisibility(panes, new Set([currentPane]));
        } else {
            updatePaneVisibility(panes, new Set(['links', currentPane]));
        }

        // Hide the Links nav item on desktop (links pane is always visible already).
        toggleLinksNav(false);
    } else {  // if we aren't in desktop mode, we're in mobile mode.
        // Show only the current pane.
        updatePaneVisibility(panes, new Set([currentPane]));

        // Show the Links nav item on mobile so users can navigate to it.
        toggleLinksNav(true);
    }

    console.log(`applyLayout(): mode=${mode}, currentPane=${currentPane}`);
}

// Apply the logo background image using data from PHP-injected global.
function setLogoBackground() {
    // The PHP template injects window.headerData; bail if missing.
    if (!window.headerData || !window.headerData.logo) {
        return;
    }

    $('#logo').css('background-image', `url('${window.headerData.logo}')`);
}

// Apply a body background image sequence based on configured settings.
function setBackgroundFromSettings() {
    if (!document.body) {
        return;
    }

    const backgrounds = normalizeBackgrounds(window.headerData && window.headerData.backgrounds);
    if (!backgrounds.length) {
        updateBackgroundImage('linear-gradient(#00000055)', false);
        updateBackgroundAuthor(null);
        return;
    }

    const settings = getBackgroundSettings();
    const isRandom = settings.mode.indexOf('random') === 0;
    const isSlideshow = settings.mode.indexOf('slideshow') !== -1;
    const isSequentialLoad = settings.mode === 'sequential_load';

    backgroundState.backgrounds = backgrounds;
    backgroundState.mode = settings.mode;
    backgroundState.duration = settings.duration;
    backgroundState.order = buildBackgroundOrder(backgrounds.length, isRandom);
    backgroundState.index = 0;

    let initialIndex = 0;
    if (isSequentialLoad) {
        initialIndex = getSequentialLoadIndex(backgrounds.length);
        saveSequentialLoadIndex(initialIndex + 1, backgrounds.length);
    }
    backgroundState.index = initialIndex;
    applyBackgroundIndex(initialIndex, false);

    if (isSlideshow && backgrounds.length > 1) {
        scheduleBackgroundAdvance();
    }
}

function getBackgroundSettings() {
    const rawSettings = window.headerData && typeof window.headerData === 'object'
        ? window.headerData.backgroundSettings
        : null;
    const mode = rawSettings && typeof rawSettings.mode === 'string'
        ? rawSettings.mode
        : BACKGROUND_DEFAULT_SETTINGS.mode;
    const durationRaw = rawSettings && rawSettings.duration != null
        ? parseInt(rawSettings.duration, 10)
        : NaN;
    const duration = Number.isFinite(durationRaw) && durationRaw > 0
        ? durationRaw
        : BACKGROUND_DEFAULT_SETTINGS.duration;
    return { mode, duration };
}

function normalizeBackgrounds(rawBackgrounds) {
    const raw = Array.isArray(rawBackgrounds) ? rawBackgrounds : [];
    return raw
        .map((bg) => {
            if (typeof bg === 'string') {
                return { url: bg, author: '', authorUrl: '' };
            }
            if (bg && typeof bg === 'object' && typeof bg.url === 'string') {
                return {
                    url: bg.url,
                    author: typeof bg.author === 'string' ? bg.author : '',
                    authorUrl: typeof bg.authorUrl === 'string' ? bg.authorUrl : ''
                };
            }
            return null;
        })
        .filter((bg) => bg && bg.url.length > 0);
}

function buildBackgroundOrder(count, randomize) {
    const order = Array.from({ length: count }, (_, i) => i);
    if (randomize) {
        for (let i = order.length - 1; i > 0; i -= 1) {
            const j = Math.floor(Math.random() * (i + 1));
            [order[i], order[j]] = [order[j], order[i]];
        }
    }
    return order;
}

function getSequentialLoadIndex(count) {
    if (count <= 0) {
        return 0;
    }
    try {
        const raw = localStorage.getItem(BACKGROUND_NEXT_INDEX_KEY);
        const parsed = parseInt(raw, 10);
        if (!Number.isFinite(parsed) || parsed < 0 || parsed >= count) {
            return 0;
        }
        return parsed;
    } catch (err) {
        return 0;
    }
}

function saveSequentialLoadIndex(nextIndex, count) {
    if (count <= 0) {
        return;
    }
    const value = Number.isFinite(nextIndex) ? nextIndex : 0;
    try {
        localStorage.setItem(BACKGROUND_NEXT_INDEX_KEY, String(value));
    } catch (err) {
        // Ignore storage failures (private mode, quota, etc.)
    }
}

function scheduleBackgroundAdvance() {
    if (backgroundState.timerId) {
        clearTimeout(backgroundState.timerId);
    }
    backgroundState.timerId = setTimeout(function() {
        advanceBackground();
        scheduleBackgroundAdvance();
    }, backgroundState.duration * 1000);
}

function advanceBackground() {
    if (!backgroundState.backgrounds.length) {
        return;
    }
    const isRandom = backgroundState.mode.indexOf('random') === 0;
    let nextIndex = backgroundState.index + 1;
    if (nextIndex >= backgroundState.order.length) {
        backgroundState.order = buildBackgroundOrder(backgroundState.backgrounds.length, isRandom);
        nextIndex = 0;
    }
    backgroundState.index = nextIndex;
    applyBackgroundIndex(nextIndex, true);
}

function applyBackgroundIndex(index, allowFade) {
    const orderIndex = backgroundState.order[index];
    const chosen = backgroundState.backgrounds[orderIndex];
    if (!chosen) {
        return;
    }
    updateBackgroundImage(`linear-gradient(#00000055), url('${chosen.url}')`, allowFade);
    updateBackgroundAuthor(chosen);
}

function updateBackgroundImage(imageValue, allowFade) {
    if (!document.body) {
        return;
    }
    if (backgroundState.fadeTimerId) {
        clearTimeout(backgroundState.fadeTimerId);
        backgroundState.fadeTimerId = null;
    }
    const body = document.body;
    if (!allowFade || !body.classList.contains('bg-ready')) {
        body.style.setProperty('--bg-image', imageValue);
        body.classList.add('bg-ready');
        return;
    }
    body.classList.add('bg-fade-out');
    backgroundState.fadeTimerId = setTimeout(function() {
        body.style.setProperty('--bg-image', imageValue);
        body.classList.remove('bg-fade-out');
        backgroundState.fadeTimerId = null;
    }, BACKGROUND_FADE_MS);
}

function updateBackgroundAuthor(chosen) {
    const author = chosen && chosen.author && chosen.author.trim().length > 0
        ? chosen.author.trim()
        : 'anonymous';
    const authorUrl = normalizeExternalUrl(chosen ? chosen.authorUrl : '');
    const $authorLink = $('.authorLink');
    const $authorPlain = $('.authorPlain');

    if (authorUrl) {
        $authorLink.attr('href', authorUrl).removeClass('hidden');
        $authorLink.find('.authorName').text(author);
        $authorPlain.addClass('hidden').text('');
    } else {
        $authorLink.addClass('hidden').attr('href', '');
        $authorPlain.text(author).removeClass('hidden');
    }
}

// Normalize a user-supplied URL; defaults to https:// when scheme is missing.
function normalizeExternalUrl(value) {
    if (typeof value !== 'string') {
        return '';
    }

    const trimmed = value.trim();
    if (!trimmed) {
        return '';
    }

    if (/^[a-z][a-z0-9+.-]*:/i.test(trimmed)) {
        return trimmed;
    }

    return `https://${trimmed}`;
}

// Render event list panes in the public view from embedded JSON payloads.
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
        const events = Array.isArray(parsed.events) ? parsed.events : [];
        const now = new Date();
        const nowTime = now.getTime();
        const next24h = nowTime + 24 * 60 * 60 * 1000;

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
            return `/res/scr/event-ical.php?pane=${encodeURIComponent(paneId)}&event=${encodeURIComponent(eventId)}`;
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

        function buildYahooLikeUrl(event, host) {
            const params = new URLSearchParams();
            params.set('desc', event.description);
            params.set('dur', event.isAllDay ? 'allday' : 'false');
            params.set('et', event.isAllDay ? formatDateOnly(event.endDt) : formatUtcDateTime(event.endDt));
            params.set('in_loc', event.address);
            params.set('st', event.isAllDay ? formatDateOnly(event.startDt) : formatUtcDateTime(event.startDt));
            params.set('title', event.name);
            params.set('v', '60');
            return `https://${host}/?${params.toString()}`;
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
            if (provider === 'yahoo') {
                return buildYahooLikeUrl(normalized, 'calendar.yahoo.com');
            }
            if (provider === 'aol') {
                return buildYahooLikeUrl(normalized, 'calendar.aol.com');
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

        function buildCalendarMenuHtml(paneId, eventId) {
            return `
                <div class=\"eventCalendarMenu\">
                    <button
                        class=\"eventCalendarButton eventCalendarToggle\"
                        type=\"button\"
                        data-pane-id=\"${escapeHtml(paneId)}\"
                        data-event-id=\"${escapeHtml(eventId || '')}\"
                        aria-haspopup=\"true\"
                        aria-expanded=\"false\"
                    >
                        Save to Calendar
                    </button>
                    <div class=\"eventCalendarDropdown hidden\" role=\"menu\" aria-label=\"Calendar options\">
                        <button class=\"eventCalendarOption\" type=\"button\" data-calendar-provider=\"ics\" role=\"menuitem\">Download ICS File</button>
                        <button class=\"eventCalendarOption\" type=\"button\" data-calendar-provider=\"google\" role=\"menuitem\">Add to Google Calendar</button>
                        <button class=\"eventCalendarOption\" type=\"button\" data-calendar-provider=\"outlook\" role=\"menuitem\">Add to Outlook Calendar</button>
                        <button class=\"eventCalendarOption\" type=\"button\" data-calendar-provider=\"m365\" role=\"menuitem\">Add to Microsoft 365 Calendar</button>
                        <button class=\"eventCalendarOption\" type=\"button\" data-calendar-provider=\"yahoo\" role=\"menuitem\">Add to Yahoo! Calendar</button>
                        <button class=\"eventCalendarOption\" type=\"button\" data-calendar-provider=\"aol\" role=\"menuitem\">Add to AOL Calendar</button>
                    </div>
                </div>
            `;
        }

        function renderEventItem(event, allowCalendar, paneId) {
            const timeRange = formatEventRange(event);
            const details = event.descriptionHtml ? event.descriptionHtml : '';
            const address = event.address || '';
            const addressLink = address ? buildMapsUrl(address) : '';
            const button = allowCalendar ? buildCalendarMenuHtml(paneId, event.id || '') : '';
            const rawDescription = event.description || '';
            const truncated = truncateDescription(rawDescription);
            return `
                <div class=\"eventItem\" data-event-id=\"${escapeHtml(event.id || '')}\" data-pane-id=\"${escapeHtml(paneId)}\">
                    <div class=\"eventItemTitle\">${escapeHtml(event.name || 'Untitled')}</div>
                    <div class=\"eventItemMeta\">${escapeHtml(timeRange)}</div>
                    ${address ? `<div class=\"eventItemMeta\"><a href=\"${escapeHtml(addressLink)}\" target=\"_blank\" rel=\"noopener\">${escapeHtml(address)}</a></div>` : ''}
                    ${details ? `<div class=\"eventItemMeta\">${escapeHtml(truncated)}</div>` : ''}
                    ${button}
                </div>
            `;
        }

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\"/g, '&quot;')
                .replace(/'/g, '&#39;');
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

            const isHappening = startTime <= next24h && endTime >= nowTime;
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

        const $happening = $pane.find('.eventHappening');
        const $upcomingBody = $pane.find('.eventUpcoming .eventSectionBody');
        const $pastColumn = $pane.find('.eventPast');
        const $pastBody = $pane.find('.eventPast .eventSectionBody');

        $happening.empty();
        $upcomingBody.empty();
        $pastBody.empty();

        const paneId = $pane.data('pane-id') || $container.attr('id') || '';

        if (happening.length) {
            happening.forEach((event) => $happening.append(renderEventItem(event, true, paneId)));
        } else {
            $happening.append('<div class=\"eventItem\">No events happening now.</div>');
        }

        if (upcoming.length) {
            upcoming.forEach((event) => $upcomingBody.append(renderEventItem(event, true, paneId)));
        } else {
            $upcomingBody.append('<div class=\"eventItem\">No upcoming events.</div>');
        }

        if (showPast && past.length) {
            past.slice(0, 5).forEach((event) => $pastBody.append(renderEventItem(event, false, paneId)));
            $pastColumn.removeClass('hidden');
        } else {
            $pastColumn.addClass('hidden');
        }

        if (!showPast) {
            $pane.find('.eventSplit').addClass('eventSplitSingle');
        } else {
            $pane.find('.eventSplit').removeClass('eventSplitSingle');
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
            $('.eventCalendarDropdown').addClass('hidden');
            $('.eventCalendarToggle').attr('aria-expanded', 'false');
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
                $address.html(`<a href=\"${escapeHtml(link)}\" target=\"_blank\" rel=\"noopener\">${escapeHtml(event.address)}</a>`);
            } else {
                $address.text('');
            }
            $description.html(event.descriptionHtml || '');
            if (allowCalendar) {
                $calendarMenu.removeClass('hidden');
                $calendarToggle.prop('disabled', false).removeClass('hidden');
                $calendarToggle.data('pane-id', paneId || '');
                $calendarToggle.data('event-id', event.id || '');
            } else {
                closeCalendarMenus();
                $calendarMenu.addClass('hidden');
                $calendarToggle.prop('disabled', true).addClass('hidden');
                $calendarToggle.data('pane-id', '');
                $calendarToggle.data('event-id', '');
            }
            closeCalendarMenus();
            $overlay.removeClass('hidden');
        }

        function closeModal() {
            closeCalendarMenus();
            $overlay.addClass('hidden');
        }

        $pane.off('click.eventModal').on('click.eventModal', '.eventItem', function() {
            const eventId = $(this).data('event-id') || '';
            if (!eventId) {
                return;
            }
            const allEvents = [].concat(happening, upcoming, past);
            const match = allEvents.find((item) => item && item.id === eventId);
            const allowCalendar = happening.concat(upcoming).some((item) => item && item.id === eventId);
            const itemPaneId = $(this).data('pane-id') || paneId;
            openModal(match, allowCalendar, itemPaneId);
        });

        $pane.off('click.eventCalendarToggle').on('click.eventCalendarToggle', '.eventCalendarToggle', function(event) {
            event.stopPropagation();
            const $menu = $(this).closest('.eventCalendarMenu');
            const isOpen = $menu.hasClass('isOpen');
            closeCalendarMenus();
            if (!isOpen) {
                $menu.addClass('isOpen');
                $menu.find('.eventCalendarDropdown').removeClass('hidden');
                $(this).attr('aria-expanded', 'true');
            }
        });

        $pane.off('click.eventCalendarOption').on('click.eventCalendarOption', '.eventCalendarOption', function(event) {
            event.stopPropagation();
            const provider = $(this).data('calendar-provider') || '';
            const $menu = $(this).closest('.eventCalendarMenu');
            const eventId = $menu.find('.eventCalendarToggle').data('event-id') || '';
            const actionPaneId = $menu.find('.eventCalendarToggle').data('pane-id') || paneId;
            const match = [].concat(happening, upcoming).find((item) => item && item.id === eventId);
            closeCalendarMenus();
            if (match) {
                performCalendarAction(provider, match, actionPaneId);
            }
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
            const modalEventId = $calendarToggle.data('event-id') || '';
            const modalPaneId = $calendarToggle.data('pane-id') || paneId;
            const match = [].concat(happening, upcoming).find((item) => item && item.id === modalEventId);
            closeCalendarMenus();
            if (match) {
                performCalendarAction(provider, match, modalPaneId);
            }
        });
    });
}

// Highlight the nav link corresponding to the current pane.
function updateNavActiveState() {
    // Remove any active state first.
    $('.navLink').removeClass('navActive');

    // Add active state to the link that matches the current pane.
    $(`.navLink[data-pane=\"${currentPane}\"]`).addClass('navActive');
}

// Toggle navbar layout based on whether its content exceeds the viewport width.
function updateNavBarLayout() {
    const navBar = $('#navBar');
    if (!navBar.length) {
        return;
    }

    navBar.removeClass('navBarFull');

    const barEl = navBar.get(0);
    if (!barEl) {
        return;
    }

    const viewWidth = document.documentElement.clientWidth;
    if (barEl.scrollWidth >= viewWidth) {
        navBar.addClass('navBarFull');
    }

    updateNavBarFades();
}

// Toggle nav fade indicators for scrollable navbars.
function updateNavBarFades() {
    const navBar = document.getElementById('navBar');
    const navBarWrap = document.getElementById('navBarWrap');
    if (!navBar || !navBarWrap) {
        return;
    }

    navBarWrap.classList.remove('navFadeLeft', 'navFadeRight');

    if (!navBar.classList.contains('navBarFull')) {
        return;
    }

    const maxScroll = navBar.scrollWidth - navBar.clientWidth;
    if (maxScroll <= 1) {
        return;
    }

    if (navBar.scrollLeft > 0) {
        navBarWrap.classList.add('navFadeLeft');
    }
    if (navBar.scrollLeft < maxScroll - 1) {
        navBarWrap.classList.add('navFadeRight');
    }
}

// Helper: default pane for mobile (first nav entry).
function getDefaultMobilePane() {
    if (paneOrder.includes('users')) {
        return 'users';
    }
    return paneOrder[0] || 'links';
}

// Helper: default pane for desktop (second nav entry if present, else first).
function getDefaultDesktopPane() {
    const linksHidden = document.body && document.body.classList.contains('linksHidden');
    if (linksHidden) {
        return paneOrder[0] || 'about';
    }
    if (paneOrder.includes('users')) {
        return 'users';
    }
    return paneOrder[1] || paneOrder[0] || 'about';
}

// Lock layout height to the visual viewport (iOS Safari safe).
function setAppHeight() {
    const vv = window.visualViewport;
    const height = vv ? vv.height : window.innerHeight;
    document.documentElement.style.setProperty('--app-height', `${height}px`);
}

// Fetch favicons for link targets and apply them as CSS background images.
function setLinkFavicons() {
    const links = Array.from(document.querySelectorAll('.linkList > li > a[href]'));
    if (!links.length) {
        return;
    }

    const domains = collectDomainsFromLinks(links);
    if (!domains.length) {
        return;
    }

    fetch('res/scr/favicon.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ domains }),
    })
        .then((response) => (response.ok ? response.json() : null))
        .then((data) => {
            if (!data || !data.icons) {
                return;
            }

            Object.keys(data.icons).forEach((domain) => {
                const icon = data.icons[domain] && data.icons[domain].icon;
                if (icon) {
                    faviconCache.set(domain, icon);
                }
            });

            applyFaviconsToLinks(links, data.icons);
        })
        .catch(() => {});
}

// Normalize link hrefs into http/https URLs (ignoring mailto/tel/hash).
function normalizeHttpUrl(href) {
    if (!href || href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('tel:')) {
        return null;
    }

    try {
        const trimmed = href.trim();
        const needsScheme = !/^https?:\/\//i.test(trimmed) && !trimmed.startsWith('//');
        const candidate = needsScheme && !trimmed.startsWith('/') ? `https://${trimmed}` : trimmed;
        const url = new URL(candidate, window.location.href);
        if (url.protocol !== 'http:' && url.protocol !== 'https:') {
            return null;
        }
        return url.href;
    } catch (error) {
        return null;
    }
}

// Return the host portion of a URL, or null if parsing fails.
function getHostKey(url) {
    try {
        return new URL(url).host;
    } catch (error) {
        return null;
    }
}

// Collect unique base domains from link elements.
function collectDomainsFromLinks(links) {
    const domains = new Set();
    links.forEach((link) => {
        const href = link.getAttribute('href') || '';
        const normalized = normalizeHttpUrl(href);
        if (!normalized) {
            return;
        }

        const hostKey = getHostKey(normalized);
        if (!hostKey) {
            return;
        }

        const baseDomain = hostKey.replace(/^www\./i, '');
        if (baseDomain) {
            domains.add(baseDomain);
        }
    });

    return Array.from(domains);
}

// Apply cached or fetched favicons to link elements.
function applyFaviconsToLinks(links, iconMap) {
    links.forEach((link) => {
        const href = link.getAttribute('href') || '';
        const normalized = normalizeHttpUrl(href);
        if (!normalized) {
            return;
        }

        const hostKey = getHostKey(normalized);
        if (!hostKey) {
            return;
        }

        const baseDomain = hostKey.replace(/^www\./i, '');
        const entry = iconMap[baseDomain];
        const iconUrl = entry && entry.icon ? entry.icon : faviconCache.get(baseDomain);
        if (iconUrl) {
            link.style.setProperty('--link-icon', `url('${iconUrl}')`);
        }
    });
}

// Ensure we update the layout as soon as the page loads.
$(document).ready(function() {
    init();
});

// Fade in content after all assets have loaded.
$(window).on('load', function() {
    document.body.classList.remove('is-loading');
});
