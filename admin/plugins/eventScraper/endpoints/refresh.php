<?php
// Admin "Refresh now" — POST with optional feed=<feedId>.
// No feed param: refresh all configured feeds (failure-isolated).
// feed param: refresh that one feed (used by per-feed Refresh button).
// Auth + CSRF gated via lawnding_require_admin_mutation.

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

$feedId = isset($_POST['feed']) && is_string($_POST['feed']) ? trim($_POST['feed']) : '';

if ($feedId !== '') {
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $feedId)) {
        event_scraper_refresh_respond(['error' => 'Invalid feed id.'], 400);
    }
    // Single-feed refresh — auto-create config entry if missing so
    // "Refresh now" on an unconfigured feed bootstraps it on first hit.
    $config = event_scraper_load_config();
    if (!isset($config['feeds'][$feedId])) {
        $adapter = event_scraper_load_adapter($feedId);
        if (!$adapter) {
            event_scraper_refresh_respond(['error' => 'Adapter not found.'], 404);
        }
        $config['feeds'][$feedId] = [
            'label'             => (string) ($adapter['label'] ?? $feedId),
            'adapterId'         => $feedId,
            'defaultCategoryId' => '',
            'allowlist'         => [],
            'lastReviewedAt'    => '',
        ];
        if (empty($config['cronToken'])) {
            $config['cronToken'] = bin2hex(random_bytes(32));
        }
        event_scraper_save_config($config);
    }
    $result = event_scraper_run_refresh($feedId, 'admin');
    $result['feedId'] = $feedId;
    event_scraper_refresh_respond($result, 200);
}

$result = event_scraper_run_all_feeds('admin');
event_scraper_refresh_respond($result, 200);
