<?php
require_once lawnding_admin_path('modules/mediaGallery/helpers.php');
lawnding_init_session();

media_gallery_require_method('POST');
media_gallery_require_edit_site();

$paneId = (string) ($_POST['paneId'] ?? '');
$itemId = (string) ($_POST['itemId'] ?? '');

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
$index = media_gallery_require_item($state['items'], $itemId);

$item = $state['items'][$index];
$oldFile = is_array($item) ? (string) ($item['file'] ?? '') : '';
$absOld = media_gallery_abs_from_asset($state['paths']['data_dir'], $oldFile);

$ext = media_gallery_safe_ext((string) ($upload['name'] ?? ''), $mime);
$mediaDir = media_gallery_media_dir($state['paths']['data_dir'], $paneId);
media_gallery_ensure_dir($mediaDir);
$filename = 'media-' . $itemId . '.' . $ext;
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

if ($absOld && is_readable($absOld)) {
    unlink($absOld);
}

$existingThumbPath = is_array($item) ? (string) ($item['thumb'] ?? '') : '';
$existingFocalX = is_array($item) && isset($item['focal_x']) && is_numeric($item['focal_x']) ? (float) $item['focal_x'] : null;
$existingFocalY = is_array($item) && isset($item['focal_y']) && is_numeric($item['focal_y']) ? (float) $item['focal_y'] : null;
$thumbRelative = $existingThumbPath;
if (!media_gallery_thumb_is_custom($existingThumbPath)) {
    $absOldThumb = media_gallery_abs_from_asset($state['paths']['data_dir'], $existingThumbPath);
    if ($absOldThumb && is_readable($absOldThumb)) {
        unlink($absOldThumb);
    }
    $thumbRelative = media_gallery_derive_thumb($targetPath, $state['paths']['data_dir'], $paneId, $itemId, $isVideo, $existingFocalX, $existingFocalY);
}

$type = $isVideo ? 'video' : 'image';
$relativePath = 'res/data/mediaGalleryContent-' . $paneId . '/' . $filename;

$state['items'][$index]['file']          = lawnding_normalize_asset_path($relativePath);
$state['items'][$index]['type']          = $type;
$state['items'][$index]['thumb']         = $thumbRelative;
$state['items'][$index]['original_size'] = $originalSize;
$state['items'][$index]['saved_size']    = $savedSize;

$extras = [];
if (!extension_loaded('gd')) {
    $extras['gd_unavailable'] = true;
}
media_gallery_save_and_respond($state['json_path'], $state['data'], $state['items'], $extras);
