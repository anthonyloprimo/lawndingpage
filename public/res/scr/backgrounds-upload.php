<?php
// Upload a new background image and append it to header.json.
require_once __DIR__ . '/../../../lp-bootstrap.php';
require_once __DIR__ . '/backgrounds-helpers.php';
lawnding_init_session();

// JSON API response for the admin UI.
header('Content-Type: application/json');

// Endpoint accepts POST only.
backgrounds_require_method('POST');

// Convert ini size strings like "2M" into bytes.
function ini_size_to_bytes($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    $last = strtolower($value[strlen($value) - 1]);
    $number = (float) $value;
    switch ($last) {
        case 'g':
            $number *= 1024;
            // no break
        case 'm':
            $number *= 1024;
            // no break
        case 'k':
            $number *= 1024;
            break;
    }
    return (int) $number;
}

// Fail fast when the overall POST payload exceeds the PHP limit.
$postMaxBytes = ini_size_to_bytes(ini_get('post_max_size'));
$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($postMaxBytes > 0 && $contentLength > $postMaxBytes) {
    error_log('backgrounds-upload.php: payload too large (' . $contentLength . ' bytes, limit ' . $postMaxBytes . ' bytes).');
    backgrounds_json_response(['error' => 'Payload too large. Please reduce image sizes and try again.'], 413);
}

// Require auth and edit_site permission.
backgrounds_require_edit_site();
backgrounds_require_csrf();

// Validate the file upload payload.
$upload = $_FILES['bgFile'] ?? null;
if (!$upload || !is_array($upload)) {
    backgrounds_json_response(['error' => 'No background image uploaded.'], 400);
}
if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    $error = $upload['error'] ?? UPLOAD_ERR_OK;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        backgrounds_json_response(['error' => 'Upload too large. Images must be under 5MB.'], 413);
    }
    backgrounds_json_response(['error' => 'Upload failed. Please try again.'], 400);
}

// Resolve the target directories and header.json location.
$paths = backgrounds_paths();
$imgDir = $paths['img_dir'];
$headerPath = $paths['header_path'];

// Load header.json with a minimal fallback structure.
$headerData = backgrounds_load_header($headerPath);

// Save a validated image file into res/img and return its relative path.
function save_image($fileArray, $destName, $imgDir) {
    if (!isset($fileArray['tmp_name']) || !is_uploaded_file($fileArray['tmp_name'])) {
        return null;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $fileArray['tmp_name']);
    finfo_close($finfo);
    if (strpos($mime, 'image/') !== 0) {
        return null;
    }
    static $mimeExt = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    $ext = $mimeExt[$mime] ?? 'jpg';
    $safeName = $destName
        ? $destName . '.' . $ext
        : preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($fileArray['name'], PATHINFO_FILENAME)) . '.' . $ext;
    $targetPath = $imgDir . $safeName;
    $resized = function_exists('lawnding_gd_resize_image')
        && lawnding_gd_resize_image($fileArray['tmp_name'], $targetPath, 1920, 10000);
    if (!$resized && !move_uploaded_file($fileArray['tmp_name'], $targetPath)) {
        return null;
    }
    return 'res/img/' . $safeName;
}

// Persist the upload and bail on invalid files.
$saved = save_image($upload, null, $imgDir);
if (!$saved) {
    backgrounds_json_response(['error' => 'Invalid image upload.'], 400);
}

// Append the new background record to header.json data.
if (empty($headerData['backgrounds']) || !is_array($headerData['backgrounds'])) {
    $headerData['backgrounds'] = [];
}
$headerData['backgrounds'][] = [
    'url' => $saved,
    'author' => '',
    'authorUrl' => '',
];

// Persist updated header.json.
$headerJson = json_encode($headerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($headerJson === false || file_put_contents($headerPath, $headerJson, LOCK_EX) === false) {
    backgrounds_json_response(['error' => 'Failed to write header data'], 500);
}

// Build the normalized response payload for the UI.
$backgroundsRaw = $headerData['backgrounds'] ?? [];
if (!is_array($backgroundsRaw)) {
    $backgroundsRaw = [];
}

$backgrounds = backgrounds_build_payload($backgroundsRaw);
$response = ['backgrounds' => $backgrounds];
if (!extension_loaded('gd')) {
    $response['gd_unavailable'] = true;
}
backgrounds_json_response($response);
