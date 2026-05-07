<?php
// Read-only: returns the currently-cached catalogue + last-scrape metadata
// + admin's saved config (allowlist, targetPaneId, defaultCategoryId).
//
// Session-gated (any logged-in admin can read; the USERS-pane visibility
// rule applies — read access is wider than write access). No CSRF needed
// for a GET that returns a JSON snapshot.

require_once __DIR__ . '/../../../../lp-bootstrap.php';
require_once lawnding_admin_path('auth.php');
require_once __DIR__ . '/../helpers.php';

unset($_GET['plugin'], $_GET['endpoint']);
lawnding_init_session();

header('Content-Type: application/json');

$tgConfig = function_exists('lawnding_load_tg_config') ? lawnding_load_tg_config() : [];
$usersPath = function_exists('lawnding_config')
    ? lawnding_config('users_path', dirname(__DIR__, 3) . '/users.json')
    : dirname(__DIR__, 3) . '/users.json';
$users = function_exists('lawnding_load_users_file') ? lawnding_load_users_file($usersPath) : [];
$identity = lawnding_resolve_admin_identity($tgConfig, $users, ['full_admin', 'add_users', 'edit_users', 'remove_users', 'edit_site']);

if (!$identity['isAuthenticated']) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$adapterId = isset($_GET['adapter']) && is_string($_GET['adapter']) && preg_match('/^[a-zA-Z0-9_-]+$/', $_GET['adapter'])
    ? $_GET['adapter']
    : 'furrycons-na';

$adapter = event_scraper_load_adapter($adapterId);
$catalogue = event_scraper_load_catalogue($adapterId);
$config = event_scraper_load_config();

$lastScrapePath = event_scraper_last_scrape_path($adapterId);
$lastScrape = null;
if (is_readable($lastScrapePath)) {
    $raw = file_get_contents($lastScrapePath);
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $lastScrape = $decoded;
        }
    }
}

echo json_encode([
    'adapter'    => $adapter ? ['id' => $adapter['id'] ?? $adapterId, 'label' => $adapter['label'] ?? $adapterId, 'url' => $adapter['url'] ?? ''] : null,
    'catalogue'  => $catalogue,
    'lastScrape' => $lastScrape,
    'config'     => [
        'enabled'           => (bool) ($config['enabled'] ?? false),
        'targetPaneId'      => (string) ($config['targetPaneId'] ?? ''),
        'defaultCategoryId' => (string) ($config['defaultCategoryId'] ?? ''),
        'lastReviewedAt'    => (string) ($config['lastReviewedAt'] ?? ''),
        'allowlist'         => is_array($config['allowlist'] ?? null) ? $config['allowlist'] : new stdClass(),
    ],
], JSON_UNESCAPED_SLASHES);
