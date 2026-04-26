<?php
// Validate Telegram group IDs by calling getChat for each. Admin-gated
// (canEditSite + CSRF). POST only; payload is form-urlencoded with
// csrf_token + group_ids[] fields.
require_once __DIR__ . '/../../../lp-bootstrap.php';
$tgBotPath = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('lib/tg-bot.php')
    : __DIR__ . '/../../../admin/lib/tg-bot.php';
require_once $tgBotPath;

lawnding_init_session();

header('Content-Type: application/json');

function tg_validate_respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tg_validate_respond(['ok' => false, 'description' => 'Method not allowed.'], 405);
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
    tg_validate_respond(['ok' => false, 'description' => 'Unauthorized.'], 401);
}
if (!$identity['context']['canEditSite']) {
    tg_validate_respond(['ok' => false, 'description' => 'Forbidden.'], 403);
}

// CSRF check.
$sessionToken = $_SESSION['csrf_token'] ?? '';
$postedToken = $_POST['csrf_token'] ?? '';
if (!is_string($sessionToken) || $sessionToken === '' || !is_string($postedToken) || $postedToken === ''
    || !hash_equals($sessionToken, $postedToken)) {
    tg_validate_respond(['ok' => false, 'description' => 'Invalid CSRF token.'], 403);
}

$token = isset($tgConfig['bot_token']) ? trim((string) $tgConfig['bot_token']) : '';
if ($token === '') {
    tg_validate_respond(['ok' => false, 'description' => 'Bot token missing.']);
}

$rawGroupIds = $_POST['group_ids'] ?? [];
if (!is_array($rawGroupIds)) {
    tg_validate_respond(['ok' => false, 'description' => 'Missing group IDs.']);
}

$groupIds = [];
foreach ($rawGroupIds as $value) {
    if (!is_string($value)) {
        continue;
    }
    $trimmed = trim($value);
    if ($trimmed === '') {
        continue;
    }
    $groupIds[] = $trimmed;
}
$groupIds = array_values(array_unique($groupIds));
if (empty($groupIds)) {
    tg_validate_respond(['ok' => false, 'description' => 'No valid group IDs supplied.']);
}

$bot = new TgBotClient($token);
$valid = [];
$invalid = [];
$errors = [];

foreach ($groupIds as $groupId) {
    $resp = $bot->request('getChat', ['chat_id' => $groupId]);
    if (!is_array($resp) || empty($resp['ok'])) {
        $invalid[] = $groupId;
        $errors[$groupId] = is_array($resp) && isset($resp['description'])
            ? (string) $resp['description']
            : 'Request failed';
        continue;
    }
    $valid[] = $groupId;
}

tg_validate_respond([
    'ok' => true,
    'valid' => $valid,
    'invalid' => $invalid,
    'errors' => $errors,
]);
