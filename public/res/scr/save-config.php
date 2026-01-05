<?php
// Handles saving config changes (links, header, backgrounds, markdown, and uploads).
require_once __DIR__ . '/../../../lp-bootstrap.php';
// Load versioned constants (schema version, site version) for consistency checks.
require_once __DIR__ . '/../version.php';
session_start();

// Unified JSON response helper.
function respond($payload, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

// Convert ini size strings like "2M" into bytes for comparisons.
function ini_size_to_bytes($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    $last = strtolower($value[strlen($value) - 1]);
    $number = (float) $value;
    switch ($last) {
        case 'g':
            $number *= 1024;
            // no break
        case 'm':
            $number *= 1024;
            // no break
        case 'k':
            $number *= 1024;
            break;
    }
    return (int) $number;
}

// Read users.json into an array (empty on failure).
function load_users($usersPath) {
    if (!is_readable($usersPath)) {
        return [];
    }
    $decoded = json_decode(file_get_contents($usersPath), true);
    return is_array($decoded) ? $decoded : [];
}

// Find a user record by username.
function find_user($users, $username) {
    foreach ($users as $user) {
        if (is_array($user) && ($user['username'] ?? '') === $username) {
            return $user;
        }
    }
    return null;
}

// Resolve filesystem paths for data, images, and config files.
function resolve_paths() {
    $publicDir = function_exists('lawnding_config')
        ? lawnding_config('public_dir', dirname(__DIR__, 2))
        : dirname(__DIR__, 2);
    // The admin dir holds module manifests for pane migration and management.
    $adminDir = function_exists('lawnding_config')
        ? lawnding_config('admin_dir', dirname(__DIR__, 3) . '/admin')
        : dirname(__DIR__, 3) . '/admin';
    $dataDir = function_exists('lawnding_config')
        ? lawnding_config('data_dir', $publicDir . '/res/data')
        : $publicDir . '/res/data';
    $imgDir = function_exists('lawnding_config')
        ? lawnding_config('img_dir', $publicDir . '/res/img')
        : $publicDir . '/res/img';
    $imgDir = rtrim($imgDir, '/\\') . '/';
    // Pane icon uploads are stored under res/img/panes.
    $paneIconDir = rtrim($imgDir, '/\\') . '/panes/';

    return [
        'public_dir' => $publicDir,
        'data_dir' => $dataDir,
        'img_dir' => $imgDir,
        'modules_dir' => rtrim($adminDir, '/\\') . '/modules',
        'pane_icon_dir' => $paneIconDir,
        'header_path' => $dataDir . '/header.json',
        'links_path' => $dataDir . '/links.json',
        'panes_path' => $dataDir . '/panes.json',
    ];
}

// Load existing header data with defaults.
function load_header_data($headerPath) {
    $headerData = [
        'logo' => 'res/img/logo.jpg',
        'title' => 'Long Island Furs',
        'subtitle' => 'A Long Island furry community encompassing Queens, Nassau County, and Suffolk County.  And Staten Island, but we do not talk about that.',
        'backgrounds' => ['res/img/bg.jpg']
    ];
    if (is_readable($headerPath)) {
        $decoded = json_decode(file_get_contents($headerPath), true);
        if (is_array($decoded)) {
            $headerData = array_merge($headerData, $decoded);
        }
    }
    return $headerData;
}

// Parse a JSON payload if present; otherwise return null.
function parse_json_payload($payload, $errorMessage) {
    if ($payload === null) {
        return null;
    }
    $decoded = json_decode($payload, true);
    if (!is_array($decoded)) {
        respond(['error' => $errorMessage], 400);
    }
    return $decoded;
}

// Persist a JSON file with a standard encoding strategy.
function write_json_file($path, $data, $errorMessage) {
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents($path, $encoded) === false) {
        respond(['error' => $errorMessage], 500);
    }
}

// Persist plain text content to disk.
function write_text_file($path, $content, $errorMessage) {
    if (file_put_contents($path, $content) === false) {
        respond(['error' => $errorMessage], 500);
    }
}

// Endpoint accepts POST only.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Method not allowed'], 405);
}

// Fail fast when the overall POST payload exceeds the PHP limit.
$postMaxBytes = ini_size_to_bytes(ini_get('post_max_size'));
$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($postMaxBytes > 0 && $contentLength > $postMaxBytes) {
    error_log('save-config.php: payload too large (' . $contentLength . ' bytes, limit ' . $postMaxBytes . ' bytes).');
    respond(['error' => 'Payload too large. Please reduce image sizes and try again.'], 413);
}

// Ensure the user is authenticated before processing updates.
if (empty($_SESSION['auth_user'])) {
    respond(['error' => 'Unauthorized'], 401);
}

// Load users.json and resolve the current user's permissions.
$allowedPermissions = ['full_admin', 'add_users', 'edit_users', 'remove_users', 'edit_site'];
$usersPath = function_exists('lawnding_config')
    ? lawnding_config('users_path', dirname(__DIR__, 3) . '/admin/users.json')
    : dirname(__DIR__, 3) . '/admin/users.json';
$users = load_users($usersPath);
$authUser = $_SESSION['auth_user'];
$authRecord = find_user($users, $authUser);
if (!$authRecord) {
    respond(['error' => 'Unauthorized'], 401);
}

// Site edits require either full admin or edit_site permission.
$permissions = $authRecord['permissions'] ?? [];
if (!is_array($permissions)) {
    $permissions = [];
}
$permissions = array_values(array_intersect($permissions, $allowedPermissions));
$isFullAdmin = !empty($authRecord['master']) || in_array('full_admin', $permissions, true);
$canEditSite = $isFullAdmin || in_array('edit_site', $permissions, true);
if (!$canEditSite) {
    respond(['error' => 'Forbidden'], 403);
}

// Validate file uploads (size and PHP error codes).
foreach ($_FILES as $upload) {
    if (!is_array($upload)) {
        continue;
    }
    $error = $upload['error'] ?? UPLOAD_ERR_OK;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        respond(['error' => 'Upload too large. Images must be under 2MB.'], 413);
    }
    if ($error !== UPLOAD_ERR_OK) {
        respond(['error' => 'Upload failed. Please try again.'], 400);
    }
}

// Resolve filesystem paths for data, images, and config files.
$paths = resolve_paths();
$imgDir = $paths['img_dir'];
$paneIconDir = $paths['pane_icon_dir'];
$headerPath = $paths['header_path'];
$linksPath = $paths['links_path'];
$panesPath = $paths['panes_path'];
$modulesDir = $paths['modules_dir'];

// Load existing header data with defaults.
$headerData = load_header_data($headerPath);

// Load pane configuration for save map and reserved IDs.
$panes = load_panes_config($panesPath);
$paneIds = array_values(array_filter(array_map(function ($pane) {
    return is_array($pane) ? ($pane['id'] ?? '') : '';
}, $panes), function ($value) {
    return is_string($value) && $value !== '';
}));

$action = $_POST['action'] ?? '';

// Normalize stored asset paths into res/... form for consistent matching.
function normalize_asset_path($path) {
    if (!is_string($path) || $path === '') {
        return $path;
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $trimmed = ltrim($path, '/');
    $baseUrl = function_exists('lawnding_config')
        ? trim((string) lawnding_config('base_url', ''), '/')
        : '';
    if ($baseUrl !== '' && str_starts_with($trimmed, $baseUrl . '/res/')) {
        return substr($trimmed, strlen($baseUrl) + 1);
    }
    if (str_starts_with($trimmed, 'public/res/')) {
        return substr($trimmed, strlen('public/'));
    }
    if (str_starts_with($trimmed, 'res/')) {
        return $trimmed;
    }
    return $path;
}

// Block IDs that collide with existing admin DOM element IDs.
function is_reserved_link_id($value, array $extra = []) {
    if (!is_string($value)) {
        return false;
    }
    $value = strtolower(trim($value));
    if ($value === '') {
        return false;
    }
    $reserved = [
        'adminnotices',
        'bg',
        'bgconfig',
        'bgdeleteconfirm',
        'bgdeletemodal',
        'bgfileinput',
        'container',
        'header',
        'links',
        'linksconfig',
        'linklist',
        'logo',
        'logofileinput',
        'mask-bottom',
        'mask-left',
        'mask-right',
        'mask-top',
        'nojswarning',
        'navbar',
        'permissionsmodal',
        'permissionsform',
        'permissionsselfconfirmmodal',
        'permissionsselfconfirmyes',
        'permissionsusername',
        'removeusermodal',
        'removeuserwarning',
        'removeusername',
        'resetconfirmmodal',
        'resetconfirmmessage',
        'resetconfirmyes',
        'resetpasswordmodal',
        'savingoverlay',
        'tutorialoverlay',
        'tutorialpopover',
        'users',
    ];
    $extra = array_map(function ($item) {
        return is_string($item) ? strtolower(trim($item)) : '';
    }, $extra);
    $reserved = array_merge($reserved, array_filter($extra));
    return in_array($value, $reserved, true);
}

// Load panes.json and return pane list (empty on invalid/missing).
function load_panes_config($path) {
    if (!is_readable($path)) {
        return [];
    }
    $decoded = json_decode(file_get_contents($path), true);
    if (!is_array($decoded) || !isset($decoded['panes']) || !is_array($decoded['panes'])) {
        return [];
    }
    return $decoded['panes'];
}

// Load a module manifest by ID from admin/modules/<id>/<id>.json.
function load_module_manifest($modulesDir, $moduleId) {
    if (!is_string($moduleId) || $moduleId === '') {
        return null;
    }
    $manifestPath = rtrim($modulesDir, '/\\') . '/' . $moduleId . '/' . $moduleId . '.json';
    if (!is_readable($manifestPath)) {
        return null;
    }
    $decoded = json_decode(file_get_contents($manifestPath), true);
    return is_array($decoded) ? $decoded : null;
}

// Replace {paneId} tokens in manifest patterns with the pane ID.
function resolve_pane_filename($pattern, $paneId) {
    if (!is_string($pattern) || $pattern === '' || !is_string($paneId) || $paneId === '') {
        return '';
    }
    return str_replace('{paneId}', $paneId, $pattern);
}

// Build a deterministic migration plan from legacy About/Rules/FAQ into panes.json.
// Returns the proposed payload, file actions, and a token hash for confirmation.
function build_migration_plan(array $paths, string $modulesDir): array {
    $basicTextManifest = load_module_manifest($modulesDir, 'basicText');
    if (!is_array($basicTextManifest)) {
        return ['error' => 'Missing basicText module manifest.'];
    }
    // Use the schema version defined in version.php, with a safe fallback.
    $schemaVersion = defined('PANE_SCHEMA_VERSION') ? PANE_SCHEMA_VERSION : 1;

    $iconMap = [
        'about' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M13,9H11V7H13M13,17H11V11H13M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2Z" /></svg>',
        'rules' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M7,13V11H21V13H7M7,19V17H21V19H7M7,7V5H21V7H7M3,8V5H2V4H4V8H3M2,17V16H5V20H2V19H4V18.5H3V17.5H4V17H2M4.25,10A0.75,0.75 0 0,1 5,10.75C5,10.95 4.92,11.14 4.79,11.27L3.12,13H5V14H2V13.08L4,11H2V10H4.25Z" /></svg>',
        'faq' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M18,15H6L2,19V3A1,1 0 0,1 3,2H18A1,1 0 0,1 19,3V14A1,1 0 0,1 18,15M23,9V23L19,19H8A1,1 0 0,1 7,18V17H21V8H22A1,1 0 0,1 23,9M8.19,4C7.32,4 6.62,4.2 6.08,4.59C5.56,5 5.3,5.57 5.31,6.36L5.32,6.39H7.25C7.26,6.09 7.35,5.86 7.53,5.7C7.71,5.55 7.93,5.47 8.19,5.47C8.5,5.47 8.76,5.57 8.94,5.75C9.12,5.94 9.2,6.2 9.2,6.5C9.2,6.82 9.13,7.09 8.97,7.32C8.83,7.55 8.62,7.75 8.36,7.91C7.85,8.25 7.5,8.55 7.31,8.82C7.11,9.08 7,9.5 7,10H9C9,9.69 9.04,9.44 9.13,9.26C9.22,9.08 9.39,8.9 9.64,8.74C10.09,8.5 10.46,8.21 10.75,7.81C11.04,7.41 11.19,7 11.19,6.5C11.19,5.74 10.92,5.13 10.38,4.68C9.85,4.23 9.12,4 8.19,4M7,11V13H9V11H7M13,13H15V11H13V13M13,4V10H15V4H13Z" /></svg>',
    ];

    $panes = [
        [
            'id' => 'about',
            'name' => 'About',
            'module' => 'basicText',
            'icon' => ['type' => 'svg', 'value' => $iconMap['about']],
            'data' => ['md' => 'about.md'],
            'order' => 1,
        ],
        [
            'id' => 'rules',
            'name' => 'Rules',
            'module' => 'basicText',
            'icon' => ['type' => 'svg', 'value' => $iconMap['rules']],
            'data' => ['md' => 'rules.md'],
            'order' => 2,
        ],
        [
            'id' => 'faq',
            'name' => 'FAQ',
            'module' => 'basicText',
            'icon' => ['type' => 'svg', 'value' => $iconMap['faq']],
            'data' => ['md' => 'faq.md'],
            'order' => 3,
        ],
    ];

    $dataDir = rtrim($paths['data_dir'], '/\\');
    $panesPath = $paths['panes_path'];
    $actions = [
        'create' => [],
        'update' => [],
        'delete' => [],
        'rename' => [],
        'backup' => [],
    ];

    if (is_readable($panesPath)) {
        $actions['backup'][] = basename($panesPath) . '.bak';
        $actions['update'][] = basename($panesPath);
    } else {
        $actions['create'][] = basename($panesPath);
    }

    foreach (['about.md', 'rules.md', 'faq.md'] as $file) {
        $path = $dataDir . '/' . $file;
        if (!is_readable($path)) {
            $actions['create'][] = $file;
        }
    }

    $payload = ['schema_version' => $schemaVersion, 'panes' => $panes];
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $token = hash('sha256', (string) $encoded);

    return [
        'payload' => $payload,
        'actions' => $actions,
        'token' => $token,
    ];
}

// Reject SVG that contains scripts or inline event handlers.
function is_safe_svg($svg) {
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
}

// Validate pane IDs (camelCase alphanumeric).
function is_valid_pane_id($value) {
    if (!is_string($value) || $value === '') {
        return false;
    }
    return preg_match('/^[a-z][a-z0-9]*(?:[A-Z][a-z0-9]*)*$/', $value) === 1;
}

// Save a pane icon file upload under res/img/panes with a stable filename.
function save_pane_icon($fileArray, $paneId, $iconDir) {
    if (!isset($fileArray['tmp_name']) || !is_uploaded_file($fileArray['tmp_name'])) {
        return null;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $fileArray['tmp_name']);
    finfo_close($finfo);
    if (strpos((string) $mime, 'image/') !== 0) {
        return null;
    }
    $ext = strtolower(pathinfo($fileArray['name'], PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/i', '', $ext);
    $base = preg_replace('/[^a-z0-9]/i', '', (string) $paneId);
    if ($base === '') {
        return null;
    }
    if ($ext === '') {
        $ext = 'png';
    }
    if (!is_dir($iconDir)) {
        mkdir($iconDir, 0755, true);
    }
    $filename = $base . '.' . $ext;
    $targetPath = rtrim($iconDir, '/\\') . '/' . $filename;
    if (!move_uploaded_file($fileArray['tmp_name'], $targetPath)) {
        return null;
    }
    return $filename;
}

// Validate and save an uploaded image; returns relative path.
function save_image($fileArray, $destName) {
    global $imgDir;
    if (!isset($fileArray['tmp_name']) || !is_uploaded_file($fileArray['tmp_name'])) {
        return null;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $fileArray['tmp_name']);
    finfo_close($finfo);
    if (strpos($mime, 'image/') !== 0) {
        return null;
    }
    $ext = strtolower(pathinfo($fileArray['name'], PATHINFO_EXTENSION));
    $safeName = $destName ? $destName . ($ext ? '.' . $ext : '') : basename($fileArray['name']);
    $targetPath = $imgDir . $safeName;
    if (!move_uploaded_file($fileArray['tmp_name'], $targetPath)) {
        return null;
    }
    return 'res/img/' . $safeName;
}

// Gather POST data and JSON payloads.
$siteTitle = $_POST['siteTitle'] ?? null;
$siteSubtitle = $_POST['siteSubtitle'] ?? null;
$linksJson = $_POST['links'] ?? null;
$backgroundsJson = $_POST['backgrounds'] ?? null;
$backgroundAuthorsJson = $_POST['backgroundAuthors'] ?? null;
$panePayload = $_POST['pane'] ?? null;
$panesPayload = $_POST['panes'] ?? null;

// Parse and validate JSON payloads.
$linksData = parse_json_payload($linksJson, 'Invalid links payload');
$backgroundsData = parse_json_payload($backgroundsJson, 'Invalid backgrounds payload');
$backgroundAuthors = parse_json_payload($backgroundAuthorsJson, 'Invalid background authors payload');

if ($action === 'pane_management') {
    // Pane management save: validate entries, rename/remove data files, and write panes.json.
    if (!is_string($panesPayload) || $panesPayload === '') {
        respond(['error' => 'Missing panes payload.'], 400);
    }
    $decodedPanes = json_decode($panesPayload, true);
    if (!is_array($decodedPanes)) {
        respond(['error' => 'Invalid panes payload.'], 400);
    }

    $existingPanes = $panes;
    $existingById = [];
    foreach ($existingPanes as $pane) {
        if (!is_array($pane)) {
            continue;
        }
        $id = $pane['id'] ?? '';
        if (is_string($id) && $id !== '') {
            $existingById[$id] = $pane;
        }
    }

    $newPanes = [];
    $seenIds = [];
    $usedPrevIds = [];

    // Normalize and validate each pane entry from the client payload.
    foreach ($decodedPanes as $index => $pane) {
        if (!is_array($pane)) {
            respond(['error' => 'Invalid pane entry.'], 400);
        }
        $name = isset($pane['name']) ? (string) $pane['name'] : '';
        $id = isset($pane['id']) ? (string) $pane['id'] : '';
        $moduleId = isset($pane['module']) ? (string) $pane['module'] : '';
        $previousId = isset($pane['previousId']) ? (string) $pane['previousId'] : $id;
        $previousModule = isset($pane['previousModule']) ? (string) $pane['previousModule'] : '';
        $icon = isset($pane['icon']) && is_array($pane['icon']) ? $pane['icon'] : ['type' => 'none', 'value' => ''];

        if ($name === '' || $id === '' || $moduleId === '') {
            respond(['error' => 'Pane name, id, and module are required.'], 400);
        }
        if (!is_valid_pane_id($id)) {
            respond(['error' => 'Invalid pane id: ' . $id . '.'], 400);
        }
        if (is_reserved_link_id($id)) {
            respond(['error' => 'Pane id is reserved: ' . $id . '.'], 400);
        }
        if (isset($seenIds[$id])) {
            respond(['error' => 'Duplicate pane id: ' . $id . '.'], 400);
        }
        $seenIds[$id] = true;

        if ($previousId !== '') {
            $usedPrevIds[$previousId] = true;
        }

        $manifest = load_module_manifest($modulesDir, $moduleId);
        if (!is_array($manifest)) {
            respond(['error' => 'Module not found: ' . $moduleId . '.'], 400);
        }

        $iconType = isset($icon['type']) ? (string) $icon['type'] : 'none';
        $iconValue = isset($icon['value']) ? (string) $icon['value'] : '';
        if ($iconType === 'svg') {
            if (is_string($iconValue) && $iconValue !== '') {
                $iconValue = preg_replace('/<title[^>]*>[\\s\\S]*?<\\/title>/i', '', $iconValue);
            }
            if (!is_safe_svg($iconValue)) {
                respond(['error' => 'Invalid SVG icon for pane ' . $name . '.'], 400);
            }
        } elseif ($iconType === 'file') {
            $iconValue = basename($iconValue);
        } else {
            $iconType = 'none';
            $iconValue = '';
        }

        $dataFiles = [];
        $manifestDataFiles = $manifest['data_files'] ?? [];
        if (is_array($manifestDataFiles)) {
            foreach ($manifestDataFiles as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $type = isset($entry['type']) ? (string) $entry['type'] : '';
                $pattern = isset($entry['pattern']) ? (string) $entry['pattern'] : '';
                if ($type === '' || $pattern === '') {
                    continue;
                }
                $filename = resolve_pane_filename($pattern, $id);
                if ($filename === '') {
                    continue;
                }
                $dataFiles[$type] = $filename;
            }
        }

        $newPanes[] = [
            'id' => $id,
            'name' => $name,
            'module' => $moduleId,
            'icon' => ['type' => $iconType, 'value' => $iconValue],
            'data' => $dataFiles,
            'order' => $index + 1,
            '_previous_id' => $previousId,
            '_previous_module' => $previousModule,
        ];
    }

    $removed = [];
    foreach ($existingById as $id => $pane) {
        if (!isset($usedPrevIds[$id])) {
            $removed[$id] = $pane;
        }
    }

    // Apply renames and module changes (rename data files or delete old data).
    foreach ($newPanes as $pane) {
        $currentId = $pane['id'];
        $prevId = $pane['_previous_id'] ?: $currentId;
        $currentModule = $pane['module'];
        $prevModule = $pane['_previous_module'] ?: ($existingById[$prevId]['module'] ?? '');

        if ($prevId !== $currentId && $prevModule === $currentModule && isset($existingById[$prevId])) {
            $oldManifest = load_module_manifest($modulesDir, $prevModule);
            $oldData = is_array($oldManifest) ? ($oldManifest['data_files'] ?? []) : [];
            if (is_array($oldData)) {
                foreach ($oldData as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $pattern = isset($entry['pattern']) ? (string) $entry['pattern'] : '';
                    if ($pattern === '') {
                        continue;
                    }
                    $oldFile = resolve_pane_filename($pattern, $prevId);
                    $newFile = resolve_pane_filename($pattern, $currentId);
                    if ($oldFile === '' || $newFile === '' || $oldFile === $newFile) {
                        continue;
                    }
                    $oldPath = rtrim($paths['data_dir'], '/\\') . '/' . $oldFile;
                    $newPath = rtrim($paths['data_dir'], '/\\') . '/' . $newFile;
                    if (is_readable($oldPath) && !is_readable($newPath)) {
                        rename($oldPath, $newPath);
                    }
                }
            }
        }

        if ($prevModule !== '' && $prevModule !== $currentModule && isset($existingById[$prevId])) {
            $oldManifest = load_module_manifest($modulesDir, $prevModule);
            $oldData = is_array($oldManifest) ? ($oldManifest['data_files'] ?? []) : [];
            if (is_array($oldData)) {
                foreach ($oldData as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $pattern = isset($entry['pattern']) ? (string) $entry['pattern'] : '';
                    if ($pattern === '') {
                        continue;
                    }
                    $oldFile = resolve_pane_filename($pattern, $prevId);
                    if ($oldFile === '') {
                        continue;
                    }
                    $oldPath = rtrim($paths['data_dir'], '/\\') . '/' . $oldFile;
                    if (is_readable($oldPath)) {
                        unlink($oldPath);
                    }
                }
            }
        }

        $newManifest = load_module_manifest($modulesDir, $currentModule);
        $newData = is_array($newManifest) ? ($newManifest['data_files'] ?? []) : [];
        if (is_array($newData)) {
            foreach ($newData as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $type = isset($entry['type']) ? (string) $entry['type'] : '';
                $pattern = isset($entry['pattern']) ? (string) $entry['pattern'] : '';
                if ($type === '' || $pattern === '') {
                    continue;
                }
                $file = resolve_pane_filename($pattern, $currentId);
                if ($file === '') {
                    continue;
                }
                $path = rtrim($paths['data_dir'], '/\\') . '/' . $file;
                if (!is_readable($path)) {
                    if ($type === 'json') {
                        write_json_file($path, new stdClass(), 'Failed to initialize pane data for ' . $currentId . '.');
                    } else {
                        write_text_file($path, '', 'Failed to initialize pane data for ' . $currentId . '.');
                    }
                }
            }
        }
    }

    // Delete data files for panes removed from the configuration.
    foreach ($removed as $pane) {
        $removedId = $pane['id'] ?? '';
        $removedModule = $pane['module'] ?? '';
        $removedManifest = load_module_manifest($modulesDir, $removedModule);
        $removedData = is_array($removedManifest) ? ($removedManifest['data_files'] ?? []) : [];
        if (is_array($removedData)) {
            foreach ($removedData as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $pattern = isset($entry['pattern']) ? (string) $entry['pattern'] : '';
                if ($pattern === '') {
                    continue;
                }
                $file = resolve_pane_filename($pattern, (string) $removedId);
                if ($file === '') {
                    continue;
                }
                $path = rtrim($paths['data_dir'], '/\\') . '/' . $file;
                if (is_readable($path)) {
                    unlink($path);
                }
            }
        }
    }

    // Persist icon changes and track file references for cleanup.
    $iconFiles = [];
    foreach ($newPanes as &$pane) {
        $paneId = $pane['id'];
        $icon = $pane['icon'];
        $iconType = $icon['type'] ?? 'none';
        $iconValue = $icon['value'] ?? '';
        if ($iconType === 'file') {
            $fileKey = 'paneIconFile_' . $paneId;
            if (isset($_FILES[$fileKey])) {
                $saved = save_pane_icon($_FILES[$fileKey], $paneId, $paneIconDir);
                if ($saved) {
                    $iconValue = $saved;
                }
            }
            $iconValue = basename((string) $iconValue);
            $icon['value'] = $iconValue;
            $pane['icon'] = $icon;
            if ($iconValue !== '') {
                $iconFiles[$iconValue] = true;
            }
        }
        unset($pane['_previous_id'], $pane['_previous_module']);
    }
    unset($pane);

    // Remove icon files that are no longer referenced by any pane.
    $oldIconFiles = [];
    foreach ($existingPanes as $pane) {
        if (!is_array($pane)) {
            continue;
        }
        $icon = $pane['icon'] ?? [];
        if (!is_array($icon)) {
            continue;
        }
        if (($icon['type'] ?? '') === 'file') {
            $value = $icon['value'] ?? '';
            if (is_string($value) && $value !== '') {
                $oldIconFiles[$value] = true;
            }
        }
    }

    foreach ($oldIconFiles as $file => $_) {
        if (!isset($iconFiles[$file])) {
            $path = rtrim($paneIconDir, '/\\') . '/' . $file;
            if (is_readable($path)) {
                unlink($path);
            }
        }
    }

    // Write the updated panes.json payload with the current schema version.
    $schemaVersion = defined('PANE_SCHEMA_VERSION') ? PANE_SCHEMA_VERSION : 1;
    write_json_file($panesPath, ['schema_version' => $schemaVersion, 'panes' => $newPanes], 'Failed to write panes config.');
    respond(['status' => 'ok']);
}

if ($action === 'migration_preview') {
    // Block migration if required module manifests are missing.
    $requiredModules = ['basicText'];
    foreach ($requiredModules as $moduleId) {
        if (!is_array(load_module_manifest($modulesDir, $moduleId))) {
            respond(['error' => 'Migration requires module ' . $moduleId . '.'], 400);
        }
    }
    // Migration preview: build plan and return a token so apply can verify consistency.
    $plan = build_migration_plan($paths, $modulesDir);
    if (!empty($plan['error'])) {
        respond(['error' => $plan['error']], 400);
    }
    respond([
        'status' => 'ok',
        'actions' => $plan['actions'],
        'panes' => $plan['payload']['panes'],
        'payload' => $plan['payload'],
        'token' => $plan['token'],
    ]);
}

if ($action === 'migration_apply') {
    // Block migration if required module manifests are missing.
    $requiredModules = ['basicText'];
    foreach ($requiredModules as $moduleId) {
        if (!is_array(load_module_manifest($modulesDir, $moduleId))) {
            respond(['error' => 'Migration requires module ' . $moduleId . '.'], 400);
        }
    }
    // Migration apply: verify token, backup panes.json, then write new schema and files.
    $token = $_POST['token'] ?? '';
    if (!is_string($token) || $token === '') {
        respond(['error' => 'Missing migration token.'], 400);
    }
    $plan = build_migration_plan($paths, $modulesDir);
    if (!empty($plan['error'])) {
        respond(['error' => $plan['error']], 400);
    }
    if (!hash_equals($plan['token'], $token)) {
        respond(['error' => 'Migration plan changed. Please preview again.'], 409);
    }

    $panesPath = $paths['panes_path'];
    if (is_readable($panesPath)) {
        $backupPath = $panesPath . '.bak';
        if (!copy($panesPath, $backupPath)) {
            respond(['error' => 'Failed to backup panes.json.'], 500);
        }
    }

    write_json_file($panesPath, $plan['payload'], 'Failed to write panes.json.');

    $dataDir = rtrim($paths['data_dir'], '/\\');
    foreach (['about.md', 'rules.md', 'faq.md'] as $file) {
        $path = $dataDir . '/' . $file;
        if (!is_readable($path)) {
            write_text_file($path, '', 'Failed to initialize ' . $file . '.');
        }
    }

    respond(['status' => 'ok']);
}

// Handle logo upload (optional).
if (isset($_FILES['logoFile'])) {
    $savedLogo = save_image($_FILES['logoFile'], 'logo');
    if ($savedLogo) {
        $headerData['logo'] = $savedLogo;
    }
}

// Handle background list updates and new uploads.
$newBackgrounds = null;
if (is_array($backgroundsData)) {
    $newBackgrounds = [];
    foreach ($backgroundsData as $bg) {
        if (!is_array($bg)) {
            continue;
        }
        $author = $bg['author'] ?? '';
        $authorUrl = $bg['authorUrl'] ?? '';
        $existingUrl = $bg['url'] ?? '';
        $fileKey = $bg['fileKey'] ?? null;
        $authorUrl = is_string($authorUrl) ? trim($authorUrl) : '';

        if ($fileKey && isset($_FILES[$fileKey])) {
            $saved = save_image($_FILES[$fileKey], null);
            if ($saved) {
                $newBackgrounds[] = [
                    'url' => $saved,
                    'author' => $author,
                    'authorUrl' => $authorUrl,
                ];
            }
        } elseif ($existingUrl) {
            $newBackgrounds[] = [
                'url' => normalize_asset_path($existingUrl),
                'author' => $author,
                'authorUrl' => $authorUrl,
            ];
        }
    }
}

// Handle background author edits without replacing the image URLs.
$backgroundAuthorsChanged = false;
if (is_array($backgroundAuthors)) {
    $existingBackgrounds = $headerData['backgrounds'] ?? [];
    foreach ($backgroundAuthors as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $index = isset($entry['index']) ? (int) $entry['index'] : null;
        $url = normalize_asset_path($entry['url'] ?? '');
        $author = $entry['author'] ?? '';
        $authorUrl = $entry['authorUrl'] ?? '';
        $authorUrl = is_string($authorUrl) ? trim($authorUrl) : '';
        if ($index === null || $url === '' || !isset($existingBackgrounds[$index])) {
            continue;
        }
        $current = $existingBackgrounds[$index];
        if (is_string($current)) {
            if ($current !== $url) {
                continue;
            }
            $existingBackgrounds[$index] = [
                'url' => $url,
                'author' => $author,
                'authorUrl' => $authorUrl,
            ];
            $backgroundAuthorsChanged = true;
            continue;
        }
        if (is_array($current)) {
            if (($current['url'] ?? '') !== $url) {
                continue;
            }
            $existingBackgrounds[$index]['author'] = $author;
            $existingBackgrounds[$index]['authorUrl'] = $authorUrl;
            $backgroundAuthorsChanged = true;
        }
    }
    if ($backgroundAuthorsChanged) {
        $headerData['backgrounds'] = $existingBackgrounds;
    }
}

// Handle link configuration updates.
$linksOut = null;
if (is_array($linksData)) {
    $linksOut = [];
    $reservedIds = [];
    foreach ($linksData as $link) {
        if (!is_array($link)) {
            continue;
        }
        $type = $link['type'] ?? '';
        if ($type === 'separator') {
            $linksOut[] = ['type' => 'separator'];
        } elseif ($type === 'link') {
            $id = $link['id'] ?? '';
            if (is_reserved_link_id($id, $paneIds)) {
                $reservedIds[] = $id;
                continue;
            }
            $linksOut[] = [
                'type' => 'link',
                'id' => $id,
                'href' => $link['href'] ?? '',
                'text' => $link['text'] ?? '',
                'title' => $link['title'] ?? '',
                'fullWidth' => !empty($link['fullWidth']),
                'cta' => !empty($link['cta']),
            ];
        }
    }
    if (!empty($reservedIds)) {
        $unique = array_values(array_unique($reservedIds));
        respond(['error' => 'Error: ID cannot be ' . implode(', ', $unique) . '. Please change them to different IDs.'], 400);
    }
}

// Save pane data via module save_map entries.
if (is_array($panePayload)) {
    foreach ($panes as $pane) {
        if (!is_array($pane)) {
            continue;
        }
        $paneId = isset($pane['id']) ? (string) $pane['id'] : '';
        if ($paneId === '' || !isset($panePayload[$paneId]) || !is_array($panePayload[$paneId])) {
            continue;
        }
        $moduleId = isset($pane['module']) ? (string) $pane['module'] : '';
        $manifest = load_module_manifest($modulesDir, $moduleId);
        if (!is_array($manifest)) {
            continue;
        }
        $saveMap = $manifest['save_map'] ?? [];
        if (!is_array($saveMap)) {
            continue;
        }
        foreach ($saveMap as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = isset($entry['key']) ? (string) $entry['key'] : '';
            $type = isset($entry['type']) ? (string) $entry['type'] : 'text';
            $pattern = isset($entry['file']) ? (string) $entry['file'] : '';
            if ($key === '' || $pattern === '') {
                continue;
            }
            if (!array_key_exists($key, $panePayload[$paneId])) {
                continue;
            }
            $filename = resolve_pane_filename($pattern, $paneId);
            if ($filename === '') {
                continue;
            }
            $targetPath = rtrim($paths['data_dir'], '/\\') . '/' . $filename;
            $value = $panePayload[$paneId][$key];
            if ($type === 'json') {
                $decoded = json_decode((string) $value, true);
                if (!is_array($decoded)) {
                    respond(['error' => 'Invalid JSON for pane ' . $paneId . '.'], 400);
                }
                write_json_file($targetPath, $decoded, 'Failed to write pane data for ' . $paneId . '.');
            } else {
                write_text_file($targetPath, (string) $value, 'Failed to write pane data for ' . $paneId . '.');
            }
        }
    }
}

// Update header data and persist if any relevant fields changed.
$headerChanged = false;
if ($siteTitle !== null) {
    $headerData['title'] = $siteTitle;
    $headerChanged = true;
}
if ($siteSubtitle !== null) {
    $headerData['subtitle'] = $siteSubtitle;
    $headerChanged = true;
}
if (isset($_FILES['logoFile']) && !empty($headerData['logo'])) {
    $headerChanged = true;
}
if (is_array($newBackgrounds)) {
    $headerData['backgrounds'] = $newBackgrounds;
    $headerChanged = true;
}
if ($backgroundAuthorsChanged) {
    $headerChanged = true;
}

if ($headerChanged) {
    $headerData['logo'] = normalize_asset_path($headerData['logo'] ?? '');
    write_json_file($headerPath, $headerData, 'Failed to write header data');
}
if (is_array($linksOut)) {
    write_json_file($linksPath, $linksOut, 'Failed to write links data');
}

// All operations succeeded.
respond(['status' => 'ok']);
