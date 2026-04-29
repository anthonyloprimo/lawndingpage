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

// ----- Integration tests (require GD) -----
// Pin behavior the pure helpers can't reach. The initial engine refactor
// regressed byte-exact passthrough for under-cap uploads because the
// extension-comparison check never matched for upload tempfiles; the fix
// detects source format from the bytes. These tests prevent that regression
// from drifting back in.

if (extension_loaded('gd')) {
    $tmpDir = sys_get_temp_dir();

    // (1) Passthrough: under-cap JPEG source -> byte-for-byte to JPEG dest.
    $smallSrc = $tmpDir . '/lp-test-small-' . uniqid() . '.jpg';
    $smallDst = $tmpDir . '/lp-test-small-dst-' . uniqid() . '.jpg';
    $im = imagecreatetruecolor(100, 100);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 100, 50));
    imagejpeg($im, $smallSrc, 85);
    imagedestroy($im);
    $smallSrcBytes = file_get_contents($smallSrc);

    test_assert(
        lawnding_image_resize($smallSrc, $smallDst, 1920, 10000, 'maxbox') === true,
        'passthrough: maxbox call on under-cap source returns true'
    );
    test_assert(
        file_get_contents($smallDst) === $smallSrcBytes,
        'passthrough: under-cap source written byte-for-byte (no GD re-encode)'
    );

    @unlink($smallSrc);
    @unlink($smallDst);

    // (2) Resize: over-cap source -> bytes differ, output is at cap width.
    $bigSrc = $tmpDir . '/lp-test-big-' . uniqid() . '.jpg';
    $bigDst = $tmpDir . '/lp-test-big-dst-' . uniqid() . '.jpg';
    $im = imagecreatetruecolor(2000, 1500);
    imagefill($im, 0, 0, imagecolorallocate($im, 50, 100, 200));
    imagejpeg($im, $bigSrc, 85);
    imagedestroy($im);
    $bigSrcBytes = file_get_contents($bigSrc);

    test_assert(
        lawnding_image_resize($bigSrc, $bigDst, 1920, 10000, 'maxbox') === true,
        'resize: maxbox call on over-cap source returns true'
    );
    $bigDstBytes = file_get_contents($bigDst);
    test_assert(
        $bigSrcBytes !== $bigDstBytes,
        'resize: over-cap source is re-encoded (bytes differ from source)'
    );
    $bigDstInfo = getimagesizefromstring($bigDstBytes);
    test_assert(
        $bigDstInfo !== false && $bigDstInfo[0] === 1920,
        'resize: over-cap source is resized to cap width (1920)'
    );

    @unlink($bigSrc);
    @unlink($bigDst);

    // (3) Format conversion: src is JPEG, dest is PNG -> re-encode (no passthrough).
    $jpegSrc = $tmpDir . '/lp-test-jpeg-' . uniqid() . '.jpg';
    $pngDst  = $tmpDir . '/lp-test-png-dst-' . uniqid() . '.png';
    $im = imagecreatetruecolor(100, 100);
    imagefill($im, 0, 0, imagecolorallocate($im, 100, 200, 50));
    imagejpeg($im, $jpegSrc, 85);
    imagedestroy($im);
    $jpegBytes = file_get_contents($jpegSrc);

    test_assert(
        lawnding_image_resize($jpegSrc, $pngDst, 1920, 10000, 'maxbox') === true,
        'format conversion: maxbox call returns true even when src/dest formats differ'
    );
    $pngDstBytes = file_get_contents($pngDst);
    test_assert(
        $jpegBytes !== $pngDstBytes,
        'format conversion: JPEG src does not pass through into PNG dest'
    );
    test_assert(
        substr($pngDstBytes, 0, 8) === "\x89PNG\r\n\x1a\n",
        'format conversion: dest file has PNG magic header'
    );

    @unlink($jpegSrc);
    @unlink($pngDst);
}
