<?php
require_once __DIR__ . '/../../../lp-bootstrap.php';
require_once __DIR__ . '/media-gallery-helpers.php';
lawnding_init_session();

media_gallery_require_method('POST');
media_gallery_require_edit_site();
media_gallery_require_csrf();

$paneId = $_POST['paneId'] ?? '';
$itemId = $_POST['itemId'] ?? '';
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
$filePath = is_array($item) ? (string) ($item['file'] ?? '') : '';
$thumbPath = is_array($item) ? (string) ($item['thumb'] ?? '') : '';

$absFile = media_gallery_abs_from_asset($paths['data_dir'], $filePath);
$absThumb = media_gallery_abs_from_asset($paths['data_dir'], $thumbPath);
if ($absFile && is_readable($absFile)) {
    unlink($absFile);
}
if ($absThumb && is_readable($absThumb)) {
    unlink($absThumb);
}

array_splice($items, $index, 1);
$items = media_gallery_reindex_orders($items);
$data['items'] = $items;
media_gallery_write_data($jsonPath, $data);

media_gallery_json_response([
    'items' => media_gallery_build_payload($items)
]);
