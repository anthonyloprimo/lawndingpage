<?php
require_once __DIR__ . '/../../../lp-bootstrap.php';
// Module preview proxy: serves module preview images to authenticated admins only.
lawnding_init_session();

function respond_status($code) {
    http_response_code($code);
    exit;
}

// Only authenticated users with edit permission can view previews.
if (empty($_SESSION['auth_user'])) {
    respond_status(401);
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
    respond_status(401);
}
$permissions = $record['permissions'] ?? [];
if (!is_array($permissions)) {
    $permissions = [];
}
$isFullAdmin = !empty($record['master']) || in_array('full_admin', $permissions, true);
$canEditSite = $isFullAdmin || in_array('edit_site', $permissions, true);
if (!$canEditSite) {
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
