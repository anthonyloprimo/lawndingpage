<?php
require_once __DIR__ . '/../../../lp-bootstrap.php';
// Handles saving config changes (links, header, backgrounds, markdown, and uploads).
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

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

$postMaxBytes = ini_size_to_bytes(ini_get('post_max_size'));
$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($postMaxBytes > 0 && $contentLength > $postMaxBytes) {
    error_log('save-config.php: payload too large (' . $contentLength . ' bytes, limit ' . $postMaxBytes . ' bytes).');
    http_response_code(413);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Payload too large. Please reduce image sizes and try again.']);
    exit;
}

if (empty($_SESSION['auth_user'])) {
    respond(['error' => 'Unauthorized'], 401);
}

$allowedPermissions = ['full_admin', 'add_users', 'edit_users', 'remove_users', 'edit_site'];
$usersPath = function_exists('lawnding_config')
    ? lawnding_config('users_path', dirname(__DIR__, 3) . '/admin/users.json')
    : dirname(__DIR__, 3) . '/admin/users.json';
$users = [];
if (is_readable($usersPath)) {
    $decoded = json_decode(file_get_contents($usersPath), true);
    if (is_array($decoded)) {
        $users = $decoded;
    }
}
$authUser = $_SESSION['auth_user'];
$authRecord = null;
foreach ($users as $user) {
    if (is_array($user) && ($user['username'] ?? '') === $authUser) {
        $authRecord = $user;
        break;
    }
}
if (!$authRecord) {
    respond(['error' => 'Unauthorized'], 401);
}

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

$headerPath = $dataDir . '/header.json';
$linksPath = $dataDir . '/links.json';
$aboutPath = $dataDir . '/about.md';
$rulesPath = $dataDir . '/rules.md';
$faqPath = $dataDir . '/faq.md';

// Load existing header data with defaults
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

function respond($payload, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

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

// Gather POST data
$siteTitle = $_POST['siteTitle'] ?? null;
$siteSubtitle = $_POST['siteSubtitle'] ?? null;
$linksJson = $_POST['links'] ?? null;
$backgroundsJson = $_POST['backgrounds'] ?? null;
$backgroundAuthorsJson = $_POST['backgroundAuthors'] ?? null;
$aboutMarkdown = $_POST['aboutMarkdown'] ?? null;
$rulesMarkdown = $_POST['rulesMarkdown'] ?? null;
$faqMarkdown = $_POST['faqMarkdown'] ?? null;

$linksData = null;
if ($linksJson !== null) {
    $linksData = json_decode($linksJson, true);
    if (!is_array($linksData)) {
        respond(['error' => 'Invalid links payload'], 400);
    }
}

$backgroundsData = null;
if ($backgroundsJson !== null) {
    $backgroundsData = json_decode($backgroundsJson, true);
    if (!is_array($backgroundsData)) {
        respond(['error' => 'Invalid backgrounds payload'], 400);
    }
}

$backgroundAuthors = null;
if ($backgroundAuthorsJson !== null) {
    $backgroundAuthors = json_decode($backgroundAuthorsJson, true);
    if (!is_array($backgroundAuthors)) {
        respond(['error' => 'Invalid background authors payload'], 400);
    }
}

// Handle logo upload
if (isset($_FILES['logoFile'])) {
    $savedLogo = save_image($_FILES['logoFile'], 'logo');
    if ($savedLogo) {
        $headerData['logo'] = $savedLogo;
    }
}

// Handle backgrounds
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

// Handle links
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

// Write markdown files
if ($aboutMarkdown !== null) {
    if (file_put_contents($aboutPath, $aboutMarkdown) === false) {
        respond(['error' => 'Failed to write about content'], 500);
    }
}
if ($rulesMarkdown !== null) {
    if (file_put_contents($rulesPath, $rulesMarkdown) === false) {
        respond(['error' => 'Failed to write rules content'], 500);
    }
}
if ($faqMarkdown !== null) {
    if (file_put_contents($faqPath, $faqMarkdown) === false) {
        respond(['error' => 'Failed to write FAQ content'], 500);
    }
}

// Update header data
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
    $headerJson = json_encode($headerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($headerJson === false || file_put_contents($headerPath, $headerJson) === false) {
        respond(['error' => 'Failed to write header data'], 500);
    }
}
if (is_array($linksOut)) {
    $linksJsonOut = json_encode($linksOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($linksJsonOut === false || file_put_contents($linksPath, $linksJsonOut) === false) {
        respond(['error' => 'Failed to write links data'], 500);
    }
}

respond(['status' => 'ok']);
