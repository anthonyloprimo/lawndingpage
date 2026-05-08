<?php
// Regression guard against events.nyfurs.org changing their Indico response shape.
//
// Caches a snapshot of the live category-export JSON at
// fixtures/event-scraper-nyfurs-live.json. The cache refreshes itself when
// older than 24 hours. Offline-degradation ladder mirrors the furrycons-live
// test:
//
//   fresh cache (< 24h)   -> use it, no network call
//   stale cache + online  -> fetch, update cache, use new
//   stale cache + offline -> warn, use stale (still better than nothing)
//   no cache + online     -> fetch, use new
//   no cache + offline    -> soft skip with reason
//
// Assertions are intentionally loose (count > 0 + structural spot-check) so
// the suite doesn't churn whenever NY Furs adds a new meet.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../admin/plugins/eventScraper/extractors/indico.php';

test_require_extension('curl');

$cachePath     = __DIR__ . '/fixtures/event-scraper-nyfurs-live.json';
$maxAgeSeconds = 86400;
$sourceUrl     = 'https://events.nyfurs.org/export/categ/0.json';
// Match the production scraper's request shape. nyfurs.org doesn't WAF
// today, but mirroring production future-proofs against upstream policy
// tightening (see furrycons-live for the cautionary tale).
$userAgent     = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

$cacheExists = is_file($cachePath);
$cacheStale  = !$cacheExists || (time() - filemtime($cachePath)) > $maxAgeSeconds;

if ($cacheStale) {
    $ch = curl_init($sourceUrl);
    curl_setopt_array($ch, [
        CURLOPT_USERAGENT      => $userAgent,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
        ],
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status === 200 && is_string($body) && strlen($body) > 100) {
        @file_put_contents($cachePath, $body);
    } elseif (!$cacheExists) {
        test_skip("Could not fetch events.nyfurs.org (HTTP $status) and no cached fixture exists yet");
    } else {
        fwrite(STDERR, "    NOTE: events.nyfurs.org fetch failed (HTTP $status); using stale cache (" .
            round((time() - filemtime($cachePath)) / 3600, 1) . "h old)\n");
    }
}

$body = file_get_contents($cachePath);
test_assert(is_string($body) && strlen($body) > 100, 'live fixture JSON is loadable');

$adapter = ['id' => 'nyfurs'];
$events  = event_scraper_extract_indico($body ?: '', $adapter);

test_assert(count($events) > 0, 'live page produces at least one event (regression guard for Indico shape changes)');
test_assert(count($events) >= 5, 'live page produces a reasonable number of events');

// Shape spot-check on the first event — catches changes like Indico
// renaming startDate to a different shape or dropping the results envelope.
if (count($events) > 0) {
    $first = $events[0];
    test_assert(isset($first['sourceUid']) && $first['sourceUid'] !== '', 'first event has non-empty sourceUid');
    test_assert(isset($first['name']) && $first['name'] !== '', 'first event has non-empty name');
    test_assert(
        isset($first['startDate']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $first['startDate']) === 1,
        'first event has ISO startDate (YYYY-MM-DD)'
    );
    test_assert(isset($first['url']) && strpos((string) $first['url'], 'events.nyfurs.org') !== false, 'first event url points back at NY Furs');
    // location, description, image are all allowed to be empty per-event.
}
