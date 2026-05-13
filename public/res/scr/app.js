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

    // rAF-coalesce: collapse rapid-fire calls to one per animation frame.
    // Used to wrap resize/scroll handlers so a fast drag-resize or scroll
    // burst doesn't run the same layout work 60+ times per second.
    function rafCoalesce(fn) {
        let queued = 0;
        return function () {
            if (queued) return;
            queued = requestAnimationFrame(() => {
                queued = 0;
                fn();
            });
        };
    }

    // Lock layout height to the visible viewport on iOS Safari.
    setAppHeight();
    const onAppHeightChange = rafCoalesce(setAppHeight);
    window.addEventListener('resize', onAppHeightChange);
    window.addEventListener('orientationchange', onAppHeightChange);
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', onAppHeightChange);
        window.visualViewport.addEventListener('scroll', onAppHeightChange);
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

    const onNavBarFades = rafCoalesce(updateNavBarFades);
    $('#navBar').on('scroll', onNavBarFades);
    window.addEventListener('resize', onNavBarFades);

    // Any time the window is resized, check to see if we're still in the same mode or not.
    const onWindowResize = rafCoalesce(function() {
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
    $(window).on('resize', onWindowResize);
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

// Notice banners — public-side thin wrapper around the shared manager in
// notice-core.js. The admin panel (config.js) has its own wrapper using the
// same factory.
const lpNoticeManager = window.lpNoticeFactory();

function lpAddNotice(type, text, options) {
    return lpNoticeManager.add(type, text, options);
}

function lpBindNotices() {
    return lpNoticeManager.bind();
}

window.lpAddNotice = lpAddNotice;

// Changelog modal — shell delegated to public-modals.js (factory).
function lpBindChangelog() {
    var $modal = $('#changelogModal');
    if (!$modal.length || !window.openPublicModal) { return; }
    $(document).on('click.changelog', '[data-changelog-trigger]', function(e) {
        e.preventDefault();
        window.openPublicModal($modal);
    });
    $(document).on('click.changelog', '.changelogModalClose, .changelogModalBackdrop', function() {
        window.closePublicModal($modal);
    });
}

// Ensure we update the layout as soon as the page loads.
$(document).ready(function() {
    init();
    lpBindNotices();
    lpBindChangelog();
});

// Fade in content after all assets have loaded.
$(window).on('load', function() {
    document.body.classList.remove('is-loading');
});
