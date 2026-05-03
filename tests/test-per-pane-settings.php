<?php
// Tests for the shared per-pane Settings modal data builder.
// The builder is the bridge between PHP-side state (panes.json + sidecars
// + manifest declarations) and the modal's JS layer. Pure-ish: file I/O
// happens only via lawnding_load_pane_settings (already tested in
// test-site-config.php), so most cases here exercise aggregation and
// shape correctness against a synthetic $panes input.
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../admin/lib/per-pane-settings.php';

// ---- Empty input ----

$out = lawnding_build_per_pane_settings_data([]);
test_assert(
    isset($out['panes']) && $out['panes'] === [] && isset($out['modules']) && $out['modules'] === [],
    'empty pane list produces empty panes/modules blocks'
);

// ---- Single mediaGallery pane (real manifest, no sidecar) ----
//
// mediaGallery declares per_pane_settings (customThumbs, changeMedia) AND
// settings_file. With no sidecar present (data dir clean for this pane id),
// useSiteDefaults defaults to true and resolvedValues equals site defaults
// (both false from lawnding_site_config_defaults).

$panesGallery = [
    [
        'id' => 'testGallery_doNotCollide',
        'name' => 'Test Gallery',
        'module' => 'mediaGallery',
        'icon' => ['type' => 'svg', 'value' => '<svg><circle cx="5" cy="5" r="3"/></svg>'],
    ],
];
$out = lawnding_build_per_pane_settings_data($panesGallery);
test_assert(
    isset($out['panes']['testGallery_doNotCollide']),
    'gallery pane appears keyed by paneId'
);

$pane = $out['panes']['testGallery_doNotCollide'];
test_assert(
    $pane['id'] === 'testGallery_doNotCollide' && $pane['module'] === 'mediaGallery',
    'pane carries id and module fields'
);
test_assert(
    $pane['icon']['type'] === 'svg' && strpos($pane['icon']['value'], '<svg') === 0,
    'icon type=svg passes through verbatim'
);
test_assert(
    $pane['useSiteDefaults'] === true,
    'no sidecar -> useSiteDefaults defaults to true'
);
test_assert(
    isset($pane['resolvedValues']['customThumbs']) && $pane['resolvedValues']['customThumbs'] === false
    && isset($pane['resolvedValues']['changeMedia']) && $pane['resolvedValues']['changeMedia'] === false,
    'resolvedValues populated from site defaults (both false)'
);

test_assert(
    isset($out['modules']['mediaGallery']['default_icon'])
    && strpos($out['modules']['mediaGallery']['default_icon'], '<svg') === 0,
    'modules block carries mediaGallery default_icon'
);
test_assert(
    is_array($out['modules']['mediaGallery']['per_pane_settings'])
    && count($out['modules']['mediaGallery']['per_pane_settings']) === 2,
    'modules block carries per_pane_settings declaration (2 entries for mediaGallery)'
);

// siteDefaults snapshot — used by the JS post-save handler to refresh
// resolvedValues when an admin saves with useSiteDefaults=true.
test_assert(
    isset($out['modules']['mediaGallery']['siteDefaults'])
    && is_array($out['modules']['mediaGallery']['siteDefaults'])
    && isset($out['modules']['mediaGallery']['siteDefaults']['customThumbs'])
    && $out['modules']['mediaGallery']['siteDefaults']['customThumbs'] === false
    && isset($out['modules']['mediaGallery']['siteDefaults']['changeMedia'])
    && $out['modules']['mediaGallery']['siteDefaults']['changeMedia'] === false,
    'modules block carries siteDefaults snapshot for declared keys (both default to false)'
);

// ---- Pane with module that declares no per_pane_settings ----
//
// basicText doesn't declare per_pane_settings, so the modal will only show
// the icon section. useSiteDefaults still defaults to true (the field is
// always present so JS doesn't have to special-case its absence), but
// resolvedValues is empty.

$panesBasic = [
    [
        'id' => 'aboutPane',
        'name' => 'About',
        'module' => 'basicText',
        'icon' => ['type' => 'none', 'value' => ''],
    ],
];
$out = lawnding_build_per_pane_settings_data($panesBasic);
test_assert(
    isset($out['panes']['aboutPane']),
    'basicText pane appears in output'
);
test_assert(
    $out['panes']['aboutPane']['useSiteDefaults'] === true,
    'module without per_pane_settings still gets useSiteDefaults=true'
);
test_assert(
    $out['panes']['aboutPane']['resolvedValues'] === [],
    'module without per_pane_settings gets empty resolvedValues'
);
test_assert(
    isset($out['modules']['basicText']['per_pane_settings'])
    && $out['modules']['basicText']['per_pane_settings'] === [],
    'modules block records empty per_pane_settings for basicText'
);

// ---- Multiple panes, same module aggregates once in modules block ----

$panesMulti = [
    ['id' => 'gallery1', 'name' => 'A', 'module' => 'mediaGallery'],
    ['id' => 'gallery2', 'name' => 'B', 'module' => 'mediaGallery'],
    ['id' => 'about', 'name' => 'C', 'module' => 'basicText'],
];
$out = lawnding_build_per_pane_settings_data($panesMulti);
test_assert(
    count($out['panes']) === 3,
    'three panes -> three entries in panes block'
);
test_assert(
    count($out['modules']) === 2 && isset($out['modules']['mediaGallery']) && isset($out['modules']['basicText']),
    'modules block deduplicated to one entry per unique module'
);

// ---- Defensive: malformed pane records dropped, output stays valid ----

$panesBad = [
    'not-an-array',
    ['id' => '', 'module' => 'mediaGallery'],         // missing id
    ['id' => 'orphan', 'module' => ''],                // missing module
    ['id' => 'good', 'module' => 'mediaGallery'],      // valid
    null,
];
$out = lawnding_build_per_pane_settings_data($panesBad);
test_assert(
    count($out['panes']) === 1 && isset($out['panes']['good']),
    'malformed pane records dropped; valid pane survives'
);

// ---- Defensive: missing or malformed icon record normalizes to none/'' ----

$panesIconShape = [
    ['id' => 'p1', 'module' => 'mediaGallery'],                                           // no icon key
    ['id' => 'p2', 'module' => 'mediaGallery', 'icon' => 'not-an-array'],                  // wrong type
    ['id' => 'p3', 'module' => 'mediaGallery', 'icon' => ['type' => 'bogus', 'value' => 'x']], // bad type
    ['id' => 'p4', 'module' => 'mediaGallery', 'icon' => ['type' => 'file', 'value' => 'logo.png']], // valid file
];
$out = lawnding_build_per_pane_settings_data($panesIconShape);
test_assert(
    $out['panes']['p1']['icon'] === ['type' => 'none', 'value' => ''],
    'missing icon key -> normalized to {type:none, value:""}'
);
test_assert(
    $out['panes']['p2']['icon'] === ['type' => 'none', 'value' => ''],
    'non-array icon -> normalized to none'
);
test_assert(
    $out['panes']['p3']['icon']['type'] === 'none',
    'unknown icon type -> normalized to none'
);
test_assert(
    $out['panes']['p4']['icon'] === ['type' => 'file', 'value' => 'logo.png'],
    'valid file icon passes through'
);

// ---- json_encode round-trip: output must serialize cleanly for the
// <script type="application/json"> blob ----

$panesRoundTrip = [['id' => 'rt1', 'name' => 'RT', 'module' => 'mediaGallery']];
$out = lawnding_build_per_pane_settings_data($panesRoundTrip);
$json = json_encode($out, JSON_UNESCAPED_SLASHES);
test_assert(
    is_string($json) && $json !== '' && json_last_error() === JSON_ERROR_NONE,
    'output json_encodes without errors'
);
$decoded = json_decode($json, true);
test_assert(
    isset($decoded['panes']['rt1']['module']) && $decoded['panes']['rt1']['module'] === 'mediaGallery',
    'round-trip through json preserves shape'
);
