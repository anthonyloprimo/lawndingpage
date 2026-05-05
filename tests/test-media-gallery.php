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

// ----- media_gallery_build_payload (item-shape pass-through) -----

// New uploaded_at / uploaded_by fields are passed through verbatim
$payload = media_gallery_build_payload([
    [
        'id' => 'abc',
        'type' => 'image',
        'file' => 'res/data/x/foo.jpg',
        'thumb' => 'res/data/x/thumbs/foo.webp',
        'title' => '',
        'order' => 1,
        'uploaded_at' => '2026-04-29T18:42:30+00:00',
        'uploaded_by' => 'Phinnay',
    ],
]);
test_assert(
    isset($payload[0]['uploaded_at']) && $payload[0]['uploaded_at'] === '2026-04-29T18:42:30+00:00',
    'build_payload preserves uploaded_at string verbatim'
);
test_assert(
    isset($payload[0]['uploaded_by']) && $payload[0]['uploaded_by'] === 'Phinnay',
    'build_payload preserves uploaded_by string verbatim'
);

// TG-derived sentinel survives unchanged in uploaded_by; uploaded_by_display
// falls back to the raw sentinel when no cached profile is available
// (the test env has no membership cache).
$payload = media_gallery_build_payload([
    ['id' => 'tg1', 'type' => 'image', 'file' => '', 'thumb' => '', 'uploaded_by' => 'tg:114290546'],
]);
test_assert(
    $payload[0]['uploaded_by'] === 'tg:114290546',
    'build_payload preserves tg:<id> sentinel in uploaded_by'
);
test_assert(
    $payload[0]['uploaded_by_display'] === 'tg:114290546',
    'build_payload uploaded_by_display falls back to raw sentinel when cache has no profile'
);

// Bcrypt usernames are already friendly — uploaded_by_display mirrors uploaded_by
$payload = media_gallery_build_payload([
    ['id' => 'b1', 'type' => 'image', 'file' => '', 'thumb' => '', 'uploaded_by' => 'Phinnay'],
]);
test_assert(
    $payload[0]['uploaded_by_display'] === 'Phinnay',
    'build_payload uploaded_by_display passes bcrypt usernames through unchanged'
);

// Legacy items missing the new fields default to empty strings (no PHP notices, no nulls)
$payload = media_gallery_build_payload([
    ['id' => 'legacy', 'type' => 'image', 'file' => '', 'thumb' => ''],
]);
test_assert(
    $payload[0]['uploaded_at'] === '' && $payload[0]['uploaded_by'] === '',
    'build_payload defaults uploaded_at / uploaded_by to "" for legacy items'
);

// ----- media_gallery_apply_changes (save_map validator dispatch target) -----
//
// Pure: caller does I/O. Wired into save-config.php's save_map dispatch
// via mediaGallery's manifest `validator` field. Merges {updates: [...]}
// payloads into the existing items array, re-sorts by order, and
// renumbers order to be contiguous starting at 1.

// Empty existing + empty updates -> empty items (with key seeded)
$out = media_gallery_apply_changes([], ['updates' => []]);
test_assert(
    isset($out['items']) && $out['items'] === [],
    'apply_changes seeds items=[] when both inputs are empty'
);

// Update touches title and re-sorts on order
$existing = [
    'items' => [
        ['id' => 'a', 'title' => 'Alpha', 'order' => 1],
        ['id' => 'b', 'title' => 'Beta',  'order' => 2],
    ],
];
$out = media_gallery_apply_changes($existing, ['updates' => [
    ['id' => 'b', 'title' => 'Bravo'],
    ['id' => 'a', 'order' => 5],
]]);
test_assert(
    $out['items'][0]['id'] === 'b' && $out['items'][0]['title'] === 'Bravo' && $out['items'][0]['order'] === 1,
    'apply_changes updates title and renumbers order to 1..N after sort'
);
test_assert(
    $out['items'][1]['id'] === 'a' && $out['items'][1]['order'] === 2,
    'apply_changes promotes a (order=5) past b (order=2) and renumbers'
);

// Unknown ids in payload are ignored (item may have been removed out-of-band)
$existing = ['items' => [['id' => 'x', 'title' => 'X', 'order' => 1]]];
$out = media_gallery_apply_changes($existing, ['updates' => [
    ['id' => 'ghost', 'title' => 'should be dropped'],
]]);
test_assert(
    count($out['items']) === 1 && $out['items'][0]['id'] === 'x' && $out['items'][0]['title'] === 'X',
    'apply_changes silently drops updates targeting unknown ids'
);

// ----- media_gallery_update_paths (on_rename helper) -----

$data = [
    'items' => [
        ['id' => '1', 'file' => 'res/data/mediaGalleryContent-old/media-1.jpg',
                      'thumb' => 'res/data/mediaGalleryContent-old/thumbs/media-1-thumb.webp'],
        ['id' => '2', 'file' => '', 'thumb' => ''],
    ],
];
$updated = media_gallery_update_paths($data, 'old', 'new');
test_assert(
    $updated['items'][0]['file'] === 'res/data/mediaGalleryContent-new/media-1.jpg',
    'update_paths rewrites the dir segment in item.file'
);
test_assert(
    $updated['items'][0]['thumb'] === 'res/data/mediaGalleryContent-new/thumbs/media-1-thumb.webp',
    'update_paths rewrites the dir segment in item.thumb'
);
test_assert(
    $updated['items'][1]['file'] === '' && $updated['items'][1]['thumb'] === '',
    'update_paths leaves empty file/thumb fields alone'
);

// Substring safety: a legitimate file with the new id as a substring of
// the OLD id should not get accidentally rewritten. The replace targets
// "mediaGalleryContent-<old>/" with the trailing slash, so the match is
// directory-anchored, not substring-anchored.
$data = ['items' => [['file' => 'res/data/mediaGalleryContent-foobar/x.jpg']]];
$updated = media_gallery_update_paths($data, 'foo', 'baz');
test_assert(
    $updated['items'][0]['file'] === 'res/data/mediaGalleryContent-foobar/x.jpg',
    'update_paths leaves "foobar" alone when renaming "foo" -> "baz" (trailing slash anchors the match)'
);

// ---- thumb_is_custom reads thumb_origin directly ----
test_assert(media_gallery_thumb_is_custom(['thumb_origin' => 'custom']) === true, 'thumb_is_custom: custom -> true');
test_assert(media_gallery_thumb_is_custom(['thumb_origin' => 'auto']) === false,  'thumb_is_custom: auto -> false');
test_assert(media_gallery_thumb_is_custom([]) === false,                          'thumb_is_custom: missing field -> false (defensive default)');

// ---- generate_id: sequential int allocation, decoupled from content ----
test_assert(media_gallery_generate_id([]) === 1,
    'generate_id: empty list -> 1');
test_assert(media_gallery_generate_id([['id' => '5', 'title' => 'a']]) === 6,
    'generate_id: single record with id "5" -> 6');
test_assert(media_gallery_generate_id([
        ['id' => '2'], ['id' => '7'], ['id' => '4']
    ]) === 8,
    'generate_id: max id wins regardless of position');
test_assert(media_gallery_generate_id([['id' => 12]]) === 13,
    'generate_id: int id (not string) handled');
test_assert(media_gallery_generate_id([['id' => 'abc']]) === 1,
    'generate_id: non-numeric id casts to 0, returns 1 from empty-effective list');
test_assert(media_gallery_generate_id([['title' => 'no id field']]) === 1,
    'generate_id: missing id field treated as 0');
test_assert(media_gallery_generate_id(['not-an-array', ['id' => '3']]) === 4,
    'generate_id: non-array entries skipped defensively');
// 4-digit-random-era ids (mediaGallery pre-Phase 1) cast cleanly: a gallery
// carrying old "4827" continues sequentially from 4828, not from 1.
test_assert(media_gallery_generate_id([['id' => '4827']]) === 4828,
    'generate_id: legacy 4-digit ids continue sequentially (no reset)');

// ---- load_pane_state happy path (return-shape contract) ----
// Failure paths exit via media_gallery_json_response and aren't catchable
// without test-mode plumbing. Failure logic reduces to is_valid_pane_id
// (covered) + find_pane (trivial), so happy-path only here.
$fixtureRoot = sys_get_temp_dir() . '/lp-test-load-pane-state-' . uniqid();
mkdir($fixtureRoot . '/mediaGalleryContent-fixture', 0775, true);
file_put_contents($fixtureRoot . '/panes.json', json_encode([
    'panes' => [['id' => 'fixture', 'module' => 'mediaGallery']],
]));
file_put_contents($fixtureRoot . '/mediaGalleryContent-fixture/items.json', json_encode([
    'items' => [['id' => 'abc', 'thumb_origin' => 'auto']],
]));

$origDataDirSet = isset($GLOBALS['LAWNDING_CONFIG']['data_dir']);
$origDataDir = $GLOBALS['LAWNDING_CONFIG']['data_dir'] ?? null;
try {
    $GLOBALS['LAWNDING_CONFIG']['data_dir'] = $fixtureRoot;
    $state = media_gallery_load_pane_state('fixture');
    test_assert(
        array_keys($state) === ['paths', 'json_path', 'data', 'items'],
        'load_pane_state: returns documented keys ([paths, json_path, data, items])'
    );
    test_assert(
        count($state['items']) === 1 && ($state['items'][0]['id'] ?? '') === 'abc',
        'load_pane_state: surfaces items from items.json'
    );
} finally {
    if ($origDataDirSet) {
        $GLOBALS['LAWNDING_CONFIG']['data_dir'] = $origDataDir;
    } else {
        unset($GLOBALS['LAWNDING_CONFIG']['data_dir']);
    }
    @unlink($fixtureRoot . '/mediaGalleryContent-fixture/items.json');
    @rmdir($fixtureRoot . '/mediaGalleryContent-fixture');
    @unlink($fixtureRoot . '/panes.json');
    @rmdir($fixtureRoot);
}
