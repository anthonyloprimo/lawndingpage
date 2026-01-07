<?php
// Delete a background entry and optionally remove the underlying image file.
require_once __DIR__ . '/../../../lp-bootstrap.php';
require_once __DIR__ . '/backgrounds-helpers.php';
lawnding_init_session();

// JSON API response for the admin UI.
header('Content-Type: application/json');

// Endpoint accepts POST only.
backgrounds_require_method('POST');

// Require auth and edit_site permission.
backgrounds_require_edit_site();
backgrounds_require_csrf();

// Expected request data.
$url = isset($_POST['url']) ? (string) $_POST['url'] : '';
$index = isset($_POST['index']) ? (int) $_POST['index'] : null;
if ($url === '') {
    backgrounds_json_response(['error' => 'Missing background URL.'], 400);
}

// Locate the header JSON where backgrounds are stored.
$paths = backgrounds_paths();
$publicDir = $paths['public_dir'];
$headerPath = $paths['header_path'];

// Load header.json and normalize background data.
$headerData = backgrounds_load_header($headerPath);
$backgroundsRaw = $headerData['backgrounds'] ?? [];
if (!is_array($backgroundsRaw)) {
    $backgroundsRaw = [];
}

// Find and remove the target background (by index or URL match).
$normalizedUrl = backgrounds_normalize_asset_path($url);
$removed = false;

if ($index !== null && isset($backgroundsRaw[$index])) {
    $target = $backgroundsRaw[$index];
    $targetUrl = is_array($target) ? ($target['url'] ?? '') : (is_string($target) ? $target : '');
    if (backgrounds_normalize_asset_path($targetUrl) === $normalizedUrl) {
        unset($backgroundsRaw[$index]);
        $removed = true;
    }
}

if (!$removed) {
    foreach ($backgroundsRaw as $key => $bg) {
        $targetUrl = is_array($bg) ? ($bg['url'] ?? '') : (is_string($bg) ? $bg : '');
        if (backgrounds_normalize_asset_path($targetUrl) === $normalizedUrl) {
            unset($backgroundsRaw[$key]);
            $removed = true;
            break;
        }
    }
}

if (!$removed) {
    backgrounds_json_response(['error' => 'Background not found.'], 404);
}

// Persist updated header.json.
$backgroundsRaw = array_values($backgroundsRaw);
$headerData['backgrounds'] = $backgroundsRaw;

$headerJson = json_encode($headerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($headerJson === false || file_put_contents($headerPath, $headerJson) === false) {
    backgrounds_json_response(['error' => 'Failed to write header data'], 500);
}

// If the background is a local image, remove the file if no longer referenced.
if (str_starts_with($normalizedUrl, 'res/img/')) {
    $stillUsed = false;
    foreach ($backgroundsRaw as $bg) {
        $bgUrl = is_array($bg) ? ($bg['url'] ?? '') : (is_string($bg) ? $bg : '');
        if (backgrounds_normalize_asset_path($bgUrl) === $normalizedUrl) {
            $stillUsed = true;
            break;
        }
    }
    if (!$stillUsed) {
        $filePath = $publicDir . '/' . $normalizedUrl;
        $realFile = realpath($filePath);
        $imgRoot = realpath($publicDir . '/res/img');
        if ($realFile && $imgRoot && str_starts_with($realFile, $imgRoot . DIRECTORY_SEPARATOR)) {
            if (is_file($realFile)) {
                unlink($realFile);
            }
        }
    }
}

// Return the updated backgrounds list for the UI.
$backgrounds = backgrounds_build_payload($backgroundsRaw);
backgrounds_json_response(['backgrounds' => $backgrounds]);
