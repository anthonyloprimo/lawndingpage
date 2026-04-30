<?php
// Tests for site-config + per-pane settings inheritance helpers in
// lp-bootstrap.php. The resolver is pure (caller passes loaded settings
// in), so most cases are exercised without touching the filesystem. The
// load/save helpers are exercised with a temp file.
require_once __DIR__ . '/bootstrap.php';

// ---- lawnding_resolve_pane_setting (pure) ----

// Empty settings array (sidecar settings.json doesn't exist) -> falls back
// to site config defaults. customThumbs default is false.
test_assert(
    lawnding_resolve_pane_setting([], 'mediaGallery', 'customThumbs') === false,
    'empty pane settings -> site default (customThumbs=false)'
);

// useSiteDefaults=true (explicit) behaves identically to absent useSiteDefaults
test_assert(
    lawnding_resolve_pane_setting(['useSiteDefaults' => true, 'customThumbs' => true], 'mediaGallery', 'customThumbs') === false,
    'useSiteDefaults=true ignores per-pane override values, returns site default'
);

// useSiteDefaults=false honors the per-pane value
test_assert(
    lawnding_resolve_pane_setting(['useSiteDefaults' => false, 'customThumbs' => true], 'mediaGallery', 'customThumbs') === true,
    'useSiteDefaults=false honors per-pane override (customThumbs=true)'
);
test_assert(
    lawnding_resolve_pane_setting(['useSiteDefaults' => false, 'customThumbs' => false], 'mediaGallery', 'customThumbs') === false,
    'useSiteDefaults=false honors per-pane override (customThumbs=false)'
);

// useSiteDefaults=false with a missing key -> safe default (false), not
// a fall-through to site config. Override means override.
test_assert(
    lawnding_resolve_pane_setting(['useSiteDefaults' => false], 'mediaGallery', 'customThumbs') === false,
    'useSiteDefaults=false with missing key -> false (no fall-through to site)'
);

// Non-bool override values are rejected (only strict true counts as enabled)
test_assert(
    lawnding_resolve_pane_setting(['useSiteDefaults' => false, 'customThumbs' => 1], 'mediaGallery', 'customThumbs') === false,
    'non-bool truthy values do not enable a setting (strict bool gate)'
);
test_assert(
    lawnding_resolve_pane_setting(['useSiteDefaults' => false, 'customThumbs' => 'yes'], 'mediaGallery', 'customThumbs') === false,
    'string "yes" does not enable a setting'
);

// Unknown module/key combos return false rather than erroring
test_assert(
    lawnding_resolve_pane_setting([], 'unknownModule', 'someKey') === false,
    'unknown module returns false'
);
test_assert(
    lawnding_resolve_pane_setting([], 'mediaGallery', 'unknownKey') === false,
    'unknown key returns false'
);

// ---- lawnding_load_pane_settings (file I/O) ----

// Missing file -> empty array
$tmpDir = sys_get_temp_dir() . '/lp-test-site-config-' . uniqid();
mkdir($tmpDir, 0775, true);
$settingsPath = $tmpDir . '/settings.json';

test_assert(
    lawnding_load_pane_settings($settingsPath) === [],
    'missing settings file -> empty array (treated as use-site-defaults)'
);

// Round-trip: save then load
$saved = lawnding_save_pane_settings($settingsPath, ['useSiteDefaults' => false, 'customThumbs' => true]);
test_assert($saved === true, 'save_pane_settings returns true on success');

$loaded = lawnding_load_pane_settings($settingsPath);
test_assert(
    isset($loaded['useSiteDefaults']) && $loaded['useSiteDefaults'] === false
    && isset($loaded['customThumbs']) && $loaded['customThumbs'] === true,
    'round-trip preserves override values verbatim'
);

// Malformed JSON -> empty array (defensive against hand-edited files)
file_put_contents($settingsPath, '{not valid json');
test_assert(
    lawnding_load_pane_settings($settingsPath) === [],
    'malformed settings.json -> empty array'
);

// Save creates parent dir if missing (so callers never have to mkdir first)
$nestedPath = $tmpDir . '/nested/inner/settings.json';
test_assert(
    lawnding_save_pane_settings($nestedPath, ['useSiteDefaults' => true]) === true,
    'save_pane_settings creates parent directory'
);
test_assert(
    is_file($nestedPath) && is_dir(dirname($nestedPath)),
    'parent directory exists after save'
);

// Cleanup
@unlink($settingsPath);
@unlink($nestedPath);
@rmdir(dirname($nestedPath));
@rmdir($tmpDir . '/nested');
@rmdir($tmpDir);

// ---- lawnding_load_site_config (defaults merge) ----

// Defaults helper returns the canonical baseline (changes here ripple
// through the admin UI and resolvers, so the test pins the shape).
$defaults = lawnding_site_config_defaults();
test_assert(
    isset($defaults['mediaGallery']['customThumbs']) && $defaults['mediaGallery']['customThumbs'] === false,
    'defaults: mediaGallery.customThumbs starts false'
);
test_assert(
    isset($defaults['mediaGallery']['changeMedia']) && $defaults['mediaGallery']['changeMedia'] === false,
    'defaults: mediaGallery.changeMedia starts false'
);
test_assert(
    isset($defaults['mediaGallery']['maxUploadSizeMB']) && $defaults['mediaGallery']['maxUploadSizeMB'] === 5,
    'defaults: mediaGallery.maxUploadSizeMB is 5 (MB)'
);

// lawnding_app_upload_max_bytes() respects site config (with hardcoded
// 5MB fallback when no admin/lp-siteConfig.json file exists, as in tests).
test_assert(
    lawnding_app_upload_max_bytes() === 5 * 1024 * 1024,
    'app upload cap reads site config default (5 MB) when no saved file'
);
