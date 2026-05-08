<?php
// Read-only: returns everything admin.js needs to render the multi-feed UI
// + per-pane modal contributions. Session-gated (any logged-in admin can
// read; no CSRF needed for GET).

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

$config = event_scraper_load_config();
$adapters = event_scraper_discover_adapters();

$feeds = [];
foreach ($adapters as $adapterId => $adapter) {
    $adapterId = (string) $adapterId;
    $feed = event_scraper_get_feed($adapterId, $config);
    $catalogue = event_scraper_load_catalogue($adapterId);
    $lastScrape = null;
    $lastPath = event_scraper_last_scrape_path($adapterId);
    if (is_readable($lastPath)) {
        $raw = @file_get_contents($lastPath);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $lastScrape = $decoded;
            }
        }
    }
    $feeds[$adapterId] = [
        'id'           => $adapterId,
        'label'        => $feed['label'],
        'isConfigured' => $feed['isConfigured'],
        'adapter'      => [
            'label'     => (string) ($adapter['label'] ?? $adapterId),
            'url'       => (string) ($adapter['url'] ?? ''),
            'extractor' => (string) ($adapter['extractor'] ?? ''),
        ],
        'defaultCategoryId' => $feed['defaultCategoryId'],
        'allowlist'         => $feed['allowlist'],
        'autoPublish'       => $feed['autoPublish'],
        'lastReviewedAt'    => $feed['lastReviewedAt'],
        'catalogue'         => $catalogue,
        'lastScrape'        => $lastScrape,
    ];
}

// Per-pane subscription map for the per-pane modal injection.
$paneSubscriptions = [];
foreach (event_scraper_eventlist_panes() as $pane) {
    $paneId = (string) ($pane['id'] ?? '');
    if ($paneId === '') {
        continue;
    }
    $sidecarPath = function_exists('lawnding_module_settings_path')
        ? lawnding_module_settings_path('eventList', $paneId)
        : '';
    $settings = ($sidecarPath !== '' && function_exists('lawnding_load_pane_settings'))
        ? lawnding_load_pane_settings($sidecarPath)
        : [];
    $paneSubscriptions[$paneId] = [
        'override'        => is_array($settings['subscribedFeeds'] ?? null) ? array_values($settings['subscribedFeeds']) : [],
        'useSiteDefaults' => !isset($settings['useSiteDefaults']) || $settings['useSiteDefaults'] === true,
    ];
}

echo json_encode([
    'feeds'                    => $feeds,
    'siteDefaultSubscriptions' => is_array($config['siteDefaultSubscriptions'] ?? null) ? array_values($config['siteDefaultSubscriptions']) : [],
    'paneSubscriptions'        => $paneSubscriptions,
    'config'                   => [
        'enabled' => (bool) ($config['enabled'] ?? false),
    ],
], JSON_UNESCAPED_SLASHES);
