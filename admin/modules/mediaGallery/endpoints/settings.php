<?php
// Persist per-pane settings overrides to the sidecar settings.json inside
// the pane's content directory. POST { paneId, useSiteDefaults?,
// customThumbs?, changeMedia?, csrf_token }. Caller is the per-pane gear
// modal's Save button.
//
// useSiteDefaults defaults to "true" so absent or unparseable values are
// safe (gallery falls back to site defaults). Override values are read
// only when useSiteDefaults is explicitly false — otherwise they're
// dropped from the persisted shape, keeping the file sparse.
require_once lawnding_admin_path('modules/mediaGallery/helpers.php');
lawnding_init_session();

media_gallery_require_method('POST');
media_gallery_require_edit_site();

$paneId = $_POST['paneId'] ?? '';
if (!is_string($paneId) || $paneId === '' || !media_gallery_is_valid_pane_id($paneId)) {
    media_gallery_json_response(['error' => 'Invalid pane id.'], 400);
}

$paths = media_gallery_paths();
$panes = media_gallery_load_panes($paths['panes_path']);
$pane = media_gallery_find_pane($panes, $paneId);
if (!$pane) {
    media_gallery_json_response(['error' => 'Pane not found.'], 404);
}

// Treat missing or any non-falsy useSiteDefaults value as true. The form
// posts the literal "0" only when the admin has explicitly unchecked
// "Use site defaults".
$useSiteDefaults = !isset($_POST['useSiteDefaults']) || $_POST['useSiteDefaults'] !== '0';

$settings = ['useSiteDefaults' => $useSiteDefaults];
if (!$useSiteDefaults) {
    $settings['customThumbs'] = !empty($_POST['customThumbs']);
    $settings['changeMedia']  = !empty($_POST['changeMedia']);
}

$settingsPath = media_gallery_settings_path($paths['data_dir'], $paneId);
if (!lawnding_save_pane_settings($settingsPath, $settings)) {
    media_gallery_json_response(['error' => 'Failed to write pane settings.'], 500);
}

media_gallery_json_response(['status' => 'ok', 'settings' => $settings]);
