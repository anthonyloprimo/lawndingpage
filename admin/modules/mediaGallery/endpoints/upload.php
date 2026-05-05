<?php
require_once lawnding_admin_path('modules/mediaGallery/helpers.php');
lawnding_init_session();

media_gallery_require_method('POST');

$postMaxBytes = lawnding_ini_size_to_bytes(ini_get('post_max_size'));
$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($postMaxBytes > 0 && $contentLength > $postMaxBytes) {
    media_gallery_json_response(['error' => 'Payload too large. Please reduce image sizes and try again.'], 413);
}

$identity = media_gallery_require_edit_site();

$paneId = (string) ($_POST['paneId'] ?? '');

$upload = $_FILES['mediaFile'] ?? null;
if (!$upload || !is_array($upload)) {
    media_gallery_json_response(['error' => 'No media uploaded.'], 400);
}
if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    $error = $upload['error'] ?? UPLOAD_ERR_OK;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        $phpLimit = ini_get('upload_max_filesize');
        media_gallery_json_response(['error' => 'Upload too large. Your server\'s PHP limit is ' . $phpLimit . 'B — increase upload_max_filesize in php.ini.'], 413);
    }
    media_gallery_json_response(['error' => 'Upload failed. Please try again.'], 400);
}
if (!is_uploaded_file($upload['tmp_name'] ?? '')) {
    media_gallery_json_response(['error' => 'Invalid upload.'], 400);
}
$appUploadMaxBytes = lawnding_app_upload_max_bytes();
$appUploadMaxLabel = lawnding_app_upload_max_label();
if (($upload['size'] ?? 0) > $appUploadMaxBytes) {
    media_gallery_json_response(['error' => 'Upload too large. Media must be under ' . $appUploadMaxLabel . '.'], 413);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? finfo_file($finfo, $upload['tmp_name']) : '';
if ($finfo) {
    finfo_close($finfo);
}
$mime = is_string($mime) ? $mime : '';
if ($mime === '' || strpos($mime, 'image/') !== 0) {
    $errorMsg = strpos($mime, 'video/') === 0
        ? 'Video uploads are not enabled. Galleries currently accept images only.'
        : 'Invalid media upload.';
    media_gallery_json_response(['error' => $errorMsg], 400);
}

$state = media_gallery_load_pane_state($paneId);

$existingIds = media_gallery_collect_ids($state['items']);
$newId = media_gallery_generate_id($existingIds);

$ext = media_gallery_safe_ext((string) ($upload['name'] ?? ''), $mime);
$mediaDir = media_gallery_media_dir($state['paths']['data_dir'], $paneId);
media_gallery_ensure_dir($mediaDir);
$filename = 'media-' . $newId . '.' . $ext;
$targetPath = rtrim($mediaDir, '/\\') . '/' . $filename;

$originalSize = (int) ($upload['size'] ?? 0);
$isVideo = strpos($mime, 'video/') === 0;
$resized = !$isVideo
    && function_exists('lawnding_image_resize')
    && lawnding_image_resize($upload['tmp_name'], $targetPath, 1920, 10000, 'maxbox');
if (!$resized && !move_uploaded_file($upload['tmp_name'], $targetPath)) {
    media_gallery_json_response(['error' => 'Upload failed. Please try again.'], 400);
}
$savedSize = is_file($targetPath) ? (int) filesize($targetPath) : $originalSize;

// Optional focal coords from the client (set by smartcrop.js auto-
// fallback before upload). Numeric values clamp to [0, 1]; absent /
// empty / non-numeric inputs leave focal as null and derive_thumb
// falls back to centered crop.
$focalXRaw = $_POST['focal_x'] ?? null;
$focalYRaw = $_POST['focal_y'] ?? null;
$focalX = null;
$focalY = null;
if ($focalXRaw !== null && $focalXRaw !== '' && $focalYRaw !== null && $focalYRaw !== ''
    && is_numeric($focalXRaw) && is_numeric($focalYRaw)) {
    $focalX = max(0.0, min(1.0, (float) $focalXRaw));
    $focalY = max(0.0, min(1.0, (float) $focalYRaw));
}

$thumbRelative = media_gallery_derive_thumb($targetPath, $state['paths']['data_dir'], $paneId, $newId, $isVideo, $focalX, $focalY);

$type = $isVideo ? 'video' : 'image';
$relativePath = 'res/data/mediaGalleryContent-' . $paneId . '/' . $filename;

$maxOrder = 0;
foreach ($state['items'] as $item) {
    if (is_array($item) && isset($item['order'])) {
        $order = (int) $item['order'];
        if ($order > $maxOrder) {
            $maxOrder = $order;
        }
    }
}

$state['items'][] = [
    'id'            => $newId,
    'type'          => $type,
    'file'          => lawnding_normalize_asset_path($relativePath),
    'thumb'         => $thumbRelative,
    'thumb_origin'  => 'auto',
    'title'         => '',
    'order'         => $maxOrder + 1,
    'original_size' => $originalSize,
    'saved_size'    => $savedSize,
    'focal_x'       => $focalX,
    'focal_y'       => $focalY,
    'uploaded_at'   => gmdate('c'),
    'uploaded_by'   => isset($identity['authUser']) ? (string) $identity['authUser'] : '',
];

$extras = ['id' => $newId];
if (!extension_loaded('gd')) {
    $extras['gd_unavailable'] = true;
}
media_gallery_save_and_respond($state['json_path'], $state['data'], $state['items'], $extras);
