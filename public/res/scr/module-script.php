<?php
require_once __DIR__ . '/../../../lp-bootstrap.php';

$moduleId = $_GET['module'] ?? '';
$file = $_GET['file'] ?? '';
if (!is_string($moduleId) || $moduleId === '' || preg_match('/[^a-zA-Z0-9_-]/', $moduleId)) {
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

$modulesDir = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('modules')
    : dirname(__DIR__, 3) . '/admin/modules';
$scriptPath = rtrim($modulesDir, '/\\') . '/' . $moduleId . '/' . $file;
if (!is_readable($scriptPath)) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/javascript; charset=utf-8');
readfile($scriptPath);
