<?php
// Set or clear the focal point on a gallery item, and re-derive the
// auto-thumb using the new coords. Custom-uploaded thumbs (basename
// prefix thumb-) are preserved -- explicit user intent overrides
// auto-derivation.
//
// POST { paneId, itemId, focal_x, focal_y, csrf_token }
//   focal_x/focal_y omitted or empty string -> clear focal point
//                                              (back to centered crop)
//   focal_x/focal_y numeric                  -> clamp to [0, 1] and set
require_once lawnding_admin_path('modules/mediaGallery/helpers.php');
lawnding_init_session();

media_gallery_require_method('POST');
media_gallery_require_edit_site();

$paneId    = (string) ($_POST['paneId'] ?? '');
$itemId    = (string) ($_POST['itemId'] ?? '');
$focalXRaw = $_POST['focal_x'] ?? null;
$focalYRaw = $_POST['focal_y'] ?? null;

$focalProvided = ($focalXRaw !== null && $focalXRaw !== '' && $focalYRaw !== null && $focalYRaw !== '');
$focalX = null;
$focalY = null;
if ($focalProvided) {
    if (!is_numeric($focalXRaw) || !is_numeric($focalYRaw)) {
        media_gallery_json_response(['error' => 'Invalid focal coords.'], 400);
    }
    $focalX = max(0.0, min(1.0, (float) $focalXRaw));
    $focalY = max(0.0, min(1.0, (float) $focalYRaw));
}

$state = media_gallery_load_pane_state($paneId);
$index = media_gallery_require_item($state['items'], $itemId);

$item = $state['items'][$index];
$itemFile = is_array($item) ? (string) ($item['file'] ?? '') : '';
$existingThumbPath = is_array($item) ? (string) ($item['thumb'] ?? '') : '';
$isVideo = (is_array($item) ? (string) ($item['type'] ?? '') : '') === 'video';

$state['items'][$index]['focal_x'] = $focalX;
$state['items'][$index]['focal_y'] = $focalY;

// Re-derive the auto-thumb with the new focal coords. Skip for videos
// (no thumb to derive) and for items whose thumb is admin-uploaded
// (custom thumbs are explicit user intent and survive focal changes).
if (!$isVideo && !media_gallery_thumb_is_custom($existingThumbPath)) {
    $absSrcFile = media_gallery_abs_from_asset($state['paths']['data_dir'], $itemFile);
    if ($absSrcFile !== null && is_readable($absSrcFile)) {
        $absOldThumb = media_gallery_abs_from_asset($state['paths']['data_dir'], $existingThumbPath);
        if ($absOldThumb !== null && is_readable($absOldThumb)) {
            unlink($absOldThumb);
        }
        $newThumb = media_gallery_derive_thumb(
            $absSrcFile,
            $state['paths']['data_dir'],
            $paneId,
            $itemId,
            $isVideo,
            $focalX,
            $focalY
        );
        $state['items'][$index]['thumb'] = $newThumb;
    }
}

media_gallery_save_and_respond($state['json_path'], $state['data'], $state['items']);
