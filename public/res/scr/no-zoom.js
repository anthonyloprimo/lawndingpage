// Disable pinch-zoom on touch devices while preserving single-finger scrolling.
(function() {
    if (!('ontouchstart' in window) && !(navigator.maxTouchPoints > 0)) {
        return;
    }

    function preventPinch(event) {
        if (event.touches && event.touches.length > 1) {
            event.preventDefault();
        }
        if (typeof event.scale === 'number' && event.scale !== 1) {
            event.preventDefault();
        }
    }

    document.addEventListener('touchstart', preventPinch, { passive: false });
    document.addEventListener('touchmove', preventPinch, { passive: false });
    document.addEventListener('gesturestart', preventPinch, { passive: false });
    document.addEventListener('gesturechange', preventPinch, { passive: false });
    document.addEventListener('gestureend', preventPinch, { passive: false });
})();
