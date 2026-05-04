<?php
// Tests for the manifest-walker helpers in lp-bootstrap.php that drive
// the module-driven save flow + Site Config UI. These read real module
// manifests from admin/modules/, so the asserts are pinned against the
// shipped manifests (mediaGallery + the simpler basicText/etc). If a
// manifest changes shape, update both the manifest and the assertions
// here in the same commit.
require_once __DIR__ . '/bootstrap.php';

// ---- lawnding_module_manifest (shared decoder) ----

$mediaManifest = lawnding_module_manifest('mediaGallery');
test_assert(
    is_array($mediaManifest) && ($mediaManifest['id'] ?? '') === 'mediaGallery',
    'manifest reader decodes mediaGallery.json with matching id'
);
test_assert(
    isset($mediaManifest['site_config']['settings']),
    'mediaGallery manifest carries site_config.settings array'
);

// Unknown module id -> [] (no exception)
test_assert(
    lawnding_module_manifest('nonexistentModule') === [],
    'unknown module id returns empty array'
);

// Path-traversal guard: bad characters in id -> [] without touching FS
test_assert(
    lawnding_module_manifest('../escape') === [],
    'path-traversal id rejected'
);
test_assert(
    lawnding_module_manifest('') === [],
    'empty id returns empty array'
);

// ---- lawnding_discover_module_ids ----

$ids = lawnding_discover_module_ids();
test_assert(
    in_array('mediaGallery', $ids, true),
    'discovery includes mediaGallery'
);
test_assert(
    !in_array('_template', $ids, true),
    'discovery excludes _template (IGNORE_ME.txt sentinel)'
);
// Result is sorted -> deterministic across requests
$sorted = $ids;
sort($sorted);
test_assert(
    $ids === $sorted,
    'discovery returns sorted module ids for stable iteration order'
);

// ---- lawnding_module_pane_content_dir ----

$dir = lawnding_module_pane_content_dir('mediaGallery', 'aboutGallery');
test_assert(
    is_string($dir) && str_ends_with($dir, '/mediaGalleryContent-aboutGallery'),
    'pane_content_dir substitutes {paneId} into the manifest pattern'
);

// Module without pane_content_dir -> ''
test_assert(
    lawnding_module_pane_content_dir('basicText', 'aboutPane') === '',
    'pane_content_dir returns empty string for modules without pane_content_dir'
);

// Bad pane id -> '' (path-traversal guard)
test_assert(
    lawnding_module_pane_content_dir('mediaGallery', '../escape') === '',
    'pane_content_dir rejects path-traversal pane id'
);
test_assert(
    lawnding_module_pane_content_dir('mediaGallery', '') === '',
    'pane_content_dir rejects empty pane id'
);

// ---- lawnding_module_on_rename_callback ----

test_assert(
    lawnding_module_on_rename_callback('mediaGallery') === 'media_gallery_on_rename',
    'on_rename callback name read from mediaGallery manifest'
);
test_assert(
    lawnding_module_on_rename_callback('basicText') === '',
    'on_rename empty for modules without callback declared'
);

// ---- lawnding_module_save_map_validator ----

test_assert(
    lawnding_module_save_map_validator('mediaGallery', 'mediaChanges') === 'media_gallery_apply_changes',
    'save_map validator name found by key (mediaChanges -> media_gallery_apply_changes)'
);
test_assert(
    lawnding_module_save_map_validator('mediaGallery', 'unknownKey') === '',
    'save_map validator returns empty when key not found'
);
test_assert(
    lawnding_module_save_map_validator('basicText', 'content') === '',
    'save_map validator returns empty when entry has no validator field'
);

// ---- lawnding_module_data_file_initial ----

$initial = lawnding_module_data_file_initial('mediaGallery', 'mediaGalleryContent-{paneId}/items.json');
test_assert(
    is_array($initial) && isset($initial['items']) && $initial['items'] === [],
    'data_file initial returns the {items: []} payload from mediaGallery manifest'
);
test_assert(
    lawnding_module_data_file_initial('mediaGallery', 'no-such-pattern.json') === null,
    'data_file initial returns null for unmatched pattern'
);
test_assert(
    lawnding_module_data_file_initial('basicText', '{paneId}.md') === null,
    'data_file initial returns null when entry has no initial field'
);

// ---- lawnding_module_site_config ----

$schema = lawnding_module_site_config('mediaGallery');
test_assert(
    isset($schema['label']) && $schema['label'] === 'Media Gallery',
    'site_config label read from mediaGallery manifest'
);
test_assert(
    isset($schema['settings']) && is_array($schema['settings']) && count($schema['settings']) === 3,
    'site_config returns 3 settings entries (customThumbs, changeMedia, maxUploadSizeMB)'
);
$keys = array_column($schema['settings'], 'key');
test_assert(
    in_array('maxUploadSizeMB', $keys, true),
    'site_config carries maxUploadSizeMB entry'
);
foreach ($schema['settings'] as $entry) {
    if ($entry['key'] === 'maxUploadSizeMB') {
        test_assert(
            $entry['type'] === 'int' && $entry['default'] === 5,
            'maxUploadSizeMB declared as int with default 5'
        );
    }
    if ($entry['key'] === 'customThumbs') {
        test_assert(
            $entry['type'] === 'bool' && $entry['default'] === false,
            'customThumbs declared as bool with default false'
        );
    }
}

// Modules without site_config -> [] (not in SITE CONFIG UI)
test_assert(
    lawnding_module_site_config('basicText') === [],
    'site_config returns empty array for modules without site_config block'
);

// ---- lawnding_module_load_helpers (idempotent require_once) ----

test_assert(
    lawnding_module_load_helpers('mediaGallery') === true,
    'load_helpers returns true for mediaGallery (helpers.php exists)'
);
test_assert(
    function_exists('media_gallery_apply_changes'),
    'helpers.php side effect: media_gallery_apply_changes is defined after load'
);
// Idempotent — second call hits the cache, doesn't re-require
test_assert(
    lawnding_module_load_helpers('mediaGallery') === true,
    'load_helpers idempotent on repeat call'
);
test_assert(
    lawnding_module_load_helpers('basicText') === false,
    'load_helpers returns false for basicText (no helpers.php)'
);

// ---- lawnding_remove_directory ----

$tmpRoot = sys_get_temp_dir() . '/lp-test-remove-dir-' . uniqid();
mkdir($tmpRoot . '/sub/inner', 0775, true);
file_put_contents($tmpRoot . '/a.txt', 'a');
file_put_contents($tmpRoot . '/sub/b.txt', 'b');
file_put_contents($tmpRoot . '/sub/inner/c.txt', 'c');

lawnding_remove_directory($tmpRoot);
test_assert(
    !is_dir($tmpRoot),
    'remove_directory recursively deletes nested files + dirs'
);

// No-op on missing dir (caller doesn't have to is_dir-check first)
lawnding_remove_directory('/tmp/lp-test-this-does-not-exist-' . uniqid());
test_assert(true, 'remove_directory is a no-op on missing dir (no exception)');

// No-op on empty string (path-traversal-safe early return)
lawnding_remove_directory('');
test_assert(true, 'remove_directory is a no-op on empty string');

// ---- lawnding_site_config_defaults (manifest walker) ----
// Pinned shape: mediaGallery declares its three flags via its manifest.
// If a future module ships its own site_config block, this assertion
// still holds (it asserts presence, not exclusivity).

$defaults = lawnding_site_config_defaults();
test_assert(
    isset($defaults['mediaGallery']['customThumbs']) && $defaults['mediaGallery']['customThumbs'] === false,
    'walker reproduces mediaGallery.customThumbs default (false)'
);
test_assert(
    isset($defaults['mediaGallery']['maxUploadSizeMB']) && $defaults['mediaGallery']['maxUploadSizeMB'] === 5,
    'walker reproduces mediaGallery.maxUploadSizeMB default (5, int)'
);

// ---- lawnding_site_config_labels (sibling walker) ----

$labels = lawnding_site_config_labels();
test_assert(
    isset($labels['_modules']['mediaGallery']) && $labels['_modules']['mediaGallery'] === 'Media Gallery',
    'walker reproduces mediaGallery fieldset legend ("Media Gallery")'
);
test_assert(
    isset($labels['mediaGallery']['customThumbs']) && $labels['mediaGallery']['customThumbs'] === 'Allow per-item custom thumbnail uploads',
    'walker reproduces customThumbs label'
);
test_assert(
    isset($labels['mediaGallery']['maxUploadSizeMB']) && $labels['mediaGallery']['maxUploadSizeMB'] === 'Max upload size (MB)',
    'walker reproduces maxUploadSizeMB label'
);

// Modules without site_config block don't appear in labels
test_assert(
    !isset($labels['_modules']['basicText']),
    'basicText (no site_config) does not appear in fieldset legends'
);
test_assert(
    !isset($labels['basicText']),
    'basicText (no site_config) does not appear as label bucket'
);
