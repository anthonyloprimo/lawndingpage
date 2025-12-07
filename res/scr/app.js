// Lawnding Page JS scaffolding

// Define the width in which we toggle mobile or desktop view modes. Initialize the mode and currentPane variables.
const BREAKPOINT = 979;
let mode = null;
let currentPane = null;
let paneOrder = [];

// Returns either 'desktop' or 'mobile' as the view mode.  Allows easy hooking into mode-based features on the page.
function getMode() {
    return window.innerWidth > BREAKPOINT ? 'desktop' : 'mobile';
}

// On first run, do all this.
function init() {
    // store jquery references as constants
    const panes = $('.pane');
    const navLinks = $('.navLink');

    // Hide the noscript warning if JS is enabled.  How can we hide it with this code if JS isn't running?  Well if JS WASN'T running, you wouldn't be trying to hide it now would you? :3
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

    // Set the header logo background image from JSON-provided data.
    setLogoBackground();

    // Randomize the body background image from the JSON-provided list while preserving other background styles.
    setRandomBackground();

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
                const firstPane = paneOrder[0];
                const secondPane = getDefaultDesktopPane();
                if (currentPane === firstPane && secondPane) {
                    currentPane = secondPane;
                }
            } else {
                // On mobile, keep current pane as-is.
                currentPane = previousPane;
            }

            console.log(`mode changed: ${mode}`);
            applyLayout();
            updateNavActiveState();
        }
    });
}

// Apply layout
function applyLayout() {
    // easily store jquery reference to a constant.
    const panes = $('.pane');

    // check the mode and apply layout based on that.
    if (mode === 'desktop') {
        // If we change from mobile to desktop mode, and we're viewing the first nav pane, force to the second since the first is always visible.
        const firstPane = paneOrder[0];
        const secondPane = getDefaultDesktopPane();
        if (currentPane === firstPane && secondPane) {
            currentPane = secondPane;
        }

        // See which pane is current and show them (remove the hidden class), otherwise hide them.
        panes.each(function() {
            const pane = $(this);
            const id = pane.attr('id');
            if (id === 'links' || id === currentPane) {
                pane.removeClass('hidden');
            } else {
                pane.addClass('hidden');
            }
        });

        // Hide the Links nav item on desktop (links pane is always visible already).
        $('.navLink[data-pane="links"]').addClass('hidden');
    } else {  // if we aren't in desktop mode, we're in mobile mode.
        panes.each(function() {
            // Get the ID of the current pane we're viewing and as long as it's the same as the actual current pane, show it.  Otherwise, hide it.
            const pane = $(this);
            const id = pane.attr('id');
            if (id === currentPane) {
                pane.removeClass('hidden');
            } else {
                pane.addClass('hidden');
            }
        });

        // Show the Links nav item on mobile so users can navigate to it.
        $('.navLink[data-pane="links"]').removeClass('hidden');
    }

    console.log(`applyLayout(): mode=${mode}, currentPane=${currentPane}`);
}

// Applies the logo background image using data from PHP-injected global.
function setLogoBackground() {
    // The PHP template injects window.headerData; bail if missing.
    if (!window.headerData || !window.headerData.logo) {
        return;
    }

    $('#logo').css('background-image', `url('${window.headerData.logo}')`);
}

// Picks a random body background image from headerData.backgrounds while keeping other background properties intact.
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
                return { url: bg, author: '' };
            }
            if (bg && typeof bg === 'object' && typeof bg.url === 'string') {
                return { url: bg.url, author: typeof bg.author === 'string' ? bg.author : '' };
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
    $('.authorName').text(author);
}

// Highlight the nav link corresponding to the current pane.
function updateNavActiveState() {
    // Remove any active state first.
    $('.navLink').removeClass('navActive');

    // Add active state to the link that matches the current pane.
    $(`.navLink[data-pane=\"${currentPane}\"]`).addClass('navActive');
}

// Helper: default pane for mobile (first nav entry).
function getDefaultMobilePane() {
    return paneOrder[0] || 'links';
}

// Helper: default pane for desktop (second nav entry if present, else first).
function getDefaultDesktopPane() {
    return paneOrder[1] || paneOrder[0] || 'about';
}

// Ensures we update the layout as soon as the page loads.
$(document).ready(function() {
    init();
});
