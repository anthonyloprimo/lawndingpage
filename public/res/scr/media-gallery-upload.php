<?php
require_once __DIR__ . '/../../../lp-bootstrap.php';
require_once __DIR__ . '/media-gallery-helpers.php';
lawnding_init_session();

media_gallery_require_method('POST');

$postMaxBytes = media_gallery_ini_size_to_bytes(ini_get('post_max_size'));
$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($postMaxBytes > 0 && $contentLength > $postMaxBytes) {
    media_gallery_json_response(['error' => 'Payload too large. Please reduce image sizes and try again.'], 413);
}

media_gallery_require_edit_site();
media_gallery_require_csrf();

$paneId = $_POST['paneId'] ?? '';
if (!is_string($paneId) || $paneId === '' || !media_gallery_is_valid_pane_id($paneId)) {
    media_gallery_json_response(['error' => 'Invalid pane id.'], 400);
}

$upload = $_FILES['mediaFile'] ?? null;
if (!$upload || !is_array($upload)) {
    media_gallery_json_response(['error' => 'No media uploaded.'], 400);
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
if ($mime === '' || (strpos($mime, 'image/') !== 0 && strpos($mime, 'video/') !== 0)) {
    media_gallery_json_response(['error' => 'Invalid media upload.'], 400);
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

$existingIds = media_gallery_collect_ids($items);
$newId = media_gallery_generate_id($existingIds);

$ext = media_gallery_safe_ext((string) ($upload['name'] ?? ''));
$mediaDir = media_gallery_media_dir($paths['data_dir'], $paneId);
media_gallery_ensure_dir($mediaDir);
$filename = 'media-' . $newId . '.' . $ext;
$targetPath = rtrim($mediaDir, '/\\') . '/' . $filename;

if (!move_uploaded_file($upload['tmp_name'], $targetPath)) {
    media_gallery_json_response(['error' => 'Upload failed. Please try again.'], 400);
}

$type = strpos($mime, 'video/') === 0 ? 'video' : 'image';
$relativePath = 'res/data/mediaGalleryContent-' . $paneId . '/' . $filename;

$maxOrder = 0;
foreach ($items as $item) {
    if (is_array($item) && isset($item['order'])) {
        $order = (int) $item['order'];
        if ($order > $maxOrder) {
            $maxOrder = $order;
        }
    }
}

$items[] = [
    'id' => $newId,
    'type' => $type,
    'file' => media_gallery_normalize_asset_path($relativePath),
    'thumb' => '',
    'title' => '',
    'order' => $maxOrder + 1,
];

$items = media_gallery_reindex_orders($items);
$data['items'] = $items;
media_gallery_write_data($jsonPath, $data);

media_gallery_json_response([
    'items' => media_gallery_build_payload($items),
    'id' => $newId,
]);
