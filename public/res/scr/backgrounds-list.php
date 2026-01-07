<?php
// Return the current list of background images for the admin UI.
require_once __DIR__ . '/../../../lp-bootstrap.php';
require_once __DIR__ . '/backgrounds-helpers.php';
lawnding_init_session();

// JSON API response for the admin UI.
header('Content-Type: application/json');

// Endpoint accepts GET only.
backgrounds_require_method('GET');

// Require auth and edit_site permission.
backgrounds_require_edit_site();

// Locate the header JSON where backgrounds are stored.
$paths = backgrounds_paths();
$headerPath = $paths['header_path'];

// Load header.json and normalize background data.
$headerData = backgrounds_load_header($headerPath);
$backgroundsRaw = $headerData['backgrounds'] ?? [];
if (!is_array($backgroundsRaw)) {
    $backgroundsRaw = [];
}

// Map raw background entries into a normalized payload for the UI.
$backgrounds = backgrounds_build_payload($backgroundsRaw);

backgrounds_json_response(['backgrounds' => $backgrounds]);
