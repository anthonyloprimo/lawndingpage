<?php
// Upload a new background image and append it to header.json.
require_once __DIR__ . '/../../../lp-bootstrap.php';
require_once __DIR__ . '/backgrounds-helpers.php';
lawnding_init_session();

// JSON API response for the admin UI.
header('Content-Type: application/json');

// Endpoint accepts POST only.
backgrounds_require_method('POST');

$postMaxBytes = lawnding_ini_size_to_bytes(ini_get('post_max_size'));
$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($postMaxBytes > 0 && $contentLength > $postMaxBytes) {
    error_log('backgrounds-upload.php: payload too large (' . $contentLength . ' bytes, limit ' . $postMaxBytes . ' bytes).');
    backgrounds_json_response(['error' => 'Payload too large. Please reduce image sizes and try again.'], 413);
}

// Require auth and edit_site permission.
backgrounds_require_edit_site();

// Validate the file upload payload.
$upload = $_FILES['bgFile'] ?? null;
if (!$upload || !is_array($upload)) {
    backgrounds_json_response(['error' => 'No background image uploaded.'], 400);
}
if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    $error = $upload['error'] ?? UPLOAD_ERR_OK;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        $phpLimit = ini_get('upload_max_filesize');
        backgrounds_json_response(['error' => 'Upload too large. Your server\'s PHP limit is ' . $phpLimit . 'B — increase upload_max_filesize in php.ini.'], 413);
    }
    backgrounds_json_response(['error' => 'Upload failed. Please try again.'], 400);
}

// Resolve the target directories and header.json location.
$paths = backgrounds_paths();
$imgDir = $paths['img_dir'];
$headerPath = $paths['header_path'];

// Load header.json with a minimal fallback structure.
$headerData = backgrounds_load_header($headerPath);

// Validate and save the uploaded image.
$originalSize = (int) ($upload['size'] ?? 0);
$result = lawnding_validate_and_save_image($upload, $imgDir, null, 1920, 10000, lawnding_image_mime_map());
if (!$result['ok']) {
    backgrounds_json_response(['error' => $result['error']], 400);
}
$saved = 'res/img/' . $result['filename'];
$savedPath = $imgDir . $result['filename'];
$savedSize = is_file($savedPath) ? (int) filesize($savedPath) : $originalSize;

// Append the new background record to header.json data.
if (empty($headerData['backgrounds']) || !is_array($headerData['backgrounds'])) {
    $headerData['backgrounds'] = [];
}
$headerData['backgrounds'][] = [
    'url'           => $saved,
    'author'        => '',
    'authorUrl'     => '',
    'original_size' => $originalSize,
    'saved_size'    => $savedSize,
];

// Persist updated header.json.
$headerJson = json_encode($headerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($headerJson === false || file_put_contents($headerPath, $headerJson, LOCK_EX) === false) {
    backgrounds_json_response(['error' => 'Failed to write header data'], 500);
}

// Build the normalized response payload for the UI.
$backgrounds = backgrounds_build_payload($headerData['backgrounds']);
$response = ['backgrounds' => $backgrounds];
if (!extension_loaded('gd')) {
    $response['gd_unavailable'] = true;
}
backgrounds_json_response($response);
