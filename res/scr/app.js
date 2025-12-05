// Lawnding Page JS scaffolding

// Define the width in which we toggle mobile or desktop view modes. Initialize the mode and currentPane variables.
const BREAKPOINT = 980;
let mode = null;
let currentPane = null;

// Returns either 'desktop' or 'mobile' as the view mode.  Allows easy hooking into mode-based features on the page.
function getMode() {
    return window.innerWidth >= BREAKPOINT ? 'desktop' : 'mobile';
}

// On first run, do all this.
function init() {
    // store jquery references as constants
    const panes = $('.pane');
    const navLinks = $('.navLink');

    // Hide the noscript warning if JS is enabled.  How can we hide it with this code if JS isn't running?  Well if JS WASN'T running, you wouldn't be trying to hide it now would you? :3
    $('#noJsWarning').hide();

    // Set the mode based on the getmode function, determine the default view (pane) on the mode - desktop mode shows about, mobile shows links.  This is because in dekstop mode, links are always displayed on the left side.
    mode = getMode();
    currentPane = mode === 'desktop' ? 'about' : 'links';
    console.log(`init(): mode=${mode}, panes=${panes.length}, navLinks=${navLinks.length}`);

    // Apply the layout based on the above.
    applyLayout();
    updateNavActiveState();

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

            // When moving to desktop, force the secondary pane to 'about' if we were on 'links' (links is always visible on desktop).
            if (mode === 'desktop' && currentPane === 'links') {
                currentPane = 'about';
            }
            // When moving to mobile, keep the current pane as-is (no reset to links).
            if (mode === 'mobile') {
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
        // If we change from mobile to desktop mode, and we're viewing links, we force the current pane to be about since links are always shown.
        if (currentPane === 'links') {
            currentPane = 'about';
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

// Highlight the nav link corresponding to the current pane.
function updateNavActiveState() {
    // Remove any active state first.
    $('.navLink').removeClass('navActive');

    // Add active state to the link that matches the current pane.
    $(`.navLink[data-pane=\"${currentPane}\"]`).addClass('navActive');
}

// Ensures we update the layout as soon as the page loads.
$(document).ready(function() {
    init();
});
