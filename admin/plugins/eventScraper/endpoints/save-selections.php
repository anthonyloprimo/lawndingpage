<?php
// Save the admin's selections + target pane + default category.
//
// POST {csrf_token, adapter?, targetPaneId, defaultCategoryId,
//       selections: [<sourceUid>, ...]}
//
// Only validates that selected UIDs are present in the cached catalogue;
// unknown UIDs are silently dropped (defensive against stale form data).
// Re-runs ingest immediately so the eventList pane reflects the new
// selection without requiring a separate refresh.

require_once __DIR__ . '/../../../../lp-bootstrap.php';
require_once lawnding_admin_path('auth.php');
require_once __DIR__ . '/../helpers.php';

unset($_GET['plugin'], $_GET['endpoint']);
lawnding_init_session();

function event_scraper_save_respond($payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    event_scraper_save_respond(['error' => 'Method not allowed.'], 405);
}

lawnding_require_admin_mutation(null, static function (string $msg, int $code): void {
    event_scraper_save_respond(['error' => $msg], $code);
});

$adapterId = isset($_POST['adapter']) && is_string($_POST['adapter']) && $_POST['adapter'] !== ''
    ? $_POST['adapter']
    : 'furrycons-na';

$targetPaneId = isset($_POST['targetPaneId']) && is_string($_POST['targetPaneId'])
    ? trim($_POST['targetPaneId'])
    : '';

$defaultCategoryId = isset($_POST['defaultCategoryId']) && is_string($_POST['defaultCategoryId'])
    ? trim($_POST['defaultCategoryId'])
    : '';

if ($targetPaneId === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $targetPaneId)) {
    event_scraper_save_respond(['error' => 'Invalid or missing targetPaneId.'], 400);
}

$selectionsRaw = $_POST['selections'] ?? '[]';
if (!is_string($selectionsRaw)) {
    event_scraper_save_respond(['error' => 'Invalid selections payload.'], 400);
}
$selections = json_decode($selectionsRaw, true);
if (!is_array($selections)) {
    event_scraper_save_respond(['error' => 'Invalid JSON in selections.'], 400);
}

// Validate every selection against the current catalogue. Drop unknown.
$catalogue = event_scraper_load_catalogue($adapterId);
$catalogueUids = [];
foreach ($catalogue['events'] ?? [] as $event) {
    if (is_array($event) && isset($event['sourceUid'])) {
        $catalogueUids[(string) $event['sourceUid']] = true;
    }
}

$now = gmdate('c');
$allowlistEntries = [];
foreach ($selections as $uid) {
    if (!is_scalar($uid)) {
        continue;
    }
    $uid = (string) $uid;
    if (!isset($catalogueUids[$uid])) {
        continue;
    }
    $allowlistEntries[$uid] = ['addedAt' => $now];
}

// Merge into existing config; preserve other adapters' allowlists.
$config = event_scraper_load_config();
$allowlist = is_array($config['allowlist'] ?? null) ? $config['allowlist'] : [];
$allowlist[$adapterId] = $allowlistEntries;

$config['allowlist'] = $allowlist;
$config['targetPaneId'] = $targetPaneId;
$config['defaultCategoryId'] = $defaultCategoryId;
$config['lastReviewedAt'] = $now;
$config['enabled'] = true;

// Bootstrap the cron token on first save so the admin doesn't have to click
// Rotate just to get an initial value. Keeps later saves idempotent — only
// generates if no token exists yet.
if (empty($config['cronToken'])) {
    $config['cronToken'] = bin2hex(random_bytes(32));
}

if (!event_scraper_save_config($config)) {
    event_scraper_save_respond(['error' => 'Failed to write config.'], 500);
}

// Re-run ingest using the cached catalogue (no network fetch).
$ingest = event_scraper_apply_ingest($adapterId, $catalogue['events'] ?? [], $config);

event_scraper_save_respond([
    'status'        => 'ok',
    'selectedCount' => count($allowlistEntries),
    'ingest'        => $ingest,
], 200);
