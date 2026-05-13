<?php
// Save the site-default subscribed-feeds list. POST {csrf_token,
// subscriptions: <json-array of feedIds>}. Re-runs ingest for every
// feed against panes that use site defaults so changes take effect
// immediately (no waiting for the cron).

require_once __DIR__ . '/../../../../lp-bootstrap.php';
require_once lawnding_admin_path('auth.php');
require_once __DIR__ . '/../helpers.php';

unset($_GET['plugin'], $_GET['endpoint']);
lawnding_init_session();

function event_scraper_save_default_subs_respond($payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    event_scraper_save_default_subs_respond(['error' => 'Method not allowed.'], 405);
}

lawnding_require_admin_mutation(null, static function (string $msg, int $code): void {
    event_scraper_save_default_subs_respond(['error' => $msg], $code);
});

$rawSubs = $_POST['subscriptions'] ?? '[]';
if (!is_string($rawSubs)) {
    event_scraper_save_default_subs_respond(['error' => 'Invalid subscriptions payload.'], 400);
}
$decoded = json_decode($rawSubs, true);
if (!is_array($decoded)) {
    event_scraper_save_default_subs_respond(['error' => 'Invalid JSON in subscriptions.'], 400);
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
$config['siteDefaultSubscriptions'] = $cleaned;

if (!event_scraper_save_config($config)) {
    event_scraper_save_default_subs_respond(['error' => 'Failed to write config.'], 500);
}

// Re-ingest every configured feed so panes-using-site-defaults catch up
// immediately. Per-pane-override panes are unaffected (their
// subscribedFeeds aren't read here).
$ingest = [];
foreach ($config['feeds'] as $feedId => $_) {
    $feedId = (string) $feedId;
    $catalogue = event_scraper_load_catalogue($feedId);
    $ingest[$feedId] = event_scraper_apply_ingest($feedId, $catalogue['events'], $config);
}

event_scraper_log('info', 'site_defaults_saved', [
    'subscriptions' => $cleaned,
]);

event_scraper_save_default_subs_respond([
    'status'        => 'ok',
    'subscriptions' => $cleaned,
    'ingest'        => $ingest,
], 200);
