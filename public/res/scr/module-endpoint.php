<?php
// Generic HTTP-callable proxy for module-specific server endpoints.
// Mirrors module-script.php / module-style.php for the endpoint case:
// modules drop handler files at admin/modules/<id>/endpoints/<name>.php
// and admin.js posts to /res/scr/module-endpoint.php?module=<id>&endpoint=<name>.
//
// admin/.htaccess denies direct HTTP access to module folders, so this
// proxy is the only path through which module endpoints can run -- which
// is the security property: the proxy validates module/endpoint names
// against [a-zA-Z0-9_-] before resolving a path, preventing traversal
// or arbitrary include of files outside admin/modules/<id>/endpoints/.
//
// The included handler is responsible for its own auth/CSRF/method
// gating; this proxy doesn't impose any policy beyond path validation.
require_once __DIR__ . '/../../../lp-bootstrap.php';

$moduleId = $_GET['module'] ?? $_POST['module'] ?? '';
$endpoint = $_GET['endpoint'] ?? $_POST['endpoint'] ?? '';

function lawnding_module_endpoint_error(int $code, string $message): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

if (!is_string($moduleId) || $moduleId === '' || preg_match('/[^a-zA-Z0-9_-]/', $moduleId)) {
    lawnding_module_endpoint_error(400, 'Invalid module id.');
}
if (!is_string($endpoint) || $endpoint === '' || preg_match('/[^a-zA-Z0-9_-]/', $endpoint)) {
    lawnding_module_endpoint_error(400, 'Invalid endpoint name.');
}

$modulesDir = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('modules')
    : dirname(__DIR__, 3) . '/admin/modules';
$endpointPath = rtrim($modulesDir, '/\\') . '/' . $moduleId . '/endpoints/' . $endpoint . '.php';
if (!is_readable($endpointPath)) {
    lawnding_module_endpoint_error(404, 'Endpoint not found.');
}

require $endpointPath;
