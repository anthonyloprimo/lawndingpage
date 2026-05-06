<?php
// Telegram bot test endpoint. Admin-gated (canEditSite + CSRF).
// Probes the configured bot token via getMe. Returns ok=true on success.
require_once __DIR__ . '/../../../../lp-bootstrap.php';
$tgBotPath = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('lib/tg-bot.php')
    : __DIR__ . '/../../../../admin/lib/tg-bot.php';
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
    : dirname(__DIR__, 4) . '/admin/auth.php';
require_once $adminAuthPath;

$tgConfig = lawnding_load_tg_config();

lawnding_require_admin_mutation(
    null,
    function ($msg, $code) { tg_test_respond(['ok' => false, 'description' => $msg . '.'], $code); }
);

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
