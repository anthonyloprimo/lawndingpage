<?php
// Returns the scraper's current catalogue record for a single event
// (eventlist-record-ready shape). Backs admin.js's Resume sync
// immediate-revert path.

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

$adapterId = (string) ($_GET['adapter'] ?? '');
$sourceUid = (string) ($_GET['uid'] ?? '');
if ($adapterId === '' || $sourceUid === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing adapter or uid']);
    exit;
}

$adapters = event_scraper_discover_adapters();
if (!isset($adapters[$adapterId])) {
    http_response_code(404);
    echo json_encode(['error' => 'Unknown adapter']);
    exit;
}

$catalogue = event_scraper_load_catalogue($adapterId);
$events = is_array($catalogue['events'] ?? null) ? $catalogue['events'] : [];
$match = null;
foreach ($events as $event) {
    if (is_array($event) && (string) ($event['sourceUid'] ?? '') === $sourceUid) {
        $match = $event;
        break;
    }
}
if ($match === null) {
    // Catalogue is stale, or the upstream feed dropped this event. Caller
    // falls back to clearing the lock only; next cron pass will reconcile.
    http_response_code(404);
    echo json_encode(['error' => 'Event not in current catalogue']);
    exit;
}

$config = event_scraper_load_config();
$feed = event_scraper_get_feed($adapterId, $config);
$defaultCategoryId = (string) ($feed['defaultCategoryId'] ?? '');
$adapter = $adapters[$adapterId];
$keywordBadgesMap = is_array($adapter['keywordBadges'] ?? null) ? $adapter['keywordBadges'] : [];

$record = event_scraper_to_eventlist_record($match, $adapterId, $defaultCategoryId, $keywordBadgesMap);

echo json_encode(['record' => $record], JSON_UNESCAPED_SLASHES);
