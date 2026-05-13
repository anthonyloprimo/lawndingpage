<?php
require_once __DIR__ . '/../../../lp-bootstrap.php';

// Serve plugin-specific JS from admin/plugins/<id>/<file>.
// Mirrors module-script.php; same path-validation contract.
$pluginId = $_GET['plugin'] ?? '';
$file = $_GET['file'] ?? '';
if (!is_string($pluginId) || $pluginId === '' || preg_match('/[^a-zA-Z0-9_-]/', $pluginId)) {
    http_response_code(400);
    exit;
}
if (!is_string($file) || $file === '' || preg_match('/[^a-zA-Z0-9._-]/', $file)) {
    http_response_code(400);
    exit;
}
if (str_contains($file, '..') || !str_ends_with($file, '.js')) {
    http_response_code(400);
    exit;
}

$pluginsDir = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('plugins')
    : dirname(__DIR__, 3) . '/admin/plugins';
$scriptPath = rtrim($pluginsDir, '/\\') . '/' . $pluginId . '/' . $file;
if (!is_readable($scriptPath)) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/javascript; charset=utf-8');
readfile($scriptPath);
