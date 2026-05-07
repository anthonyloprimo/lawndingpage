<?php
// Generate a fresh cron token. Old token is invalidated immediately.

require_once __DIR__ . '/../../../../lp-bootstrap.php';
require_once lawnding_admin_path('auth.php');
require_once __DIR__ . '/../helpers.php';

unset($_GET['plugin'], $_GET['endpoint']);
lawnding_init_session();

function event_scraper_rotate_respond($payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    event_scraper_rotate_respond(['error' => 'Method not allowed.'], 405);
}

lawnding_require_admin_mutation(null, static function (string $msg, int $code): void {
    event_scraper_rotate_respond(['error' => $msg], $code);
});

$config = event_scraper_load_config();
$config['cronToken'] = bin2hex(random_bytes(32));

if (!event_scraper_save_config($config)) {
    event_scraper_rotate_respond(['error' => 'Failed to write config.'], 500);
}

event_scraper_rotate_respond([
    'status'    => 'ok',
    'cronToken' => $config['cronToken'],
], 200);
