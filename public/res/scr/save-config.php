<?php
// Handles saving config changes (links, header, backgrounds, markdown, and uploads).
require_once __DIR__ . '/../../../lp-bootstrap.php';
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
    $dataDir = function_exists('lawnding_config')
        ? lawnding_config('data_dir', $publicDir . '/res/data')
        : $publicDir . '/res/data';
    $imgDir = function_exists('lawnding_config')
        ? lawnding_config('img_dir', $publicDir . '/res/img')
        : $publicDir . '/res/img';
    $imgDir = rtrim($imgDir, '/\\') . '/';

    return [
        'public_dir' => $publicDir,
        'data_dir' => $dataDir,
        'img_dir' => $imgDir,
        'header_path' => $dataDir . '/header.json',
        'links_path' => $dataDir . '/links.json',
        'about_path' => $dataDir . '/about.md',
        'rules_path' => $dataDir . '/rules.md',
        'faq_path' => $dataDir . '/faq.md',
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
$headerPath = $paths['header_path'];
$linksPath = $paths['links_path'];
$aboutPath = $paths['about_path'];
$rulesPath = $paths['rules_path'];
$faqPath = $paths['faq_path'];

// Load existing header data with defaults.
$headerData = load_header_data($headerPath);

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
function is_reserved_link_id($value) {
    if (!is_string($value)) {
        return false;
    }
    $value = strtolower(trim($value));
    if ($value === '') {
        return false;
    }
    $reserved = [
        'about',
        'adminnotices',
        'bg',
        'bgconfig',
        'bgdeleteconfirm',
        'bgdeletemodal',
        'bgfileinput',
        'container',
        'donate',
        'events',
        'faq',
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
        'rules',
        'savingoverlay',
        'tutorialoverlay',
        'tutorialpopover',
        'users',
    ];
    return in_array($value, $reserved, true);
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
$aboutMarkdown = $_POST['aboutMarkdown'] ?? null;
$rulesMarkdown = $_POST['rulesMarkdown'] ?? null;
$faqMarkdown = $_POST['faqMarkdown'] ?? null;

// Parse and validate JSON payloads.
$linksData = parse_json_payload($linksJson, 'Invalid links payload');
$backgroundsData = parse_json_payload($backgroundsJson, 'Invalid backgrounds payload');
$backgroundAuthors = parse_json_payload($backgroundAuthorsJson, 'Invalid background authors payload');

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
            if (is_reserved_link_id($id)) {
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

// Write markdown files (only when provided).
if ($aboutMarkdown !== null) {
    write_text_file($aboutPath, $aboutMarkdown, 'Failed to write about content');
}
if ($rulesMarkdown !== null) {
    write_text_file($rulesPath, $rulesMarkdown, 'Failed to write rules content');
}
if ($faqMarkdown !== null) {
    write_text_file($faqPath, $faqMarkdown, 'Failed to write FAQ content');
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
