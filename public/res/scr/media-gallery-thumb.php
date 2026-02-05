<?php
require_once __DIR__ . '/../../../lp-bootstrap.php';
require_once __DIR__ . '/media-gallery-helpers.php';
lawnding_init_session();

media_gallery_require_method('POST');
media_gallery_require_edit_site();
media_gallery_require_csrf();

$paneId = $_POST['paneId'] ?? '';
$itemId = $_POST['itemId'] ?? '';
$clear = $_POST['clear'] ?? '';

if (!is_string($paneId) || $paneId === '' || !media_gallery_is_valid_pane_id($paneId)) {
    media_gallery_json_response(['error' => 'Invalid pane id.'], 400);
}
if (!is_string($itemId) || $itemId === '') {
    media_gallery_json_response(['error' => 'Invalid item id.'], 400);
}

$paths = media_gallery_paths();
$panes = media_gallery_load_panes($paths['panes_path']);
$pane = media_gallery_find_pane($panes, $paneId);
if (!$pane) {
    media_gallery_json_response(['error' => 'Pane not found.'], 404);
}

$jsonFile = media_gallery_pane_json_file($pane, $paneId);
$jsonPath = rtrim($paths['data_dir'], '/\\') . '/' . $jsonFile;
$data = media_gallery_load_data($jsonPath);
$items = $data['items'] ?? [];
if (!is_array($items)) {
    $items = [];
}

$index = media_gallery_find_item_index($items, $itemId);
if ($index < 0) {
    media_gallery_json_response(['error' => 'Media not found.'], 404);
}

$item = $items[$index];
$oldThumb = is_array($item) ? (string) ($item['thumb'] ?? '') : '';
$absOldThumb = media_gallery_abs_from_asset($paths['data_dir'], $oldThumb);

if ($clear === '1') {
    if ($absOldThumb && is_readable($absOldThumb)) {
        unlink($absOldThumb);
    }
    $items[$index]['thumb'] = '';
    $data['items'] = media_gallery_reindex_orders($items);
    media_gallery_write_data($jsonPath, $data);
    media_gallery_json_response([
        'items' => media_gallery_build_payload($data['items'])
    ]);
}

$upload = $_FILES['thumbFile'] ?? null;
if (!$upload || !is_array($upload)) {
    media_gallery_json_response(['error' => 'No thumbnail uploaded.'], 400);
}
if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    $error = $upload['error'] ?? UPLOAD_ERR_OK;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        media_gallery_json_response(['error' => 'Upload too large. Media must be under 2MB.'], 413);
    }
    media_gallery_json_response(['error' => 'Upload failed. Please try again.'], 400);
}
if (!is_uploaded_file($upload['tmp_name'] ?? '')) {
    media_gallery_json_response(['error' => 'Invalid upload.'], 400);
}
if (($upload['size'] ?? 0) > (2 * 1024 * 1024)) {
    media_gallery_json_response(['error' => 'Upload too large. Media must be under 2MB.'], 413);
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
$mediaDir = media_gallery_media_dir($paths['data_dir'], $paneId);
media_gallery_ensure_dir($mediaDir);
$filename = 'thumb-' . $itemId . '.' . $ext;
$targetPath = rtrim($mediaDir, '/\\') . '/' . $filename;

if (!move_uploaded_file($upload['tmp_name'], $targetPath)) {
    media_gallery_json_response(['error' => 'Upload failed. Please try again.'], 400);
}

if ($absOldThumb && is_readable($absOldThumb)) {
    unlink($absOldThumb);
}

$relativePath = 'res/data/mediaGalleryContent-' . $paneId . '/' . $filename;
$items[$index]['thumb'] = media_gallery_normalize_asset_path($relativePath);

$items = media_gallery_reindex_orders($items);
$data['items'] = $items;
media_gallery_write_data($jsonPath, $data);

media_gallery_json_response([
    'items' => media_gallery_build_payload($items)
]);
