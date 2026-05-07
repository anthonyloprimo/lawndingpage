<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../admin/plugins/eventScraper/helpers.php';

// ------------------------------------------------------------------------
// event_scraper_event_field_diff
// ------------------------------------------------------------------------

test_assert(
    event_scraper_event_field_diff(['name' => 'A'], ['name' => 'A']) === [],
    'identical events have empty field diff'
);

$prev = ['name' => 'A', 'startDate' => '2026-06-01', 'endDate' => '2026-06-03', 'location' => 'X'];
$next = ['name' => 'A', 'startDate' => '2026-06-02', 'endDate' => '2026-06-04', 'location' => 'X'];
test_assert(
    event_scraper_event_field_diff($prev, $next) === ['startDate', 'endDate'],
    'reports each changed field by name, in declaration order'
);

// Missing-vs-empty parity: '' on one side, key absent on the other -> equal.
test_assert(
    event_scraper_event_field_diff(['name' => 'A'], ['name' => 'A', 'endDate' => '']) === [],
    'absent vs empty-string treated as equal'
);

// Watched-fields list intentionally excludes things like sourceUid; renaming
// a UID-derived field shouldn't fire CHANGED.
$prevWithUid = ['name' => 'A', 'sourceUid' => '100'];
$nextWithUid = ['name' => 'A', 'sourceUid' => '200'];
test_assert(
    event_scraper_event_field_diff($prevWithUid, $nextWithUid) === [],
    'sourceUid not in watched fields (changes there are out of band)'
);

// ------------------------------------------------------------------------
// event_scraper_diff
// ------------------------------------------------------------------------

$prevList = [
    ['sourceUid' => '1', 'name' => 'A', 'startDate' => '2026-06-01'],
    ['sourceUid' => '2', 'name' => 'B', 'startDate' => '2026-07-01'],
    ['sourceUid' => '3', 'name' => 'C', 'startDate' => '2026-08-01'],
];

$nextList = [
    ['sourceUid' => '1', 'name' => 'A',         'startDate' => '2026-06-01'], // identical
    ['sourceUid' => '2', 'name' => 'B Renamed', 'startDate' => '2026-07-01'], // changed
    // sourceUid 3 removed
    ['sourceUid' => '4', 'name' => 'D',         'startDate' => '2026-09-01'], // new
];

$diff = event_scraper_diff($prevList, $nextList);
test_assert(count($diff['new']) === 1, 'one new event');
test_assert($diff['new'][0]['sourceUid'] === '4', 'correct new event');
test_assert(count($diff['changed']) === 1, 'one changed event');
test_assert($diff['changed'][0]['uid'] === '2', 'changed event uid');
test_assert($diff['changed'][0]['fields'] === ['name'], 'changed event reports specific fields');
test_assert(count($diff['removed']) === 1, 'one removed event');
test_assert($diff['removed'][0]['sourceUid'] === '3', 'correct removed event');

// Empty-input edges.
test_assert(event_scraper_diff([], []) === ['new' => [], 'changed' => [], 'removed' => []], 'empty diff is empty');

$diff = event_scraper_diff([], $prevList);
test_assert(count($diff['new']) === 3 && !$diff['changed'] && !$diff['removed'], 'first scrape: all-new');

$diff = event_scraper_diff($prevList, []);
test_assert(!$diff['new'] && !$diff['changed'] && count($diff['removed']) === 3, 'source went empty: all-removed');

// Non-arrays in input are ignored, not exploded.
$diff = event_scraper_diff([null, 'string', ['sourceUid' => '5', 'name' => 'E']], [['sourceUid' => '5', 'name' => 'E']]);
test_assert(!$diff['new'] && !$diff['changed'] && !$diff['removed'], 'malformed entries silently skipped');

// ------------------------------------------------------------------------
// event_scraper_to_eventlist_record
// ------------------------------------------------------------------------

$normalized = [
    'sourceUid'       => '26609',
    'name'            => 'Furry Weekend Atlanta 2026',
    'startDate'       => '2026-05-07',
    'endDate'         => '2026-05-10',
    'location'        => 'Atlanta Marriott Marquis, Atlanta, GA',
    'description'     => 'A convention.',
    'url'             => 'http://furrycons.com/event/26609/x',
    'registrationUrl' => 'https://x.com/r',
    'image'           => 'https://x.com/i.png',
];
$record = event_scraper_to_eventlist_record($normalized, 'furrycons-na', '7');
test_assert($record['name'] === 'Furry Weekend Atlanta 2026', 'name preserved');
test_assert($record['startDate'] === '2026-05-07', 'startDate preserved');
test_assert($record['endDate'] === '2026-05-10', 'endDate preserved');
test_assert($record['allDay'] === true, 'date-only normalized event maps to all-day');
test_assert($record['startTime'] === '' && $record['endTime'] === '', 'no times for all-day');
test_assert($record['address'] === 'Atlanta Marriott Marquis, Atlanta, GA', 'address from location');
test_assert(strpos($record['description'], 'A convention.') === 0, 'description starts with original');
test_assert(strpos($record['description'], 'More info: http://furrycons.com/event/26609/x') !== false, 'url appended to description');
test_assert(strpos($record['description'], 'Register: https://x.com/r') !== false, 'registrationUrl appended');
test_assert($record['categoryId'] === '7', 'defaultCategoryId applied');
test_assert($record['source'] === 'eventScraper', 'source tag set');
test_assert($record['sourceAdapter'] === 'furrycons-na', 'sourceAdapter tag set');
test_assert($record['sourceUid'] === '26609', 'sourceUid preserved');

require_once __DIR__ . '/../admin/modules/eventList/helpers.php';

// When the extractor sets startTime, the mapper honors it (not all-day).
$timed = [
    'sourceUid' => '89',
    'name'      => 'NYFurs TF2 Night',
    'startDate' => '2026-03-30',
    'endDate'   => '2026-03-30',
    'startTime' => '19:30',
    'endTime'   => '23:59',
    'location'  => 'Discord Server',
    'url'       => 'https://events.nyfurs.org/event/89/',
];
$timedRecord = event_scraper_to_eventlist_record($timed, 'nyfurs', '7');
test_assert($timedRecord['allDay'] === false, 'startTime present -> mapper sets allDay=false');
test_assert($timedRecord['startTime'] === '19:30', 'startTime passed through');
test_assert($timedRecord['endTime'] === '23:59', 'endTime passed through');
test_assert(event_list_event_is_valid($timedRecord), 'timed scraped record passes eventList validator');

// Defensive: endTime without startTime is normalized to all-day, not a half-timed event.
$halfTimed = ['sourceUid' => '90', 'name' => 'X', 'startDate' => '2026-04-01', 'endTime' => '14:00'];
$halfRecord = event_scraper_to_eventlist_record($halfTimed, 'nyfurs', '');
test_assert($halfRecord['allDay'] === true, 'endTime alone (no startTime) normalizes to all-day');
test_assert($halfRecord['endTime'] === '', 'endTime cleared when startTime is empty');

// Empty-fields fall back so the eventList validator doesn't silently drop the record.
$bareMinimum = ['sourceUid' => '99', 'name' => 'E', 'startDate' => '2026-06-01'];
$record = event_scraper_to_eventlist_record($bareMinimum, 'furrycons-na', '');
test_assert($record['address'] === 'Location TBA', 'empty location falls back to placeholder');
test_assert($record['description'] === 'E', 'empty description falls back to event name');
test_assert($record['endDate'] === '', 'absent endDate normalizes to empty string');

test_assert(event_list_event_is_valid($record), 'scraped record passes eventList validator');
$rich = event_scraper_to_eventlist_record($normalized, 'furrycons-na', '7');
test_assert(event_list_event_is_valid($rich), 'rich scraped record also passes validator');

// ------------------------------------------------------------------------
// event_scraper_build_ingest_changes
// ------------------------------------------------------------------------

$catalogue = [
    ['sourceUid' => '1', 'name' => 'A', 'startDate' => '2026-06-01', 'location' => 'X'],
    ['sourceUid' => '2', 'name' => 'B', 'startDate' => '2026-07-01', 'location' => 'Y'],
    ['sourceUid' => '3', 'name' => 'C', 'startDate' => '2026-08-01', 'location' => 'Z'],
];

// Empty everything -> no changes.
$changes = event_scraper_build_ingest_changes([], [], 'furrycons-na', [], '');
test_assert(!$changes['create'] && !$changes['update'] && !$changes['delete'], 'empty inputs produce empty changes');

// Allowlist matches catalogue, no existing events -> all creates.
$changes = event_scraper_build_ingest_changes($catalogue, ['1', '2'], 'furrycons-na', [], '7');
test_assert(count($changes['create']) === 2, 'two creates for allowlist of 2 with no existing');
test_assert(!$changes['update'] && !$changes['delete'], 'no updates or deletes on first ingest');
$createdUids = array_column($changes['create'], 'sourceUid');
test_assert(in_array('1', $createdUids) && in_array('2', $createdUids), 'creates carry correct sourceUids');

// Existing event matches allowlist + catalogue -> update (id preserved).
$existing = [[
    'id' => '42', 'name' => 'A old', 'startDate' => '2026-06-01',
    'source' => 'eventScraper', 'sourceAdapter' => 'furrycons-na', 'sourceUid' => '1',
]];
$changes = event_scraper_build_ingest_changes($catalogue, ['1'], 'furrycons-na', $existing, '7');
test_assert(count($changes['create']) === 0 && count($changes['update']) === 1, 'existing scraped match becomes update');
test_assert($changes['update'][0]['id'] === '42', 'update preserves existing event id');
test_assert($changes['update'][0]['name'] === 'A', 'update carries fresh fields');

// Existing event NOT in allowlist -> delete.
$existing = [[
    'id' => '50', 'name' => 'B', 'startDate' => '2026-07-01',
    'source' => 'eventScraper', 'sourceAdapter' => 'furrycons-na', 'sourceUid' => '2',
]];
$changes = event_scraper_build_ingest_changes($catalogue, [], 'furrycons-na', $existing, '7');
test_assert($changes['delete'] === ['50'], 'unticking a con queues a delete');

// Source-removed: existing scraped event whose UID is no longer in catalogue -> delete.
$existing = [[
    'id' => '60', 'name' => 'D (gone)', 'startDate' => '2026-09-01',
    'source' => 'eventScraper', 'sourceAdapter' => 'furrycons-na', 'sourceUid' => '999',
]];
$changes = event_scraper_build_ingest_changes($catalogue, ['999'], 'furrycons-na', $existing, '7');
test_assert($changes['delete'] === ['60'], 'auto-delete when source drops a previously-ingested event');

// Manual events are NEVER touched.
$existing = [
    ['id' => '70', 'name' => 'Manual 1', 'startDate' => '2026-06-15'],
    ['id' => '71', 'name' => 'Manual 2', 'startDate' => '2026-06-16', 'source' => ''],
    ['id' => '72', 'name' => 'Manual 3', 'startDate' => '2026-06-17', 'source' => 'somethingElse'],
];
$changes = event_scraper_build_ingest_changes($catalogue, [], 'furrycons-na', $existing, '7');
test_assert(!$changes['delete'], 'manual events with no source/wrong source are never deleted');

// Cross-adapter isolation: events from a different adapter aren't touched
// even if their sourceUid happens to collide.
$existing = [[
    'id' => '80', 'name' => 'Other source', 'startDate' => '2026-06-01',
    'source' => 'eventScraper', 'sourceAdapter' => 'someOtherAdapter', 'sourceUid' => '1',
]];
$changes = event_scraper_build_ingest_changes($catalogue, ['1'], 'furrycons-na', $existing, '7');
test_assert(count($changes['create']) === 1, 'cross-adapter UID collision still creates a fresh furrycons record');
test_assert(!$changes['delete'], 'other adapter\'s events untouched');

// Mixed scenario: 1 update, 1 delete (unticked), 1 delete (source-removed),
// 1 create (newly-allowlisted), 1 manual untouched.
$existing = [
    ['id' => 'M', 'name' => 'Manual', 'startDate' => '2026-06-15'],
    ['id' => 'a', 'name' => 'A old', 'startDate' => '2026-06-01',
     'source' => 'eventScraper', 'sourceAdapter' => 'furrycons-na', 'sourceUid' => '1'],
    ['id' => 'b', 'name' => 'B old', 'startDate' => '2026-07-01',
     'source' => 'eventScraper', 'sourceAdapter' => 'furrycons-na', 'sourceUid' => '2'],
    ['id' => 'd', 'name' => 'D gone', 'startDate' => '2026-09-01',
     'source' => 'eventScraper', 'sourceAdapter' => 'furrycons-na', 'sourceUid' => '999'],
];
$changes = event_scraper_build_ingest_changes($catalogue, ['1', '3'], 'furrycons-na', $existing, '7');
test_assert(count($changes['create']) === 1, 'mixed: one create');
test_assert(count($changes['update']) === 1, 'mixed: one update');
test_assert(count($changes['delete']) === 2, 'mixed: two deletes (unticked + source-removed)');
test_assert(in_array('b', $changes['delete']) && in_array('d', $changes['delete']), 'correct ids targeted for deletion');
test_assert(!in_array('M', $changes['delete']), 'manual event stays put in mixed scenario');

// ------------------------------------------------------------------------
// event_scraper_load_catalogue / event_scraper_load_adapter
// ------------------------------------------------------------------------

test_assert(event_scraper_load_adapter('furrycons-na') !== null, 'real adapter loads');
test_assert(event_scraper_load_adapter('../../../etc/passwd') === null, 'path-traversal adapter id rejected');
test_assert(event_scraper_load_adapter('does-not-exist') === null, 'missing adapter returns null');
test_assert(event_scraper_load_adapter('') === null, 'empty adapter id rejected');

// ------------------------------------------------------------------------
// event_scraper_pick_user_agent
// ------------------------------------------------------------------------

test_assert(
    event_scraper_pick_user_agent(['userAgent' => 'Mozilla/5.0 fixed']) === 'Mozilla/5.0 fixed',
    'fixed string UA returned as-is'
);

test_assert(
    event_scraper_pick_user_agent([]) === '',
    'missing userAgent returns empty'
);

$pool = ['UA-A', 'UA-B', 'UA-C'];
$seen = [];
for ($i = 0; $i < 50; $i++) {
    $picked = event_scraper_pick_user_agent(['userAgent' => $pool]);
    test_assert(in_array($picked, $pool, true), 'array UA picks from pool');
    $seen[$picked] = true;
}
test_assert(count($seen) >= 2, 'array UA actually rotates (probability of single value over 50 picks ~ 3 * (1/3)^50 ~ 0)');

$rotateSeen = [];
for ($i = 0; $i < 50; $i++) {
    $picked = event_scraper_pick_user_agent(['userAgent' => '@rotate']);
    test_assert(strpos($picked, 'Mozilla/5.0') === 0, '@rotate produces a real-browser-shaped UA');
    $rotateSeen[$picked] = true;
}
test_assert(count($rotateSeen) >= 2, '@rotate rotates across the built-in pool');

// Defensive: array-of-non-strings is filtered out.
test_assert(
    event_scraper_pick_user_agent(['userAgent' => [123, null, 'real-ua']]) === 'real-ua',
    'non-string entries in array pool are filtered'
);
test_assert(
    event_scraper_pick_user_agent(['userAgent' => []]) === '',
    'empty array returns empty (caller bails on missing UA)'
);
