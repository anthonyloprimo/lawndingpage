<?php
// Tests for lawnding_is_safe_svg() — the minimal SVG sanitizer.
require_once __DIR__ . '/bootstrap.php';

// Valid SVGs pass through.
test_assert(lawnding_is_safe_svg('<svg viewBox="0 0 24 24"><path d="M10,20V14H14V20H19V12H22L12,3L2,12H5V20H10Z"/></svg>') === true, 'standard SVG passes');
test_assert(lawnding_is_safe_svg('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><circle cx="24" cy="24" r="20"/></svg>') === true, 'SVG with xmlns passes');

// Empty / null rejected.
test_assert(lawnding_is_safe_svg('') === false, 'empty string rejected');
test_assert(lawnding_is_safe_svg(null) === false, 'null rejected');

// <script> tag rejected (case-insensitive).
test_assert(lawnding_is_safe_svg('<svg><script>alert(1)</script></svg>') === false, 'lowercase script tag rejected');
test_assert(lawnding_is_safe_svg('<svg><SCRIPT>alert(1)</SCRIPT></svg>') === false, 'uppercase SCRIPT tag rejected');
test_assert(lawnding_is_safe_svg('<svg><Script>alert(1)</Script></svg>') === false, 'mixed-case Script tag rejected');

// Inline event handlers rejected.
test_assert(lawnding_is_safe_svg('<svg onclick="alert(1)"><path/></svg>') === false, 'onclick attribute rejected');
test_assert(lawnding_is_safe_svg('<svg onload="alert(1)"><path/></svg>') === false, 'onload attribute rejected');
test_assert(lawnding_is_safe_svg('<svg onmouseover="alert(1)"><path/></svg>') === false, 'onmouseover attribute rejected');
test_assert(lawnding_is_safe_svg('<svg ONCLICK="alert(1)"><path/></svg>') === false, 'uppercase ONCLICK rejected');
test_assert(lawnding_is_safe_svg('<svg onFocus="x"><path/></svg>') === false, 'onfocus attribute rejected');

// on* in attribute values (not as attribute names) should not trigger false positives.
test_assert(lawnding_is_safe_svg('<svg><text>click online</text></svg>') === true, 'on* in text content is not a handler');
test_assert(lawnding_is_safe_svg('<svg data-onclick="foo"><path/></svg>') === true, 'data-onclick is not an event handler');
