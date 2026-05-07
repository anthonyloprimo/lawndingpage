<?php
// Event Scraper helpers — refresh, diff, ingest, render.
//
// Lazy-loaded by init.php on first hook fire OR by endpoint scripts at the
// top of their request. Heavy logic lives here so init.php stays light per
// the every-request load.

// ------------------------------------------------------------------------
// Logging — thin wrapper around lawnding_log_event so call sites stay
// one-liners. Diagnostics admin UI surfaces these via the standard feed.
// ------------------------------------------------------------------------

function event_scraper_log(string $severity, string $eventSlug, array $context = []): void {
    if (function_exists('lawnding_log_event')) {
        lawnding_log_event($severity, 'event_scraper.' . $eventSlug, $context);
    }
}

// ------------------------------------------------------------------------
// Paths
// ------------------------------------------------------------------------

function event_scraper_plugin_dir(): string {
    return __DIR__;
}

function event_scraper_cache_dir(): string {
    return __DIR__ . '/cache';
}

function event_scraper_config_path(): string {
    return dirname(__DIR__, 2) . '/lp-eventScraperConfig.json';
}

function event_scraper_adapter_path(string $adapterId): string {
    return __DIR__ . '/adapters/' . $adapterId . '.json';
}

function event_scraper_catalogue_path(string $adapterId): string {
    return __DIR__ . '/cache/catalogue-' . $adapterId . '.json';
}

function event_scraper_catalogue_prev_path(string $adapterId): string {
    return __DIR__ . '/cache/catalogue-prev-' . $adapterId . '.json';
}

function event_scraper_last_scrape_path(string $adapterId): string {
    return __DIR__ . '/cache/lastScrape-' . $adapterId . '.json';
}

// ------------------------------------------------------------------------
// Config (lp-eventScraperConfig.json)
// ------------------------------------------------------------------------

function event_scraper_config_defaults(): array {
    return [
        'enabled'                  => false,
        'cronToken'                => '',
        'feeds'                    => [],
        'siteDefaultSubscriptions' => [],
    ];
}

function event_scraper_load_config(): array {
    $path = event_scraper_config_path();
    $defaults = event_scraper_config_defaults();
    if (!is_readable($path)) {
        return $defaults;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return $defaults;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }
    $merged = array_replace($defaults, $decoded);
    if (!is_array($merged['feeds'])) {
        $merged['feeds'] = [];
    }
    if (!is_array($merged['siteDefaultSubscriptions'])) {
        $merged['siteDefaultSubscriptions'] = [];
    }
    return $merged;
}

// LOCK_EX + 0640. Bcrypt-style: refuse to write a config without a non-empty
// cronToken when one was previously set (prevents a partial write from
// nuking the cron auth and silently breaking the daily refresh).
function event_scraper_save_config(array $config): bool {
    $path = event_scraper_config_path();
    $clean = array_replace(event_scraper_config_defaults(), $config);
    $json = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        event_scraper_log('error', 'config_write_failed', [
            'path'  => $path,
            'phase' => 'json_encode',
        ]);
        return false;
    }
    $ok = @file_put_contents($path, $json, LOCK_EX);
    if ($ok === false) {
        event_scraper_log('error', 'config_write_failed', [
            'path'   => $path,
            'phase'  => 'file_put_contents',
            'reason' => event_scraper_describe_write_failure($path),
        ]);
        return false;
    }
    @chmod($path, 0640);
    return true;
}

// Best-effort post-mortem on a failed write — checks whether the directory
// is writable and whether the file already exists with the wrong owner.
// Pure inspection (read-only), feeds the diagnostics log so admins can
// see "permission denied vs. directory missing vs. file owned by SSH user
// while web is www-data" without SSHing in.
function event_scraper_describe_write_failure(string $path): string {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        return 'parent directory does not exist: ' . $dir;
    }
    if (!is_writable($dir)) {
        $statDir = @stat($dir);
        $owner = $statDir ? ('uid=' . $statDir['uid'] . ' gid=' . $statDir['gid']) : 'unknown';
        $procUser = function_exists('posix_geteuid') ? ('uid=' . posix_geteuid() . ' gid=' . posix_getegid()) : 'unknown';
        return 'directory not writable by web process (' . $procUser . '); directory owner is ' . $owner;
    }
    if (file_exists($path) && !is_writable($path)) {
        $statFile = @stat($path);
        $owner = $statFile ? ('uid=' . $statFile['uid'] . ' gid=' . $statFile['gid']) : 'unknown';
        return 'file exists but is not writable by web process; file owner is ' . $owner;
    }
    return 'unknown (file_put_contents returned false; check disk space and SELinux/AppArmor)';
}

// ------------------------------------------------------------------------
// Adapters + feed lookup
// ------------------------------------------------------------------------

// Glob adapters/*.json. Memoize when a third call site appears.
function event_scraper_discover_adapters(): array {
    $files = glob(__DIR__ . '/adapters/*.json');
    if (!$files) {
        return [];
    }
    $out = [];
    foreach ($files as $f) {
        $raw = @file_get_contents($f);
        if ($raw === false) {
            continue;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            continue;
        }
        $id = (string) ($decoded['id'] ?? '');
        if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
            continue;
        }
        $out[$id] = $decoded;
    }
    ksort($out);
    return $out;
}

// isConfigured = admin has saved this feed; UI renders placeholders otherwise.
function event_scraper_get_feed(string $feedId, array $config): array {
    $feed = is_array($config['feeds'][$feedId] ?? null) ? $config['feeds'][$feedId] : [];
    $adapter = event_scraper_load_adapter($feedId);
    return [
        'id'                => $feedId,
        'label'             => $feed['label'] ?? '' ?: (string) ($adapter['label'] ?? $feedId),
        'adapterId'         => $feedId,
        'defaultCategoryId' => (string) ($feed['defaultCategoryId'] ?? ''),
        'allowlist'         => is_array($feed['allowlist'] ?? null) ? $feed['allowlist'] : [],
        'lastReviewedAt'    => (string) ($feed['lastReviewedAt'] ?? ''),
        'isConfigured'      => is_array($config['feeds'][$feedId] ?? null),
    ];
}

function event_scraper_load_adapter(string $adapterId): ?array {
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $adapterId)) {
        return null;
    }
    $path = event_scraper_adapter_path($adapterId);
    if (!is_readable($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

// ------------------------------------------------------------------------
// Catalogue
// ------------------------------------------------------------------------

function event_scraper_load_catalogue(string $adapterId): array {
    $path = event_scraper_catalogue_path($adapterId);
    if (!is_readable($path)) {
        return ['fetchedAt' => '', 'sourceUrl' => '', 'events' => []];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return ['fetchedAt' => '', 'sourceUrl' => '', 'events' => []];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['fetchedAt' => '', 'sourceUrl' => '', 'events' => []];
    }
    $decoded['events'] = is_array($decoded['events'] ?? null) ? $decoded['events'] : [];
    return $decoded;
}

function event_scraper_save_catalogue(string $adapterId, array $catalogue): bool {
    $path = event_scraper_catalogue_path($adapterId);
    $json = json_encode($catalogue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        event_scraper_log('error', 'catalogue_write_failed', [
            'adapter' => $adapterId,
            'path'    => $path,
            'phase'   => 'json_encode',
        ]);
        return false;
    }
    if (@file_put_contents($path, $json, LOCK_EX) === false) {
        event_scraper_log('error', 'catalogue_write_failed', [
            'adapter' => $adapterId,
            'path'    => $path,
            'phase'   => 'file_put_contents',
            'reason'  => event_scraper_describe_write_failure($path),
        ]);
        return false;
    }
    return true;
}

// ------------------------------------------------------------------------
// Pure transforms (unit-testable)
// ------------------------------------------------------------------------

// Diff two catalogue event lists by sourceUid.
// Returns ['new' => [...], 'changed' => [...], 'removed' => [...]].
function event_scraper_diff(array $prev, array $next): array {
    $prevById = [];
    foreach ($prev as $e) {
        if (is_array($e) && isset($e['sourceUid'])) {
            $prevById[(string) $e['sourceUid']] = $e;
        }
    }
    $nextById = [];
    foreach ($next as $e) {
        if (is_array($e) && isset($e['sourceUid'])) {
            $nextById[(string) $e['sourceUid']] = $e;
        }
    }

    // Cast keys back to string when iterating: PHP coerces numeric-looking
    // string keys to int on assignment, so $uid would otherwise come out as
    // int 2 when the source data uses string '2'. Stable string ids matter
    // for set-membership tests downstream (allowlist lookup, etc.).
    $new = [];
    $changed = [];
    foreach ($nextById as $uid => $event) {
        $uid = (string) $uid;
        if (!isset($prevById[$uid])) {
            $new[] = $event;
            continue;
        }
        $fields = event_scraper_event_field_diff($prevById[$uid], $event);
        if ($fields) {
            $changed[] = [
                'uid'      => $uid,
                'event'    => $event,
                'previous' => $prevById[$uid],
                'fields'   => $fields,
            ];
        }
    }

    $removed = [];
    foreach ($prevById as $uid => $event) {
        if (!isset($nextById[(string) $uid])) {
            $removed[] = $event;
        }
    }

    return ['new' => $new, 'changed' => $changed, 'removed' => $removed];
}

// Field-by-field comparator. Returns the list of changed field names.
// Keeps the comparison restricted to fields the eventList renderer surfaces;
// internal-only fields (e.g. fetchedAt) don't contribute to "changed".
function event_scraper_event_field_diff(array $prev, array $next): array {
    $watched = ['name', 'startDate', 'endDate', 'location', 'description', 'url', 'registrationUrl', 'image'];
    $diff = [];
    foreach ($watched as $f) {
        if ((string) ($prev[$f] ?? '') !== (string) ($next[$f] ?? '')) {
            $diff[] = $f;
        }
    }
    return $diff;
}

// Map a normalized scraper event into an eventList event record. Empty
// required fields fall back to placeholder strings so the eventList
// validator (event_list_event_is_valid) doesn't silently drop the record.
function event_scraper_to_eventlist_record(array $event, string $adapterId, string $defaultCategoryId): array {
    $description = trim((string) ($event['description'] ?? ''));
    $url = trim((string) ($event['url'] ?? ''));
    if ($url !== '') {
        $description = ($description !== '' ? $description . "\n\n" : '') . 'More info: ' . $url;
    }
    $registrationUrl = trim((string) ($event['registrationUrl'] ?? ''));
    if ($registrationUrl !== '') {
        $description = ($description !== '' ? $description . "\n" : '') . 'Register: ' . $registrationUrl;
    }
    if ($description === '') {
        $description = (string) ($event['name'] ?? 'Event');
    }

    $address = trim((string) ($event['location'] ?? ''));
    if ($address === '') {
        $address = 'Location TBA';
    }

    return [
        'name'          => (string) ($event['name'] ?? ''),
        'startDate'     => (string) ($event['startDate'] ?? ''),
        'endDate'       => (string) ($event['endDate'] ?? ''),
        'startTime'     => '',
        'endTime'       => '',
        'allDay'        => true,
        'address'       => $address,
        'description'   => $description,
        'categoryId'    => $defaultCategoryId,
        'source'        => 'eventScraper',
        'sourceAdapter' => $adapterId,
        'sourceUid'     => (string) ($event['sourceUid'] ?? ''),
    ];
}

// Build the {create, update, delete} changes payload that
// event_list_apply_events() consumes.
//
// Inputs:
//   $catalogue        — normalized event records from current scrape
//   $allowlistUids    — list of sourceUids the admin opted in to ingest
//   $adapterId        — for tagging records with sourceAdapter
//   $existingEvents   — current eventList events for the target pane
//   $defaultCategoryId — category assigned to created records
//
// Output rule, by zone:
//   in catalogue + in allowlist + new       -> create
//   in catalogue + in allowlist + existing  -> update (id preserved)
//   in catalogue + NOT allowlist            -> nothing if not existing; delete if existing
//   NOT in catalogue + existing scraped     -> delete (auto on source removal, per plan)
//   manual events (source !== 'eventScraper') -> never touched
function event_scraper_build_ingest_changes(
    array $catalogue,
    array $allowlistUids,
    string $adapterId,
    array $existingEvents,
    string $defaultCategoryId
): array {
    $allowlistSet = [];
    foreach ($allowlistUids as $uid) {
        if (is_scalar($uid)) {
            $allowlistSet[(string) $uid] = true;
        }
    }

    $existingByUid = [];
    foreach ($existingEvents as $event) {
        if (!is_array($event)) {
            continue;
        }
        if (($event['source'] ?? '') !== 'eventScraper') {
            continue;
        }
        if (($event['sourceAdapter'] ?? '') !== $adapterId) {
            continue;
        }
        $uid = (string) ($event['sourceUid'] ?? '');
        if ($uid !== '') {
            $existingByUid[$uid] = $event;
        }
    }

    $catalogueByUid = [];
    foreach ($catalogue as $event) {
        if (!is_array($event)) {
            continue;
        }
        $uid = (string) ($event['sourceUid'] ?? '');
        if ($uid !== '') {
            $catalogueByUid[$uid] = $event;
        }
    }

    $creates = [];
    $updates = [];
    $deletes = [];

    foreach ($catalogueByUid as $uid => $event) {
        if (!isset($allowlistSet[$uid])) {
            continue;
        }
        $record = event_scraper_to_eventlist_record($event, $adapterId, $defaultCategoryId);
        if (isset($existingByUid[$uid])) {
            $record['id'] = (string) ($existingByUid[$uid]['id'] ?? '');
            $updates[] = $record;
        } else {
            $creates[] = $record;
        }
    }

    foreach ($existingByUid as $uid => $event) {
        if (!isset($catalogueByUid[$uid]) || !isset($allowlistSet[$uid])) {
            $deletes[] = (string) ($event['id'] ?? '');
        }
    }

    return [
        'create' => $creates,
        'update' => $updates,
        'delete' => array_values(array_filter($deletes, fn ($id) => $id !== '')),
    ];
}

// ------------------------------------------------------------------------
// Subscription resolution
// ------------------------------------------------------------------------

// Per-pane override when "Use site defaults" is unticked; site default otherwise.
function event_scraper_pane_subscriptions(string $paneId, ?array $config = null): array {
    if ($config === null) {
        $config = event_scraper_load_config();
    }
    $siteDefaults = is_array($config['siteDefaultSubscriptions'] ?? null)
        ? $config['siteDefaultSubscriptions']
        : [];

    if (!function_exists('lawnding_module_settings_path') || !function_exists('lawnding_load_pane_settings')) {
        return $siteDefaults;
    }
    $sidecarPath = lawnding_module_settings_path('eventList', $paneId);
    if ($sidecarPath === '') {
        return $siteDefaults;
    }
    $settings = lawnding_load_pane_settings($sidecarPath);
    $useSiteDefaults = !isset($settings['useSiteDefaults']) || $settings['useSiteDefaults'] === true;
    if ($useSiteDefaults) {
        return $siteDefaults;
    }
    $override = is_array($settings['subscribedFeeds'] ?? null) ? $settings['subscribedFeeds'] : [];
    return array_values(array_filter($override, 'is_string'));
}

// Inverse: which eventList panes subscribe to a given feed?
function event_scraper_panes_subscribed_to(string $feedId, array $allEventListPanes, ?array $config = null): array {
    if ($config === null) {
        $config = event_scraper_load_config();
    }
    $out = [];
    foreach ($allEventListPanes as $pane) {
        $paneId = (string) ($pane['id'] ?? '');
        if ($paneId === '') {
            continue;
        }
        if (in_array($feedId, event_scraper_pane_subscriptions($paneId, $config), true)) {
            $out[] = $paneId;
        }
    }
    return $out;
}

// ------------------------------------------------------------------------
// I/O wrappers
// ------------------------------------------------------------------------

// Built-in pool of recent real-browser UAs. Used when the adapter declares
// userAgent: "@rotate" (or when a future caller passes an empty UA — defensive).
// Update opportunistically; UAs decay over years but stay reasonable for a long
// time as long as the major-version number stays plausible.
function event_scraper_random_browser_ua(): string {
    static $pool = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:120.0) Gecko/20100101 Firefox/120.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:120.0) Gecko/20100101 Firefox/120.0',
        'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
    ];
    return $pool[array_rand($pool)];
}

// Resolve adapter['userAgent'] to a concrete UA string. Accepts:
//   string         -> used as-is (existing fixed-UA behavior)
//   "@rotate"      -> random pick from the built-in browser pool
//   array<string>  -> random pick from the adapter's own pool
function event_scraper_pick_user_agent(array $adapter): string {
    $ua = $adapter['userAgent'] ?? '';
    if (is_array($ua)) {
        $ua = array_values(array_filter($ua, 'is_string'));
        return $ua ? $ua[array_rand($ua)] : '';
    }
    if ($ua === '@rotate') {
        return event_scraper_random_browser_ua();
    }
    return (string) $ua;
}

// Fetch via cURL. Tolerant of cURL errors (returns status 0 with message).
function event_scraper_fetch_url(string $url, string $userAgent, int $timeoutSeconds = 15): array {
    if (!function_exists('curl_init')) {
        // Surface in Diagnostics. Without curl the daily refresh is silently
        // dead; admins reading the diagnostics feed need to see why.
        if (function_exists('lawnding_log_event')) {
            lawnding_log_event('error', 'event_scraper.curl_missing', [
                'message' => 'PHP cURL extension is not loaded; install php-curl on the server.',
                'url'     => $url,
            ]);
        }
        return ['status' => 0, 'body' => '', 'error' => 'PHP cURL extension is not loaded; install php-curl on the server.'];
    }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_USERAGENT      => $userAgent,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
        CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml'],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $body === null) {
        return ['status' => $status, 'body' => '', 'error' => $err !== '' ? $err : 'fetch failed'];
    }
    return ['status' => $status, 'body' => (string) $body, 'error' => null];
}

// Read existing eventList events file (canonical writer is save-config.php;
// we mirror its read shape). Returns the decoded {events: [...]} structure
// or [] on missing/malformed.
function event_scraper_load_pane_events(string $paneId): array {
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $paneId)) {
        return [];
    }
    $path = function_exists('lawnding_data_path')
        ? lawnding_data_path($paneId . '.json')
        : __DIR__ . '/../../../public/res/data/' . $paneId . '.json';
    if (!is_readable($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    return is_array($decoded['events'] ?? null) ? $decoded['events'] : [];
}

// Write the merged events array back. Race window matches save-config.php's
// pre-existing last-write-wins behavior — same window the platform itself
// uses; LOCK_EX is the project standard (the project docs File I/O section).
function event_scraper_save_pane_events(string $paneId, array $events): bool {
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $paneId)) {
        event_scraper_log('error', 'pane_events_write_failed', [
            'paneId' => $paneId,
            'phase'  => 'validate',
            'reason' => 'paneId failed allowlist regex (defense against path traversal)',
        ]);
        return false;
    }
    $path = function_exists('lawnding_data_path')
        ? lawnding_data_path($paneId . '.json')
        : __DIR__ . '/../../../public/res/data/' . $paneId . '.json';
    $payload = ['events' => array_values($events)];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        event_scraper_log('error', 'pane_events_write_failed', [
            'paneId' => $paneId,
            'path'   => $path,
            'phase'  => 'json_encode',
        ]);
        return false;
    }
    if (@file_put_contents($path, $json, LOCK_EX) === false) {
        event_scraper_log('error', 'pane_events_write_failed', [
            'paneId' => $paneId,
            'path'   => $path,
            'phase'  => 'file_put_contents',
            'reason' => event_scraper_describe_write_failure($path),
        ]);
        return false;
    }
    return true;
}

// ------------------------------------------------------------------------
// Refresh orchestrator
// ------------------------------------------------------------------------

// Fetch + extract + diff + ingest. Caller passes 'cron' or 'admin' as
// $trigger; the value is recorded in lastScrape.json for diagnostics.
function event_scraper_run_refresh(string $adapterId, string $trigger): array {
    $now = gmdate('c');
    $writeLast = function (array $payload) use ($adapterId): void {
        $path = event_scraper_last_scrape_path($adapterId);
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            event_scraper_log('warn', 'last_scrape_write_failed', [
                'adapter' => $adapterId,
                'path'    => $path,
                'reason'  => event_scraper_describe_write_failure($path),
            ]);
        }
    };

    $adapter = event_scraper_load_adapter($adapterId);
    if (!$adapter) {
        $err = "Adapter not found: $adapterId";
        event_scraper_log('error', 'adapter_not_found', ['adapter' => $adapterId]);
        $writeLast(['ranAt' => $now, 'status' => 'error', 'count' => 0, 'error' => $err, 'trigger' => $trigger]);
        return ['status' => 'error', 'count' => 0, 'error' => $err];
    }

    $url = (string) ($adapter['url'] ?? '');
    $userAgent = event_scraper_pick_user_agent($adapter);
    if ($url === '' || $userAgent === '') {
        $err = 'Adapter is missing required url or userAgent';
        event_scraper_log('error', 'adapter_invalid', [
            'adapter' => $adapterId,
            'reason'  => 'missing url or userAgent',
        ]);
        $writeLast(['ranAt' => $now, 'status' => 'error', 'count' => 0, 'error' => $err, 'trigger' => $trigger]);
        return ['status' => 'error', 'count' => 0, 'error' => $err];
    }

    $fetched = event_scraper_fetch_url($url, $userAgent);
    if ($fetched['status'] !== 200 || $fetched['body'] === '') {
        $err = 'Fetch failed: HTTP ' . $fetched['status'] . ' ' . (string) $fetched['error'];
        $writeLast(['ranAt' => $now, 'status' => 'error', 'count' => 0, 'error' => $err, 'trigger' => $trigger]);
        event_scraper_log('error', 'fetch_failed', [
            'adapter' => $adapterId,
            'status'  => $fetched['status'],
            'error'   => $fetched['error'],
        ]);
        return ['status' => 'error', 'count' => 0, 'error' => $err];
    }
    // Capture payload size now; reused in the success log below.
    $payloadSizeKb = round(strlen($fetched['body']) / 1024, 1);

    $extractor = (string) ($adapter['extractor'] ?? '');
    $extractorPath = __DIR__ . '/extractors/' . $extractor . '.php';
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $extractor) || !is_readable($extractorPath)) {
        $err = "Extractor not found: $extractor";
        event_scraper_log('error', 'extractor_not_found', [
            'adapter'   => $adapterId,
            'extractor' => $extractor,
            'path'      => $extractorPath,
        ]);
        $writeLast(['ranAt' => $now, 'status' => 'error', 'count' => 0, 'error' => $err, 'trigger' => $trigger]);
        return ['status' => 'error', 'count' => 0, 'error' => $err];
    }
    require_once $extractorPath;
    $extractFn = 'event_scraper_extract_' . $extractor;
    if (!function_exists($extractFn)) {
        $err = "Extractor function missing: $extractFn";
        event_scraper_log('error', 'extractor_function_missing', [
            'adapter'    => $adapterId,
            'extractor'  => $extractor,
            'expectedFn' => $extractFn,
        ]);
        $writeLast(['ranAt' => $now, 'status' => 'error', 'count' => 0, 'error' => $err, 'trigger' => $trigger]);
        return ['status' => 'error', 'count' => 0, 'error' => $err];
    }
    $events = $extractFn($fetched['body'], $adapter);

    // Guard against silent calendar nuke. If the extractor returns nothing
    // (source format change, partial fetch, WAF returning a 200 soft-block
    // page) we MUST NOT rewrite the catalogue — the next ingest pass would
    // delete every previously-ingested event because none would still be
    // "in the catalogue." A stale catalogue is strictly better than an
    // empty one.
    if (count($events) === 0) {
        $err = 'Extractor produced 0 events; refusing to overwrite the catalogue. Previous data retained.';
        event_scraper_log('error', 'empty_result', [
            'adapter'   => $adapterId,
            'payloadKb' => $payloadSizeKb,
        ]);
        $writeLast(['ranAt' => $now, 'status' => 'error', 'count' => 0, 'error' => $err, 'trigger' => $trigger]);
        return ['status' => 'error', 'count' => 0, 'error' => $err];
    }

    $prev = event_scraper_load_catalogue($adapterId);
    $diff = event_scraper_diff($prev['events'] ?? [], $events);

    // Rotate: prev <- old; current <- new. The copy is best-effort (the
    // diff is already computed; rotation only matters for the next scrape's
    // historical view), so a failure logs at warn, not error.
    $cataloguePath = event_scraper_catalogue_path($adapterId);
    if (file_exists($cataloguePath)) {
        if (!@copy($cataloguePath, event_scraper_catalogue_prev_path($adapterId))) {
            event_scraper_log('warn', 'catalogue_rotate_failed', [
                'adapter' => $adapterId,
                'reason'  => event_scraper_describe_write_failure(event_scraper_catalogue_prev_path($adapterId)),
            ]);
        }
    }

    if (!event_scraper_save_catalogue($adapterId, [
        'fetchedAt' => $now,
        'sourceUrl' => $url,
        'events'    => $events,
    ])) {
        // catalogue_write_failed already logged inside event_scraper_save_catalogue.
        $err = 'Fetched ' . count($events) . ' events, but failed to write the catalogue file. Check Diagnostics for the file-permissions detail.';
        $writeLast(['ranAt' => $now, 'status' => 'error', 'count' => count($events), 'error' => $err, 'trigger' => $trigger]);
        return ['status' => 'error', 'count' => count($events), 'error' => $err];
    }

    // Diagnostics for changes admins might want to know about, plus a
    // single "scrape completed" info entry that always fires (even when
    // nothing changed) so admins can see the cron is alive in the feed.
    foreach ($diff['changed'] as $change) {
        event_scraper_log('info', 'event_changed', [
            'adapter' => $adapterId,
            'uid'     => $change['uid'],
            'name'    => $change['event']['name'] ?? '',
            'fields'  => $change['fields'],
        ]);
    }
    foreach ($diff['removed'] as $event) {
        event_scraper_log('info', 'event_removed', [
            'adapter'   => $adapterId,
            'uid'       => $event['sourceUid'] ?? '',
            'name'      => $event['name'] ?? '',
            'startDate' => $event['startDate'] ?? '',
        ]);
    }
    event_scraper_log('info', 'scrape_completed', [
        'adapter'   => $adapterId,
        'trigger'   => $trigger,
        'count'     => count($events),
        'newCount'  => count($diff['new']),
        'changed'   => count($diff['changed']),
        'removed'   => count($diff['removed']),
        'payloadKb' => $payloadSizeKb,
    ]);

    // Ingest pass — only if a target pane is configured.
    $config = event_scraper_load_config();
    $ingested = event_scraper_apply_ingest($adapterId, $events, $config);

    $writeLast([
        'ranAt'   => $now,
        'status'  => 'ok',
        'count'   => count($events),
        'diff'    => [
            'new'     => count($diff['new']),
            'changed' => count($diff['changed']),
            'removed' => count($diff['removed']),
        ],
        'error'   => null,
        'trigger' => $trigger,
    ]);

    return [
        'status'   => 'ok',
        'count'    => count($events),
        'diff'     => $diff,
        'ingested' => $ingested,
    ];
}

// Apply one feed's catalogue to every subscribing eventList pane.
function event_scraper_apply_ingest(string $feedId, array $catalogue, array $config): array {
    $feed = event_scraper_get_feed($feedId, $config);
    if (!$feed['isConfigured']) {
        return ['status' => 'ok', 'reason' => 'feed not configured', 'panes' => []];
    }

    $allPanes = event_scraper_eventlist_panes();
    $subscribers = event_scraper_panes_subscribed_to($feedId, $allPanes, $config);
    if (!$subscribers) {
        return ['status' => 'ok', 'reason' => 'no panes subscribed', 'panes' => []];
    }

    $allowlistUids = array_keys($feed['allowlist']);
    $defaultCategoryId = $feed['defaultCategoryId'];

    $results = [];
    $allOk = true;
    foreach ($subscribers as $paneId) {
        $r = event_scraper_apply_feed_to_pane($feedId, $paneId, $allowlistUids, $catalogue, $defaultCategoryId);
        $r['paneId'] = $paneId;
        if (($r['status'] ?? '') !== 'ok') {
            $allOk = false;
        }
        $results[] = $r;
    }
    return ['status' => $allOk ? 'ok' : 'error', 'panes' => $results];
}

// Empty $allowlistUids wipes this feed's events from the pane (unsubscribe path).
function event_scraper_apply_feed_to_pane(
    string $feedId,
    string $paneId,
    array $allowlistUids,
    array $catalogue,
    string $defaultCategoryId
): array {
    $existing = event_scraper_load_pane_events($paneId);
    $changes = event_scraper_build_ingest_changes(
        $catalogue,
        $allowlistUids,
        $feedId,
        $existing,
        $defaultCategoryId
    );
    if (!$changes['create'] && !$changes['update'] && !$changes['delete']) {
        return ['status' => 'ok', 'created' => 0, 'updated' => 0, 'deleted' => 0];
    }
    require_once dirname(__DIR__, 2) . '/modules/eventList/helpers.php';
    $merged = event_list_apply_events(['events' => $existing], ['changes' => $changes]);
    if (!event_scraper_save_pane_events($paneId, $merged['events'] ?? [])) {
        // pane_events_write_failed already logged inside the save helper.
        return ['status' => 'error', 'reason' => 'pane events write failed'];
    }
    return [
        'status'  => 'ok',
        'created' => count($changes['create']),
        'updated' => count($changes['update']),
        'deleted' => count($changes['delete']),
    ];
}

// Failure-isolated multi-feed loop. Top-level status 'ok' only when every feed succeeds.
function event_scraper_run_all_feeds(string $trigger): array {
    $config = event_scraper_load_config();
    $feeds = is_array($config['feeds'] ?? null) ? $config['feeds'] : [];
    if (!$feeds) {
        return ['status' => 'ok', 'feeds' => [], 'message' => 'No feeds configured.'];
    }
    $results = [];
    $allOk = true;
    foreach ($feeds as $feedId => $_) {
        $feedId = (string) $feedId;
        try {
            $r = event_scraper_run_refresh($feedId, $trigger);
        } catch (\Throwable $e) {
            event_scraper_log('error', 'feed_exception', [
                'feed'  => $feedId,
                'error' => $e->getMessage(),
            ]);
            $r = ['status' => 'error', 'count' => 0, 'error' => 'Uncaught: ' . $e->getMessage()];
        }
        $r['feedId'] = $feedId;
        $results[] = $r;
        if (($r['status'] ?? '') !== 'ok') {
            $allOk = false;
        }
    }
    return ['status' => $allOk ? 'ok' : 'error', 'feeds' => $results];
}

// ------------------------------------------------------------------------
// Render
// ------------------------------------------------------------------------

// Returns the list of {id, name} for every pane whose module is eventList.
// Used by the SITE CONFIG render to populate the target-pane select.
function event_scraper_eventlist_panes(): array {
    if (!function_exists('lawnding_load_panes') || !function_exists('lawnding_data_path')) {
        return [];
    }
    $panes = lawnding_load_panes(lawnding_data_path('panes.json'));
    $out = [];
    foreach ($panes as $pane) {
        if (!is_array($pane)) {
            continue;
        }
        $moduleId = (string) ($pane['module'] ?? '');
        if ($moduleId !== 'eventList') {
            continue;
        }
        $id = (string) ($pane['id'] ?? '');
        $name = (string) ($pane['name'] ?? $id);
        if ($id !== '') {
            $out[] = ['id' => $id, 'name' => $name];
        }
    }
    return $out;
}

// SITE CONFIG slot — renders the multi-feed picker inside eventList's section.
// PHP emits structure + server-known data; admin.js fetches the catalogue
// data per-feed and fills the tables.
function event_scraper_render_eventlist_extras(): void {
    $config = event_scraper_load_config();
    $adapters = event_scraper_discover_adapters();
    $categories = function_exists('event_list_load_categories') ? event_list_load_categories() : [];
    $cronToken = (string) ($config['cronToken'] ?? '');
    $siteDefaults = is_array($config['siteDefaultSubscriptions'] ?? null) ? $config['siteDefaultSubscriptions'] : [];

    $proxyUrl = function_exists('lawnding_asset_url')
        ? lawnding_asset_url('res/scr/plugin-endpoint.php?plugin=eventScraper&endpoint=cron')
        : '/res/scr/plugin-endpoint.php?plugin=eventScraper&endpoint=cron';
    $hostHint = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : 'YOUR_HOST_HERE';
    $cronUrl = 'https://' . $hostHint . $proxyUrl;
    ?>
    <fieldset class="siteConfigGroup eventScraperConfig"
              data-cron-url="<?php echo htmlspecialchars($cronUrl, ENT_QUOTES, 'UTF-8'); ?>"
              data-cron-token="<?php echo htmlspecialchars($cronToken, ENT_QUOTES, 'UTF-8'); ?>">
        <legend>Event Scraper feeds</legend>
        <p class="paneHint">Configure each feed's source data, then choose which feeds new Event List panes subscribe to by default. Per-pane overrides live in the gear icon on each pane.</p>

        <div class="eventScraperGlobalStatus" aria-live="polite">
            <span class="eventScraperGlobalStatusText">Loading feed list…</span>
            <button type="button" class="eventScraperRefreshAllBtn" disabled>Refresh all feeds</button>
        </div>

        <fieldset class="eventScraperDefaultSubsBlock">
            <legend>Default subscribed feeds</legend>
            <p class="paneHint">New Event List panes (and panes with "Use site defaults" ticked) subscribe to these.</p>
            <div class="eventScraperDefaultSubsList" data-site-defaults="<?php echo htmlspecialchars(json_encode(array_values($siteDefaults), JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>">
                <p class="eventScraperListEmpty">Loading…</p>
            </div>
            <div class="eventScraperDefaultSubsActions">
                <button type="button" class="eventScraperSaveDefaultSubsBtn" disabled>Save defaults</button>
                <span class="eventScraperSaveDefaultSubsStatus" aria-live="polite"></span>
            </div>
        </fieldset>

        <div class="eventScraperFeedList">
            <?php foreach ($adapters as $adapterId => $adapter): ?>
                <?php
                    $feed = event_scraper_get_feed((string) $adapterId, $config);
                    $adapterLabel = (string) ($adapter['label'] ?? $adapterId);
                    $adapterUrl = (string) ($adapter['url'] ?? '');
                    $extractor = (string) ($adapter['extractor'] ?? '');
                ?>
                <fieldset class="eventScraperFeedBlock"
                          data-feed-id="<?php echo htmlspecialchars((string) $adapterId, ENT_QUOTES, 'UTF-8'); ?>"
                          data-configured="<?php echo $feed['isConfigured'] ? '1' : '0'; ?>">
                    <legend><?php echo htmlspecialchars($feed['label'] ?: $adapterLabel, ENT_QUOTES, 'UTF-8'); ?></legend>
                    <p class="paneHint">
                        Source: <?php echo htmlspecialchars($adapterLabel, ENT_QUOTES, 'UTF-8'); ?>
                        (<?php echo htmlspecialchars($extractor, ENT_QUOTES, 'UTF-8'); ?>) ·
                        <?php echo htmlspecialchars($adapterUrl, ENT_QUOTES, 'UTF-8'); ?>
                    </p>

                    <div class="eventScraperControls">
                        <label class="eventScraperControl">
                            <span>Display name</span>
                            <input type="text" class="eventScraperFeedLabel"
                                   value="<?php echo htmlspecialchars($feed['label'] ?: $adapterLabel, ENT_QUOTES, 'UTF-8'); ?>">
                        </label>
                        <label class="eventScraperControl">
                            <span>Default category</span>
                            <select class="eventScraperDefaultCategory">
                                <option value="">— None —</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars((string) $cat['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                        <?php if ((string) $cat['id'] === $feed['defaultCategoryId']) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <div class="eventScraperFeedStatus" aria-live="polite">
                        <span class="eventScraperFeedStatusText">
                            <?php echo $feed['isConfigured'] ? 'Loading…' : 'Not configured yet — click "Refresh now" to fetch and start picking events.'; ?>
                        </span>
                        <button type="button" class="eventScraperRefreshBtn">Refresh now</button>
                    </div>

                    <div class="eventScraperFilter">
                        <label class="eventScraperControl">
                            <span>Filter</span>
                            <input type="search" class="eventScraperFilterText" placeholder="Search by name…">
                        </label>
                        <label class="eventScraperControl">
                            <span>Region</span>
                            <select class="eventScraperFilterRegion">
                                <option value="">All</option>
                            </select>
                        </label>
                    </div>

                    <div class="eventScraperList" aria-live="polite" aria-label="Available events">
                        <p class="eventScraperListEmpty">No events cached yet.</p>
                    </div>

                    <div class="eventScraperActions">
                        <span class="eventScraperSelectionCount" aria-live="polite">0 of 0 selected</span>
                        <span class="eventScraperDirtyIndicator" aria-live="polite" hidden>* unsaved changes</span>
                        <button type="button" class="eventScraperSaveBtn">Save feed</button>
                        <span class="eventScraperSaveStatus" aria-live="polite"></span>
                    </div>
                </fieldset>
            <?php endforeach; ?>
            <?php if (!$adapters): ?>
                <p class="paneHint">No adapters found in <code>admin/plugins/eventScraper/adapters/</code>. Drop a JSON adapter file there to add a feed.</p>
            <?php endif; ?>
        </div>

        <details class="eventScraperCronSection">
            <summary>Daily cron setup</summary>
            <p class="paneHint">One cron command refreshes every configured feed in sequence. Per-feed failures don't stop the others. The token is sent in a header so it stays out of webserver access logs.</p>
            <pre class="eventScraperCronLine" aria-label="Cron command"></pre>
            <div class="eventScraperCronActions">
                <button type="button" class="eventScraperCronCopyBtn"<?php if ($cronToken === '') echo ' disabled'; ?>>Copy command</button>
                <button type="button" class="eventScraperCronRevealBtn"<?php if ($cronToken === '') echo ' disabled'; ?>>Reveal token</button>
                <button type="button" class="eventScraperCronRotateBtn">Rotate token</button>
                <span class="eventScraperCronStatus" aria-live="polite"></span>
            </div>
        </details>
    </fieldset>
    <?php
}
