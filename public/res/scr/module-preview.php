<?php
require_once __DIR__ . '/../../../lp-bootstrap.php';
// Module preview proxy: serves module preview images to authenticated admins only.
lawnding_init_session();

function respond_status($code) {
    http_response_code($code);
    exit;
}

// Only authenticated users with edit permission can view previews.
$adminAuthPath = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('auth.php')
    : dirname(__DIR__, 3) . '/admin/auth.php';
require_once $adminAuthPath;

$allowedPermissions = ['full_admin', 'add_users', 'edit_users', 'remove_users', 'edit_site'];
$usersPath = function_exists('lawnding_config')
    ? (function_exists('lawnding_runtime_file_path')
        ? lawnding_runtime_file_path('users_path')
        : lawnding_config('users_path', dirname(__DIR__, 3) . '/admin/users.json'))
    : dirname(__DIR__, 3) . '/admin/users.json';
$users = lawnding_load_users_file($usersPath);
$tgConfig = lawnding_load_tg_config();

$identity = lawnding_resolve_admin_identity($tgConfig, $users, $allowedPermissions);
if (!$identity['isAuthenticated']) {
    respond_status(401);
}
if (!$identity['context']['canEditSite']) {
    respond_status(403);
}

$moduleId = $_GET['module'] ?? '';
$file = $_GET['file'] ?? '';
if (!is_string($moduleId) || $moduleId === '' || !is_string($file) || $file === '') {
    respond_status(400);
}
// Allow only safe module IDs and filenames to avoid path traversal.
if (preg_match('/[^a-zA-Z0-9_-]/', $moduleId)) {
    respond_status(400);
}
$file = basename($file);
$modulesDir = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('modules')
    : dirname(__DIR__, 3) . '/admin/modules';
$path = rtrim($modulesDir, '/\\') . '/' . $moduleId . '/' . $file;
if (!is_readable($path)) {
    respond_status(404);
}

// Infer mime type and stream the preview image.
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $path);
finfo_close($finfo);
if (!$mime) {
    $mime = 'application/octet-stream';
}
header('Content-Type: ' . $mime);
readfile($path);
