<?php
require_once lawnding_admin_path('modules/mediaGallery/helpers.php');
lawnding_init_session();

media_gallery_require_method('POST');
media_gallery_require_edit_site();

$paneId = (string) ($_POST['paneId'] ?? '');
$itemId = (string) ($_POST['itemId'] ?? '');
$clear  = $_POST['clear'] ?? '';

$state = media_gallery_load_pane_state($paneId);
$index = media_gallery_require_item($state['items'], $itemId);

$item = $state['items'][$index];
$oldThumb = is_array($item) ? (string) ($item['thumb'] ?? '') : '';
$absOldThumb = media_gallery_abs_from_asset($state['paths']['data_dir'], $oldThumb);

if ($clear === '1') {
    if ($absOldThumb && is_readable($absOldThumb)) {
        unlink($absOldThumb);
    }
    $state['items'][$index]['thumb'] = '';
    media_gallery_save_and_respond($state['json_path'], $state['data'], $state['items']);
}

$upload = $_FILES['thumbFile'] ?? null;
if (!$upload || !is_array($upload)) {
    media_gallery_json_response(['error' => 'No thumbnail uploaded.'], 400);
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
    media_gallery_json_response(['error' => 'Invalid thumbnail upload.'], 400);
}

$ext = media_gallery_safe_ext((string) ($upload['name'] ?? ''));
$thumbsDir = media_gallery_thumbs_dir($state['paths']['data_dir'], $paneId);
media_gallery_ensure_dir($thumbsDir);
$filename = 'thumb-' . $itemId . '.' . $ext;
$targetPath = rtrim($thumbsDir, '/\\') . '/' . $filename;

if (!move_uploaded_file($upload['tmp_name'], $targetPath)) {
    media_gallery_json_response(['error' => 'Upload failed. Please try again.'], 400);
}

if ($absOldThumb && is_readable($absOldThumb)) {
    unlink($absOldThumb);
}

$relativePath = 'res/data/mediaGalleryContent-' . $paneId . '/thumbs/' . $filename;
$state['items'][$index]['thumb'] = lawnding_normalize_asset_path($relativePath);

media_gallery_save_and_respond($state['json_path'], $state['data'], $state['items']);
