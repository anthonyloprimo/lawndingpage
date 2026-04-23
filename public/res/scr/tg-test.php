<?php
// Telegram bot test endpoint. Admin-gated (canEditSite + CSRF).
// Probes the configured bot token via getMe. Returns ok=true on success.
require_once __DIR__ . '/../../../lp-bootstrap.php';
$tgBotPath = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('lib/tg-bot.php')
    : __DIR__ . '/../../../admin/lib/tg-bot.php';
require_once $tgBotPath;

lawnding_init_session();

header('Content-Type: application/json');

function tg_test_respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tg_test_respond(['ok' => false, 'description' => 'Method not allowed.'], 405);
}

// Resolve admin identity (bcrypt or Telegram-derived).
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
    tg_test_respond(['ok' => false, 'description' => 'Unauthorized.'], 401);
}
if (!$identity['context']['canEditSite']) {
    tg_test_respond(['ok' => false, 'description' => 'Forbidden.'], 403);
}

// CSRF check.
$sessionToken = $_SESSION['csrf_token'] ?? '';
$postedToken = $_POST['csrf_token'] ?? '';
if (!is_string($sessionToken) || $sessionToken === '' || !is_string($postedToken) || $postedToken === ''
    || !hash_equals($sessionToken, $postedToken)) {
    tg_test_respond(['ok' => false, 'description' => 'Invalid CSRF token.'], 403);
}

$token = isset($tgConfig['bot_token']) ? trim((string) $tgConfig['bot_token']) : '';
if ($token === '') {
    tg_test_respond(['ok' => false, 'description' => 'Bot token missing.']);
}

$bot = new TgBotClient($token);
$resp = $bot->request('getMe');
if (!is_array($resp) || empty($resp['ok'])) {
    $description = is_array($resp) && isset($resp['description'])
        ? (string) $resp['description']
        : 'Request failed.';
    tg_test_respond(['ok' => false, 'description' => $description]);
}

$result = is_array($resp['result'] ?? null) ? $resp['result'] : [];
$username = isset($result['username']) ? $result['username'] : '';
$id = isset($result['id']) ? $result['id'] : '';
$label = trim('Bot OK: ' . ($username !== '' ? '@' . $username : '') . ($id !== '' ? ' (id ' . $id . ')' : ''));

tg_test_respond(['ok' => true, 'description' => $label]);
