<?php
// Save a single pane's subscribedFeeds override. POST {csrf_token, paneId,
// useSiteDefaults: "1"|"0", subscriptions: <json-array of feedIds>}.
// Writes the subscribedFeeds key into the pane's settings.json sidecar,
// preserving useSiteDefaults + manifest-declared keys (the core
// pane-settings-save endpoint owns those; this endpoint owns subscribedFeeds).
// Re-ingests for each feed against this pane so events reflect new state.

require_once __DIR__ . '/../../../../lp-bootstrap.php';
require_once lawnding_admin_path('auth.php');
require_once __DIR__ . '/../helpers.php';

unset($_GET['plugin'], $_GET['endpoint']);
lawnding_init_session();

function event_scraper_save_pane_subs_respond($payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    event_scraper_save_pane_subs_respond(['error' => 'Method not allowed.'], 405);
}

lawnding_require_admin_mutation(null, static function (string $msg, int $code): void {
    event_scraper_save_pane_subs_respond(['error' => $msg], $code);
});

$paneId = isset($_POST['paneId']) && is_string($_POST['paneId']) ? trim($_POST['paneId']) : '';
if ($paneId === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $paneId)) {
    event_scraper_save_pane_subs_respond(['error' => 'Invalid pane id.'], 400);
}

// Confirm the pane is an eventList pane (defense against cross-module
// writes). 404 keeps the surface honest if a pane id is wrong.
$panesPath = function_exists('lawnding_data_path') ? lawnding_data_path('panes.json') : '';
$panesData = $panesPath !== '' ? lawnding_read_json($panesPath) : [];
$panes = is_array($panesData['panes'] ?? null) ? $panesData['panes'] : [];
$pane = null;
foreach ($panes as $p) {
    if (is_array($p) && (string) ($p['id'] ?? '') === $paneId) {
        $pane = $p;
        break;
    }
}
if (!$pane || ($pane['module'] ?? '') !== 'eventList') {
    event_scraper_save_pane_subs_respond(['error' => 'Pane not found or not an Event List pane.'], 404);
}

$useSiteDefaults = !isset($_POST['useSiteDefaults']) || $_POST['useSiteDefaults'] !== '0';

$rawSubs = $_POST['subscriptions'] ?? '[]';
$decoded = is_string($rawSubs) ? json_decode($rawSubs, true) : null;
if (!is_array($decoded)) {
    $decoded = [];
}

$config = event_scraper_load_config();
$validFeedIds = array_keys(is_array($config['feeds'] ?? null) ? $config['feeds'] : []);
$cleaned = [];
foreach ($decoded as $feedId) {
    if (!is_string($feedId)) {
        continue;
    }
    if (in_array($feedId, $validFeedIds, true) && !in_array($feedId, $cleaned, true)) {
        $cleaned[] = $feedId;
    }
}

// Load existing sidecar, modify only the subscription-related keys,
// preserve everything else (manifest-declared bools, etc.).
$sidecarPath = function_exists('lawnding_module_settings_path')
    ? lawnding_module_settings_path('eventList', $paneId)
    : '';
if ($sidecarPath === '') {
    event_scraper_save_pane_subs_respond(['error' => 'eventList has no settings_file declaration.'], 500);
}
$existing = function_exists('lawnding_load_pane_settings')
    ? lawnding_load_pane_settings($sidecarPath)
    : [];
$existing['useSiteDefaults'] = $useSiteDefaults;
$existing['subscribedFeeds'] = $cleaned;

if (!function_exists('lawnding_save_pane_settings') || !lawnding_save_pane_settings($sidecarPath, $existing)) {
    event_scraper_log('error', 'pane_subscriptions_write_failed', [
        'paneId' => $paneId,
        'path'   => $sidecarPath,
        'reason' => event_scraper_describe_write_failure($sidecarPath),
    ]);
    event_scraper_save_pane_subs_respond(['error' => 'Failed to write pane settings.'], 500);
}

// Re-ingest every feed against this pane — adds events for newly-subscribed
// feeds, removes events from feeds that just got unsubscribed (build_ingest
// auto-deletes events whose UID isn't in the feed's allowlist).
$paneIngestResults = [];
foreach ($config['feeds'] as $feedId => $_) {
    $feedId = (string) $feedId;
    $feed = event_scraper_get_feed($feedId, $config);
    $catalogue = event_scraper_load_catalogue($feedId);

    $effectiveSubs = event_scraper_pane_subscriptions($paneId, $config);
    $isSubscribed = in_array($feedId, $effectiveSubs, true);

    $allowlistUids = $isSubscribed ? array_keys($feed['allowlist']) : [];
    $r = event_scraper_apply_feed_to_pane(
        $feedId,
        $paneId,
        $allowlistUids,
        $catalogue['events'],
        $feed['defaultCategoryId']
    );
    $r['feedId'] = $feedId;
    $r['subscribed'] = $isSubscribed;
    $paneIngestResults[] = $r;
}

event_scraper_log('info', 'pane_subscriptions_saved', [
    'paneId'          => $paneId,
    'useSiteDefaults' => $useSiteDefaults,
    'subscriptions'   => $cleaned,
]);

event_scraper_save_pane_subs_respond([
    'status'          => 'ok',
    'paneId'          => $paneId,
    'useSiteDefaults' => $useSiteDefaults,
    'subscriptions'   => $cleaned,
    'ingest'          => $paneIngestResults,
], 200);
