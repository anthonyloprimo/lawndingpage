<?php
// Telegram setWebhook automation. Admin-gated (canEditSite + CSRF).
// Registers the canonical webhook URL + the configured secret_token
// in one Bot API call. Mirrors test.php's response shape.
require_once __DIR__ . '/../../../../lp-bootstrap.php';
$tgBotPath = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('lib/tg-bot.php')
    : __DIR__ . '/../../../../admin/lib/tg-bot.php';
require_once $tgBotPath;

lawnding_init_session();

header('Content-Type: application/json');

function tg_register_webhook_respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tg_register_webhook_respond(['ok' => false, 'description' => 'Method not allowed.'], 405);
}

$adminAuthPath = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('auth.php')
    : dirname(__DIR__, 4) . '/admin/auth.php';
require_once $adminAuthPath;

$tgConfig = lawnding_load_tg_config();

lawnding_require_admin_mutation(
    null,
    function ($msg, $code) { tg_register_webhook_respond(['ok' => false, 'description' => $msg . '.'], $code); }
);

$token = isset($tgConfig['bot_token']) ? trim((string) $tgConfig['bot_token']) : '';
if ($token === '') {
    tg_register_webhook_respond(['ok' => false, 'description' => 'Bot token missing.']);
}

$secret = isset($tgConfig['webhook_secret_token']) ? trim((string) $tgConfig['webhook_secret_token']) : '';
if ($secret === '') {
    tg_register_webhook_respond(['ok' => false, 'description' => 'Webhook secret_token missing — save the bot config once to generate it.']);
}

// Compute the canonical webhook URL the same way admin/config.php does.
// Two sites total today; if a third callsite appears, extract a helper.
$assetBase = '';
if (function_exists('lawnding_config')) {
    $assetBase = (string) lawnding_config('base_url', '');
}
if ($assetBase === '') {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    if (is_string($scriptName) && str_starts_with($scriptName, '/public/')) {
        $assetBase = '/public';
    }
}
$assetBase = rtrim($assetBase, '/');
$scheme = lawnding_request_is_secure() ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
if ($host === '') {
    tg_register_webhook_respond(['ok' => false, 'description' => 'Cannot determine request host.']);
}
$webhookUrl = $scheme . '://' . $host . $assetBase . '/res/scr/plugin-endpoint.php?plugin=telegram&endpoint=webhook';

$bot = new TgBotClient($token);
$resp = $bot->setWebhook($webhookUrl, $secret);

if (!is_array($resp) || empty($resp['ok'])) {
    $description = is_array($resp) && isset($resp['description'])
        ? (string) $resp['description']
        : 'setWebhook failed.';
    tg_register_webhook_respond(['ok' => false, 'description' => $description]);
}

tg_register_webhook_respond(['ok' => true, 'description' => 'Webhook registered: ' . $webhookUrl]);
