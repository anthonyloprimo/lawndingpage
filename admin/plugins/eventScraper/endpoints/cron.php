<?php
// Cron endpoint — token-gated, runs the daily refresh.
//
// Reached via /res/scr/plugin-endpoint.php?plugin=eventScraper&endpoint=cron
// with header X-Scraper-Cron-Token: <secret>. The secret is stored in
// admin/lp-eventScraperConfig.json; the admin UI renders the curl line
// ready to paste into crontab.
//
// Token in a header (not a query string) so the secret doesn't land in
// webserver access logs.
//
// Returns JSON. HTTP 200 even on extractor errors so cron callers don't
// retry storms; the JSON body carries status: ok|error.

require_once __DIR__ . '/../../../../lp-bootstrap.php';
require_once __DIR__ . '/../helpers.php';

unset($_GET['plugin'], $_GET['endpoint']);

header('Content-Type: application/json');

$config = event_scraper_load_config();
$expected = (string) ($config['cronToken'] ?? '');
$presented = (string) ($_SERVER['HTTP_X_SCRAPER_CRON_TOKEN'] ?? '');

if ($expected === '' || $presented === '' || !hash_equals($expected, $presented)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$adapterId = isset($_GET['adapter']) && is_string($_GET['adapter'])
    ? $_GET['adapter']
    : 'furrycons-na';

$result = event_scraper_run_refresh($adapterId, 'cron');
echo json_encode($result, JSON_UNESCAPED_SLASHES);
