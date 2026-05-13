<?php
// Generic HTTP-callable proxy for plugin-specific server endpoints.
// Mirrors module-endpoint.php for plugins:
//   plugins drop handler files at admin/plugins/<id>/endpoints/<name>.php
//   callers POST/GET /res/scr/plugin-endpoint.php?plugin=<id>&endpoint=<name>.
//
// admin/.htaccess denies direct HTTP access to plugin folders, so this
// proxy is the only path through which plugin endpoints can run -- which
// is the security property: the proxy validates plugin/endpoint names
// against [a-zA-Z0-9_-] before resolving a path, preventing traversal
// or arbitrary include of files outside admin/plugins/<id>/endpoints/.
//
// The included handler is responsible for its own auth/CSRF/method
// gating; this proxy doesn't impose any policy beyond path validation.
require_once __DIR__ . '/../../../lp-bootstrap.php';

$pluginId = $_GET['plugin'] ?? $_POST['plugin'] ?? '';
$endpoint = $_GET['endpoint'] ?? $_POST['endpoint'] ?? '';

function lawnding_plugin_endpoint_error(int $code, string $message): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

if (!is_string($pluginId) || $pluginId === '' || preg_match('/[^a-zA-Z0-9_-]/', $pluginId)) {
    lawnding_plugin_endpoint_error(400, 'Invalid plugin id.');
}
if (!is_string($endpoint) || $endpoint === '' || preg_match('/[^a-zA-Z0-9_-]/', $endpoint)) {
    lawnding_plugin_endpoint_error(400, 'Invalid endpoint name.');
}

$pluginsDir = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('plugins')
    : dirname(__DIR__, 3) . '/admin/plugins';
$endpointPath = rtrim($pluginsDir, '/\\') . '/' . $pluginId . '/endpoints/' . $endpoint . '.php';
if (!is_readable($endpointPath)) {
    lawnding_plugin_endpoint_error(404, 'Endpoint not found.');
}

require $endpointPath;
