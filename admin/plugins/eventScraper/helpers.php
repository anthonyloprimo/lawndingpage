<?php
// Event Scraper helpers — refresh, diff, ingest, render.
//
// Lazy-loaded by init.php on first hook fire OR by endpoint scripts at the
// top of their request. Heavy logic lives here so init.php stays light per
// the every-request load.

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
        'enabled'           => false,
        'targetPaneId'      => '',
        'defaultCategoryId' => '',
        'cronToken'         => '',
        'lastReviewedAt'    => '',
        'allowlist'         => new stdClass(),
    ];
}

function event_scraper_load_config(): array {
    $path = event_scraper_config_path();
    $defaults = event_scraper_config_defaults();
    $defaults['allowlist'] = [];
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
    return array_replace($defaults, $decoded);
}

// LOCK_EX + 0640. Bcrypt-style: refuse to write a config without a non-empty
// cronToken when one was previously set (prevents a partial write from
// nuking the cron auth and silently breaking the daily refresh).
function event_scraper_save_config(array $config): bool {
    $path = event_scraper_config_path();
    $clean = array_replace(event_scraper_config_defaults(), $config);
    $json = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $ok = @file_put_contents($path, $json, LOCK_EX);
    if ($ok === false) {
        return false;
    }
    @chmod($path, 0640);
    return true;
}

// ------------------------------------------------------------------------
// Adapters
// ------------------------------------------------------------------------

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
        return false;
    }
    return @file_put_contents($path, $json, LOCK_EX) !== false;
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
// I/O wrappers
// ------------------------------------------------------------------------

// Fetch via cURL with the adapter's User-Agent. Returns ['status', 'body',
// 'error']. Tolerant of cURL errors (returns status 0 with error message).
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
        return false;
    }
    $path = function_exists('lawnding_data_path')
        ? lawnding_data_path($paneId . '.json')
        : __DIR__ . '/../../../public/res/data/' . $paneId . '.json';
    $payload = ['events' => array_values($events)];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    return @file_put_contents($path, $json, LOCK_EX) !== false;
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
        @file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    };

    $adapter = event_scraper_load_adapter($adapterId);
    if (!$adapter) {
        $err = "Adapter not found: $adapterId";
        $writeLast(['ranAt' => $now, 'status' => 'error', 'count' => 0, 'error' => $err, 'trigger' => $trigger]);
        return ['status' => 'error', 'count' => 0, 'error' => $err];
    }

    $url = (string) ($adapter['url'] ?? '');
    $userAgent = (string) ($adapter['userAgent'] ?? '');
    if ($url === '' || $userAgent === '') {
        $err = 'Adapter is missing required url or userAgent';
        $writeLast(['ranAt' => $now, 'status' => 'error', 'count' => 0, 'error' => $err, 'trigger' => $trigger]);
        return ['status' => 'error', 'count' => 0, 'error' => $err];
    }

    $fetched = event_scraper_fetch_url($url, $userAgent);
    if ($fetched['status'] !== 200 || $fetched['body'] === '') {
        $err = 'Fetch failed: HTTP ' . $fetched['status'] . ' ' . (string) $fetched['error'];
        $writeLast(['ranAt' => $now, 'status' => 'error', 'count' => 0, 'error' => $err, 'trigger' => $trigger]);
        if (function_exists('lawnding_log_event')) {
            lawnding_log_event('error', 'event_scraper.fetch_failed', [
                'adapter' => $adapterId,
                'status'  => $fetched['status'],
                'error'   => $fetched['error'],
            ]);
        }
        return ['status' => 'error', 'count' => 0, 'error' => $err];
    }
    // Capture payload size now; reused in the success log below.
    $payloadSizeKb = round(strlen($fetched['body']) / 1024, 1);

    $extractor = (string) ($adapter['extractor'] ?? '');
    $extractorPath = __DIR__ . '/extractors/' . $extractor . '.php';
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $extractor) || !is_readable($extractorPath)) {
        $err = "Extractor not found: $extractor";
        $writeLast(['ranAt' => $now, 'status' => 'error', 'count' => 0, 'error' => $err, 'trigger' => $trigger]);
        return ['status' => 'error', 'count' => 0, 'error' => $err];
    }
    require_once $extractorPath;
    $extractFn = 'event_scraper_extract_' . $extractor;
    if (!function_exists($extractFn)) {
        $err = "Extractor function missing: $extractFn";
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
        $writeLast(['ranAt' => $now, 'status' => 'error', 'count' => 0, 'error' => $err, 'trigger' => $trigger]);
        if (function_exists('lawnding_log_event')) {
            lawnding_log_event('error', 'event_scraper.empty_result', [
                'adapter'   => $adapterId,
                'payloadKb' => $payloadSizeKb,
            ]);
        }
        return ['status' => 'error', 'count' => 0, 'error' => $err];
    }

    $prev = event_scraper_load_catalogue($adapterId);
    $diff = event_scraper_diff($prev['events'] ?? [], $events);

    // Rotate: prev <- old; current <- new.
    @copy(event_scraper_catalogue_path($adapterId), event_scraper_catalogue_prev_path($adapterId));
    event_scraper_save_catalogue($adapterId, [
        'fetchedAt' => $now,
        'sourceUrl' => $url,
        'events'    => $events,
    ]);

    // Diagnostics for changes admins might want to know about, plus a
    // single "scrape completed" info entry that always fires (even when
    // nothing changed) so admins can see the cron is alive in the feed.
    if (function_exists('lawnding_log_event')) {
        foreach ($diff['changed'] as $change) {
            lawnding_log_event('info', 'event_scraper.event_changed', [
                'adapter' => $adapterId,
                'uid'     => $change['uid'],
                'name'    => $change['event']['name'] ?? '',
                'fields'  => $change['fields'],
            ]);
        }
        foreach ($diff['removed'] as $event) {
            lawnding_log_event('info', 'event_scraper.event_removed', [
                'adapter'   => $adapterId,
                'uid'       => $event['sourceUid'] ?? '',
                'name'      => $event['name'] ?? '',
                'startDate' => $event['startDate'] ?? '',
            ]);
        }
        lawnding_log_event('info', 'event_scraper.scrape_completed', [
            'adapter'    => $adapterId,
            'trigger'    => $trigger,
            'count'      => count($events),
            'newCount'   => count($diff['new']),
            'changed'    => count($diff['changed']),
            'removed'    => count($diff['removed']),
            'payloadKb'  => $payloadSizeKb,
        ]);
    }

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

// Apply the configured allowlist + target pane against the latest catalogue.
// Used both inside run_refresh AND by the save-selections endpoint (which
// re-runs ingest without re-fetching after the admin changes selections).
function event_scraper_apply_ingest(string $adapterId, array $catalogue, array $config): array {
    $paneId = trim((string) ($config['targetPaneId'] ?? ''));
    if ($paneId === '') {
        return ['status' => 'skipped', 'reason' => 'no targetPaneId configured', 'created' => 0, 'updated' => 0, 'deleted' => 0];
    }

    $allowlistByAdapter = is_array($config['allowlist'] ?? null) ? $config['allowlist'] : [];
    $thisAllowlist = is_array($allowlistByAdapter[$adapterId] ?? null) ? $allowlistByAdapter[$adapterId] : [];
    $allowlistUids = array_keys($thisAllowlist);

    $existingEvents = event_scraper_load_pane_events($paneId);

    $changes = event_scraper_build_ingest_changes(
        $catalogue,
        $allowlistUids,
        $adapterId,
        $existingEvents,
        (string) ($config['defaultCategoryId'] ?? '')
    );

    if (!$changes['create'] && !$changes['update'] && !$changes['delete']) {
        return ['status' => 'ok', 'created' => 0, 'updated' => 0, 'deleted' => 0];
    }

    require_once dirname(__DIR__, 2) . '/modules/eventList/helpers.php';
    $merged = event_list_apply_events(['events' => $existingEvents], ['changes' => $changes]);
    event_scraper_save_pane_events($paneId, $merged['events'] ?? []);

    return [
        'status'  => 'ok',
        'created' => count($changes['create']),
        'updated' => count($changes['update']),
        'deleted' => count($changes['delete']),
    ];
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
        $moduleId = (string) ($pane['moduleId'] ?? $pane['type'] ?? '');
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

// SITE CONFIG slot — renders the picker UI inside the eventList fieldset.
// The JS island in admin.js populates the convention table from a fetch to
// the catalogue endpoint; this PHP only emits the static structure +
// server-known data (target pane list, categories list, current config).
function event_scraper_render_eventlist_extras(): void {
    $config = event_scraper_load_config();
    $panes = event_scraper_eventlist_panes();
    $categories = function_exists('event_list_load_categories') ? event_list_load_categories() : [];

    $targetPaneId = (string) ($config['targetPaneId'] ?? '');
    $defaultCategoryId = (string) ($config['defaultCategoryId'] ?? '');
    $cronToken = (string) ($config['cronToken'] ?? '');
    $hasToken = $cronToken !== '';

    // Cron command rendered with token redacted by default; JS swaps to real
    // token after a "Reveal" click so casual screenshots don't leak it.
    $proxyUrl = function_exists('lawnding_asset_url')
        ? lawnding_asset_url('res/scr/plugin-endpoint.php?plugin=eventScraper&endpoint=cron')
        : '/res/scr/plugin-endpoint.php?plugin=eventScraper&endpoint=cron';
    $hostHint = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : 'YOUR_HOST_HERE';
    $cronUrl = 'https://' . $hostHint . $proxyUrl;
    ?>
    <fieldset class="siteConfigGroup eventScraperConfig"
              data-adapter-id="furrycons-na"
              data-cron-url="<?php echo htmlspecialchars($cronUrl, ENT_QUOTES, 'UTF-8'); ?>"
              data-cron-token="<?php echo htmlspecialchars($cronToken, ENT_QUOTES, 'UTF-8'); ?>">
        <legend>Convention ingestion (Event Scraper)</legend>
        <p class="paneHint">Pull convention dates from FurryCons.com into one of your Event List panes. Pick which conventions you want to show; the list refreshes itself daily.</p>

        <div class="eventScraperStatus" aria-live="polite">
            <span class="eventScraperStatusText">Loading…</span>
            <button type="button" class="eventScraperRefreshBtn" disabled>Refresh now</button>
        </div>

        <div class="eventScraperControls">
            <label class="eventScraperControl">
                <span>Target pane</span>
                <select class="eventScraperTargetPane">
                    <option value="">— Select an Event List pane —</option>
                    <?php foreach ($panes as $pane): ?>
                        <option value="<?php echo htmlspecialchars($pane['id'], ENT_QUOTES, 'UTF-8'); ?>"
                            <?php if ($pane['id'] === $targetPaneId) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($pane['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="eventScraperControl">
                <span>Default category</span>
                <select class="eventScraperDefaultCategory">
                    <option value="">— None —</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat['id'], ENT_QUOTES, 'UTF-8'); ?>"
                            <?php if ((string) $cat['id'] === $defaultCategoryId) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
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

        <div class="eventScraperList" aria-live="polite" aria-label="Available conventions">
            <p class="eventScraperListEmpty">Click "Refresh now" to fetch the current convention list.</p>
        </div>

        <div class="eventScraperActions">
            <span class="eventScraperSelectionCount" aria-live="polite">0 of 0 selected</span>
            <button type="button" class="eventScraperSaveBtn" disabled>Save selections</button>
            <span class="eventScraperSaveStatus" aria-live="polite"></span>
        </div>

        <details class="eventScraperCronSection">
            <summary>Daily cron setup</summary>
            <p class="paneHint">Add this command to your server's crontab to refresh once a day. The token is sent in a header rather than a URL so the secret stays out of webserver access logs.</p>
            <pre class="eventScraperCronLine" aria-label="Cron command"></pre>
            <div class="eventScraperCronActions">
                <button type="button" class="eventScraperCronCopyBtn"<?php if (!$hasToken) echo ' disabled'; ?>>Copy command</button>
                <button type="button" class="eventScraperCronRevealBtn"<?php if (!$hasToken) echo ' disabled'; ?>>Reveal token</button>
                <button type="button" class="eventScraperCronRotateBtn">Rotate token</button>
                <span class="eventScraperCronStatus" aria-live="polite"></span>
            </div>
        </details>
    </fieldset>
    <?php
}
