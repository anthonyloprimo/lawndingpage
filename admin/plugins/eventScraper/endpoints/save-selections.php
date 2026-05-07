<?php
// Save a single feed's allowlist + display name + default category.
// POST {csrf_token, feed: <feedId>, label, defaultCategoryId, selections: <json-array>}.
// Re-runs ingest immediately for this feed (no network fetch — uses
// cached catalogue) so all subscribing panes pick up the new selection.

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

$feedId = isset($_POST['feed']) && is_string($_POST['feed']) ? trim($_POST['feed']) : '';
if ($feedId === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $feedId)) {
    event_scraper_save_respond(['error' => 'Invalid or missing feed id.'], 400);
}

$adapter = event_scraper_load_adapter($feedId);
if (!$adapter) {
    event_scraper_save_respond(['error' => 'Adapter not found.'], 404);
}

$label = isset($_POST['label']) && is_string($_POST['label']) ? trim($_POST['label']) : '';
if ($label === '') {
    $label = (string) ($adapter['label'] ?? $feedId);
}

$defaultCategoryId = isset($_POST['defaultCategoryId']) && is_string($_POST['defaultCategoryId'])
    ? trim($_POST['defaultCategoryId'])
    : '';

$selectionsRaw = $_POST['selections'] ?? '[]';
if (!is_string($selectionsRaw)) {
    event_scraper_save_respond(['error' => 'Invalid selections payload.'], 400);
}
$selections = json_decode($selectionsRaw, true);
if (!is_array($selections)) {
    event_scraper_save_respond(['error' => 'Invalid JSON in selections.'], 400);
}

// Validate every selection against the cached catalogue. Drop unknowns.
$catalogue = event_scraper_load_catalogue($feedId);
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

// Merge into config — preserve other feeds' state.
$config = event_scraper_load_config();
$config['feeds'][$feedId] = [
    'label'             => $label,
    'adapterId'         => $feedId,
    'defaultCategoryId' => $defaultCategoryId,
    'allowlist'         => $allowlistEntries,
    'lastReviewedAt'    => $now,
];
$config['enabled'] = true;
if (empty($config['cronToken'])) {
    $config['cronToken'] = bin2hex(random_bytes(32));
}

if (!event_scraper_save_config($config)) {
    event_scraper_save_respond(['error' => 'Failed to write config.'], 500);
}

// Re-ingest into all subscribing panes (cached catalogue, no network).
$ingest = event_scraper_apply_ingest($feedId, $catalogue['events'] ?? [], $config);

if (($ingest['status'] ?? '') === 'error') {
    event_scraper_save_respond([
        'status'        => 'error',
        'error'         => 'Selections saved but ingest failed for one or more panes — see Diagnostics.',
        'selectedCount' => count($allowlistEntries),
        'ingest'        => $ingest,
    ], 500);
}

event_scraper_log('info', 'selections_saved', [
    'feed'           => $feedId,
    'label'          => $label,
    'selectedCount'  => count($allowlistEntries),
    'paneCount'      => count($ingest['panes'] ?? []),
]);

event_scraper_save_respond([
    'status'        => 'ok',
    'feedId'        => $feedId,
    'selectedCount' => count($allowlistEntries),
    'ingest'        => $ingest,
], 200);
