<?php
// Cron endpoint — token-gated, runs every configured feed.
// Header X-Scraper-Cron-Token holds the secret (kept out of access logs).
// Returns HTTP 200 with aggregated per-feed status; top-level "ok" only when
// every feed succeeded so cron monitors can alert on partial failures.

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

$result = event_scraper_run_all_feeds('cron');
echo json_encode($result, JSON_UNESCAPED_SLASHES);
