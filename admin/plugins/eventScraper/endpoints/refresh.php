<?php
// Admin "Refresh now" button — same logic as cron.php, but session-gated.
//
// Reached via /res/scr/plugin-endpoint.php?plugin=eventScraper&endpoint=refresh
// POST with csrf_token + optional adapter (defaults to furrycons-na).

require_once __DIR__ . '/../../../../lp-bootstrap.php';
require_once lawnding_admin_path('auth.php');
require_once __DIR__ . '/../helpers.php';

unset($_GET['plugin'], $_GET['endpoint']);
lawnding_init_session();

function event_scraper_refresh_respond($payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    event_scraper_refresh_respond(['error' => 'Method not allowed.'], 405);
}

lawnding_require_admin_mutation(null, static function (string $msg, int $code): void {
    event_scraper_refresh_respond(['error' => $msg], $code);
});

$adapterId = isset($_POST['adapter']) && is_string($_POST['adapter'])
    ? $_POST['adapter']
    : 'furrycons-na';

$result = event_scraper_run_refresh($adapterId, 'admin');
event_scraper_refresh_respond($result, 200);
