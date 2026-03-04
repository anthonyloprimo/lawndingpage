<?php
// Telegram bot test endpoint (GET).
require_once __DIR__ . '/../../../lp-bootstrap.php';
$tgBotPath = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('lib/tg-bot.php')
    : __DIR__ . '/../../../admin/lib/tg-bot.php';
require_once $tgBotPath;

header('Content-Type: application/json');

$adminDir = function_exists('lawnding_config')
    ? lawnding_config('admin_dir', dirname(__DIR__, 3) . '/admin')
    : dirname(__DIR__, 3) . '/admin';
$configPath = rtrim($adminDir, '/\\') . '/lp-tgBot.json';
if (!is_readable($configPath)) {
    echo json_encode(['ok' => false, 'description' => 'Bot config not found.']);
    exit;
}
$decoded = json_decode(file_get_contents($configPath), true);
if (!is_array($decoded)) {
    echo json_encode(['ok' => false, 'description' => 'Bot config invalid.']);
    exit;
}
$token = isset($decoded['bot_token']) ? trim((string) $decoded['bot_token']) : '';
if ($token === '') {
    echo json_encode(['ok' => false, 'description' => 'Bot token missing.']);
    exit;
}

$bot = new TgBotClient($token);
$resp = $bot->request('getMe');
if (!is_array($resp) || empty($resp['ok'])) {
    $description = is_array($resp) && isset($resp['description'])
        ? (string) $resp['description']
        : 'Request failed.';
    echo json_encode(['ok' => false, 'description' => $description]);
    exit;
}

$result = is_array($resp['result'] ?? null) ? $resp['result'] : [];
$username = isset($result['username']) ? $result['username'] : '';
$id = isset($result['id']) ? $result['id'] : '';
$label = trim('Bot OK: ' . ($username !== '' ? '@' . $username : '') . ($id !== '' ? ' (id ' . $id . ')' : ''));

echo json_encode(['ok' => true, 'description' => $label]);
