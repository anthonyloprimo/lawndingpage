<?php
require_once __DIR__ . '/../../../lp-bootstrap.php';

// Serve module-specific CSS from admin/modules/<id>/style.css.
$moduleId = $_GET['module'] ?? '';
if (!is_string($moduleId) || $moduleId === '' || preg_match('/[^a-zA-Z0-9_-]/', $moduleId)) {
    http_response_code(400);
    exit;
}

$cssPath = function_exists('lawnding_module_file')
    ? lawnding_module_file($moduleId, 'style.css')
    : '';
if (!is_readable($cssPath)) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/css; charset=utf-8');
readfile($cssPath);
