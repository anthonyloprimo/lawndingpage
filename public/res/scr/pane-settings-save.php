<?php
// Per-pane settings sidecar save. POST { paneId, module, useSiteDefaults,
// ...override values, csrf_token }. Writes the pane's settings.json
// (under the module's settings_file path).
// useSiteDefaults defaults true; "0" means admin unchecked the toggle and
// override values are read for each declared per_pane_settings key.
// Modules with no per_pane_settings return 200 persisted=false — the
// modal posts both endpoints unconditionally.
require_once __DIR__ . '/../../../lp-bootstrap.php';
require_once __DIR__ . '/pane-settings-helpers.php';
lawnding_init_session();

pane_settings_require_method('POST');
pane_settings_require_edit_site();

$paneId = $_POST['paneId'] ?? '';
if (!is_string($paneId) || !pane_settings_is_valid_pane_id($paneId)) {
    pane_settings_json_response(['error' => 'Invalid pane id.'], 400);
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

$pane = $panes[$paneIndex];
$moduleId = is_string($pane['module'] ?? null) ? $pane['module'] : '';
if ($moduleId === '') {
    pane_settings_json_response(['error' => 'Pane has no module.'], 500);
}

// Reject client/server module mismatches so a hand-crafted request can't
// claim a pane belongs to a different module than panes.json says.
$postedModule = $_POST['module'] ?? '';
if (is_string($postedModule) && $postedModule !== '' && $postedModule !== $moduleId) {
    pane_settings_json_response(['error' => 'Module mismatch.'], 400);
}

$declared = lawnding_module_per_pane_settings($moduleId);
if (empty($declared)) {
    // No sidecar to write — return success so the modal's sequenced
    // save flow doesn't need to special-case icon-only modules.
    pane_settings_json_response(['status' => 'ok', 'persisted' => false]);
}

$settingsPath = lawnding_module_settings_path($moduleId, $paneId);
if ($settingsPath === '') {
    pane_settings_json_response(['error' => 'Module has no settings_file declaration.'], 500);
}

$useSiteDefaults = !isset($_POST['useSiteDefaults']) || $_POST['useSiteDefaults'] !== '0';

// Preserve unknown keys (plugin contributions like subscribedFeeds). This
// endpoint owns useSiteDefaults + manifest-declared keys; everything else
// passes through unmodified so plugins can contribute to the same sidecar.
$existing = lawnding_load_pane_settings($settingsPath);
$ownedKeys = ['useSiteDefaults'];
foreach ($declared as $entry) {
    $ownedKeys[] = $entry['key'];
}
$settings = array_diff_key($existing, array_flip($ownedKeys));
$settings['useSiteDefaults'] = $useSiteDefaults;
if (!$useSiteDefaults) {
    foreach ($declared as $entry) {
        $key = $entry['key'];
        $type = $entry['type'];
        if ($type === 'bool') {
            // Checkbox keys only POST when checked: !empty maps absent→false.
            $settings[$key] = !empty($_POST[$key]);
        }
    }
}

if (!lawnding_save_pane_settings($settingsPath, $settings)) {
    pane_settings_json_response(['error' => 'Failed to write pane settings.'], 500);
}

pane_settings_json_response(['status' => 'ok', 'persisted' => true, 'settings' => $settings]);
