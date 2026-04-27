<?php
// Truncates admin/errors.jsonl. Logs a 'errors_cleared' audit entry into the
// freshly-empty file so the action itself leaves a trail.
// Gated on canEditSite + CSRF.

require_once __DIR__ . '/../../../lp-bootstrap.php';
require_once lawnding_admin_path('lib/tg-auth.php');
require_once lawnding_admin_path('auth.php');

lawnding_init_session();

header('Content-Type: application/json; charset=utf-8');

function diag_clear_respond($payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    diag_clear_respond(['error' => 'Method not allowed'], 405);
}

$allowedPermissions = ['full_admin', 'add_users', 'edit_users', 'remove_users', 'edit_site'];
$tgConfig = lawnding_load_tg_config();
$users = lawnding_load_users_file(lawnding_config('users_path'));
$identity = lawnding_resolve_admin_identity($tgConfig, $users, $allowedPermissions);

if (!$identity['isAuthenticated'] || empty($identity['context']['canEditSite'])) {
    diag_clear_respond(['error' => 'Forbidden'], 403);
}

$sessionToken = $_SESSION['csrf_token'] ?? '';
$postedToken = $_POST['csrf_token'] ?? '';
if (!is_string($sessionToken) || $sessionToken === ''
    || !is_string($postedToken) || $postedToken === ''
    || !hash_equals($sessionToken, $postedToken)) {
    diag_clear_respond(['error' => 'Forbidden'], 403);
}

// Truncate. Also clear the rotation file so a clear means "really empty,"
// not "empty until the rotation file gets surfaced."
$logPath = lawnding_logs_path();
$rotPath = lawnding_logs_rotate_path();
if (is_file($logPath)) {
    @file_put_contents($logPath, '', LOCK_EX);
}
if (is_file($rotPath)) {
    @unlink($rotPath);
}

// Audit: who cleared it, when. The append goes into the now-empty log so the
// next tail returns at least one entry showing the clear happened.
lawnding_log_event('info', 'errors_cleared', [
    'by' => $identity['displayName'] ?? '',
    'auth_user' => $identity['authUser'] ?? '',
]);

diag_clear_respond(['ok' => true]);
