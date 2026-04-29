<?php
require_once __DIR__ . '/bootstrap.php';

// ----- lawnding_image_resize_dimensions -----

// maxbox: fit inside box, never upscale, never crop
test_assert(
    lawnding_image_resize_dimensions(2000, 1500, 1920, 10000, 'maxbox') === [1920, 1440, 0, 0, 2000, 1500],
    'maxbox: 2000x1500 in 1920x10000 box -> 1920x1440'
);
test_assert(
    lawnding_image_resize_dimensions(800, 600, 1920, 10000, 'maxbox') === [800, 600, 0, 0, 800, 600],
    'maxbox: under-sized image passes through (no upscale)'
);
test_assert(
    lawnding_image_resize_dimensions(1920, 10000, 1920, 10000, 'maxbox') === [1920, 10000, 0, 0, 1920, 10000],
    'maxbox: exactly-target dims pass through'
);
test_assert(
    lawnding_image_resize_dimensions(4000, 1000, 1920, 10000, 'maxbox') === [1920, 480, 0, 0, 4000, 1000],
    'maxbox: oversize-on-width axis only triggers proportional resize'
);

// contain: letterbox inside box (output dimensions <= target on at least one axis)
test_assert(
    lawnding_image_resize_dimensions(1600, 900, 400, 400, 'contain') === [400, 225, 0, 0, 1600, 900],
    'contain: 16:9 in 1:1 box -> 400x225 (h-letterbox)'
);
test_assert(
    lawnding_image_resize_dimensions(900, 1600, 400, 400, 'contain') === [225, 400, 0, 0, 900, 1600],
    'contain: portrait in 1:1 box -> 225x400 (v-letterbox)'
);
test_assert(
    lawnding_image_resize_dimensions(800, 800, 400, 400, 'contain') === [400, 400, 0, 0, 800, 800],
    'contain: square in square box -> exact target'
);

// cover: fill box exactly, center-crop overflow axis
test_assert(
    lawnding_image_resize_dimensions(1600, 900, 400, 400, 'cover') === [400, 400, 350, 0, 900, 900],
    'cover: 16:9 in 1:1 box -> 400x400 with horizontal center-crop'
);
test_assert(
    lawnding_image_resize_dimensions(900, 1600, 400, 400, 'cover') === [400, 400, 0, 350, 900, 900],
    'cover: portrait in 1:1 box -> 400x400 with vertical center-crop'
);
test_assert(
    lawnding_image_resize_dimensions(800, 800, 400, 400, 'cover') === [400, 400, 0, 0, 800, 800],
    'cover: already-square source needs no crop offset'
);
test_assert(
    lawnding_image_resize_dimensions(400, 400, 400, 400, 'cover') === [400, 400, 0, 0, 400, 400],
    'cover: source equals target -> identity'
);

// edge cases / defensive
test_assert(
    lawnding_image_resize_dimensions(0, 0, 400, 400, 'cover') === null,
    'zero source dims -> null'
);
test_assert(
    lawnding_image_resize_dimensions(800, 600, 0, 400, 'cover') === null,
    'zero target width -> null'
);
test_assert(
    lawnding_image_resize_dimensions(800, 600, 400, 400, 'unknown_mode') === null,
    'unknown mode -> null'
);
test_assert(
    lawnding_image_resize_dimensions(-100, 600, 400, 400, 'cover') === null,
    'negative source dim -> null'
);

// ----- lawnding_image_resize_output_format -----

test_assert(
    lawnding_image_resize_output_format('thumb.webp', true) === 'webp',
    'webp ext + GD has webp -> webp'
);
test_assert(
    lawnding_image_resize_output_format('thumb.webp', false) === null,
    'webp ext + GD lacks webp -> null (caller fallback signal)'
);
test_assert(
    lawnding_image_resize_output_format('thumb.jpg', true) === 'jpeg',
    'jpg ext -> jpeg'
);
test_assert(
    lawnding_image_resize_output_format('thumb.jpeg', true) === 'jpeg',
    'jpeg ext -> jpeg'
);
test_assert(
    lawnding_image_resize_output_format('thumb.JPG', true) === 'jpeg',
    'uppercase ext is case-insensitive'
);
test_assert(
    lawnding_image_resize_output_format('thumb.png', false) === 'png',
    'png ext is independent of webp support'
);
test_assert(
    lawnding_image_resize_output_format('thumb.gif', true) === 'gif',
    'gif ext -> gif'
);
test_assert(
    lawnding_image_resize_output_format('noext', true) === null,
    'no extension -> null'
);
test_assert(
    lawnding_image_resize_output_format('thumb.bmp', true) === null,
    'unsupported ext -> null'
);
