<?php
// Bootstrap shared paths/helpers and ensure config is available.
require_once __DIR__ . '/../lp-bootstrap.php';
// Prevent stale HTML/PHP responses from being cached.
$cacheHeadersPath = function_exists('lawnding_public_path')
    ? lawnding_public_path('res/scr/cache_headers.php')
    : __DIR__ . '/../public/res/scr/cache_headers.php';
require_once $cacheHeadersPath;
// Load the authoritative site version and set client cookie if needed.
$versionPath = function_exists('lawnding_public_path')
    ? lawnding_public_path('res/version.php')
    : __DIR__ . '/../public/res/version.php';
require_once $versionPath;
if (!isset($_COOKIE['site_version']) || $_COOKIE['site_version'] !== SITE_VERSION) {
    setcookie('site_version', SITE_VERSION, [
        'expires' => time() + 31536000,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => false,
        'samesite' => 'Lax'
    ]);
}

// Load Parsedown for Markdown rendering.
$parsedownPath = function_exists('lawnding_public_path')
    ? lawnding_public_path('res/scr/Parsedown.php')
    : __DIR__ . '/../public/res/scr/Parsedown.php';
require $parsedownPath;

// Helper for data file lookups that works with or without bootstrap helpers.
$dataPath = function (string $file): string {
    return function_exists('lawnding_data_path')
        ? lawnding_data_path($file)
        : __DIR__ . '/../public/res/data/' . $file;
};

// Read file contents if available; otherwise return the provided fallback.
$readFile = function (string $path, string $fallback = ''): string {
    return is_readable($path) ? (string) file_get_contents($path) : $fallback;
};

// Read JSON and return an array; if missing/invalid, return the fallback.
$readJson = function (string $path, array $fallback = []): array {
    if (!is_readable($path)) {
        return $fallback;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : $fallback;
};

// Render a shared SVG icon by name to avoid inline duplication.
function lawnding_icon_svg(string $name): string {
    static $paths = [
        'help' => 'M15.07,11.25L14.17,12.17C13.45,12.89 13,13.5 13,15H11V14.5C11,13.39 11.45,12.39 12.17,11.67L13.41,10.41C13.78,10.05 14,9.55 14,9C14,7.89 13.1,7 12,7A2,2 0 0,0 10,9H8A4,4 0 0,1 12,5A4,4 0 0,1 16,9C16,9.88 15.64,10.67 15.07,11.25M13,19H11V17H13M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12C22,6.47 17.5,2 12,2Z',
        'save' => 'M15,9H5V5H15M12,19A3,3 0 0,1 9,16A3,3 0 0,1 12,13A3,3 0 0,1 15,16A3,3 0 0,1 12,19M17,3H5C3.89,3 3,3.9 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V7L17,3Z',
        'move_up' => 'M13,20H11V8L5.5,13.5L4.08,12.08L12,4.16L19.92,12.08L18.5,13.5L13,8V20Z',
        'move_down' => 'M11,4H13V16L18.5,10.5L19.92,11.92L12,19.84L4.08,11.92L5.5,10.5L11,16V4Z',
        'delete' => 'M19,4H15.5L14.5,3H9.5L8.5,4H5V6H19M6,19A2,2 0 0,0 8,21H16A2,2 0 0,0 18,19V7H6V19Z',
        'links' => 'M19,3H5A2,2 0 0,0 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5A2,2 0 0,0 19,3M13.94,14.81L11.73,17C11.08,17.67 10.22,18 9.36,18C8.5,18 7.64,17.67 7,17C5.67,15.71 5.67,13.58 7,12.26L8.35,10.9L8.34,11.5C8.33,12 8.41,12.5 8.57,12.94L8.62,13.09L8.22,13.5C7.91,13.8 7.74,14.21 7.74,14.64C7.74,15.07 7.91,15.47 8.22,15.78C8.83,16.4 9.89,16.4 10.5,15.78L12.7,13.59C13,13.28 13.18,12.87 13.18,12.44C13.18,12 13,11.61 12.7,11.3C12.53,11.14 12.44,10.92 12.44,10.68C12.44,10.45 12.53,10.23 12.7,10.06C13.03,9.73 13.61,9.74 13.94,10.06C14.57,10.7 14.92,11.54 14.92,12.44C14.92,13.34 14.57,14.18 13.94,14.81M17,11.74L15.66,13.1V12.5C15.67,12 15.59,11.5 15.43,11.06L15.38,10.92L15.78,10.5C16.09,10.2 16.26,9.79 16.26,9.36C16.26,8.93 16.09,8.53 15.78,8.22C15.17,7.6 14.1,7.61 13.5,8.22L11.3,10.42C11,10.72 10.82,11.13 10.82,11.56C10.82,12 11,12.39 11.3,12.7C11.47,12.86 11.56,13.08 11.56,13.32C11.56,13.56 11.47,13.78 11.3,13.94C11.13,14.11 10.91,14.19 10.68,14.19C10.46,14.19 10.23,14.11 10.06,13.94C8.75,12.63 8.75,10.5 10.06,9.19L12.27,7C13.58,5.67 15.71,5.68 17,7C17.65,7.62 18,8.46 18,9.36C18,10.26 17.65,11.1 17,11.74Z',
        'users' => 'M12,5.5A3.5,3.5 0 0,1 15.5,9A3.5,3.5 0 0,1 12,12.5A3.5,3.5 0 0,1 8.5,9A3.5,3.5 0 0,1 12,5.5M5,8C5.56,8 6.08,8.15 6.53,8.42C6.38,9.85 6.8,11.27 7.66,12.38C7.16,13.34 6.16,14 5,14A3,3 0 0,1 2,11A3,3 0 0,1 5,8M19,8A3,3 0 0,1 22,11A3,3 0 0,1 19,14C17.84,14 16.84,13.34 16.34,12.38C17.2,11.27 17.62,9.85 17.47,8.42C17.92,8.15 18.44,8 19,8M5.5,18.25C5.5,16.18 8.41,14.5 12,14.5C15.59,14.5 18.5,16.18 18.5,18.25V20H5.5V18.25M0,20V18.5C0,17.11 1.89,15.94 4.45,15.6C3.86,16.28 3.5,17.22 3.5,18.25V20H0M24,20H20.5V18.25C20.5,17.22 20.14,16.28 19.55,15.6C22.11,15.94 24,17.11 24,18.5V20Z',
        'backgrounds' => 'M22.7 14.3L21.7 15.3L19.7 13.3L20.7 12.3C20.8 12.2 20.9 12.1 21.1 12.1C21.2 12.1 21.4 12.2 21.5 12.3L22.8 13.6C22.9 13.8 22.9 14.1 22.7 14.3M13 19.9V22H15.1L21.2 15.9L19.2 13.9L13 19.9M21 5C21 3.9 20.1 3 19 3H5C3.9 3 3 3.9 3 5V19C3 20.1 3.9 21 5 21H11V19.1L12.1 18H5L8.5 13.5L11 16.5L14.5 12L16.1 14.1L21 9.1V5Z',
        'about' => 'M13,9H11V7H13M13,17H11V11H13M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2Z',
        'rules' => 'M7,13V11H21V13H7M7,19V17H21V19H7M7,7V5H21V7H7M3,8V5H2V4H4V8H3M2,17V16H5V20H2V19H4V18.5H3V17.5H4V17H2M4.25,10A0.75,0.75 0 0,1 5,10.75C5,10.95 4.92,11.14 4.79,11.27L3.12,13H5V14H2V13.08L4,11H2V10H4.25Z',
        'faq' => 'M18,15H6L2,19V3A1,1 0 0,1 3,2H18A1,1 0 0,1 19,3V14A1,1 0 0,1 18,15M23,9V23L19,19H8A1,1 0 0,1 7,18V17H21V8H22A1,1 0 0,1 23,9M8.19,4C7.32,4 6.62,4.2 6.08,4.59C5.56,5 5.3,5.57 5.31,6.36L5.32,6.39H7.25C7.26,6.09 7.35,5.86 7.53,5.7C7.71,5.55 7.93,5.47 8.19,5.47C8.5,5.47 8.76,5.57 8.94,5.75C9.12,5.94 9.2,6.2 9.2,6.5C9.2,6.82 9.13,7.09 8.97,7.32C8.83,7.55 8.62,7.75 8.36,7.91C7.85,8.25 7.5,8.55 7.31,8.82C7.11,9.08 7,9.5 7,10H9C9,9.69 9.04,9.44 9.13,9.26C9.22,9.08 9.39,8.9 9.64,8.74C10.09,8.5 10.46,8.21 10.75,7.81C11.04,7.41 11.19,7 11.19,6.5C11.19,5.74 10.92,5.13 10.38,4.68C9.85,4.23 9.12,4 8.19,4M7,11V13H9V11H7M13,13H15V11H13V13M13,4V10H15V4H13Z',
        'events' => 'M19,19V8H5V19H19M16,1H18V3H19A2,2 0 0,1 21,5V19A2,2 0 0,1 19,21H5C3.89,21 3,20.1 3,19V5C3,3.89 3.89,3 5,3H6V1H8V3H16V1M7,10H9V12H7V10M15,10H17V12H15V10M11,14H13V16H11V14M15,14H17V16H15V14Z',
        'donate' => 'M7,15H9C9,16.08 10.37,17 12,17C13.63,17 15,16.08 15,15C15,13.9 13.96,13.5 11.76,12.97C9.64,12.44 7,11.78 7,9C7,7.21 8.47,5.69 10.5,5.18V3H13.5V5.18C15.53,5.69 17,7.21 17,9H15C15,7.92 13.63,7 12,7C10.37,7 9,7.92 9,9C9,10.1 10.04,10.5 12.24,11.03C14.36,11.56 17,12.22 17,15C17,16.79 15.53,18.31 13.5,18.82V21H10.5V18.82C8.47,18.31 7,16.79 7,15Z',
        'changelog' => 'M13,9H18.5L13,3.5V9M6,2H14L20,8V20A2,2 0 0,1 18,22H6C4.89,22 4,21.1 4,20V4C4,2.89 4.89,2 6,2M6.12,15.5L9.86,19.24L11.28,17.83L8.95,15.5L11.28,13.17L9.86,11.76L6.12,15.5M17.28,15.5L13.54,11.76L12.12,13.17L14.45,15.5L12.12,17.83L13.54,19.24L17.28,15.5Z',
    ];

    if (!isset($paths[$name])) {
        return '';
    }

    $path = htmlspecialchars($paths[$name], ENT_QUOTES, 'UTF-8');
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="' . $path . '" /></svg>';
}

// Shared modal wrapper to reduce repeated markup.
function lawnding_modal_open(string $id, string $title, array $options = []): void {
    $isOpen = !empty($options['is_open']);
    $extraClass = $options['extra_class'] ?? '';
    $ariaHidden = $options['aria_hidden'] ?? ($isOpen ? 'false' : 'true');
    $class = trim('userModalOverlay' . ($isOpen ? ' isOpen' : '') . ($extraClass ? ' ' . $extraClass : ''));

    echo '<div class="' . htmlspecialchars($class) . '" id="' . htmlspecialchars($id) . '" role="dialog" aria-modal="true" aria-hidden="' . htmlspecialchars($ariaHidden) . '">';
    echo '<div class="userModal glassConcave">';
    echo '<h4>' . htmlspecialchars($title) . '</h4>';
}

// Close the shared modal wrapper.
function lawnding_modal_close(): void {
    echo '</div></div>';
}

// Shared link row controls (move up/down, delete).
function lawnding_render_link_controls(): string {
    return ''
        . '<button class="moveUpLink iconButton" type="button" title="Move this entry up in the list." aria-label="Move this entry up in the list.">'
        . lawnding_icon_svg('move_up')
        . '</button>'
        . '<button class="moveDownLink iconButton" type="button" title="Move this entry down in the list." aria-label="Move this entry down in the list.">'
        . lawnding_icon_svg('move_down')
        . '</button>'
        . '<button class="deleteLink usersDanger iconButton" type="button" title="Removes this entry from the list." aria-label="Remove this entry from the list.">'
        . lawnding_icon_svg('delete')
        . '</button>';
}

// Shared user action buttons (permissions, reset, remove).
function lawnding_render_user_actions($username, $csrfToken, $permissionsDisabled, $resetDisabled, $removeDisabled): string {
    $usernameEsc = htmlspecialchars((string) $username);
    $csrfEsc = htmlspecialchars((string) $csrfToken);
    $permissionsAttr = $permissionsDisabled ? ' disabled' : '';
    $resetAttr = $resetDisabled ? ' disabled' : '';
    $removeAttr = $removeDisabled ? ' disabled' : '';

    return ''
        . '<div class="usersActions">'
        . '<button class="usersButton usersPermissionsButton iconButton" type="button" title="Permissions" aria-label="Permissions"' . $permissionsAttr . '>'
        . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M3,5H9V11H3V5M5,7V9H7V7H5M11,7H21V9H11V7M11,15H21V17H11V15M5,20L1.5,16.5L2.91,15.09L5,17.17L9.59,12.59L11,14L5,20Z" /></svg>'
        . '</button>'
        . '<form method="post" action="" class="usersResetForm" data-username="' . $usernameEsc . '">'
        . '<input type="hidden" name="action" value="reset_password">'
        . '<input type="hidden" name="csrf_token" value="' . $csrfEsc . '">'
        . '<input type="hidden" name="target_username" value="' . $usernameEsc . '">'
        . '<button class="usersButton usersResetButton iconButton" type="submit" title="Reset Password" aria-label="Reset Password"' . $resetAttr . '>'
        . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12.63,2C18.16,2 22.64,6.5 22.64,12C22.64,17.5 18.16,22 12.63,22C9.12,22 6.05,20.18 4.26,17.43L5.84,16.18C7.25,18.47 9.76,20 12.64,20A8,8 0 0,0 20.64,12A8,8 0 0,0 12.64,4C8.56,4 5.2,7.06 4.71,11H7.47L3.73,14.73L0,11H2.69C3.19,5.95 7.45,2 12.63,2M15.59,10.24C16.09,10.25 16.5,10.65 16.5,11.16V15.77C16.5,16.27 16.09,16.69 15.58,16.69H10.05C9.54,16.69 9.13,16.27 9.13,15.77V11.16C9.13,10.65 9.54,10.25 10.04,10.24V9.23C10.04,7.7 11.29,6.46 12.81,6.46C14.34,6.46 15.59,7.7 15.59,9.23V10.24M12.81,7.86C12.06,7.86 11.44,8.47 11.44,9.23V10.24H14.19V9.23C14.19,8.47 13.57,7.86 12.81,7.86Z" /></svg>'
        . '</button>'
        . '</form>'
        . '<button class="usersButton usersDanger usersRemoveButton iconButton" type="button" aria-label="Remove user" title="Remove this user"' . $removeAttr . '>'
        . lawnding_icon_svg('delete')
        . '</button>'
        . '</div>';
}

// Resolve the base URL prefix for admin assets and public resources.
$assetBase = '';
if (function_exists('lawnding_config')) {
    $assetBase = (string) lawnding_config('base_url', '');
}
if ($assetBase === '') {
    // Fallback when running directly from /public and base_url is unknown.
    if (empty($_SERVER['DOCUMENT_ROOT']) || !is_dir($_SERVER['DOCUMENT_ROOT'] . '/res')) {
        $assetBase = '/public';
    }
}
$assetBase = rtrim($assetBase, '/');

// Normalize asset URLs for config values that may be relative to /res.
$makeAssetUrl = function ($path) use ($assetBase) {
    if (!is_string($path) || $path === '') {
        return $path;
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    if (str_starts_with($path, $assetBase . '/')) {
        return $path;
    }
    if (str_starts_with($path, '/res/')) {
        return $assetBase . $path;
    }
    if (str_starts_with($path, 'res/')) {
        return $assetBase !== '' ? $assetBase . '/' . $path : '/' . $path;
    }
    return $path;
};

// Load changelog markdown from the project root (read-only pane).
$Parsedown = new Parsedown();
$rootDir = function_exists('lawnding_config')
    ? lawnding_config('root_dir', dirname(__DIR__))
    : dirname(__DIR__);
$changelogPath = rtrim($rootDir, '/') . '/CHANGELOG.md';
$changelogMarkdown = $readFile($changelogPath);
$changelog = $Parsedown->text($changelogMarkdown);

// Load link list configuration (JSON structure used by the editor).
$linksJsonPath = $dataPath('links.json');
$linksData = $readJson($linksJsonPath, []);

// Load header configuration, with defaults when JSON is missing.
$headerJsonPath = $dataPath('header.json');
$headerDefaults = [
    'logo' => 'res/img/logo.jpg',
    'title' => 'Long Island Furs',
    'subtitle' => 'A Long Island furry community encompassing Queens, Nassau County, and Suffolk County.  And Staten Island, but we do not talk about that.',
    'backgrounds' => ['res/img/bg.jpg'],
    'backgroundSettings' => [
        'mode' => 'random_load',
        'duration' => 5
    ]
];
$headerData = array_merge($headerDefaults, $readJson($headerJsonPath, []));
if (empty($headerData['backgroundSettings']) || !is_array($headerData['backgroundSettings'])) {
    $headerData['backgroundSettings'] = $headerDefaults['backgroundSettings'];
}
$backgroundSettings = array_merge($headerDefaults['backgroundSettings'], $headerData['backgroundSettings']);
$backgroundMode = (string) ($backgroundSettings['mode'] ?? $headerDefaults['backgroundSettings']['mode']);
$backgroundDuration = (int) ($backgroundSettings['duration'] ?? $headerDefaults['backgroundSettings']['duration']);
$validBackgroundModes = ['random_load', 'sequential_load', 'random_slideshow', 'sequential_slideshow'];
if (!in_array($backgroundMode, $validBackgroundModes, true)) {
    $backgroundMode = $headerDefaults['backgroundSettings']['mode'];
}
if ($backgroundDuration <= 0) {
    $backgroundDuration = (int) $headerDefaults['backgroundSettings']['duration'];
}
// Build a display-ready version with asset URLs resolved.
$headerDataDisplay = $headerData;
$headerDataDisplay['logo'] = $makeAssetUrl($headerDataDisplay['logo'] ?? '');
if (!empty($headerDataDisplay['backgrounds']) && is_array($headerDataDisplay['backgrounds'])) {
    $headerDataDisplay['backgrounds'] = array_map(function ($bg) use ($makeAssetUrl) {
        if (is_string($bg)) {
            return $makeAssetUrl($bg);
        }
        if (is_array($bg)) {
            $bg['url'] = $makeAssetUrl($bg['url'] ?? '');
            return $bg;
        }
        return $bg;
    }, $headerDataDisplay['backgrounds']);
}
// Keep raw background data for editing and file operations.
$backgrounds = [];
if (!empty($headerData['backgrounds']) && is_array($headerData['backgrounds'])) {
    $backgrounds = $headerData['backgrounds'];
}

// Load pane instances for dynamic module rendering.
$loadPanes = function (string $path): array {
    if (!is_readable($path)) {
        return [];
    }
    $decoded = json_decode(file_get_contents($path), true);
    if (!is_array($decoded) || !isset($decoded['panes']) || !is_array($decoded['panes'])) {
        return [];
    }
    return $decoded['panes'];
};

$sortPanes = function (array $panes): array {
    $indexed = [];
    foreach ($panes as $index => $pane) {
        if (is_array($pane)) {
            $pane['_index'] = $index;
            $indexed[] = $pane;
        }
    }
    usort($indexed, function ($a, $b) {
        $orderA = isset($a['order']) ? (int) $a['order'] : PHP_INT_MAX;
        $orderB = isset($b['order']) ? (int) $b['order'] : PHP_INT_MAX;
        if ($orderA === $orderB) {
            return ($a['_index'] ?? 0) <=> ($b['_index'] ?? 0);
        }
        return $orderA <=> $orderB;
    });
    foreach ($indexed as &$pane) {
        unset($pane['_index']);
    }
    return $indexed;
};

$isSafeSvg = function (?string $svg): bool {
    if (!is_string($svg) || $svg === '') {
        return false;
    }
    if (stripos($svg, '<script') !== false) {
        return false;
    }
    if (preg_match('/\\son[a-z]+\\s*=\\s*["\']?/i', $svg)) {
        return false;
    }
    return true;
};

$renderPaneIcon = function (array $pane) use ($makeAssetUrl, $isSafeSvg): string {
    $icon = $pane['icon'] ?? [];
    if (!is_array($icon)) {
        return '';
    }
    $type = $icon['type'] ?? '';
    if ($type === 'svg') {
        $svg = $icon['value'] ?? '';
        return $isSafeSvg($svg) ? $svg : '';
    }
    if ($type === 'file') {
        $value = $icon['value'] ?? '';
        if (!is_string($value) || $value === '') {
            return '';
        }
        $src = $makeAssetUrl('res/img/panes/' . ltrim($value, '/'));
        return '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="">';
    }
    return '';
};

// Load panes.json schema metadata to determine if migration is required.
$panesPath = $dataPath('panes.json');
$panesSchemaValid = false;
$panesSchemaVersion = null;
$paneSchemaTarget = defined('PANE_SCHEMA_VERSION') ? PANE_SCHEMA_VERSION : 1;
if (is_readable($panesPath)) {
    $raw = json_decode(file_get_contents($panesPath), true);
    if (is_array($raw) && isset($raw['schema_version']) && isset($raw['panes']) && is_array($raw['panes'])) {
        $panesSchemaValid = true;
        $panesSchemaVersion = (int) $raw['schema_version'];
    }
}
$needsMigration = !$panesSchemaValid || $panesSchemaVersion !== $paneSchemaTarget;
$migrationMode = 'missing';
if ($panesSchemaValid) {
    if ($panesSchemaVersion < $paneSchemaTarget) {
        $migrationMode = 'upgrade';
    } elseif ($panesSchemaVersion > $paneSchemaTarget) {
        $migrationMode = 'downgrade';
    } else {
        $migrationMode = 'ok';
    }
}
$panes = $sortPanes($loadPanes($panesPath));
$paneIds = array_values(array_filter(array_map(function ($pane) {
    return is_array($pane) ? ($pane['id'] ?? '') : '';
}, $panes), function ($value) {
    return is_string($value) && $value !== '';
}));

// Load module manifests to populate the pane management UI (type picker + previews).
$modulesDir = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('modules')
    : __DIR__ . '/modules';
$modulesCatalog = [];
if (is_dir($modulesDir)) {
    foreach (scandir($modulesDir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $ignorePath = rtrim($modulesDir, '/\\') . '/' . $entry . '/IGNORE_ME.txt';
        if (is_readable($ignorePath)) {
            continue;
        }
        $manifestPath = rtrim($modulesDir, '/\\') . '/' . $entry . '/' . $entry . '.json';
        if (!is_readable($manifestPath)) {
            continue;
        }
        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            continue;
        }
        $id = $manifest['id'] ?? $entry;
        if (!is_string($id) || $id === '') {
            continue;
        }
        // Resolve preview image through an authenticated proxy endpoint.
        $preview = $manifest['preview'] ?? '';
        $previewUrl = '';
        if (is_string($preview) && $preview !== '') {
            $base = $assetBase !== '' ? $assetBase : '';
            $previewUrl = $base . '/res/scr/module-preview.php?module=' . rawurlencode($id) . '&file=' . rawurlencode($preview);
        }
        $modulesCatalog[] = [
            'id' => $id,
            'name' => (string) ($manifest['name'] ?? $id),
            'description' => (string) ($manifest['description'] ?? ''),
            'preview' => $previewUrl,
        ];
    }
}
// Serialize pane data needed by the pane management UI.
$panesForJs = array_map(function ($pane) {
    if (!is_array($pane)) {
        return null;
    }
    return [
        'id' => (string) ($pane['id'] ?? ''),
        'name' => (string) ($pane['name'] ?? ''),
        'module' => (string) ($pane['module'] ?? ''),
        'icon' => is_array($pane['icon'] ?? null) ? $pane['icon'] : ['type' => 'none', 'value' => ''],
    ];
}, $panes);
$panesForJs = array_values(array_filter($panesForJs));

// Identify panes that reference missing module manifests.
$availableModules = array_map(function ($module) {
    return $module['id'] ?? '';
}, $modulesCatalog);
$availableModules = array_values(array_filter($availableModules, function ($value) {
    return is_string($value) && $value !== '';
}));
$missingModules = [];
$missingModulePanes = [];
foreach ($panes as $pane) {
    if (!is_array($pane)) {
        continue;
    }
    $moduleId = $pane['module'] ?? '';
    if (is_string($moduleId) && $moduleId !== '' && !in_array($moduleId, $availableModules, true)) {
        $missingModules[] = $moduleId;
        $paneName = $pane['name'] ?? '';
        $paneId = $pane['id'] ?? '';
        $label = is_string($paneName) && $paneName !== '' ? $paneName : (is_string($paneId) ? $paneId : '');
        if ($label !== '') {
            if (!isset($missingModulePanes[$moduleId])) {
                $missingModulePanes[$moduleId] = [];
            }
            $missingModulePanes[$moduleId][] = $label;
        }
    }
}
$missingModules = array_values(array_unique($missingModules));
$missingModuleDetails = [];
foreach ($missingModulePanes as $moduleId => $paneList) {
    $uniquePanes = array_values(array_unique($paneList));
    if (!empty($uniquePanes)) {
        $missingModuleDetails[] = $moduleId . ' (' . implode(', ', $uniquePanes) . ')';
    } else {
        $missingModuleDetails[] = $moduleId;
    }
}

// Permission gates and admin UI state (set by upstream controller).
$currentUserName = $_SESSION['auth_user'] ?? '';
$canAddUsers = $canAddUsers ?? true;
$canEditUsers = $canEditUsers ?? true;
$canRemoveUsers = $canRemoveUsers ?? true;
$canEditSite = $canEditSite ?? true;
$isFullAdmin = $isFullAdmin ?? false;
$isMasterUser = $isMasterUser ?? false;
// Collect server-side status messages to render at top of page.
$adminNotices = [];
if (!empty($usersErrors)) {
    $adminNotices[] = ['type' => 'danger', 'text' => implode(' ', $usersErrors)];
}
if (!empty($usersSuccess)) {
    $adminNotices[] = ['type' => 'ok', 'text' => $usersSuccess];
}
if (!empty($usersWarnings)) {
    $adminNotices[] = ['type' => 'danger', 'text' => implode(' ', $usersWarnings)];
}
$headerDataJson = htmlspecialchars(json_encode($headerDataDisplay, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
$appConfigPayload = [
    'basePath' => $assetBase,
    'currentUser' => $currentUserName,
    'canEditSite' => $canEditSite,
    'csrfToken' => $_SESSION['csrf_token'] ?? '',
    'paneIds' => array_values($paneIds),
    'panes' => $panesForJs,
    'modules' => $modulesCatalog,
];
$appConfigJson = htmlspecialchars(json_encode($appConfigPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
?>

<!DOCTYPE html>
<html lang="en" data-site-version="<?php echo htmlspecialchars(SITE_VERSION, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <script src="<?php echo htmlspecialchars(lawnding_versioned_url($assetBase . '/res/scr/site-version.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    
    <link rel="icon" type="image/jpg" href="<?php echo htmlspecialchars($assetBase); ?>/res/img/logo.jpg">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(lawnding_versioned_url($assetBase . '/res/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(lawnding_versioned_url($assetBase . '/res/config.css'), ENT_QUOTES, 'UTF-8'); ?>">

    <script src="<?php echo htmlspecialchars(lawnding_versioned_url($assetBase . '/res/scr/jquery-3.7.1.min.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
</head>
<body data-header-json="<?php echo $headerDataJson; ?>" data-app-config-json="<?php echo $appConfigJson; ?>">
    <!-- Runtime notices and admin alerts. -->
    <div id="noJsWarning"><noscript>This site requires JavaScript to function properly. Please enable JavaScript in your browser.</noscript></div>
    <div class="adminNotices" id="adminNotices">
        <?php foreach ($adminNotices as $notice): ?>
            <div class="adminNotice adminNotice--<?php echo htmlspecialchars($notice['type']); ?>">
                <span class="adminNoticeText"><?php echo htmlspecialchars($notice['text']); ?></span>
                <button type="button" class="adminNoticeClose" aria-label="Dismiss notification">×</button>
            </div>
        <?php endforeach; ?>
        <?php if (!empty($missingModules)): ?>
            <div class="adminNotice adminNotice--danger" data-persist="true">
                <span class="adminNoticeText">Missing pane modules detected: <?php echo htmlspecialchars(implode(', ', $missingModuleDetails)); ?>.</span>
                <button type="button" class="adminNoticeClose" aria-label="Dismiss notification">×</button>
            </div>
        <?php endif; ?>
        <?php if (!empty($needsMigration)): ?>
            <?php
                $migrationText = 'Migration required: pane configuration is missing or out of date. Migration will reset pane fields; copy your data before proceeding.';
                if ($migrationMode === 'downgrade') {
                    $migrationText = 'Pane schema is newer than this install. Migration may lose data or features and will reset pane fields; copy your data first.';
                } elseif ($migrationMode === 'upgrade') {
                    $migrationText = 'Pane schema is older than the current version. Migration will reset pane fields; copy your data before proceeding.';
                }
            ?>
            <div class="adminNotice adminNotice--danger" data-persist="true">
                <span class="adminNoticeText"><?php echo htmlspecialchars($migrationText); ?></span>
                <?php if (!empty($canEditSite) && empty($missingModules)): ?>
                    <button type="button" class="usersButton" id="migrationReviewButton">Review Migration</button>
                <?php elseif (!empty($canEditSite)): ?>
                    <button type="button" class="usersButton" disabled>Review Migration</button>
                <?php endif; ?>
                <button type="button" class="adminNoticeClose" aria-label="Dismiss notification">×</button>
            </div>
        <?php endif; ?>
        <?php if (($usersPermissionsFixResult ?? '') === 'ok'): ?>
            <div class="adminNotice adminNotice--ok">
                <span class="adminNoticeText">Updated `users.json` permissions to 0640.</span>
                <button type="button" class="adminNoticeClose" aria-label="Dismiss notification">×</button>
            </div>
        <?php elseif (($usersPermissionsFixResult ?? '') === 'fail'): ?>
            <div class="adminNotice adminNotice--danger" data-persist="true">
                <span class="adminNoticeText">Unable to update `users.json` permissions. Please set 0640 manually.</span>
                <button type="button" class="adminNoticeClose" aria-label="Dismiss notification">×</button>
            </div>
        <?php endif; ?>
        <?php if (!empty($usersPermissionsNeedsFix)): ?>
            <div class="adminNotice adminNotice--danger" data-persist="true">
                <span class="adminNoticeText">WARNING: `users.json` permissions appear too open. Recommended 0640.</span>
                <?php if (!empty($isFullAdmin)): ?>
                    <form method="post" action="">
                        <input type="hidden" name="action" value="fix_users_permissions">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                        <button type="submit">Fix</button>
                    </form>
                <?php endif; ?>
                <button type="button" class="adminNoticeClose" aria-label="Dismiss notification">×</button>
            </div>
        <?php endif; ?>
    </div>
    <!-- Overlay used during save operations. -->
    <div class="savingOverlay" id="savingOverlay" aria-hidden="true">
        <div class="savingOverlayContent">
            <div class="savingSpinner" aria-hidden="true"></div>
            <div class="savingText">Saving...</div>
        </div>
    </div>
    <!-- Header editor section (logo, title, actions). -->
    <header class="header" id="header">
        <div class="logo" id="logo">
            <button class="logoChange" type="button">Change</button>
            <input type="file" id="logoFileInput" accept="image/*" class="fileInputHidden">
        </div>
        <div class="headline">
            <input class="headlineInput" type="text" name="siteTitle" value="<?php echo htmlspecialchars($headerData['title'] ?? ''); ?>" aria-label="Site Title">
            <input class="headlineInput" type="text" name="siteSubtitle" value="<?php echo htmlspecialchars($headerData['subtitle'] ?? ''); ?>" aria-label="Site Subtitle">
        </div>
        <div class="headerActionStack">
            <div class="headerUserStack">
                <div class="signedInAs"><?php echo htmlspecialchars($_SESSION['auth_user'] ?? ''); ?></div>
                <form method="post" action="">
                    <input type="hidden" name="action" value="logout">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                    <button class="logoutButton" type="submit">Log Out</button>
                </form>
            </div>
            <div class="headerActions">
                <button class="helpTutorial iconButton" type="button" aria-label="Help" title="Need help?">
                    <?php echo lawnding_icon_svg('help'); ?>
                </button>
                <button class="saveChanges iconButton" type="button" aria-label="Save All Changes" title="Save all changes to the page.">
                    <?php echo lawnding_icon_svg('save'); ?>
                </button>
            </div>
        </div>
    </header>
    <!-- Main admin panes (links, users, backgrounds, content). -->
    <div class="container" id="container">
        <div class="pane glassConvex alwaysShow" id="links">
            <h3>LINKS</h3>
            <div class="linksConfig" id="linksConfig">
                <div class="linksConfigList">
                    <?php foreach ($linksData as $link): ?>
                        <?php if (($link['type'] ?? '') === 'separator'): ?>
                            <div class="linksConfigCard linksConfigSeparator">
                            <div class="linksConfigRow">
                                <span class="linksConfigLabel">Separator</span>
                                <span class="linksConfigSpacer"></span>
                                <?php echo lawnding_render_link_controls(); ?>
                                </div>
                            </div>
                        <?php elseif (($link['type'] ?? '') === 'link'): ?>
                        <?php
                            $href = $link['href'] ?? '';
                            $text = $link['text'] ?? '';
                            $title = $link['title'] ?? '';
                            $id = $link['id'] ?? '';
                            $isFullWidth = !empty($link['fullWidth']);
                            $isCta = !empty($link['cta']);
                        ?>
                        <div class="linksConfigCard">
                            <div class="linksConfigRow">
                                <label class="linksConfigField" title="The label that is displayed for each link.">
                                    <span class="linksConfigLabelText">Name</span>
                                    <input class="linksConfigInput" type="text" name="linkText[]" value="<?php echo htmlspecialchars($text); ?>" placeholder="Display text" title="The label that is displayed for each link.">
                                </label>
                                <div class="linksConfigField linksConfigIdField" title="The internal HTML ID of the link.  Make it unique.">
                                    <span class="linksConfigLabelText">ID</span>
                                    <span class="linksConfigIdValue" tabindex="0" aria-label="ID <?php echo htmlspecialchars($id); ?>">#<?php echo htmlspecialchars($id); ?></span>
                                    <input class="linksConfigIdInput" type="hidden" name="linkId[]" value="<?php echo htmlspecialchars($id); ?>">
                                </div>
                            </div>
                            <div class="linksConfigRow">
                                <label class="linksConfigField" title="The full URL (https: and all) to link to.">
                                    <span class="linksConfigLabelText">URL</span>
                                    <input class="linksConfigInput" type="text" name="linkUrl[]" value="<?php echo htmlspecialchars($href); ?>" placeholder="Link URL" title="The full URL (https: and all) to link to.">
                                </label>
                                <label class="linksConfigField" title="The text that appears when the user hovers over a link.">
                                    <span class="linksConfigLabelText">Tooltip</span>
                                    <input class="linksConfigInput" type="text" name="linkTitle[]" value="<?php echo htmlspecialchars($title); ?>" placeholder="Title attribute" title="The text that appears when the user hovers over a link.">
                                </label>
                            </div>
                            <div class="linksConfigRow linksConfigToggles">
                                <label class="linksConfigCheckbox" title="If checked, the link takes up the full width of the links pane.  Otherwise, it'll take up half of the width.">
                                    <input type="checkbox" name="linkFullWidth[]" <?php echo $isFullWidth ? 'checked' : ''; ?> title="If checked, the link takes up the full width of the links pane.  Otherwise, it'll take up half of the width.">
                                    Full width
                                </label>
                                <label class="linksConfigCheckbox" title="AKA Call to Action.  If checked, the link appears more prominently than the others.  Ideally, you will only want to use one, but you can set multiple links as a CTA button.">
                                    <input type="checkbox" name="linkCta[]" <?php echo $isCta ? 'checked' : ''; ?> title="AKA Call to Action.  If checked, the link appears more prominently than the others.  Ideally, you will only want to use one, but you can set multiple links as a CTA button.">
                                    CTA
                                </label>
                                <span class="linksConfigSpacer"></span>
                    <?php echo lawnding_render_link_controls(); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
                <div class="linksConfigActions">
                    <button class="addLink" type="button">Add link</button>
                    <button class="addSeparator" type="button">Add separator</button>
                </div>
            </div>
        </div>
        <div class="pane glassConvex" id="users">
            <h3>USERS</h3>
            <p class="usersNotice">Changes made here take effect immediately.</p>
            <div class="usersGrid">
                <div class="usersColumn">
                    <h4>Create User</h4>
                    <form class="usersBlock<?php echo $canAddUsers ? '' : ' isDisabled'; ?> usersCreateForm" method="post" action="">
                        <input type="hidden" name="action" value="create_user">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                        <label class="usersField">
                            Username
                            <input class="usersInput" type="text" name="new_username" placeholder="New username" required <?php echo $canAddUsers ? '' : 'disabled'; ?>>
                        </label>
                        <label class="usersField">
                            Temporary Password
                            <input class="usersInput" type="text" name="temp_password" placeholder="Temp password" required <?php echo $canAddUsers ? '' : 'disabled'; ?>>
                        </label>
                        <button class="usersButton" type="submit" <?php echo $canAddUsers ? '' : 'disabled'; ?>>Create User</button>
                        <?php if (!$canAddUsers): ?>
                            <p class="usersHint">You do not have permission to add users.</p>
                        <?php else: ?>
                            <p class="usersHint">New users must change their password on first login.</p>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="usersColumn">
                    <h4>Existing Users</h4>
                    <div class="usersBlock usersList">
                        <div class="usersRow usersHeader">
                            <span>User</span>
                            <span>Actions</span>
                        </div>
                        <?php if (!empty($users) && is_array($users)): ?>
                            <?php foreach ($users as $user): ?>
                                <?php
                                    $username = $user['username'] ?? '';
                                    $isMaster = !empty($user['master']);
                                    $isSelf = $username !== '' && $username === $currentUserName;
                                    $permissionsDisabled = $isMaster || (!$canEditUsers && !$isSelf);
                                    $resetDisabled = (!$canEditUsers && !$isSelf) || ($isMaster && !($isMasterUser || $isFullAdmin));
                                    $removeDisabled = $isMaster || (!$canRemoveUsers && !$isSelf);
                                    $tempPassword = $user['temp_password'] ?? '';
                                    $label = $username . ($isMaster ? ' (Master Account)' : '');
                                    if (!$isMaster && !empty($tempPassword)) {
                                        $label .= ' (Temporary password: ' . $tempPassword . ')';
                                    }
                                    $permissions = $user['permissions'] ?? [];
                                    if (!is_array($permissions)) {
                                        $permissions = [];
                                    }
                                    if (!empty($allowedPermissions) && is_array($allowedPermissions)) {
                                        $permissions = array_values(array_intersect($permissions, $allowedPermissions));
                                    }
                                    $permissionsAttr = htmlspecialchars(implode(',', $permissions));
                                    $csrfToken = $_SESSION['csrf_token'] ?? '';
                                ?>
                                <div class="usersRow" data-username="<?php echo htmlspecialchars($username); ?>" data-permissions="<?php echo $permissionsAttr; ?>">
                                    <span><?php echo htmlspecialchars($label); ?></span>
                                    <?php echo lawnding_render_user_actions($username, $csrfToken, $permissionsDisabled, $resetDisabled, $removeDisabled); ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="usersRow">
                                <span>No users found.</span>
                            </div>
                        <?php endif; ?>
                        <p class="usersHint">Reset issues a temporary password and forces a change on next login. Master accounts cannot be removed.</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="pane glassConvex" id="bg">
            <h3>BACKGROUND IMAGES</h3>
            <div class="paneHint">Images are saved immediately on upload. Adding or deleting a background saves immediately. To save Author, click 'Save All Changes' afterwards.</div>
            <div class="bgConfig" id="bgConfig">
                <div class="bgConfigRow bgConfigHeader">
                    <span>Preview</span>
                    <span>Author</span>
                    <span>URL</span>
                    <span aria-hidden="true"></span>
                </div>
                <?php foreach ($backgrounds as $bg): ?>
                    <?php
                        $bgUrl = '';
                        $bgAuthor = '';
                        $bgAuthorUrl = '';
                        if (is_string($bg)) {
                            $bgUrl = $bg;
                        } elseif (is_array($bg)) {
                            $bgUrl = $bg['url'] ?? '';
                            $bgAuthor = $bg['author'] ?? '';
                            $bgAuthorUrl = $bg['authorUrl'] ?? '';
                        }
                        $bgDisplayUrl = $makeAssetUrl($bgUrl);
                        $isEmptyBg = empty($bgUrl);
                    ?>
                    <div class="bgConfigRow" data-current-url="<?php echo htmlspecialchars($bgUrl); ?>" data-author-url="<?php echo htmlspecialchars($bgAuthorUrl); ?>">
                        <div class="bgThumbWrap <?php echo $isEmptyBg ? 'empty' : ''; ?>">
                            <img class="bgThumb" src="<?php echo htmlspecialchars($bgDisplayUrl); ?>" alt="Background preview">
                            <button class="bgChange" type="button">Change</button>
                        </div>
                        <input class="bgAuthorInput" type="text" name="bgAuthor[]" value="<?php echo htmlspecialchars($bgAuthor); ?>" placeholder="Author">
                        <input class="bgAuthorUrlInput" type="text" name="bgAuthorUrl[]" value="<?php echo htmlspecialchars($bgAuthorUrl); ?>" placeholder="URL">
                        <div class="bgRowActions">
                            <button class="moveUpLink iconButton" type="button" title="Move background up" aria-label="Move background up">
                                <?php echo lawnding_icon_svg('move_up'); ?>
                            </button>
                            <button class="moveDownLink iconButton" type="button" title="Move background down" aria-label="Move background down">
                                <?php echo lawnding_icon_svg('move_down'); ?>
                            </button>
                            <button class="deleteBackground usersDanger iconButton" type="button" aria-label="Delete background" title="Remove this background">
                                <?php echo lawnding_icon_svg('delete'); ?>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="bgConfigActions">
                    <div class="bgConfigOptions">
                        <div class="bgConfigOptionGroup">
                            <select class="bgConfigOptionSelect" id="bgModeSelect" aria-label="Background Mode">
                                <option value="random_load" <?php echo $backgroundMode === 'random_load' ? 'selected' : ''; ?>>Random on load</option>
                                <option value="sequential_load" <?php echo $backgroundMode === 'sequential_load' ? 'selected' : ''; ?>>Sequential on load</option>
                                <option value="random_slideshow" <?php echo $backgroundMode === 'random_slideshow' ? 'selected' : ''; ?>>Random slideshow</option>
                                <option value="sequential_slideshow" <?php echo $backgroundMode === 'sequential_slideshow' ? 'selected' : ''; ?>>Sequential slideshow</option>
                            </select>
                        </div>
                        <div class="bgConfigOptionDuration">
                            <label class="bgConfigOptionLabel" for="bgDurationInput">Duration</label>
                            <div class="bgConfigOptionDurationField">
                                <input class="bgConfigOptionInput" id="bgDurationInput" type="text" inputmode="numeric" value="<?php echo htmlspecialchars((string) $backgroundDuration); ?>" aria-label="Slideshow duration in seconds">
                                <span class="bgConfigOptionUnit">s</span>
                            </div>
                        </div>
                    </div>
                    <div class="bgConfigActionsRight">
                        <input type="file" id="bgFileInput" accept="image/*" class="fileInputHidden">
                        <button class="addBackground" type="button" title="Add new background image">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M18 15V18H15V20H18V23H20V20H23V18H20V15H18M13.3 21H5C3.9 21 3 20.1 3 19V5C3 3.9 3.9 3 5 3H19C20.1 3 21 3.9 21 5V13.3C20.4 13.1 19.7 13 19 13C17.9 13 16.8 13.3 15.9 13.9L14.5 12L11 16.5L8.5 13.5L5 18H13.1C13 18.3 13 18.7 13 19C13 19.7 13.1 20.4 13.3 21Z" /></svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php // Render each dynamic pane using its module admin template. ?>
        <?php foreach ($panes as $pane): ?>
            <?php
            $moduleId = isset($pane['module']) ? (string) $pane['module'] : '';
            $modulePath = function_exists('lawnding_admin_path')
                ? lawnding_admin_path('modules/' . $moduleId . '/admin.php')
                : __DIR__ . '/modules/' . $moduleId . '/admin.php';
            if ($moduleId !== '' && is_readable($modulePath)) {
                include $modulePath;
            }
            ?>
        <?php endforeach; ?>
        <div class="pane glassConvex" id="changelog">
            <?php echo $changelog; ?>
        </div>
    </div>
    <!-- Bottom navigation for pane switching. -->
    <nav>
        <div class="navBarWrap" id="navBarWrap">
            <ul class="navBar glassConcave" id="navBar">
            <li><a class="navLink" href="#" data-pane="users" aria-label="Users" title="User Management"><?php echo lawnding_icon_svg('users'); ?></a></li>
            <li><a class="navLink" href="#" data-pane="bg" aria-label="Backgrounds" title="Edit Random Background Images"><?php echo lawnding_icon_svg('backgrounds'); ?></a></li>
            <li class="navSeparator" aria-hidden="true"></li>
            <li><a class="navLink" href="#" data-pane="links" aria-label="Links" title="Links"><?php echo lawnding_icon_svg('links'); ?></a></li>
            <?php // Render dynamic pane nav items from panes.json. ?>
            <?php foreach ($panes as $pane): ?>
                <?php
                $paneId = isset($pane['id']) ? (string) $pane['id'] : '';
                $paneName = isset($pane['name']) ? (string) $pane['name'] : '';
                $icon = $renderPaneIcon($pane);
                if ($paneId === '') {
                    continue;
                }
                ?>
                <li class="navPaneItem" data-pane-id="<?php echo htmlspecialchars($paneId); ?>">
                    <a class="navLink" href="#" data-pane="<?php echo htmlspecialchars($paneId); ?>" aria-label="<?php echo htmlspecialchars($paneName); ?>" title="<?php echo htmlspecialchars($paneName); ?>">
                        <?php echo $icon; ?>
                    </a>
                    <?php if (!empty($canEditSite)): ?>
                        <button class="paneDeleteButton" type="button" data-pane-id="<?php echo htmlspecialchars($paneId); ?>" aria-label="Remove <?php echo htmlspecialchars($paneName); ?>" title="Remove <?php echo htmlspecialchars($paneName); ?>">×</button>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            <?php // Pane management button opens the modal (admin-only, edit permission required). ?>
            <?php if (!empty($canEditSite)): ?>
                <li class="navPaneManageItem">
                    <button class="paneManageButton" type="button" aria-label="Pane Management" title="Pane Management">Pane Management</button>
                </li>
            <?php endif; ?>
            <li class="navSeparator" aria-hidden="true"></li>
            <li><a class="navLink" href="#" data-pane="changelog" aria-label="Changelog" title="Changelog"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><title>Changelog</title><path d="M13,9H18.5L13,3.5V9M6,2H14L20,8V20A2,2 0 0,1 18,22H6C4.89,22 4,21.1 4,20V4C4,2.89 4.89,2 6,2M6.12,15.5L9.86,19.24L11.28,17.83L8.95,15.5L11.28,13.17L9.86,11.76L6.12,15.5M17.28,15.5L13.54,11.76L12.12,13.17L14.45,15.5L12.12,17.83L13.54,19.24L17.28,15.5Z" /></svg></a></li>
            </ul>
            <div class="navBarFadeLayer" aria-hidden="true">
                <div class="navBarFade navBarFadeLeft"></div>
                <div class="navBarFade navBarFadeRight"></div>
            </div>
        </div>
        <div class="footer">
            LawndingPage <?php echo htmlspecialchars(SITE_VERSION, ENT_QUOTES, 'UTF-8'); ?>.
        </div>
    </nav>
    <!-- Modal overlays for user actions and confirmations. -->
    <?php
        $resetPasswordOpen = !empty($resetPassword);
        lawnding_modal_open('resetPasswordModal', 'Temporary Password', ['is_open' => $resetPasswordOpen]);
    ?>
        <p class="usersHint">Copy this temporary password and send it to the person creating the account. They will be prompted to enter a new password the next time they log in.</p>
        <label class="usersField">
            Password for <?php echo htmlspecialchars($resetUsername ?? ''); ?>
            <input class="usersInput" type="text" readonly value="<?php echo htmlspecialchars($resetPassword ?? ''); ?>">
        </label>
        <div class="userModalActions">
            <?php if (!empty($resetLogoutAfterReset)): ?>
                <form method="post" action="">
                    <input type="hidden" name="action" value="logout">
                    <button class="usersButton" type="submit" data-modal-confirm="true">OK</button>
                </form>
            <?php else: ?>
                <button class="usersButton userModalClose" type="button" data-modal-confirm="true">OK</button>
            <?php endif; ?>
        </div>
    <?php lawnding_modal_close(); ?>

    <?php lawnding_modal_open('bgDeleteModal', 'Delete background image?'); ?>
        <p class="usersHint">This will remove the image from the list and delete the file from disk.</p>
        <div class="userModalActions">
            <button class="usersButton userModalClose" type="button">Cancel</button>
            <button class="usersButton usersDanger iconButton" id="bgDeleteConfirm" type="button" aria-label="Delete background" title="Remove this background" data-modal-confirm="true">
                <?php echo lawnding_icon_svg('delete'); ?>
            </button>
        </div>
    <?php lawnding_modal_close(); ?>

    <?php lawnding_modal_open('permissionsModal', 'User Permissions'); ?>
        <form method="post" action="" id="permissionsForm">
            <input type="hidden" name="action" value="save_permissions">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <input type="hidden" name="target_username" id="permissionsUsername" value="">
            <div class="permissionsList">
                <?php
                    $permissionLabels = [
                        'full_admin' => 'Full admin (all permissions, can reset master account password)',
                        'add_users' => 'Add users',
                        'edit_users' => 'Edit users (includes password reset & permissions)',
                        'remove_users' => 'Remove users',
                        'edit_site' => 'Edit site',
                    ];
                ?>
                <?php foreach ($allowedPermissions ?? [] as $permission): ?>
                    <?php $isFullAdminOption = $permission === 'full_admin'; ?>
                    <label class="permissionsItem">
                        <input type="checkbox" name="permissions[]" value="<?php echo htmlspecialchars($permission); ?>" <?php echo (!$isMasterUser && $isFullAdminOption) ? 'disabled' : ''; ?>>
                        <?php echo htmlspecialchars($permissionLabels[$permission] ?? $permission); ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="userModalActions">
                <button class="usersButton" type="submit" data-modal-confirm="true">Save</button>
                <button class="usersButton userModalClose" type="button">Cancel</button>
            </div>
        </form>
    <?php lawnding_modal_close(); ?>

    <?php lawnding_modal_open('removeUserModal', 'Remove Account'); ?>
        <p class="usersHint" id="removeUserWarning">WARNING: Clicking Delete will permanently remove this account. This cannot be reversed!</p>
        <form method="post" action="" id="removeUserForm">
            <input type="hidden" name="action" value="remove_user">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <input type="hidden" name="target_username" id="removeUsername" value="">
            <div class="userModalActions">
                <button class="usersButton usersDanger iconButton" type="submit" aria-label="Delete user" title="Remove this user" data-modal-confirm="true">
                    <?php echo lawnding_icon_svg('delete'); ?>
                </button>
                <button class="usersButton userModalClose" type="button">Cancel</button>
            </div>
        </form>
    <?php lawnding_modal_close(); ?>

    <?php lawnding_modal_open('permissionsSelfConfirmModal', 'Remove Your Permissions'); ?>
        <p class="usersHint">You are removing your own permissions. Another admin will need to re-enable them. Continue?</p>
        <div class="userModalActions">
            <button class="usersButton" type="button" id="permissionsSelfConfirmYes" data-modal-confirm="true">Yes</button>
            <button class="usersButton userModalClose" type="button">Cancel</button>
        </div>
    <?php lawnding_modal_close(); ?>

    <?php lawnding_modal_open('resetConfirmModal', 'Reset Password'); ?>
        <p class="usersHint" id="resetConfirmMessage">Are you sure you want to reset this password?</p>
        <div class="userModalActions">
            <button class="usersButton" type="button" id="resetConfirmYes" data-modal-confirm="true">Yes</button>
            <button class="usersButton userModalClose" type="button">No</button>
        </div>
    <?php lawnding_modal_close(); ?>

    <?php // Pane management modal: reorder, rename, add, and delete panes. ?>
    <?php lawnding_modal_open('paneManagementModal', 'Pane Management'); ?>
        <p class="usersHint">Manage panes, update their order, and change types.</p>
        <p class="usersHint">Saving will reload this page and discard unsaved edits.</p>
        <div class="paneManageList" id="paneManageList"></div>
        <div class="paneManageActions">
            <button class="usersButton" type="button" id="paneAddButton">Add Pane</button>
            <button class="usersButton" type="button" id="paneManageSave" data-modal-confirm="true">Save</button>
            <button class="usersButton userModalClose" type="button">Cancel</button>
        </div>
    <?php lawnding_modal_close(); ?>

    <?php // Pane type picker modal: select a module for add/change. ?>
    <?php lawnding_modal_open('paneTypeModal', 'Choose Pane Type'); ?>
        <div class="paneTypeList" id="paneTypeList">
            <?php foreach ($modulesCatalog as $module): ?>
                <button class="paneTypeOption" type="button"
                        data-module-id="<?php echo htmlspecialchars($module['id']); ?>"
                        data-module-name="<?php echo htmlspecialchars($module['name']); ?>"
                        data-module-desc="<?php echo htmlspecialchars($module['description']); ?>"
                        data-module-preview="<?php echo htmlspecialchars($module['preview']); ?>">
                    <div class="paneTypePreview">
                        <?php if (!empty($module['preview'])): ?>
                            <img src="<?php echo htmlspecialchars($module['preview']); ?>" alt="<?php echo htmlspecialchars($module['name']); ?> preview">
                        <?php else: ?>
                            <span class="paneTypePreviewFallback">No preview</span>
                        <?php endif; ?>
                    </div>
                    <div class="paneTypeMeta">
                        <span class="paneTypeName"><?php echo htmlspecialchars($module['name']); ?></span>
                        <?php if (!empty($module['description'])): ?>
                            <span class="paneTypeDesc"><?php echo htmlspecialchars($module['description']); ?></span>
                        <?php endif; ?>
                    </div>
                </button>
            <?php endforeach; ?>
        </div>
        <div class="paneManageActions">
            <button class="usersButton userModalClose" type="button">Close</button>
        </div>
    <?php lawnding_modal_close(); ?>

    <?php // Pane icon editor modal: SVG or image upload. ?>
    <?php lawnding_modal_open('paneIconModal', 'Edit Pane Icon'); ?>
        <div class="paneIconTabs">
            <button class="paneIconTab isActive" type="button" data-mode="svg">SVG</button>
            <button class="paneIconTab" type="button" data-mode="file">Image</button>
        </div>
        <div class="paneIconPanel isActive" data-mode="svg">
            <label class="paneIconLabel" for="paneIconSvgInput">SVG Markup</label>
            <textarea id="paneIconSvgInput" class="paneIconTextarea" rows="6" placeholder="<svg ...>"></textarea>
        </div>
        <div class="paneIconPanel" data-mode="file">
            <label class="paneIconLabel" for="paneIconFileInput">Icon File</label>
            <input type="file" id="paneIconFileInput" accept="image/*">
        </div>
        <div class="paneManageActions">
            <button class="usersButton usersDanger" type="button" id="paneIconRemove">Remove Icon</button>
            <button class="usersButton" type="button" id="paneIconSave" data-modal-confirm="true">Save</button>
            <button class="usersButton userModalClose" type="button">Cancel</button>
        </div>
    <?php lawnding_modal_close(); ?>

    <?php // Pane delete confirmation modal (warns about data deletion). ?>
    <?php lawnding_modal_open('paneDeleteConfirmModal', 'Remove Pane'); ?>
        <p class="usersHint" id="paneDeleteConfirmMessage">Are you sure you want to remove this pane? This will delete its data files.</p>
        <div class="userModalActions">
            <button class="usersButton usersDanger" type="button" id="paneDeleteConfirmYes" data-modal-confirm="true">Delete</button>
            <button class="usersButton userModalClose" type="button">Cancel</button>
        </div>
    <?php lawnding_modal_close(); ?>

    <?php // Event delete confirmation modal. ?>
    <?php lawnding_modal_open('eventDeleteConfirmModal', 'Remove Event'); ?>
        <p class="usersHint">Are you sure you want to remove this event?</p>
        <div class="userModalActions">
            <button class="usersButton usersDanger" type="button" id="eventDeleteConfirmYes" data-modal-confirm="true" autofocus>Remove</button>
            <button class="usersButton userModalClose" type="button">Cancel</button>
        </div>
    <?php lawnding_modal_close(); ?>

    <?php // Migration modal: preview and apply panes.json upgrade/downgrade. ?>
    <?php lawnding_modal_open('migrationModal', 'Pane Migration'); ?>
        <p class="usersHint">Review the proposed changes before applying migration. Migration will reset pane fields; copy your data first. This will reload the page.</p>
        <div class="migrationSummary" id="migrationSummary"></div>
        <div class="migrationDetails">
            <h4>Pane Configuration</h4>
            <pre class="migrationPre" id="migrationPanesPreview"></pre>
        </div>
        <div class="paneManageActions">
            <button class="usersButton" type="button" id="migrationApply" data-modal-confirm="true">Apply Migration</button>
            <button class="usersButton userModalClose" type="button">Cancel</button>
        </div>
    <?php lawnding_modal_close(); ?>
    <!-- Onboarding/tutorial overlay controlled by config.js. -->
    <div id="tutorialOverlay" class="hidden">
        <div id="mask-top" class="tutorialMask"></div>
        <div id="mask-left" class="tutorialMask"></div>
        <div id="mask-right" class="tutorialMask"></div>
        <div id="mask-bottom" class="tutorialMask"></div>
        <div id="tutorialPopover" class="tutorialPopover glassConcave">
            <div class="tutorialText"></div>
            <div class="tutorialControls">
                <button type="button" class="tutorialPrev">Previous</button>
                <button type="button" class="tutorialClose">Close Tutorial</button>
                <button type="button" class="tutorialNext">Next</button>
            </div>
        </div>
    </div>
    <script src="<?php echo htmlspecialchars(lawnding_versioned_url($assetBase . '/res/scr/admin-data.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script src="<?php echo htmlspecialchars(lawnding_versioned_url($assetBase . '/res/scr/app.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script src="<?php echo htmlspecialchars(lawnding_versioned_url($assetBase . '/res/scr/config.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
