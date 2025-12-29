<?php
require_once __DIR__ . '/../../../lp-bootstrap.php';
session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

function respond($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload);
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
    error_log('backgrounds-upload.php: payload too large (' . $contentLength . ' bytes, limit ' . $postMaxBytes . ' bytes).');
    respond(['error' => 'Payload too large. Please reduce image sizes and try again.'], 413);
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

$upload = $_FILES['bgFile'] ?? null;
if (!$upload || !is_array($upload)) {
    respond(['error' => 'No background image uploaded.'], 400);
}
if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    $error = $upload['error'] ?? UPLOAD_ERR_OK;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        respond(['error' => 'Upload too large. Images must be under 2MB.'], 413);
    }
    respond(['error' => 'Upload failed. Please try again.'], 400);
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
$headerData = [
    'backgrounds' => ['res/img/bg.jpg'],
];
if (is_readable($headerPath)) {
    $decoded = json_decode(file_get_contents($headerPath), true);
    if (is_array($decoded)) {
        $headerData = array_merge($headerData, $decoded);
    }
}

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

$saved = save_image($upload, null);
if (!$saved) {
    respond(['error' => 'Invalid image upload.'], 400);
}

if (empty($headerData['backgrounds']) || !is_array($headerData['backgrounds'])) {
    $headerData['backgrounds'] = [];
}
$headerData['backgrounds'][] = [
    'url' => $saved,
    'author' => '',
    'authorUrl' => '',
];

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

function make_asset_url($path) {
    if (!is_string($path) || $path === '') {
        return $path;
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $assetBase = '';
    if (function_exists('lawnding_config')) {
        $assetBase = (string) lawnding_config('base_url', '');
    }
    if ($assetBase === '') {
        if (empty($_SERVER['DOCUMENT_ROOT']) || !is_dir($_SERVER['DOCUMENT_ROOT'] . '/res')) {
            $assetBase = '/public';
        }
    }
    $assetBase = rtrim($assetBase, '/');
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
}

$headerJson = json_encode($headerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($headerJson === false || file_put_contents($headerPath, $headerJson) === false) {
    respond(['error' => 'Failed to write header data'], 500);
}

$backgroundsRaw = $headerData['backgrounds'] ?? [];
if (!is_array($backgroundsRaw)) {
    $backgroundsRaw = [];
}

$backgrounds = [];
foreach ($backgroundsRaw as $index => $bg) {
    $url = '';
    $author = '';
    $authorUrl = '';
    if (is_string($bg)) {
        $url = $bg;
    } elseif (is_array($bg)) {
        $url = $bg['url'] ?? '';
        $author = $bg['author'] ?? '';
        $authorUrl = $bg['authorUrl'] ?? '';
    }
    $url = normalize_asset_path($url);
    $backgrounds[] = [
        'url' => $url,
        'author' => $author,
        'authorUrl' => $authorUrl ?: '',
        'displayUrl' => make_asset_url($url),
        'index' => $index,
    ];
}

respond(['backgrounds' => $backgrounds]);
