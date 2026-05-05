<?php
require_once lawnding_admin_path('modules/mediaGallery/helpers.php');
lawnding_init_session();

media_gallery_require_method('POST');
media_gallery_require_edit_site();

$paneId = (string) ($_POST['paneId'] ?? '');
$itemId = (string) ($_POST['itemId'] ?? '');

$state = media_gallery_load_pane_state($paneId);
$index = media_gallery_require_item($state['items'], $itemId);

$item = $state['items'][$index];
$filePath = is_array($item) ? (string) ($item['file'] ?? '') : '';
$thumbPath = is_array($item) ? (string) ($item['thumb'] ?? '') : '';

$absFile = media_gallery_abs_from_asset($state['paths']['data_dir'], $filePath);
$absThumb = media_gallery_abs_from_asset($state['paths']['data_dir'], $thumbPath);
if ($absFile && is_readable($absFile)) {
    unlink($absFile);
}
if ($absThumb && is_readable($absThumb)) {
    unlink($absThumb);
}

array_splice($state['items'], $index, 1);
media_gallery_save_and_respond($state['json_path'], $state['data'], $state['items']);
