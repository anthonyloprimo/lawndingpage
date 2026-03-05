<?php
// Telegram webhook handler for LawndingPage bot commands.
require_once __DIR__ . '/../../../lp-bootstrap.php';

header('Content-Type: application/json');

function load_tg_bot_config(): array {
    $adminDir = function_exists('lawnding_config')
        ? lawnding_config('admin_dir', dirname(__DIR__, 3) . '/admin')
        : dirname(__DIR__, 3) . '/admin';
    $path = rtrim($adminDir, '/\\') . '/lp-tgBot.json';
    if (!is_readable($path)) {
        return [];
    }
    $decoded = json_decode(file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

$config = load_tg_bot_config();
$token = isset($config['bot_token']) ? trim((string) $config['bot_token']) : '';
if ($token === '') {
    echo json_encode(['ok' => false, 'description' => 'Bot token missing']);
    exit;
}

$tgBotPath = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('lib/tg-bot.php')
    : __DIR__ . '/../../../admin/lib/tg-bot.php';
require_once $tgBotPath;
$bot = new TgBotClient($token);

$raw = file_get_contents('php://input');
$update = json_decode($raw, true);
if (!is_array($update)) {
    echo json_encode(['ok' => true]);
    exit;
}

$message = $update['message'] ?? null;
if (!is_array($message)) {
    echo json_encode(['ok' => true]);
    exit;
}

$text = isset($message['text']) ? trim((string) $message['text']) : '';
$chat = $message['chat'] ?? null;
$chatId = is_array($chat) ? ($chat['id'] ?? null) : null;
if ($text !== '' && $chatId !== null) {
    if (strcasecmp($text, '/lpGetGroup') === 0) {
        $title = is_array($chat) ? ($chat['title'] ?? '') : '';
        $label = $title !== '' ? ($title . ' ') : '';
        $bot->sendMessage($chatId, $label . 'Group ID: ' . $chatId);
    }
}

echo json_encode(['ok' => true]);
