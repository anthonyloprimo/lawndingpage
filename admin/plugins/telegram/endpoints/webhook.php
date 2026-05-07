<?php
// Telegram webhook handler for LawndingPage bot commands.
require_once __DIR__ . '/../../../../lp-bootstrap.php';
$tgAuthPath = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('lib/tg-auth.php')
    : __DIR__ . '/../../../../admin/lib/tg-auth.php';
require_once $tgAuthPath;

header('Content-Type: application/json');

$config = lawnding_load_tg_config();
$token = isset($config['bot_token']) ? trim((string) $config['bot_token']) : '';
if ($token === '') {
    echo json_encode(['ok' => false, 'description' => 'Bot token missing']);
    exit;
}

// Silent {"ok": true} on header mismatch mirrors Telegram's docs and
// avoids leaking the live webhook URL to anonymous probes. Empty
// config secret = fresh install — accept until first save populates it.
$expectedSecret = isset($config['webhook_secret_token']) ? trim((string) $config['webhook_secret_token']) : '';
if ($expectedSecret !== '') {
    $headerSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!is_string($headerSecret) || !hash_equals($expectedSecret, $headerSecret)) {
        echo json_encode(['ok' => true]);
        exit;
    }
}

$tgBotPath = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('lib/tg-bot.php')
    : __DIR__ . '/../../../../admin/lib/tg-bot.php';
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
