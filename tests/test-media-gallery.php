<?php
require_once __DIR__ . '/bootstrap.php';
require_once lawnding_admin_path('modules/mediaGallery/helpers.php');

// ----- media_gallery_focal_crop_window (pure math) -----

// Centered focal point reproduces the engine's center-cropped cover offsets
test_assert(
    media_gallery_focal_crop_window(900, 1600, 400, 400, 0.5, 0.5) === [0, 350, 900, 900],
    'focal=(0.5,0.5) on 900x1600 portrait -> centered vertical crop, same as default cover'
);
test_assert(
    media_gallery_focal_crop_window(1600, 900, 400, 400, 0.5, 0.5) === [350, 0, 900, 900],
    'focal=(0.5,0.5) on 1600x900 landscape -> centered horizontal crop'
);
test_assert(
    media_gallery_focal_crop_window(800, 800, 400, 400, 0.5, 0.5) === [0, 0, 800, 800],
    'focal=(0.5,0.5) on already-square source -> no offset'
);

// Anchored crops slide the window toward the named edge
test_assert(
    media_gallery_focal_crop_window(900, 1600, 400, 400, 0.5, 0.0) === [0, 0, 900, 900],
    'focal=(0.5,0.0) on portrait -> top-anchored crop'
);
test_assert(
    media_gallery_focal_crop_window(900, 1600, 400, 400, 0.5, 1.0) === [0, 700, 900, 900],
    'focal=(0.5,1.0) on portrait -> bottom-anchored crop'
);
test_assert(
    media_gallery_focal_crop_window(1600, 900, 400, 400, 0.0, 0.5) === [0, 0, 900, 900],
    'focal=(0.0,0.5) on landscape -> left-anchored crop'
);
test_assert(
    media_gallery_focal_crop_window(1600, 900, 400, 400, 1.0, 0.5) === [700, 0, 900, 900],
    'focal=(1.0,0.5) on landscape -> right-anchored crop'
);

// Out-of-range focal coords clamp to [0, 1]
test_assert(
    media_gallery_focal_crop_window(900, 1600, 400, 400, 1.5, -0.5)
    === media_gallery_focal_crop_window(900, 1600, 400, 400, 1.0, 0.0),
    'out-of-range focal coords clamp to [0, 1]'
);

// The actual cat case: 660x1280 portrait, focal_y=0.25 (head area).
// Crop window slides toward the top but clamps at y=0 since the focal
// point is too close to the edge for the window to slide further.
test_assert(
    media_gallery_focal_crop_window(660, 1280, 400, 400, 0.5, 0.25) === [0, 0, 660, 660],
    'focal=(0.5,0.25) on 660x1280 cat photo -> upper square crop (head + body visible)'
);
// Compare with what the engine's centered cover produced (the broken case)
test_assert(
    media_gallery_focal_crop_window(660, 1280, 400, 400, 0.5, 0.5) === [0, 310, 660, 660],
    'focal=(0.5,0.5) on cat photo reproduces engine centered cover (310px from top — head cropped off)'
);

// Square source: focal point can't shift the crop because there's no overflow
test_assert(
    media_gallery_focal_crop_window(800, 800, 400, 400, 0.0, 1.0) === [0, 0, 800, 800],
    'square source -> no offset even with extreme focal'
);

// Source smaller than target: cover upscales, crop window centered in source
test_assert(
    media_gallery_focal_crop_window(200, 400, 400, 400, 0.5, 0.5) === [0, 100, 200, 200],
    'source smaller than target -> crop window centered in source coords'
);

// Defensive: zero/negative dimensions return safe defaults instead of crashing
test_assert(
    media_gallery_focal_crop_window(0, 0, 400, 400, 0.5, 0.5) === [0, 0, 1, 1],
    'zero src dims -> safe (1, 1) crop window'
);
test_assert(
    media_gallery_focal_crop_window(900, 1600, 0, 0, 0.5, 0.5) === [0, 0, 900, 1600],
    'zero target dims -> safe full-source window'
);

// ----- media_gallery_focal_crop_to_temp (GD pipeline) -----

if (test_check_extension('gd')) {
    // Build a synthetic source PNG (660x1280 with a colored top region so
    // we can assert the focal-aware crop kept the right pixels).
    $synthSrc = imagecreatetruecolor(660, 1280);
    $top = imagecolorallocate($synthSrc, 200, 50, 50);     // red-ish top half
    $bottom = imagecolorallocate($synthSrc, 50, 50, 200);  // blue-ish bottom half
    imagefilledrectangle($synthSrc, 0, 0, 660, 640, $top);
    imagefilledrectangle($synthSrc, 0, 640, 660, 1280, $bottom);
    $tmpSrcPath = tempnam(sys_get_temp_dir(), 'lp-test-src-') . '.png';
    imagepng($synthSrc, $tmpSrcPath);
    imagedestroy($synthSrc);

    // Top-anchored focal: cropped intermediate should be the upper 660x660,
    // which is fully red (no blue pixels because the bottom half is at y>=640
    // and the crop window is y=0..660).
    $tempPath = media_gallery_focal_crop_to_temp($tmpSrcPath, 400, 400, 0.5, 0.0);
    test_assert(is_string($tempPath) && is_readable($tempPath), 'focal_crop_to_temp top-anchored returns a readable temp path');
    if (is_string($tempPath) && is_readable($tempPath)) {
        $cropped = imagecreatefrompng($tempPath);
        test_assert($cropped !== false, 'cropped intermediate decodes as PNG');
        if ($cropped !== false) {
            test_assert(imagesx($cropped) === 660 && imagesy($cropped) === 660, 'cropped intermediate is 660x660 (the cover-window size for 660x1280 -> 400x400)');
            // Sample a pixel near the top-center: expect the red top color
            $rgb = imagecolorat($cropped, 330, 50);
            $r = ($rgb >> 16) & 0xFF;
            $b = $rgb & 0xFF;
            test_assert($r > 100 && $b < 100, 'top-anchored crop preserves top-half pixels (red dominant)');
            imagedestroy($cropped);
        }
        @unlink($tempPath);
    }

    // Bottom-anchored focal: cropped intermediate should be the lower 660x660,
    // which is fully blue.
    $tempPath2 = media_gallery_focal_crop_to_temp($tmpSrcPath, 400, 400, 0.5, 1.0);
    test_assert(is_string($tempPath2) && is_readable($tempPath2), 'focal_crop_to_temp bottom-anchored returns a readable temp path');
    if (is_string($tempPath2) && is_readable($tempPath2)) {
        $cropped2 = imagecreatefrompng($tempPath2);
        if ($cropped2 !== false) {
            $rgb2 = imagecolorat($cropped2, 330, 50);
            $r2 = ($rgb2 >> 16) & 0xFF;
            $b2 = $rgb2 & 0xFF;
            test_assert($r2 < 100 && $b2 > 100, 'bottom-anchored crop preserves bottom-half pixels (blue dominant)');
            imagedestroy($cropped2);
        }
        @unlink($tempPath2);
    }

    @unlink($tmpSrcPath);

    // Bad source path returns null (graceful failure)
    test_assert(
        media_gallery_focal_crop_to_temp('/nonexistent/path/never-exists.png', 400, 400, 0.5, 0.5) === null,
        'focal_crop_to_temp returns null for unreadable source'
    );
}
