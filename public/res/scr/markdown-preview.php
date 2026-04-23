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

$adminAuthPath = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('auth.php')
    : dirname(__DIR__, 3) . '/admin/auth.php';
require_once $adminAuthPath;

$allowedPermissions = ['full_admin', 'add_users', 'edit_users', 'remove_users', 'edit_site'];
$usersPath = function_exists('lawnding_config')
    ? lawnding_config('users_path', dirname(__DIR__, 3) . '/admin/users.json')
    : dirname(__DIR__, 3) . '/admin/users.json';
$users = lawnding_load_users_file($usersPath);
$tgConfig = lawnding_load_tg_config();

$identity = lawnding_resolve_admin_identity($tgConfig, $users, $allowedPermissions);
if (!$identity['isAuthenticated']) {
    respond(['error' => 'Unauthorized'], 401);
}
if (!$identity['context']['canEditSite']) {
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
if (!function_exists('lawnding_markdown_gate_apply')) {
    $gatingPath = function_exists('lawnding_public_path')
        ? lawnding_public_path('res/scr/markdown-gating.php')
        : __DIR__ . '/markdown-gating.php';
    require_once $gatingPath;
}

$clearance = $_POST['clearance'] ?? 'none';
$gated = lawnding_markdown_gate_apply($markdown, (string) $clearance, true);
if (empty($gated['ok'])) {
    respond(['error' => (string) ($gated['error'] ?? 'Invalid content-gating syntax.')], 400);
}

$parser = new Parsedown();
$html = $parser->text((string) ($gated['markdown'] ?? ''));
respond(['html' => $html]);
