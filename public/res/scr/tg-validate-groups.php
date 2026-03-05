<?php
// Validate Telegram group IDs by calling getChat for each.
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

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload) || empty($payload['group_ids']) || !is_array($payload['group_ids'])) {
    echo json_encode(['ok' => false, 'description' => 'Missing group IDs.']);
    exit;
}

$groupIds = [];
foreach ($payload['group_ids'] as $value) {
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
    echo json_encode(['ok' => false, 'description' => 'No valid group IDs supplied.']);
    exit;
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

echo json_encode([
    'ok' => true,
    'valid' => $valid,
    'invalid' => $invalid,
    'errors' => $errors,
]);
