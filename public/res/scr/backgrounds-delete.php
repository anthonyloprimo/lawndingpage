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

$url = isset($_POST['url']) ? (string) $_POST['url'] : '';
$index = isset($_POST['index']) ? (int) $_POST['index'] : null;
if ($url === '') {
    respond(['error' => 'Missing background URL.'], 400);
}

$publicDir = function_exists('lawnding_config')
    ? lawnding_config('public_dir', dirname(__DIR__, 2))
    : dirname(__DIR__, 2);
$dataDir = function_exists('lawnding_config')
    ? lawnding_config('data_dir', $publicDir . '/res/data')
    : $publicDir . '/res/data';
$headerPath = $dataDir . '/header.json';

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

$headerData = [
    'backgrounds' => ['res/img/bg.jpg'],
];
if (is_readable($headerPath)) {
    $decoded = json_decode(file_get_contents($headerPath), true);
    if (is_array($decoded)) {
        $headerData = array_merge($headerData, $decoded);
    }
}

$backgroundsRaw = $headerData['backgrounds'] ?? [];
if (!is_array($backgroundsRaw)) {
    $backgroundsRaw = [];
}

$normalizedUrl = normalize_asset_path($url);
$removed = false;

if ($index !== null && isset($backgroundsRaw[$index])) {
    $target = $backgroundsRaw[$index];
    $targetUrl = is_array($target) ? ($target['url'] ?? '') : (is_string($target) ? $target : '');
    if (normalize_asset_path($targetUrl) === $normalizedUrl) {
        unset($backgroundsRaw[$index]);
        $removed = true;
    }
}

if (!$removed) {
    foreach ($backgroundsRaw as $key => $bg) {
        $targetUrl = is_array($bg) ? ($bg['url'] ?? '') : (is_string($bg) ? $bg : '');
        if (normalize_asset_path($targetUrl) === $normalizedUrl) {
            unset($backgroundsRaw[$key]);
            $removed = true;
            break;
        }
    }
}

if (!$removed) {
    respond(['error' => 'Background not found.'], 404);
}

$backgroundsRaw = array_values($backgroundsRaw);
$headerData['backgrounds'] = $backgroundsRaw;

$headerJson = json_encode($headerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($headerJson === false || file_put_contents($headerPath, $headerJson) === false) {
    respond(['error' => 'Failed to write header data'], 500);
}

if (str_starts_with($normalizedUrl, 'res/img/')) {
    $stillUsed = false;
    foreach ($backgroundsRaw as $bg) {
        $bgUrl = is_array($bg) ? ($bg['url'] ?? '') : (is_string($bg) ? $bg : '');
        if (normalize_asset_path($bgUrl) === $normalizedUrl) {
            $stillUsed = true;
            break;
        }
    }
    if (!$stillUsed) {
        $filePath = $publicDir . '/' . $normalizedUrl;
        $realFile = realpath($filePath);
        $imgRoot = realpath($publicDir . '/res/img');
        if ($realFile && $imgRoot && str_starts_with($realFile, $imgRoot . DIRECTORY_SEPARATOR)) {
            if (is_file($realFile)) {
                unlink($realFile);
            }
        }
    }
}

$backgrounds = [];
foreach ($backgroundsRaw as $indexKey => $bg) {
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
    $bgUrl = normalize_asset_path($bgUrl);
    $backgrounds[] = [
        'url' => $bgUrl,
        'author' => $bgAuthor,
        'authorUrl' => $bgAuthorUrl ?: '',
        'displayUrl' => make_asset_url($bgUrl),
        'index' => $indexKey,
    ];
}

respond(['backgrounds' => $backgrounds]);
