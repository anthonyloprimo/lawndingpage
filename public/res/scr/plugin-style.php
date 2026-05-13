<?php
require_once __DIR__ . '/../../../lp-bootstrap.php';

// Serve plugin-specific CSS from admin/plugins/<id>/style.css.
// Mirrors module-style.php; same path-validation contract.
$pluginId = $_GET['plugin'] ?? '';
if (!is_string($pluginId) || $pluginId === '' || preg_match('/[^a-zA-Z0-9_-]/', $pluginId)) {
    http_response_code(400);
    exit;
}

$pluginsDir = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('plugins')
    : dirname(__DIR__, 3) . '/admin/plugins';
$cssPath = rtrim($pluginsDir, '/\\') . '/' . $pluginId . '/style.css';
if (!is_readable($cssPath)) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/css; charset=utf-8');
readfile($cssPath);
