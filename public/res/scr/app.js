// Lawnding Page JS scaffolding

// Define the width in which we toggle mobile or desktop view modes. Initialize the mode and currentPane variables.
const BREAKPOINT = 979;
let mode = null;
let currentPane = null;
let paneOrder = [];
const faviconCache = new Map();

// Helper: ensure we aren't trying to show the always-visible pane on desktop.
function ensureDesktopPaneSelection() {
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

// Helper: show/hide the Links nav item based on mode.
function toggleLinksNav(show) {
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

    // Capture the pane order from nav links for default selection logic.
    paneOrder = navLinks.map(function() {
        return $(this).data('pane');
    }).get();

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

    // Randomize the body background image from the JSON-provided list while preserving other background styles.
    setRandomBackground();

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
        event.preventDefault();

        // Determine which pane this nav link is responsible for and update state.
        const targetPane = $(this).data('pane');
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

    // check the mode and apply layout based on that.
    if (mode === 'desktop') {
        // If we change from mobile to desktop mode, and we're viewing the first nav pane, force to the second since the first is always visible.
        ensureDesktopPaneSelection();

        // Show the links pane plus the current content pane.
        updatePaneVisibility(panes, new Set(['links', currentPane]));

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

// Pick a random body background image from headerData.backgrounds while keeping other background properties intact.
function setRandomBackground() {
    if (!window.headerData) {
        document.body.style.setProperty('--bg-image', 'linear-gradient(#00000055)');
        document.body.classList.add('bg-ready');
        return;
    }

    const rawBackgrounds = Array.isArray(window.headerData.backgrounds)
        ? window.headerData.backgrounds
        : [];

    // Normalize entries to objects with url + author.
    const backgrounds = rawBackgrounds
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

    const chosen = backgrounds.length > 0 ? backgrounds[Math.floor(Math.random() * backgrounds.length)] : null;
    if (!chosen) {
        document.body.style.setProperty('--bg-image', 'linear-gradient(#00000055)');
        document.body.classList.add('bg-ready');
        return;
    }

    // Preserve existing gradient by setting the layered background-image only.
    document.body.style.setProperty('--bg-image', `linear-gradient(#00000055), url('${chosen.url}')`);
    document.body.classList.add('bg-ready');

    // Update the footer with the background author (fallback to 'anonymous' if missing).
    const author = chosen.author && chosen.author.trim().length > 0 ? chosen.author.trim() : 'anonymous';
    const authorUrl = normalizeExternalUrl(chosen.authorUrl);
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

        function buildIcsContent(event) {
            const communityName = $('#header .headline h1').text().trim() || 'LawndingPage';
            const orgName = sanitizeId(communityName) || 'LawndingPage';
            const name = String(event.name || '').trim() || 'Event';
            const startDate = event.startDate || event.date || '';
            const startTime = event.startTime || '';
            const endDate = event.endDate || startDate;
            const endTime = event.endTime || '';
            if (!startDate || !startTime) {
                return '';
            }
            const tzName = (event.timeZone || Intl.DateTimeFormat().resolvedOptions().timeZone || '').trim();
            const dtStart = new Date(`${startDate}T${startTime}`);
            const dtEnd = endTime ? new Date(`${endDate}T${endTime}`) : new Date(dtStart.getTime() + 60 * 60 * 1000);
            if (isNaN(dtStart.getTime()) || isNaN(dtEnd.getTime())) {
                return '';
            }
            const formatLocal = (date) => {
                const pad = (num) => String(num).padStart(2, '0');
                return `${date.getFullYear()}${pad(date.getMonth() + 1)}${pad(date.getDate())}T${pad(date.getHours())}${pad(date.getMinutes())}${pad(date.getSeconds())}`;
            };
            const dtStamp = (() => {
                const now = new Date();
                const pad = (num) => String(num).padStart(2, '0');
                return `${now.getUTCFullYear()}${pad(now.getUTCMonth() + 1)}${pad(now.getUTCDate())}T${pad(now.getUTCHours())}${pad(now.getUTCMinutes())}${pad(now.getUTCSeconds())}Z`;
            })();
            const uidName = sanitizeId(name) || sanitizeId(event.id || '') || 'event';
            const uidDate = `${dtStart.getFullYear()}${String(dtStart.getMonth() + 1).padStart(2, '0')}${String(dtStart.getDate()).padStart(2, '0')}`;
            const uid = `${uidName}@${orgName}-${uidDate}.lawndingpage`;
            const prodId = `-//${orgName}//LawndingPage//EN`;
            const lines = [
                'BEGIN:VCALENDAR',
                'VERSION:2.0',
                `PRODID:${prodId}`,
                'CALSCALE:GREGORIAN',
                'METHOD:PUBLISH',
                'BEGIN:VEVENT',
                icsFold(`UID:${icsEscape(uid)}`),
                `DTSTAMP:${dtStamp}`,
                tzName ? `DTSTART;TZID=${tzName}:${formatLocal(dtStart)}` : `DTSTART:${formatLocal(dtStart)}`,
                tzName ? `DTEND;TZID=${tzName}:${formatLocal(dtEnd)}` : `DTEND:${formatLocal(dtEnd)}`,
                icsFold(`SUMMARY:${icsEscape(name)}`),
                event.address ? icsFold(`LOCATION:${icsEscape(event.address)}`) : null,
                event.description ? icsFold(`DESCRIPTION:${icsEscape(event.description)}`) : null,
                'END:VEVENT',
                'END:VCALENDAR'
            ].filter(Boolean);
            return lines.join('\r\n') + '\r\n';
        }

        function downloadIcs(event) {
            const icsContent = buildIcsContent(event);
            if (!icsContent) {
                return;
            }
            const fileBase = String(event.id || 'event').replace(/[^A-Za-z0-9_-]/g, '') || 'event';
            const blob = new Blob([icsContent], { type: 'text/calendar;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `${fileBase}.ics`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }

        function renderEventItem(event, allowCalendar, paneId) {
            const timeRange = formatEventRange(event);
            const details = event.descriptionHtml ? event.descriptionHtml : '';
            const address = event.address || '';
            const addressLink = address ? buildMapsUrl(address) : '';
            const calendarLabel = 'Save to Calendar';
            const button = allowCalendar
                ? `<button class=\"eventCalendarButton\" type=\"button\" data-pane-id=\"${escapeHtml(paneId)}\" data-event-id=\"${escapeHtml(event.id || '')}\">${calendarLabel}</button>`
                : '';
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
        const $calendar = $('#eventModalCalendar');
        const $close = $('#eventModalClose');

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
                $calendar.prop('disabled', false).removeClass('hidden');
                $calendar.data('pane-id', paneId || '');
                $calendar.data('event-id', event.id || '');
            } else {
                $calendar.prop('disabled', true).addClass('hidden');
                $calendar.data('pane-id', '');
                $calendar.data('event-id', '');
            }
            $overlay.removeClass('hidden');
        }

        function closeModal() {
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

        $pane.off('click.eventCalendar').on('click.eventCalendar', '.eventCalendarButton', function(event) {
            event.stopPropagation();
            const buttonEventId = $(this).data('event-id') || '';
            if (!buttonEventId) {
                return;
            }
            const match = [].concat(happening, upcoming).find((item) => item && item.id === buttonEventId);
            if (match) {
                downloadIcs(match);
            }
        });

        $pane.off('click.eventAddress').on('click.eventAddress', '.eventItem a', function(event) {
            event.stopPropagation();
        });

        $overlay.off('click.eventModalClose').on('click.eventModalClose', function(event) {
            if (event.target === this) {
                closeModal();
            }
        });

        $close.off('click.eventModalClose').on('click.eventModalClose', function() {
            closeModal();
        });

        $calendar.off('click.eventCalendar').on('click.eventCalendar', function() {
            const modalEventId = $calendar.data('event-id') || '';
            if (!modalEventId) {
                return;
            }
            const match = [].concat(happening, upcoming).find((item) => item && item.id === modalEventId);
            if (match) {
                downloadIcs(match);
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
    return paneOrder[0] || 'links';
}

// Helper: default pane for desktop (second nav entry if present, else first).
function getDefaultDesktopPane() {
    if (paneOrder.includes('bg')) {
        return 'bg';
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
