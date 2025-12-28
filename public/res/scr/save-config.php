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

$publicDir = function_exists('lawnding_config')
    ? lawnding_config('public_dir', dirname(__DIR__, 2))
    : dirname(__DIR__, 2);
$dataDir = function_exists('lawnding_config')
    ? lawnding_config('data_dir', $publicDir . '/res/data')
    : $publicDir . '/res/data';
$imgDir = function_exists('lawnding_config')
    ? lawnding_config('img_dir', $publicDir . '/res/img')
    : $publicDir . '/res/img';

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
$siteTitle = $_POST['siteTitle'] ?? '';
$siteSubtitle = $_POST['siteSubtitle'] ?? '';
$linksJson = $_POST['links'] ?? '[]';
$backgroundsJson = $_POST['backgrounds'] ?? '[]';
$aboutMarkdown = $_POST['aboutMarkdown'] ?? '';
$rulesMarkdown = $_POST['rulesMarkdown'] ?? '';
$faqMarkdown = $_POST['faqMarkdown'] ?? '';

$linksData = json_decode($linksJson, true);
$backgroundsData = json_decode($backgroundsJson, true);

if (!is_array($linksData)) {
    respond(['error' => 'Invalid links payload'], 400);
}
if (!is_array($backgroundsData)) {
    respond(['error' => 'Invalid backgrounds payload'], 400);
}

// Handle logo upload
if (isset($_FILES['logoFile'])) {
    $savedLogo = save_image($_FILES['logoFile'], 'logo');
    if ($savedLogo) {
        $headerData['logo'] = $savedLogo;
    }
}

// Handle backgrounds
$newBackgrounds = [];
foreach ($backgroundsData as $bg) {
    if (!is_array($bg)) {
        continue;
    }
    $author = $bg['author'] ?? '';
    $existingUrl = $bg['url'] ?? '';
    $fileKey = $bg['fileKey'] ?? null;

    if ($fileKey && isset($_FILES[$fileKey])) {
        $saved = save_image($_FILES[$fileKey], null);
        if ($saved) {
            $newBackgrounds[] = [
                'url' => $saved,
                'author' => $author,
            ];
        }
    } elseif ($existingUrl) {
        $newBackgrounds[] = [
            'url' => normalize_asset_path($existingUrl),
            'author' => $author,
        ];
    }
}

// Handle links
$linksOut = [];
foreach ($linksData as $link) {
    if (!is_array($link)) {
        continue;
    }
    $type = $link['type'] ?? '';
    if ($type === 'separator') {
        $linksOut[] = ['type' => 'separator'];
    } elseif ($type === 'link') {
        $linksOut[] = [
            'type' => 'link',
            'id' => $link['id'] ?? '',
            'href' => $link['href'] ?? '',
            'text' => $link['text'] ?? '',
            'title' => $link['title'] ?? '',
            'fullWidth' => !empty($link['fullWidth']),
            'cta' => !empty($link['cta']),
        ];
    }
}

// Write markdown files
if (file_put_contents($aboutPath, $aboutMarkdown) === false) {
    respond(['error' => 'Failed to write about content'], 500);
}
if (file_put_contents($rulesPath, $rulesMarkdown) === false) {
    respond(['error' => 'Failed to write rules content'], 500);
}
if (file_put_contents($faqPath, $faqMarkdown) === false) {
    respond(['error' => 'Failed to write FAQ content'], 500);
}

// Update header data
$headerData['title'] = $siteTitle;
$headerData['subtitle'] = $siteSubtitle;
$headerData['logo'] = normalize_asset_path($headerData['logo'] ?? '');
$headerData['backgrounds'] = $newBackgrounds;

$headerJson = json_encode($headerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($headerJson === false || file_put_contents($headerPath, $headerJson) === false) {
    respond(['error' => 'Failed to write header data'], 500);
}
$linksJsonOut = json_encode($linksOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($linksJsonOut === false || file_put_contents($linksPath, $linksJsonOut) === false) {
    respond(['error' => 'Failed to write links data'], 500);
}

respond(['status' => 'ok']);
