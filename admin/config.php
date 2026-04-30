<?php
// Bootstrap shared paths/helpers and ensure config is available.
require_once __DIR__ . '/../lp-bootstrap.php';
// Prevent stale HTML/PHP responses from being cached.
$cacheHeadersPath = function_exists('lawnding_public_path')
    ? lawnding_public_path('res/scr/cache_headers.php')
    : __DIR__ . '/../public/res/scr/cache_headers.php';
require_once $cacheHeadersPath;
// Load the authoritative site version for display and shared constants.
$versionPath = function_exists('lawnding_public_path')
    ? lawnding_public_path('res/version.php')
    : __DIR__ . '/../public/res/version.php';
require_once $versionPath;

// Load Parsedown for Markdown rendering.
$parsedownPath = function_exists('lawnding_public_path')
    ? lawnding_public_path('res/scr/Parsedown.php')
    : __DIR__ . '/../public/res/scr/Parsedown.php';
require $parsedownPath;

// Telegram normalization helpers (group/user entry dedup, content-level merge).
// Loaded transitively today via admin/auth.php in callers, but make the
// dependency explicit here since the admin config UI consumes these helpers
// directly to render the bot config pane.
require_once __DIR__ . '/lib/tg-auth.php';

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

// Render a shared SVG icon by name to avoid inline duplication.
// Label registry for the SITE CONFIG admin UI. Keyed by module then flag,
// with a top-level '_modules' map for fieldset legends. Adding a new flag
// to lawnding_site_config_defaults() without a label here gets a
// camelCase-split fallback ("customThumbs" -> "Custom thumbs"); add an
// entry below when you want a hand-written description.
function lawnding_site_config_labels(): array {
    return [
        '_modules' => [
            'mediaGallery' => 'Media Gallery',
        ],
        'mediaGallery' => [
            'customThumbs'    => 'Allow per-item custom thumbnail uploads',
            'changeMedia'     => 'Allow replacing media files on existing items',
            'maxUploadSizeMB' => 'Max upload size (MB)',
        ],
    ];
}

// Fallback for missing labels. "customThumbs" -> "Custom thumbs",
// "mediaGallery" -> "Media gallery". Splits on case transitions, lowers
// the rest, capitalizes the first word.
function lawnding_camel_to_label(string $key): string {
    $spaced = preg_replace('/([a-z])([A-Z])/', '$1 $2', $key);
    return ucfirst(strtolower((string) $spaced));
}

function lawnding_icon_svg(string $name): string {
    static $paths = [
        'help' => 'M15.07,11.25L14.17,12.17C13.45,12.89 13,13.5 13,15H11V14.5C11,13.39 11.45,12.39 12.17,11.67L13.41,10.41C13.78,10.05 14,9.55 14,9C14,7.89 13.1,7 12,7A2,2 0 0,0 10,9H8A4,4 0 0,1 12,5A4,4 0 0,1 16,9C16,9.88 15.64,10.67 15.07,11.25M13,19H11V17H13M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12C22,6.47 17.5,2 12,2Z',
        'save' => 'M15,9H5V5H15M12,19A3,3 0 0,1 9,16A3,3 0 0,1 12,13A3,3 0 0,1 15,16A3,3 0 0,1 12,19M17,3H5C3.89,3 3,3.9 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V7L17,3Z',
        'move_up' => 'M13,20H11V8L5.5,13.5L4.08,12.08L12,4.16L19.92,12.08L18.5,13.5L13,8V20Z',
        'move_down' => 'M11,4H13V16L18.5,10.5L19.92,11.92L12,19.84L4.08,11.92L5.5,10.5L11,16V4Z',
        'delete' => 'M19,4H15.5L14.5,3H9.5L8.5,4H5V6H19M6,19A2,2 0 0,0 8,21H16A2,2 0 0,0 18,19V7H6V19Z',
        'links' => 'M19,3H5A2,2 0 0,0 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5A2,2 0 0,0 19,3M13.94,14.81L11.73,17C11.08,17.67 10.22,18 9.36,18C8.5,18 7.64,17.67 7,17C5.67,15.71 5.67,13.58 7,12.26L8.35,10.9L8.34,11.5C8.33,12 8.41,12.5 8.57,12.94L8.62,13.09L8.22,13.5C7.91,13.8 7.74,14.21 7.74,14.64C7.74,15.07 7.91,15.47 8.22,15.78C8.83,16.4 9.89,16.4 10.5,15.78L12.7,13.59C13,13.28 13.18,12.87 13.18,12.44C13.18,12 13,11.61 12.7,11.3C12.53,11.14 12.44,10.92 12.44,10.68C12.44,10.45 12.53,10.23 12.7,10.06C13.03,9.73 13.61,9.74 13.94,10.06C14.57,10.7 14.92,11.54 14.92,12.44C14.92,13.34 14.57,14.18 13.94,14.81M17,11.74L15.66,13.1V12.5C15.67,12 15.59,11.5 15.43,11.06L15.38,10.92L15.78,10.5C16.09,10.2 16.26,9.79 16.26,9.36C16.26,8.93 16.09,8.53 15.78,8.22C15.17,7.6 14.1,7.61 13.5,8.22L11.3,10.42C11,10.72 10.82,11.13 10.82,11.56C10.82,12 11,12.39 11.3,12.7C11.47,12.86 11.56,13.08 11.56,13.32C11.56,13.56 11.47,13.78 11.3,13.94C11.13,14.11 10.91,14.19 10.68,14.19C10.46,14.19 10.23,14.11 10.06,13.94C8.75,12.63 8.75,10.5 10.06,9.19L12.27,7C13.58,5.67 15.71,5.68 17,7C17.65,7.62 18,8.46 18,9.36C18,10.26 17.65,11.1 17,11.74Z',
        'auth_links' => 'M23 16V15.5A2.5 2.5 0 0 0 18 15.5V16A1 1 0 0 0 17 17V21A1 1 0 0 0 18 22H23A1 1 0 0 0 24 21V17A1 1 0 0 0 23 16M22 16H19V15.5A1.5 1.5 0 0 1 22 15.5M7 8.9H11V7H7A5 5 0 0 0 7 17H11V15.1H7A3.1 3.1 0 0 1 7 8.9M8 11V13H16V11M13 15.1V17H15V15.1M17 7H13V8.9H17A3.09 3.09 0 0 1 19.94 11A5.12 5.12 0 0 1 20.5 11H21.9A5 5 0 0 0 17 7Z',
        'users' => 'M12,5.5A3.5,3.5 0 0,1 15.5,9A3.5,3.5 0 0,1 12,12.5A3.5,3.5 0 0,1 8.5,9A3.5,3.5 0 0,1 12,5.5M5,8C5.56,8 6.08,8.15 6.53,8.42C6.38,9.85 6.8,11.27 7.66,12.38C7.16,13.34 6.16,14 5,14A3,3 0 0,1 2,11A3,3 0 0,1 5,8M19,8A3,3 0 0,1 22,11A3,3 0 0,1 19,14C17.84,14 16.84,13.34 16.34,12.38C17.2,11.27 17.62,9.85 17.47,8.42C17.92,8.15 18.44,8 19,8M5.5,18.25C5.5,16.18 8.41,14.5 12,14.5C15.59,14.5 18.5,16.18 18.5,18.25V20H5.5V18.25M0,20V18.5C0,17.11 1.89,15.94 4.45,15.6C3.86,16.28 3.5,17.22 3.5,18.25V20H0M24,20H20.5V18.25C20.5,17.22 20.14,16.28 19.55,15.6C22.11,15.94 24,17.11 24,18.5V20Z',
        'backgrounds' => 'M22.7 14.3L21.7 15.3L19.7 13.3L20.7 12.3C20.8 12.2 20.9 12.1 21.1 12.1C21.2 12.1 21.4 12.2 21.5 12.3L22.8 13.6C22.9 13.8 22.9 14.1 22.7 14.3M13 19.9V22H15.1L21.2 15.9L19.2 13.9L13 19.9M21 5C21 3.9 20.1 3 19 3H5C3.9 3 3 3.9 3 5V19C3 20.1 3.9 21 5 21H11V19.1L12.1 18H5L8.5 13.5L11 16.5L14.5 12L16.1 14.1L21 9.1V5Z',
        'about' => 'M13,9H11V7H13M13,17H11V11H13M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2Z',
        'rules' => 'M7,13V11H21V13H7M7,19V17H21V19H7M7,7V5H21V7H7M3,8V5H2V4H4V8H3M2,17V16H5V20H2V19H4V18.5H3V17.5H4V17H2M4.25,10A0.75,0.75 0 0,1 5,10.75C5,10.95 4.92,11.14 4.79,11.27L3.12,13H5V14H2V13.08L4,11H2V10H4.25Z',
        'faq' => 'M18,15H6L2,19V3A1,1 0 0,1 3,2H18A1,1 0 0,1 19,3V14A1,1 0 0,1 18,15M23,9V23L19,19H8A1,1 0 0,1 7,18V17H21V8H22A1,1 0 0,1 23,9M8.19,4C7.32,4 6.62,4.2 6.08,4.59C5.56,5 5.3,5.57 5.31,6.36L5.32,6.39H7.25C7.26,6.09 7.35,5.86 7.53,5.7C7.71,5.55 7.93,5.47 8.19,5.47C8.5,5.47 8.76,5.57 8.94,5.75C9.12,5.94 9.2,6.2 9.2,6.5C9.2,6.82 9.13,7.09 8.97,7.32C8.83,7.55 8.62,7.75 8.36,7.91C7.85,8.25 7.5,8.55 7.31,8.82C7.11,9.08 7,9.5 7,10H9C9,9.69 9.04,9.44 9.13,9.26C9.22,9.08 9.39,8.9 9.64,8.74C10.09,8.5 10.46,8.21 10.75,7.81C11.04,7.41 11.19,7 11.19,6.5C11.19,5.74 10.92,5.13 10.38,4.68C9.85,4.23 9.12,4 8.19,4M7,11V13H9V11H7M13,13H15V11H13V13M13,4V10H15V4H13Z',
        'events' => 'M19,19V8H5V19H19M16,1H18V3H19A2,2 0 0,1 21,5V19A2,2 0 0,1 19,21H5C3.89,21 3,20.1 3,19V5C3,3.89 3.89,3 5,3H6V1H8V3H16V1M7,10H9V12H7V10M15,10H17V12H15V10M11,14H13V16H11V14M15,14H17V16H15V14Z',
        'donate' => 'M7,15H9C9,16.08 10.37,17 12,17C13.63,17 15,16.08 15,15C15,13.9 13.96,13.5 11.76,12.97C9.64,12.44 7,11.78 7,9C7,7.21 8.47,5.69 10.5,5.18V3H13.5V5.18C15.53,5.69 17,7.21 17,9H15C15,7.92 13.63,7 12,7C10.37,7 9,7.92 9,9C9,10.1 10.04,10.5 12.24,11.03C14.36,11.56 17,12.22 17,15C17,16.79 15.53,18.31 13.5,18.82V21H10.5V18.82C8.47,18.31 7,16.79 7,15Z',
        'changelog' => 'M13,9H18.5L13,3.5V9M6,2H14L20,8V20A2,2 0 0,1 18,22H6C4.89,22 4,21.1 4,20V4C4,2.89 4.89,2 6,2M6.12,15.5L9.86,19.24L11.28,17.83L8.95,15.5L11.28,13.17L9.86,11.76L6.12,15.5M17.28,15.5L13.54,11.76L12.12,13.17L14.45,15.5L12.12,17.83L13.54,19.24L17.28,15.5Z',
        'eye_open' => 'M12,9A3,3 0 0,0 9,12A3,3 0 0,0 12,15A3,3 0 0,0 15,12A3,3 0 0,0 12,9M12,17A5,5 0 0,1 7,12A5,5 0 0,1 12,7A5,5 0 0,1 17,12A5,5 0 0,1 12,17M12,4.5C7,4.5 2.73,7.61 1,12C2.73,16.39 7,19.5 12,19.5C17,19.5 21.27,16.39 23,12C21.27,7.61 17,4.5 12,4.5Z',
        'eye_closed' => 'M12 17.5C8.2 17.5 4.8 15.4 3.2 12H1C2.7 16.4 7 19.5 12 19.5S21.3 16.4 23 12H20.8C19.2 15.4 15.8 17.5 12 17.5Z',
        'diagnostics' => 'M13,14H11V9H13M13,18H11V16H13M1,21H23L12,2L1,21Z',
        'settings' => 'M12,15.5A3.5,3.5 0 0,1 8.5,12A3.5,3.5 0 0,1 12,8.5A3.5,3.5 0 0,1 15.5,12A3.5,3.5 0 0,1 12,15.5M19.43,12.97C19.47,12.65 19.5,12.33 19.5,12C19.5,11.67 19.47,11.34 19.43,11L21.54,9.37C21.73,9.22 21.78,8.95 21.66,8.73L19.66,5.27C19.54,5.05 19.27,4.96 19.05,5.05L16.56,6.05C16.04,5.66 15.5,5.32 14.87,5.07L14.5,2.42C14.46,2.18 14.25,2 14,2H10C9.75,2 9.54,2.18 9.5,2.42L9.13,5.07C8.5,5.32 7.96,5.66 7.44,6.05L4.95,5.05C4.73,4.96 4.46,5.05 4.34,5.27L2.34,8.73C2.21,8.95 2.27,9.22 2.46,9.37L4.57,11C4.53,11.34 4.5,11.67 4.5,12C4.5,12.33 4.53,12.65 4.57,12.97L2.46,14.63C2.27,14.78 2.21,15.05 2.34,15.27L4.34,18.73C4.46,18.95 4.73,19.03 4.95,18.95L7.44,17.94C7.96,18.34 8.5,18.68 9.13,18.93L9.5,21.58C9.54,21.82 9.75,22 10,22H14C14.25,22 14.46,21.82 14.5,21.58L14.87,18.93C15.5,18.67 16.04,18.34 16.56,17.94L19.05,18.95C19.27,19.03 19.54,18.95 19.66,18.73L21.66,15.27C21.78,15.05 21.73,14.78 21.54,14.63L19.43,12.97Z',
    ];

    if (!isset($paths[$name])) {
        return '';
    }

    $path = htmlspecialchars($paths[$name], ENT_QUOTES, 'UTF-8');
    // Decorative-by-default: every consumer wraps the icon in a button or link
    // that already provides an accessible name. aria-hidden prevents screen
    // readers from announcing the icon as a redundant element.
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' . $path . '" /></svg>';
}

// Shared modal wrapper to reduce repeated markup.
function lawnding_modal_open(string $id, string $title, array $options = []): void {
    $isOpen = !empty($options['is_open']);
    $extraClass = $options['extra_class'] ?? '';
    $ariaHidden = $options['aria_hidden'] ?? ($isOpen ? 'false' : 'true');
    $class = trim('userModalOverlay' . ($isOpen ? ' isOpen' : '') . ($extraClass ? ' ' . $extraClass : ''));
    // Pair the dialog with its title via aria-labelledby so screen readers
    // announce the modal's purpose, not just "dialog".
    $titleId = $id . '-title';

    echo '<div class="' . htmlspecialchars($class) . '" id="' . htmlspecialchars($id)
        . '" role="dialog" aria-modal="true" aria-hidden="' . htmlspecialchars($ariaHidden)
        . '" aria-labelledby="' . htmlspecialchars($titleId) . '">';
    echo '<div class="userModal glassConcave">';
    echo '<h4 id="' . htmlspecialchars($titleId) . '">' . htmlspecialchars($title) . '</h4>';
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
    // Fallback only when the browser is actually visiting the /public path.
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    if (is_string($scriptName) && str_starts_with($scriptName, '/public/')) {
        $assetBase = '/public';
    }
}
$assetBase = rtrim($assetBase, '/');

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
$linksPayload = lawnding_read_json($linksJsonPath);
$linksSettings = ['show_links' => true, 'auth_links' => false];
$linksData = [];
if (is_array($linksPayload) && array_key_exists('links', $linksPayload)) {
    $linksData = is_array($linksPayload['links']) ? $linksPayload['links'] : [];
    if (isset($linksPayload['settings']) && is_array($linksPayload['settings'])) {
        $linksSettings['show_links'] = !array_key_exists('show_links', $linksPayload['settings']) || !empty($linksPayload['settings']['show_links']);
        if (array_key_exists('auth_links', $linksPayload['settings'])) {
            $linksSettings['auth_links'] = !empty($linksPayload['settings']['auth_links']);
        }
    }
} elseif (is_array($linksPayload)) {
    $linksData = $linksPayload;
}

// Load authorized links configuration (same structure as links.json).
$authLinksJsonPath = $dataPath('authorizedLinks.json');
$authLinksPayload = lawnding_read_json($authLinksJsonPath);
$authLinksData = [];
if (is_array($authLinksPayload) && array_key_exists('links', $authLinksPayload)) {
    $authLinksData = is_array($authLinksPayload['links']) ? $authLinksPayload['links'] : [];
} elseif (is_array($authLinksPayload)) {
    $authLinksData = $authLinksPayload;
}
$authLinksEnabled = !empty($linksSettings['auth_links']);
$authLinksNeedsNormalization = false;

// Load Telegram bot configuration stored in admin/lp-tgBot.json.
$tgBotPath = function_exists('lawnding_config')
    ? lawnding_config('admin_dir', __DIR__) . '/lp-tgBot.json'
    : __DIR__ . '/lp-tgBot.json';
$tgBotDefaults = [
    'bot_username' => '',
    'bot_token' => '',
    'group_ids' => [],
    'whitelist_user_ids' => [],
    'blacklist_user_ids' => [],
    'membership_cache_ttl_minutes' => 30,
    'unauthorized_message' => 'Unable to display member links.  Join the telegram group with the link above, or contact an admin for assistance.',
];
$tgBotData = array_merge($tgBotDefaults, lawnding_read_json($tgBotPath));
// Normalize group + whitelist/blacklist entries via the canonical helpers in
// admin/lib/tg-auth.php (loaded near the top of this file). The helpers return
// content levels in lowercase ('sfw'/'nsfw') per the documented schema; the
// group rendering below already uppercases for display, and the whitelist/
// blacklist textareas explicitly uppercase via strtoupper for user-visible
// formatting consistent with the placeholder text.
$tgBotGroupIds = [];
if (!empty($tgBotData['group_ids']) && is_array($tgBotData['group_ids'])) {
    $tgBotGroupIds = lawnding_tg_normalize_group_entries($tgBotData['group_ids']);
}
$tgBotWhitelistUserIds = [];
if (!empty($tgBotData['whitelist_user_ids']) && is_array($tgBotData['whitelist_user_ids'])) {
    $tgBotWhitelistUserIds = lawnding_tg_normalize_user_entries($tgBotData['whitelist_user_ids']);
}
$tgBotWhitelistUserIdsText = implode("\n", array_map(fn(array $e): string => $e['id'] . ' ' . strtoupper($e['content']), $tgBotWhitelistUserIds));
$tgBotBlacklistUserIds = [];
if (!empty($tgBotData['blacklist_user_ids']) && is_array($tgBotData['blacklist_user_ids'])) {
    $tgBotBlacklistUserIds = lawnding_tg_normalize_user_entries($tgBotData['blacklist_user_ids']);
}
$tgBotBlacklistUserIdsText = implode("\n", array_map(fn(array $e): string => $e['id'] . ' ' . strtoupper($e['content']), $tgBotBlacklistUserIds));
$webhookBase = lawnding_request_is_secure() ? 'https' : 'http';
$webhookHost = $_SERVER['HTTP_HOST'] ?? 'your-domain.com';
$webhookUrl = $webhookBase . '://' . $webhookHost . ($assetBase ?? '') . '/plugins/telegram/webhook.php';

// Load header configuration, with defaults when JSON is missing.
$headerJsonPath = $dataPath('header.json');
$headerDefaults = [
    'logo' => 'res/img/logo.jpg',
    'title' => 'Title',
    'subtitle' => 'Subtitle',
    'backgrounds' => [],
    'backgroundSettings' => [
        'mode' => 'random_load',
        'duration' => 5
    ]
];
$headerData = array_merge($headerDefaults, lawnding_read_json($headerJsonPath));
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
$headerDataDisplay['logo'] = lawnding_make_asset_url($headerDataDisplay['logo'] ?? '');
if (!empty($headerDataDisplay['backgrounds']) && is_array($headerDataDisplay['backgrounds'])) {
    $headerDataDisplay['backgrounds'] = array_map(function ($bg) {
        if (is_string($bg)) {
            return lawnding_make_asset_url($bg);
        }
        if (is_array($bg)) {
            $bg['url'] = lawnding_make_asset_url($bg['url'] ?? '');
            return $bg;
        }
        return $bg;
    }, $headerDataDisplay['backgrounds']);
}
$faviconRaw = is_string($headerData['logo'] ?? null) && $headerData['logo'] !== ''
    ? $headerData['logo']
    : 'res/img/logo.jpg';
$faviconHref = lawnding_versioned_local_asset_url(
    $faviconRaw,
    defined('SITE_VERSION') ? (string) SITE_VERSION : ''
);
// Keep raw background data for editing and file operations.
$backgrounds = [];
if (!empty($headerData['backgrounds']) && is_array($headerData['backgrounds'])) {
    $backgrounds = $headerData['backgrounds'];
}


$renderPaneIcon = function (array $pane): string {
    $icon = $pane['icon'] ?? [];
    if (is_array($icon)) {
        $type = $icon['type'] ?? '';
        if ($type === 'svg') {
            $svg = $icon['value'] ?? '';
            if (is_string($svg) && $svg !== '' && lawnding_is_safe_svg($svg)) {
                return $svg;
            }
        } elseif ($type === 'file') {
            $value = $icon['value'] ?? '';
            if (is_string($value) && $value !== '') {
                $src = lawnding_make_asset_url('res/img/panes/' . ltrim($value, '/'));
                return '<img class="navLinkIconImage" src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="">';
            }
        }
    }
    $moduleId = $pane['module'] ?? '';
    if (is_string($moduleId) && $moduleId !== '') {
        $default = lawnding_module_default_icon($moduleId);
        if ($default !== '' && lawnding_is_safe_svg($default)) {
            return $default;
        }
    }
    return '';
};

// Load panes.json schema metadata to determine if migration is required.
$panesPath = $dataPath('panes.json');
$panesSchemaValid = false;
$panesSchemaVersion = null;
$paneSchemaTarget = defined('PANE_SCHEMA_VERSION') ? PANE_SCHEMA_VERSION : 1;
$raw = lawnding_read_json($panesPath);
if (isset($raw['schema_version'], $raw['panes']) && is_array($raw['panes'])) {
    $panesSchemaValid = true;
    $panesSchemaVersion = (int) $raw['schema_version'];
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
$panes = lawnding_sort_panes(lawnding_load_panes($panesPath));
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
        $manifest = lawnding_read_json($manifestPath);
        if (empty($manifest)) {
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
// $displayName/$authUser come from admin/index.php's resolver call;
// fall back to $_SESSION for older include paths that haven't migrated.
$currentUserName = isset($displayName) && $displayName !== ''
    ? $displayName
    : ($authUser ?? ($_SESSION['auth_user'] ?? ''));
$canAddUsers = $canAddUsers ?? true;
$canEditUsers = $canEditUsers ?? true;
$canRemoveUsers = $canRemoveUsers ?? true;
$canEditSite = $canEditSite ?? true;
$isFullAdmin = $isFullAdmin ?? false;
$isMasterUser = $isMasterUser ?? false;
$isReadOnlyUser = $isReadOnlyUser ?? false;
// Collect server-side status messages to render at top of page.
$adminNotices = [];
// Drain any one-shot session flash (e.g. revocation banner from the resolver)
// into the notice list so it renders on the dashboard too.
if (function_exists('lawnding_flash_consume')) {
    $sessionFlash = lawnding_flash_consume();
    if ($sessionFlash !== null) {
        $adminNotices[] = $sessionFlash;
    }
}
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
    'isMasterUser' => $isMasterUser,
    'isReadOnlyUser' => $isReadOnlyUser,
    'csrfToken' => $_SESSION['csrf_token'] ?? '',
    'paneIds' => array_values($paneIds),
    'panes' => $panesForJs,
    'modules' => $modulesCatalog,
];
$appConfigJson = htmlspecialchars(json_encode($appConfigPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <?php
        $adminPageTitleSiteName = isset($headerDataDisplay['title']) && is_string($headerDataDisplay['title'])
            ? trim($headerDataDisplay['title'])
            : '';
        $adminPageTitle = $adminPageTitleSiteName !== ''
            ? 'Admin Panel — ' . $adminPageTitleSiteName
            : 'Admin Panel';
    ?>
    <title><?php echo htmlspecialchars($adminPageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <?php // Deprecated: site-version.js cache-busting is no longer loaded. ?>

    <link rel="icon" href="<?php echo htmlspecialchars($faviconHref, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase . '/res/style.css', ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase . '/res/config.css', ENT_QUOTES, 'UTF-8'); ?>">

    <script src="<?php echo htmlspecialchars($assetBase . '/res/scr/jquery-3.7.1.min.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
</head>
<body data-header-json="<?php echo $headerDataJson; ?>" data-app-config-json="<?php echo $appConfigJson; ?>">
    <!-- Keyboard skip target — reveals on focus, jumps past header + tools. -->
    <a class="skipLink" href="#container">Skip to main content</a>
    <div class="hidden" id="tgBotTokenToggleClosed"><?php echo lawnding_icon_svg('eye_closed'); ?></div>
    <div class="hidden" id="tgBotTokenToggleOpen"><?php echo lawnding_icon_svg('eye_open'); ?></div>
    <div class="hidden" id="tgBotGroupDeleteIcon"><?php echo lawnding_icon_svg('delete'); ?></div>
    <!-- Runtime notices and admin alerts. -->
    <div id="noJsWarning"><noscript>This site requires JavaScript to function properly. Please enable JavaScript in your browser.</noscript></div>
    <div class="adminNotices" id="adminNotices" aria-live="polite" aria-atomic="true">
        <?php foreach ($adminNotices as $notice): ?>
            <div class="adminNotice adminNotice--<?php echo htmlspecialchars($notice['type']); ?>"<?php echo $notice['type'] === 'danger' ? ' role="alert"' : ''; ?>>
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
                <div class="signedInAs"><?php echo htmlspecialchars($currentUserName ?? ''); ?></div>
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
    <main class="container" id="container">
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
                    <label class="linksConfigCheckbox" title="Toggle whether the links list appears on the public website.">
                        <input type="checkbox" id="linksVisibleToggle" <?php echo !empty($linksSettings['show_links']) ? 'checked' : ''; ?>>
                        Show link list
                    </label>
                    <label class="linksConfigCheckbox" title="Toggle whether authorized links are enabled.">
                        <input type="checkbox" id="authLinksToggle" <?php echo $authLinksEnabled ? 'checked' : ''; ?>>
                        Show auth links
                    </label>
                    <span class="linksConfigSpacer"></span>
                    <button class="addLink" type="button">Add link</button>
                    <button class="addSeparator" type="button">Add separator</button>
                </div>
            </div>
        </div>
        <div class="pane glassConvex<?php echo $authLinksEnabled ? '' : ' hidden'; ?>" id="authLinks">
            <h3>AUTH LINKS</h3>
            <p class="usersNotice">These links are only shown after the authorization check succeeds.</p>
            <div class="linksConfig authLinksConfig" id="authLinksConfig" data-needs-normalization="<?php echo $authLinksNeedsNormalization ? 'true' : 'false'; ?>">
                <div class="authLinksGrid">
                    <div class="authLinksSidebar">
                        <div class="authLinksCard">
                            <label class="linksConfigField" title="Telegram bot token.">
                                <span class="linksConfigLabelText">Bot username</span>
                                <input class="linksConfigInput" id="tgBotUsername" type="text" value="<?php echo htmlspecialchars((string) ($tgBotData['bot_username'] ?? '')); ?>" placeholder="samplebot">
                            </label>
                            <label class="linksConfigField" title="Telegram bot token.">
                                <span class="linksConfigLabelText">Bot token</span>
                                <div class="authLinksTokenField">
                                    <input class="linksConfigInput" id="tgBotToken" type="password" value="<?php echo htmlspecialchars((string) ($tgBotData['bot_token'] ?? '')); ?>" autocomplete="new-password">
                                    <button class="iconButton authLinksTokenToggle" type="button" aria-label="Show token" title="Show/hide token" data-visible="false">
                                        <?php echo lawnding_icon_svg('eye_closed'); ?>
                                    </button>
                                </div>
                                <div class="authLinksFieldActions">
                                    <button class="usersButton usersWarning authLinksTestBotButton" type="button">Test bot</button>
                                </div>
                            </label>
                            <div class="linksConfigField tgBotGroupsField">
                                <span class="linksConfigLabelText">
                                    Groups
                                    <span class="authLinksHelpIcon authLinksHelpInline" title="How to get the group ID: add the bot to the group(s) you want to check and enter `/lpGetGroup` in each group. Copy the returned ID into a Group ID input below, pick the content level, and tick any admin permissions you want that group's members to gain.">
                                        <?php echo lawnding_icon_svg('help'); ?>
                                    </span>
                                </span>
                                <div class="tgBotGroupList" id="tgBotGroupList">
                                    <div class="tgBotGroupHeaderSuper">
                                        <span></span>
                                        <span></span>
                                        <span class="tgBotGroupSuperLabel">Permissions</span>
                                        <span></span>
                                    </div>
                                    <div class="tgBotGroupHeader">
                                        <span class="tgBotGroupHeadCell tgBotGroupHeadId">Group ID</span>
                                        <span class="tgBotGroupHeadCell tgBotGroupHeadContent">Content</span>
                                        <span class="tgBotGroupHeadCell">Edit<br>site</span>
                                        <span class="tgBotGroupHeadCell">Add<br>users</span>
                                        <span class="tgBotGroupHeadCell">Edit<br>users</span>
                                        <span class="tgBotGroupHeadCell">Rem<br>users</span>
                                        <span class="tgBotGroupHeadCell" aria-hidden="true"></span>
                                    </div>
                                    <?php foreach ($tgBotGroupIds as $entry): ?>
                                        <?php
                                            $gId = (string) ($entry['id'] ?? '');
                                            $gContent = strtoupper((string) ($entry['content'] ?? 'SFW')) === 'NSFW' ? 'NSFW' : 'SFW';
                                            $gPerms = is_array($entry['permissions'] ?? null) ? $entry['permissions'] : [];
                                        ?>
                                        <div class="tgBotGroupCard">
                                            <input class="linksConfigInput tgBotGroupIdInput" type="text" value="<?php echo htmlspecialchars($gId); ?>" placeholder="-1001234567890" aria-label="Group ID">
                                            <select class="linksConfigInput tgBotGroupContentSelect" aria-label="Content level">
                                                <option value="SFW" <?php echo $gContent === 'SFW' ? 'selected' : ''; ?>>SFW</option>
                                                <option value="NSFW" <?php echo $gContent === 'NSFW' ? 'selected' : ''; ?>>NSFW</option>
                                            </select>
                                            <label class="tgBotGroupPermCell" title="Edit site content (header, panes, links).">
                                                <input type="checkbox" class="tgBotGroupPerm" value="edit_site" aria-label="Edit site" <?php echo in_array('edit_site', $gPerms, true) ? 'checked' : ''; ?>>
                                            </label>
                                            <label class="tgBotGroupPermCell" title="Create new user accounts.">
                                                <input type="checkbox" class="tgBotGroupPerm" value="add_users" aria-label="Add users" <?php echo in_array('add_users', $gPerms, true) ? 'checked' : ''; ?>>
                                            </label>
                                            <label class="tgBotGroupPermCell" title="Edit existing user accounts.">
                                                <input type="checkbox" class="tgBotGroupPerm" value="edit_users" aria-label="Edit users" <?php echo in_array('edit_users', $gPerms, true) ? 'checked' : ''; ?>>
                                            </label>
                                            <label class="tgBotGroupPermCell" title="Remove user accounts.">
                                                <input type="checkbox" class="tgBotGroupPerm" value="remove_users" aria-label="Remove users" <?php echo in_array('remove_users', $gPerms, true) ? 'checked' : ''; ?>>
                                            </label>
                                            <button class="iconButton removeTgBotGroup" type="button" aria-label="Remove group" title="Remove group">
                                                <?php echo lawnding_icon_svg('delete'); ?>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="authLinksFieldActions">
                                    <button class="usersButton addTgBotGroup" type="button">Add group</button>
                                    <button class="usersButton usersWarning authLinksValidateGroupsButton" type="button">Validate Group IDs</button>
                                </div>
                            </div>
                            <label class="linksConfigField" title="Membership cache TTL in minutes.">
                                <span class="linksConfigLabelText">Membership cache TTL (minutes)</span>
                                <input class="linksConfigInput" id="tgBotCacheTtl" type="text" inputmode="numeric" value="<?php echo htmlspecialchars((string) ($tgBotData['membership_cache_ttl_minutes'] ?? 30)); ?>">
                            </label>
                            <label class="linksConfigField" title="Displayed when a user is not authorized.">
                                <span class="linksConfigLabelText">Unauthorized message</span>
                                <textarea class="linksConfigInput" id="tgBotUnauthorizedMessage" rows="4"><?php echo htmlspecialchars((string) ($tgBotData['unauthorized_message'] ?? $tgBotDefaults['unauthorized_message'])); ?></textarea>
                            </label>
                            <label class="linksConfigField" title="One user ID per line. Append SFW or NSFW after the ID. Grants access regardless of group membership.">
                                <span class="linksConfigLabelText">Whitelist User IDs</span>
                                <textarea class="linksConfigInput" id="tgBotWhitelistUserIds" rows="4" placeholder="123456789 SFW"><?php echo htmlspecialchars($tgBotWhitelistUserIdsText); ?></textarea>
                            </label>
                            <label class="linksConfigField" title="One user ID per line. Append SFW or NSFW after the ID. Denies access regardless of group membership.">
                                <span class="linksConfigLabelText">Blacklist User IDs</span>
                                <textarea class="linksConfigInput" id="tgBotBlacklistUserIds" rows="4" placeholder="123456789 NSFW"><?php echo htmlspecialchars($tgBotBlacklistUserIdsText); ?></textarea>
                            </label>
                            <div class="authLinksHelpBlock">
                                <p><strong>Bot setup</strong></p>
                                <ol>
                                    <li>Create a bot with BotFather and paste the token above.</li>
                                    <li>Set your login domain in BotFather with <code>/setdomain</code> (must match this website host).</li>
                                    <li>Add the bot to your group(s) and make it an admin.</li>
                                    <li>Register the webhook from a terminal:</li>
                                </ol>
                                <pre><code>curl -X POST "https://api.telegram.org/bot&lt;YOUR_BOT_TOKEN&gt;/setWebhook" \
  -H "Content-Type: application/json" \
  -d '{"url":"<?php echo htmlspecialchars($webhookUrl); ?>"}'</code></pre>
                                <p>In each group, type <code>/lpGetGroup</code> and paste the returned ID into Group IDs as <code>ID SFW</code> or <code>ID NSFW</code>.</p>
                                <p>Bot username can be entered with or without <code>@</code>; it is saved without the prefix.</p>
                            </div>
                        </div>
                    </div>
                    <div class="authLinksMain">
                        <div class="linksConfigList authLinksConfigList">
                            <?php foreach ($authLinksData as $link): ?>
                                <?php if (($link['type'] ?? '') === 'separator'): ?>
                                    <div class="linksConfigCard authLinksConfigCard linksConfigSeparator">
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
                                    $contentLevel = isset($link['content']) && is_string($link['content'])
                                        ? strtolower(trim($link['content']))
                                        : 'sfw';
                                    if ($contentLevel !== 'nsfw') {
                                        $contentLevel = 'sfw';
                                    }
                                    if (!array_key_exists('content', $link)) {
                                        $authLinksNeedsNormalization = true;
                                    }
                                    $isNsfw = $contentLevel === 'nsfw';
                                ?>
                                <div class="linksConfigCard authLinksConfigCard">
                                    <div class="linksConfigRow">
                                        <label class="linksConfigField" title="The label that is displayed for each link.">
                                            <span class="linksConfigLabelText">Name</span>
                                            <input class="linksConfigInput" type="text" name="authLinkText[]" value="<?php echo htmlspecialchars($text); ?>" placeholder="Display text" title="The label that is displayed for each link.">
                                        </label>
                                        <div class="linksConfigField linksConfigIdField" title="The internal HTML ID of the link.  Make it unique.">
                                            <span class="linksConfigLabelText">ID</span>
                                            <span class="linksConfigIdValue" tabindex="0" aria-label="ID <?php echo htmlspecialchars($id); ?>">#<?php echo htmlspecialchars($id); ?></span>
                                            <input class="linksConfigIdInput" type="hidden" name="authLinkId[]" value="<?php echo htmlspecialchars($id); ?>">
                                        </div>
                                    </div>
                                    <div class="linksConfigRow">
                                        <label class="linksConfigField" title="The full URL (https: and all) to link to.">
                                            <span class="linksConfigLabelText">URL</span>
                                            <input class="linksConfigInput" type="text" name="authLinkUrl[]" value="<?php echo htmlspecialchars($href); ?>" placeholder="Link URL" title="The full URL (https: and all) to link to.">
                                        </label>
                                        <label class="linksConfigField" title="The text that appears when the user hovers over a link.">
                                            <span class="linksConfigLabelText">Tooltip</span>
                                            <input class="linksConfigInput" type="text" name="authLinkTitle[]" value="<?php echo htmlspecialchars($title); ?>" placeholder="Title attribute" title="The text that appears when the user hovers over a link.">
                                        </label>
                                    </div>
                                    <div class="linksConfigRow linksConfigToggles">
                                        <label class="linksConfigCheckbox" title="If checked, the link takes up the full width of the links pane.  Otherwise, it'll take up half of the width.">
                                            <input type="checkbox" name="authLinkFullWidth[]" <?php echo $isFullWidth ? 'checked' : ''; ?> title="If checked, the link takes up the full width of the links pane.  Otherwise, it'll take up half of the width.">
                                            Full width
                                        </label>
                                        <label class="linksConfigCheckbox" title="AKA Call to Action.  If checked, the link appears more prominently than the others.  Ideally, you will only want to use one, but you can set multiple links as a CTA button.">
                                            <input type="checkbox" name="authLinkCta[]" <?php echo $isCta ? 'checked' : ''; ?> title="AKA Call to Action.  If checked, the link appears more prominently than the others.  Ideally, you will only want to use one, but you can set multiple links as a CTA button.">
                                            CTA
                                        </label>
                                        <label class="linksConfigCheckbox" title="If checked, this authorized link is marked as NSFW content. If unchecked, it is treated as SFW.">
                                            <input type="checkbox" name="authLinkNsfw[]" <?php echo $isNsfw ? 'checked' : ''; ?> title="If checked, this authorized link is marked as NSFW content. If unchecked, it is treated as SFW.">
                                            NSFW
                                        </label>
                                        <span class="linksConfigSpacer"></span>
                                        <?php echo lawnding_render_link_controls(); ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <div class="linksConfigActions">
                            <span class="linksConfigSpacer"></span>
                            <button class="addAuthLink" type="button">Add link</button>
                            <button class="addAuthSeparator" type="button">Add separator</button>
                        </div>
                    </div>
                </div>
            </div>
            <script>
                (function() {
                    var authLinksConfig = document.getElementById('authLinksConfig');
                    if (!authLinksConfig) {
                        return;
                    }
                    authLinksConfig.setAttribute('data-needs-normalization', <?php echo $authLinksNeedsNormalization ? "'true'" : "'false'"; ?>);
                }());
            </script>
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
                                    $isReadOnly = !empty($user['read_only']);
                                    $isSelf = $username !== '' && $username === $currentUserName;
                                    $permissions = $user['permissions'] ?? [];
                                    if (!is_array($permissions)) {
                                        $permissions = [];
                                    }
                                    if (!empty($allowedPermissions) && is_array($allowedPermissions)) {
                                        $permissions = array_values(array_intersect($permissions, $allowedPermissions));
                                    }
                                    $isFullAdminTarget = $isMaster || in_array('full_admin', $permissions, true);
                                    $canManageFullAdmin = $isMasterUser || $isFullAdmin;
                                    $canManageReadOnly = $isMasterUser || $isFullAdmin;
                                    $permissionsHierarchyBlocked = ($isMaster && !$isMasterUser)
                                        || ($isFullAdminTarget && !$canManageFullAdmin)
                                        || ($isReadOnly && !$canManageReadOnly);
                                    $resetHierarchyBlocked = ($isMaster && !($isMasterUser || $isFullAdmin))
                                        || ($isFullAdminTarget && !$canManageFullAdmin)
                                        || ($isReadOnly && !$canManageReadOnly);
                                    $removeHierarchyBlocked = ($isMaster && !$isMasterUser)
                                        || ($isFullAdminTarget && !$canManageFullAdmin)
                                        || ($isReadOnly && !$canManageReadOnly);
                                    $permissionsDisabled = $permissionsHierarchyBlocked || (!$canEditUsers && !$isSelf);
                                    $resetDisabled = $resetHierarchyBlocked
                                        || (!$canEditUsers && !$isSelf)
                                        || ($isReadOnlyUser && $isSelf);
                                    $removeDisabled = $removeHierarchyBlocked
                                        || $isMaster
                                        || (!$canRemoveUsers && !$isSelf)
                                        || ($isReadOnlyUser && $isSelf);
                                    $tempPassword = $user['temp_password'] ?? '';
                                    $label = $username . ($isMaster ? ' (Master Account)' : '');
                                    if ($isReadOnly) {
                                        $label .= ' (Read-only)';
                                    }
                                    if (!$isMaster && !empty($tempPassword)) {
                                        $label .= ' (Temporary password: ' . $tempPassword . ')';
                                    }
                                    $permissionsAttr = htmlspecialchars(implode(',', $permissions));
                                    $csrfToken = $_SESSION['csrf_token'] ?? '';
                                ?>
                                <div class="usersRow"
                                     data-username="<?php echo htmlspecialchars($username); ?>"
                                     data-permissions="<?php echo $permissionsAttr; ?>"
                                     data-master="<?php echo $isMaster ? 'true' : 'false'; ?>"
                                     data-readonly="<?php echo $isReadOnly ? 'true' : 'false'; ?>">
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
                <div class="bgConfigList">
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
                            $bgDisplayUrl = lawnding_make_asset_url($bgUrl);
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
                </div>
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
        <?php // Diagnostics: tail recent runtime errors captured from the site. Gated on edit_site. ?>
        <?php if (!empty($canEditSite)): ?>
        <div class="pane glassConvex" id="diagnostics">
            <h3>DIAGNOSTICS</h3>
            <p class="paneHint">Recent runtime errors captured from the site. Useful when something on the public page or the admin flow misbehaves.</p>
            <div class="diagnosticsToolbar">
                <label class="diagnosticsToggle">
                    <input type="checkbox" id="diagnosticsAutoRefresh">
                    <span>Auto-refresh (5s)</span>
                </label>
                <button type="button" class="diagnosticsRefresh" id="diagnosticsRefresh">Refresh now</button>
                <div class="diagnosticsSevFilters" role="group" aria-label="Filter by severity">
                    <label class="diagnosticsSevFilter">
                        <input type="checkbox" data-sev="error" checked>
                        <span class="diagnosticsSevLabel diagnosticsSevLabel--error">Error</span>
                    </label>
                    <label class="diagnosticsSevFilter">
                        <input type="checkbox" data-sev="warn" checked>
                        <span class="diagnosticsSevLabel diagnosticsSevLabel--warn">Warn</span>
                    </label>
                    <label class="diagnosticsSevFilter">
                        <input type="checkbox" data-sev="info" checked>
                        <span class="diagnosticsSevLabel diagnosticsSevLabel--info">Info</span>
                    </label>
                    <label class="diagnosticsSevFilter">
                        <input type="checkbox" data-sev="debug" checked>
                        <span class="diagnosticsSevLabel diagnosticsSevLabel--debug">Debug</span>
                    </label>
                </div>
                <span class="diagnosticsStatus" id="diagnosticsStatus" aria-live="polite"></span>
            </div>
            <div
                id="diagnosticsViewer"
                class="diagnosticsViewer"
                data-tail-url="<?php echo htmlspecialchars(lawnding_asset_url('res/scr/errors-tail.php'), ENT_QUOTES, 'UTF-8'); ?>"
            >
                <p class="diagnosticsEmpty">No entries loaded yet.</p>
            </div>
        </div>
        <?php endif; ?>
        <?php // Site Config: site-wide module defaults. New panes inherit these; per-pane overrides live in the gear-icon modal on each gallery. Gated on edit_site for now; tighten to full_admin if these flags ever drive security-sensitive behavior. ?>
        <?php if (!empty($canEditSite)): ?>
        <?php
            // Walk lawnding_site_config_defaults() so adding a new flag in
            // code auto-renders here. Labels come from the site-config
            // label registry above; missing labels fall back to a
            // camelCase-split version of the key.
            $siteConfig = lawnding_load_site_config();
            $siteConfigDefaults = lawnding_site_config_defaults();
            $siteConfigLabels = lawnding_site_config_labels();
            $moduleLabels = is_array($siteConfigLabels['_modules'] ?? null) ? $siteConfigLabels['_modules'] : [];
        ?>
        <div class="pane glassConvex" id="siteConfig">
            <h3>SITE CONFIG</h3>
            <p class="paneHint">Defaults that newly created panes inherit. Existing panes can opt out per-instance via the gear icon on each pane.</p>
            <input type="hidden" name="siteConfig[__rendered]" value="1">
            <?php foreach ($siteConfigDefaults as $moduleKey => $flags): ?>
                <?php if (!is_array($flags) || empty($flags)) continue; ?>
                <?php $moduleLabel = $moduleLabels[$moduleKey] ?? lawnding_camel_to_label($moduleKey); ?>
                <fieldset class="siteConfigGroup">
                    <legend><?php echo htmlspecialchars($moduleLabel); ?></legend>
                    <?php foreach ($flags as $flagKey => $defaultValue): ?>
                        <?php
                            $flagLabel = $siteConfigLabels[$moduleKey][$flagKey] ?? lawnding_camel_to_label($flagKey);
                            $name = 'siteConfig[' . $moduleKey . '][' . $flagKey . ']';
                            $current = $siteConfig[$moduleKey][$flagKey] ?? $defaultValue;
                        ?>
                        <?php if (is_bool($defaultValue)): ?>
                            <label class="siteConfigToggle">
                                <input type="checkbox" name="<?php echo htmlspecialchars($name); ?>" <?php echo !empty($current) ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($flagLabel); ?></span>
                            </label>
                        <?php elseif (is_int($defaultValue)): ?>
                            <label class="siteConfigField">
                                <span><?php echo htmlspecialchars($flagLabel); ?></span>
                                <input type="number" min="1" step="1" name="<?php echo htmlspecialchars($name); ?>" value="<?php echo htmlspecialchars((string) (int) $current); ?>">
                            </label>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </fieldset>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
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
        <div class="pane glassConvex" id="noPane">
            <h3>SELECT A PANE</h3>
            <p>Select a pane from the navbar below.</p>
        </div>
    </main>
    <!-- Bottom navigation for pane switching. -->
    <nav>
        <div class="navBarWrap" id="navBarWrap">
            <ul class="navBar glassConcave" id="navBar">
            <li><a class="navLink" href="#" data-pane="users" aria-label="Users" title="User Management"><?php echo lawnding_icon_svg('users'); ?></a></li>
            <li><a class="navLink" href="#" data-pane="bg" aria-label="Backgrounds" title="Edit Random Background Images"><?php echo lawnding_icon_svg('backgrounds'); ?></a></li>
            <?php if (!empty($canEditSite)): ?>
            <li><a class="navLink" href="#" data-pane="diagnostics" aria-label="Diagnostics" title="Diagnostics — recent runtime errors"><?php echo lawnding_icon_svg('diagnostics'); ?></a></li>
            <?php endif; ?>
            <?php if (!empty($canEditSite)): ?>
            <li><a class="navLink" href="#" data-pane="siteConfig" aria-label="Site Config" title="Site Config — defaults that new panes inherit"><?php echo lawnding_icon_svg('settings'); ?></a></li>
            <?php endif; ?>
            <li class="navSeparator" aria-hidden="true"></li>
            <li><a class="navLink" href="#" data-pane="links" aria-label="Links" title="Links"><?php echo lawnding_icon_svg('links'); ?></a></li>
            <li class="authLinksNavItem<?php echo $authLinksEnabled ? '' : ' isHidden'; ?>"><a class="navLink<?php echo $authLinksEnabled ? '' : ' hidden'; ?>" href="#" data-pane="authLinks" aria-label="Authorized Links" title="Authorized Links"><?php echo lawnding_icon_svg('auth_links'); ?></a></li>
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
            </ul>
            <div class="navBarFadeLayer" aria-hidden="true">
                <div class="navBarFade navBarFadeLeft"></div>
                <div class="navBarFade navBarFadeRight"></div>
            </div>
        </div>
        <div class="footer">
            <?php echo lawnding_footer_platform_html(); ?><span class="footerSep"> · </span>Background image by <span class="authorPlain"></span><a class="authorLink hidden" href="" rel="noopener" target="_blank"><span class="authorName"></span></a>.
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
            <button class="usersButton usersDanger" id="bgDeleteConfirm" type="button" data-modal-confirm="true">Confirm</button>
            <button class="usersButton userModalClose" type="button">Cancel</button>
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
                <?php if (!empty($isMasterUser)): ?>
                    <label class="permissionsItem permissionsItemReadOnly" id="readOnlyPermissionItem">
                        <input type="checkbox" name="read_only" value="1" id="readOnlyToggle">
                        Read-only (no permissions, cannot edit or change password)
                    </label>
                <?php endif; ?>
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
    <script src="<?php echo htmlspecialchars($assetBase . '/res/scr/admin-data.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
    <div class="changelogModal" id="changelogModal" role="dialog" aria-modal="true" aria-label="Changelog" hidden>
        <div class="changelogModalBackdrop"></div>
        <div class="changelogModalInner">
            <div class="changelogModalHeader">
                <span>Changelog</span>
                <button class="changelogModalClose" type="button" aria-label="Close changelog">✕</button>
            </div>
            <div class="changelogModalBody changelogContent">
                <?php echo $changelog; ?>
            </div>
        </div>
    </div>
    <script src="<?php echo htmlspecialchars($assetBase . '/res/scr/shared-utils.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script src="<?php echo htmlspecialchars($assetBase . '/res/scr/notice-core.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script src="<?php echo htmlspecialchars($assetBase . '/res/scr/app.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script src="<?php echo htmlspecialchars($assetBase . '/res/scr/config.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script src="<?php echo htmlspecialchars($assetBase . '/res/scr/diagnostics.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
