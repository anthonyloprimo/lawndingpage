<?php
// Per-pane icon save. POST { paneId, iconType: 'none'|'svg', iconSvg?,
// csrf_token }. Mutates one pane's icon in panes.json. Driven by the
// per-pane Settings picker (library SVGs only; uploads belong in Site
// Config — see project_central_icon_upload_in_site_config).
// If the previous icon was a 'file' from the legacy bulk modal, the
// orphan under res/img/panes is unlinked on replace.
require_once __DIR__ . '/../../../lp-bootstrap.php';
require_once __DIR__ . '/pane-settings-helpers.php';
lawnding_init_session();

pane_settings_require_method('POST');
pane_settings_require_edit_site();

$paneId = $_POST['paneId'] ?? '';
if (!is_string($paneId) || !pane_settings_is_valid_pane_id($paneId)) {
    pane_settings_json_response(['error' => 'Invalid pane id.'], 400);
}

$iconType = $_POST['iconType'] ?? '';
if (!in_array($iconType, ['none', 'svg'], true)) {
    pane_settings_json_response(['error' => 'Invalid icon type.'], 400);
}

$paths = pane_settings_paths();
$panesData = lawnding_read_json($paths['panes_path']);
$panes = (is_array($panesData) && isset($panesData['panes']) && is_array($panesData['panes']))
    ? $panesData['panes']
    : null;
if ($panes === null) {
    pane_settings_json_response(['error' => 'Pane data missing.'], 500);
}

$paneIndex = pane_settings_find_pane_index($panes, $paneId);
if ($paneIndex < 0) {
    pane_settings_json_response(['error' => 'Pane not found.'], 404);
}

$existingIcon = is_array($panes[$paneIndex]['icon'] ?? null) ? $panes[$paneIndex]['icon'] : ['type' => 'none', 'value' => ''];
$existingType = is_string($existingIcon['type'] ?? null) ? $existingIcon['type'] : 'none';
$existingValue = is_string($existingIcon['value'] ?? null) ? $existingIcon['value'] : '';

$paneIconDir = rtrim($paths['public_dir'], '/\\') . '/res/img/panes';

$newIcon = ['type' => 'none', 'value' => ''];

if ($iconType === 'svg') {
    $svg = (string) ($_POST['iconSvg'] ?? '');
    // Strip <title> blocks before safe-svg gate (mirrors config.js).
    $svg = trim(preg_replace('/<title[^>]*>[\s\S]*?<\/title>/i', '', $svg));
    if ($svg === '') {
        pane_settings_json_response(['error' => 'SVG markup is empty.'], 400);
    }
    if (!lawnding_is_safe_svg($svg)) {
        pane_settings_json_response(['error' => 'SVG icons cannot contain scripts or inline event handlers.'], 400);
    }
    $newIcon = ['type' => 'svg', 'value' => $svg];
}
// (iconType === 'none' uses $newIcon's default value.)

// Unlink legacy file-upload icons when replaced. The endpoint can't
// set type='file' anymore, but it can replace one with svg/none.
if ($existingType === 'file' && $existingValue !== '') {
    $oldPath = rtrim($paneIconDir, '/\\') . '/' . basename($existingValue);
    if (is_readable($oldPath)) {
        @unlink($oldPath);
    }
}

$panes[$paneIndex]['icon'] = $newIcon;

if (!pane_settings_write_panes($paths['panes_path'], $panes)) {
    pane_settings_json_response(['error' => 'Failed to write panes.json.'], 500);
}

pane_settings_json_response(['status' => 'ok', 'icon' => $newIcon]);
