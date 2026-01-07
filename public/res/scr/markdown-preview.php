<?php
require_once __DIR__ . '/../../../lp-bootstrap.php';
lawnding_init_session();

function respond($payload, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function require_csrf_token() {
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($sessionToken) || $sessionToken === '' || !is_string($postedToken) || $postedToken === '') {
        respond(['error' => 'Forbidden'], 403);
    }
    if (!hash_equals($sessionToken, $postedToken)) {
        respond(['error' => 'Forbidden'], 403);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Method not allowed'], 405);
}

if (empty($_SESSION['auth_user'])) {
    respond(['error' => 'Unauthorized'], 401);
}

$usersPath = function_exists('lawnding_config')
    ? lawnding_config('users_path', dirname(__DIR__, 3) . '/admin/users.json')
    : dirname(__DIR__, 3) . '/admin/users.json';
$users = is_readable($usersPath) ? json_decode(file_get_contents($usersPath), true) : [];
$authUser = $_SESSION['auth_user'];
$record = null;
if (is_array($users)) {
    foreach ($users as $user) {
        if (is_array($user) && ($user['username'] ?? '') === $authUser) {
            $record = $user;
            break;
        }
    }
}
if (!$record) {
    respond(['error' => 'Unauthorized'], 401);
}
$permissions = $record['permissions'] ?? [];
if (!is_array($permissions)) {
    $permissions = [];
}
$isFullAdmin = !empty($record['master']) || in_array('full_admin', $permissions, true);
$canEditSite = $isFullAdmin || in_array('edit_site', $permissions, true);
if (!$canEditSite) {
    respond(['error' => 'Forbidden'], 403);
}

require_csrf_token();

$markdown = $_POST['markdown'] ?? '';
if (!is_string($markdown)) {
    respond(['error' => 'Invalid markdown payload.'], 400);
}

if (!class_exists('Parsedown')) {
    $parsedownPath = function_exists('lawnding_public_path')
        ? lawnding_public_path('res/scr/Parsedown.php')
        : __DIR__ . '/Parsedown.php';
    require_once $parsedownPath;
}

$parser = new Parsedown();
$html = $parser->text($markdown);
respond(['html' => $html]);
