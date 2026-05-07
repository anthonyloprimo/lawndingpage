<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../admin/plugins/eventScraper/extractors/jsonld.php';

$adapter = [
    'id' => 'furrycons-na',
    'uidFromUrlPattern' => '#/event/(\d+)/#',
];

// ---- Empty / malformed inputs ----

test_assert(
    event_scraper_extract_jsonld('', $adapter) === [],
    'empty HTML returns empty array'
);

test_assert(
    event_scraper_extract_jsonld('<html><body>no jsonld here</body></html>', $adapter) === [],
    'HTML without JSON-LD blocks returns empty array'
);

$malformed = '<script type="application/ld+json">{not valid json</script>';
test_assert(
    event_scraper_extract_jsonld($malformed, $adapter) === [],
    'malformed JSON in a block is skipped silently'
);

// ---- Block detection ----

$singleBlock = '<script type="application/ld+json">' . json_encode([
    '@context' => 'http://schema.org',
    '@type'    => 'Event',
    'name'     => 'Furry Weekend Atlanta 2026',
    'startDate'=> '2026-05-07',
    'endDate'  => '2026-05-10',
    'url'      => 'http://furrycons.com/event/26609/furry-weekend-atlanta-2026',
    'location' => [
        '@type' => 'Place',
        'name'  => 'Atlanta Marriott Marquis',
        'address' => [
            '@type'           => 'PostalAddress',
            'addressLocality' => 'Atlanta',
            'addressRegion'   => 'GA',
        ],
    ],
    'offers' => ['@type' => 'Offer', 'url' => 'https://furryweekend.com/register/'],
    'image'  => 'https://media.animecons.com/x.png',
    'description' => 'A convention.',
]) . '</script>';

$result = event_scraper_extract_jsonld($singleBlock, $adapter);
test_assert(count($result) === 1, 'single Event block produces one record');
test_assert($result[0]['sourceUid'] === '26609', 'sourceUid extracted from event url');
test_assert($result[0]['name'] === 'Furry Weekend Atlanta 2026', 'name preserved');
test_assert($result[0]['startDate'] === '2026-05-07', 'startDate preserved');
test_assert($result[0]['endDate'] === '2026-05-10', 'endDate preserved');
test_assert($result[0]['location'] === 'Atlanta Marriott Marquis, Atlanta, GA', 'location formatted as venue, city, region');
test_assert($result[0]['registrationUrl'] === 'https://furryweekend.com/register/', 'registrationUrl from offers.url');
test_assert($result[0]['image'] === 'https://media.animecons.com/x.png', 'image string preserved');
test_assert($result[0]['description'] === 'A convention.', 'description preserved');

// ---- Top-level array of events (furrycons.com's actual shape) ----

$arrayBlock = '<script type="application/ld+json">[' . json_encode([
    '@type' => 'Event', 'name' => 'A', 'startDate' => '2026-06-01',
    'url' => 'http://furrycons.com/event/100/a',
]) . ',' . json_encode([
    '@type' => 'Event', 'name' => 'B', 'startDate' => '2026-06-02',
    'url' => 'http://furrycons.com/event/200/b',
]) . ']</script>';

$result = event_scraper_extract_jsonld($arrayBlock, $adapter);
test_assert(count($result) === 2, 'top-level JSON array produces all entries');
test_assert($result[0]['sourceUid'] === '100' && $result[1]['sourceUid'] === '200', 'array order preserved');

// ---- Multiple blocks on the same page ----

$multiBlock = '<script type="application/ld+json">' . json_encode([
    '@context' => 'http://schema.org',
    '@type'    => 'WebSite',
    'name'     => 'FurryCons',
]) . '</script>'
. '<script type="application/ld+json">' . json_encode([
    '@type' => 'Event', 'name' => 'Real Event', 'startDate' => '2026-07-01',
    'url' => 'http://furrycons.com/event/300/real',
]) . '</script>';

$result = event_scraper_extract_jsonld($multiBlock, $adapter);
test_assert(count($result) === 1, 'non-Event blocks ignored, Event blocks kept');
test_assert($result[0]['name'] === 'Real Event', 'event from second block found');

// ---- Filtering: missing fields, wrong @type, no UID match ----

$missingName = '<script type="application/ld+json">' . json_encode([
    '@type' => 'Event', 'startDate' => '2026-06-01',
    'url' => 'http://furrycons.com/event/400/x',
]) . '</script>';
test_assert(event_scraper_extract_jsonld($missingName, $adapter) === [], 'event without name skipped');

$missingStart = '<script type="application/ld+json">' . json_encode([
    '@type' => 'Event', 'name' => 'X',
    'url' => 'http://furrycons.com/event/400/x',
]) . '</script>';
test_assert(event_scraper_extract_jsonld($missingStart, $adapter) === [], 'event without startDate skipped');

$missingUrl = '<script type="application/ld+json">' . json_encode([
    '@type' => 'Event', 'name' => 'X', 'startDate' => '2026-06-01',
]) . '</script>';
test_assert(event_scraper_extract_jsonld($missingUrl, $adapter) === [], 'event without url skipped');

$noUidMatch = '<script type="application/ld+json">' . json_encode([
    '@type' => 'Event', 'name' => 'X', 'startDate' => '2026-06-01',
    'url' => 'https://example.com/no-pattern-match',
]) . '</script>';
test_assert(event_scraper_extract_jsonld($noUidMatch, $adapter) === [], 'event whose url does not match adapter UID pattern skipped');

$wrongType = '<script type="application/ld+json">' . json_encode([
    '@type' => 'MusicEvent', 'name' => 'X', 'startDate' => '2026-06-01',
    'url' => 'http://furrycons.com/event/500/x',
]) . '</script>';
test_assert(event_scraper_extract_jsonld($wrongType, $adapter) === [], 'subtypes like MusicEvent are NOT auto-included (strict @type === Event)');

// ---- Optional / absent fields ----

$minimal = '<script type="application/ld+json">' . json_encode([
    '@type' => 'Event', 'name' => 'M', 'startDate' => '2026-06-01',
    'url' => 'http://furrycons.com/event/600/m',
]) . '</script>';
$result = event_scraper_extract_jsonld($minimal, $adapter);
test_assert(count($result) === 1, 'minimal valid event accepted');
test_assert($result[0]['endDate'] === '', 'missing endDate normalizes to empty string');
test_assert($result[0]['description'] === '', 'missing description normalizes to empty string');
test_assert($result[0]['location'] === '', 'missing location normalizes to empty string');
test_assert($result[0]['registrationUrl'] === '', 'missing offers normalizes to empty string');
test_assert($result[0]['image'] === '', 'missing image normalizes to empty string');

// ---- Location shape variations ----

test_assert(
    event_scraper_format_location('Just A Venue Name') === 'Just A Venue Name',
    'string location returned as-is'
);
test_assert(
    event_scraper_format_location(['name' => 'Hotel']) === 'Hotel',
    'Place with only name returns just name'
);
test_assert(
    event_scraper_format_location(['name' => 'Hotel', 'address' => ['addressLocality' => 'NY']]) === 'Hotel, NY',
    'Place with city only'
);
test_assert(
    event_scraper_format_location(['address' => ['addressLocality' => 'NY', 'addressRegion' => 'NY']]) === 'NY, NY',
    'address-only Place returns city + region'
);
test_assert(event_scraper_format_location(null) === '', 'null location is empty string');
test_assert(event_scraper_format_location(123) === '', 'non-string non-array location is empty string');

// ---- Offers shape variations ----

test_assert(
    event_scraper_pick_offer_url(['url' => 'https://x/']) === 'https://x/',
    'single Offer object with url'
);
test_assert(
    event_scraper_pick_offer_url([
        ['url' => ''],
        ['url' => 'https://second/'],
    ]) === 'https://second/',
    'array of Offers — first non-empty url wins'
);
test_assert(event_scraper_pick_offer_url(null) === '', 'null offers is empty string');
test_assert(event_scraper_pick_offer_url('not a thing') === '', 'string offers is empty string');

// ---- Image shape variations ----

test_assert(event_scraper_pick_first_string('https://a/') === 'https://a/', 'string image returned');
test_assert(
    event_scraper_pick_first_string(['https://a/', 'https://b/']) === 'https://a/',
    'array of strings — first wins'
);
test_assert(
    event_scraper_pick_first_string(['url' => 'https://obj/']) === 'https://obj/',
    'ImageObject with url'
);
test_assert(event_scraper_pick_first_string(null) === '', 'null image is empty string');

// ---- JSON sanitizer (handles real-world non-RFC-compliant JSON-LD) ----

test_assert(
    event_scraper_sanitize_jsonld('{"a":"b"}') === '{"a":"b"}',
    'sanitizer is a no-op on valid JSON'
);

// Literal newline inside a string value (the furrycons.com bug shape).
$dirty = "{\"description\":\"Line 1\nLine 2\"}";
$clean = event_scraper_sanitize_jsonld($dirty);
test_assert(
    json_decode($clean, true) === ['description' => "Line 1\nLine 2"],
    'literal newline inside string becomes valid JSON'
);

// Whitespace BETWEEN tokens is preserved (it is RFC-legal).
$pretty = "{\n\t\"a\": \"b\"\n}";
test_assert(
    event_scraper_sanitize_jsonld($pretty) === $pretty,
    'whitespace between tokens left untouched'
);

// Literal tab inside a string value.
$tab = "{\"a\":\"x\ty\"}";
test_assert(
    json_decode(event_scraper_sanitize_jsonld($tab), true) === ['a' => "x\ty"],
    'literal tab inside string becomes valid JSON'
);

// Already-escaped sequences are not double-escaped.
$alreadyEscaped = '{"a":"line1\\nline2"}';
test_assert(
    event_scraper_sanitize_jsonld($alreadyEscaped) === $alreadyEscaped,
    'already-escaped \\n left alone (idempotent)'
);

// Vertical tab (0x0B) — escaped via \u sequence, not a JSON shortcut.
$vt = "{\"a\":\"x" . chr(0x0B) . "y\"}";
test_assert(
    json_decode(event_scraper_sanitize_jsonld($vt), true) === ['a' => "x\x0by"],
    'rare control chars escaped via \\uXXXX'
);

// Real furrycons.com block survives the sanitizer + decode round-trip.
$dirtyBlock = '<script type="application/ld+json">[{"@type":"Event","name":"Real Con","startDate":"2026-06-01","url":"http://furrycons.com/event/9999/real-con","description":"Line one' . "\n" . 'Line two"}]</script>';
$result = event_scraper_extract_jsonld($dirtyBlock, $adapter);
test_assert(count($result) === 1, 'event with raw-newline description survives sanitization');
test_assert($result[0]['description'] === "Line one\nLine two", 'description preserves the original newline as a real \\n');

// ---- Realistic full-page sample (mirrors furrycons.com shape) ----

$realistic = file_get_contents(__DIR__ . '/fixtures/event-scraper-furrycons-sample.html');
test_assert(is_string($realistic), 'fixture HTML loads');
$result = event_scraper_extract_jsonld($realistic ?: '', $adapter);
test_assert(count($result) === 3, 'fixture extracts the 3 events it contains');
$names = array_column($result, 'name');
test_assert(in_array('Furry Weekend Atlanta 2026', $names, true), 'FWA in result');
test_assert(in_array('AnthrOhio 2026', $names, true), 'AnthrOhio in result');
test_assert(in_array('Furlandia 2026', $names, true), 'Furlandia in result');
