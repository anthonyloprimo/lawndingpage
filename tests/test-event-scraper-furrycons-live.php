<?php
// Regression guard against furrycons.com changing their JSON-LD shape.
//
// Caches a snapshot of the live page at fixtures/event-scraper-furrycons-live.html.
// The cache refreshes itself when older than 24 hours. Offline-degradation
// ladder:
//
//   fresh cache (< 24h)   -> use it, no network call
//   stale cache + online  -> fetch, update cache, use new
//   stale cache + offline -> warn, use stale (still better than nothing)
//   no cache + online     -> fetch, use new
//   no cache + offline    -> soft skip with reason
//
// Assertions are intentionally loose (count > 0 + structural spot-check)
// so the suite doesn't churn every time furrycons adds a new convention.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../admin/plugins/eventScraper/extractors/jsonld.php';

test_require_extension('curl');

$cachePath     = __DIR__ . '/fixtures/event-scraper-furrycons-live.html';
$maxAgeSeconds = 86400;
$sourceUrl     = 'https://furrycons.com/calendar/calendar.php?loc=na';
$userAgent     = 'Mozilla/5.0 (compatible; LawndingPage eventScraper test; +https://lifurs.org/)';

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
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status === 200 && is_string($body) && strlen($body) > 1000) {
        @file_put_contents($cachePath, $body);
    } elseif (!$cacheExists) {
        test_skip("Could not fetch furrycons.com (HTTP $status) and no cached fixture exists yet");
    } else {
        // Stale cache + offline. Run against the stale copy and warn so the
        // developer knows the live regression check didn't actually run today.
        fwrite(STDERR, "    NOTE: furrycons.com fetch failed (HTTP $status); using stale cache (" .
            round((time() - filemtime($cachePath)) / 3600, 1) . "h old)\n");
    }
}

$html = file_get_contents($cachePath);
test_assert(is_string($html) && strlen($html) > 1000, 'live fixture HTML is loadable');

$adapter = ['uidFromUrlPattern' => '#/event/(\d+)/#'];
$events  = event_scraper_extract_jsonld($html ?: '', $adapter);

test_assert(count($events) > 0, 'live page produces at least one event (regression guard for JSON-LD shape changes)');
test_assert(count($events) >= 10, 'live page produces a reasonable number of events');

// Shape spot-check on the first event — catches changes like a renamed
// schema field or a switch from JSON-LD to microdata-only without churning
// on simple data changes.
if (count($events) > 0) {
    $first = $events[0];
    test_assert(isset($first['sourceUid']) && $first['sourceUid'] !== '', 'first event has non-empty sourceUid');
    test_assert(isset($first['name']) && $first['name'] !== '', 'first event has non-empty name');
    test_assert(
        isset($first['startDate']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $first['startDate']) === 1,
        'first event has ISO startDate (YYYY-MM-DD)'
    );
    // location is allowed to be empty (fallback to "Location TBA" happens
    // downstream in the eventList mapper, not the extractor).
}
