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
        return;
    }

    // Preserve existing gradient by setting the layered background-image only.
    $('body').css('background-image', `linear-gradient(#00000055), url('${chosen.url}')`);

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
