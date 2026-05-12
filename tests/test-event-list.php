<?php
require_once __DIR__ . '/bootstrap.php';
require_once lawnding_admin_path('modules/eventList/helpers.php');

// ---- generate_id: sequential int allocation ----

test_assert(event_list_generate_id([]) === 1,
    'generate_id: empty list -> 1');
test_assert(event_list_generate_id([['id' => '5', 'name' => 'a']]) === 6,
    'generate_id: single record with id "5" -> 6');
test_assert(event_list_generate_id([
        ['id' => '2'], ['id' => '7'], ['id' => '4']
    ]) === 8,
    'generate_id: max id wins regardless of position');
test_assert(event_list_generate_id([['id' => 12]]) === 13,
    'generate_id: int id (not string) handled');
test_assert(event_list_generate_id([
        ['id' => 'summer-fursuit-walk-2026-06-15-1400']
    ]) === 1,
    'generate_id: legacy content-derived ids cast to 0; sequential starts fresh');
test_assert(event_list_generate_id([
        ['id' => 'summer-walk-2026'], ['id' => '3']
    ]) === 4,
    'generate_id: mixed legacy + sequential — only numeric contributes to max');
test_assert(event_list_generate_id([['name' => 'no id field']]) === 1,
    'generate_id: missing id field treated as 0');
test_assert(event_list_generate_id(['not-an-array', ['id' => '3']]) === 4,
    'generate_id: non-array entries skipped defensively');

// ---- event_is_valid: required fields, paired end date/time, end >= start ----

$validEvent = [
    'name' => 'Walk',
    'startDate' => '2026-06-15',
    'startTime' => '14:00',
    'address' => '123 Main St',
    'description' => 'desc',
];
test_assert(event_list_event_is_valid($validEvent) === true,
    'event_is_valid: minimum required fields -> true');

foreach (['name', 'startDate', 'startTime', 'address', 'description'] as $field) {
    $missing = $validEvent;
    unset($missing[$field]);
    test_assert(event_list_event_is_valid($missing) === false,
        "event_is_valid: missing $field -> false");

    $blank = $validEvent;
    $blank[$field] = '   ';
    test_assert(event_list_event_is_valid($blank) === false,
        "event_is_valid: whitespace-only $field -> false");
}

test_assert(event_list_event_is_valid(array_merge($validEvent, [
        'endDate' => '2026-06-15', 'endTime' => '16:00'
    ])) === true,
    'event_is_valid: end >= start -> true');
test_assert(event_list_event_is_valid(array_merge($validEvent, [
        'endDate' => '2026-06-15', 'endTime' => '13:00'
    ])) === false,
    'event_is_valid: end < start -> false');
test_assert(event_list_event_is_valid(array_merge($validEvent, [
        'endDate' => '2026-06-15', 'endTime' => ''
    ])) === false,
    'event_is_valid: endDate without endTime -> false');
// Single-day events with a time range: endDate normalized to '' on save,
// endTime carries the end-of-day. Was rejected pre-multi-day-toggle removal;
// now accepted since the toggle no longer gates end-date visibility.
test_assert(event_list_event_is_valid(array_merge($validEvent, [
        'endDate' => '', 'endTime' => '16:00'
    ])) === true,
    'event_is_valid: endTime without endDate (single-day timed) -> true');
test_assert(event_list_event_is_valid(array_merge($validEvent, [
        'description' => '[sfw]unclosed'
    ])) === false,
    'event_is_valid: invalid markdown gating syntax -> false');

// ---- apply_events: pure-transform additive merge ----

$baseExisting = [
    'events' => [
        array_merge($validEvent, ['id' => '5', 'name' => 'Existing']),
        array_merge($validEvent, ['id' => '7', 'name' => 'Other']),
    ],
];

// create assigns sequential id and appends
$result = event_list_apply_events($baseExisting, [
    'changes' => ['create' => [array_merge($validEvent, ['name' => 'New'])]],
]);
test_assert(count($result['events']) === 3 && $result['events'][2]['id'] === '8'
        && $result['events'][2]['name'] === 'New',
    'apply_events: create assigns next sequential id');

// invalid create silently dropped
$result = event_list_apply_events($baseExisting, [
    'changes' => ['create' => [['name' => 'no other fields']]],
]);
test_assert(count($result['events']) === 2,
    'apply_events: invalid create silently dropped (no error, no append)');

// update merges by id
$result = event_list_apply_events($baseExisting, [
    'changes' => ['update' => [['id' => '5', 'name' => 'Renamed']]],
]);
test_assert($result['events'][0]['name'] === 'Renamed' && $result['events'][0]['id'] === '5',
    'apply_events: update merges fields, preserves id');

// update with non-existent id silently dropped
$result = event_list_apply_events($baseExisting, [
    'changes' => ['update' => [['id' => '999', 'name' => 'Ghost']]],
]);
test_assert(count($result['events']) === 2 && $result['events'][0]['name'] === 'Existing',
    'apply_events: update with non-existent id is no-op');

// update that would invalidate the merged event silently dropped
$result = event_list_apply_events($baseExisting, [
    'changes' => ['update' => [['id' => '5', 'name' => '']]],
]);
test_assert($result['events'][0]['name'] === 'Existing',
    'apply_events: update that fails validation does not modify event');

// delete removes by id
$result = event_list_apply_events($baseExisting, [
    'changes' => ['delete' => ['5']],
]);
test_assert(count($result['events']) === 1 && $result['events'][0]['id'] === '7',
    'apply_events: delete removes by id');

// delete with non-existent id is no-op
$result = event_list_apply_events($baseExisting, [
    'changes' => ['delete' => ['999']],
]);
test_assert(count($result['events']) === 2,
    'apply_events: delete with non-existent id is no-op');

// view-state flags (showPast/showCalendar/calendarDefault) live in the
// per-pane settings sidecar after v1.16.0 — validator drops them silently
// even if a stale client posts them.
$result = event_list_apply_events([], [
    'showPast' => true, 'showCalendar' => true, 'calendarDefault' => false,
]);
test_assert(!array_key_exists('showPast', $result)
        && !array_key_exists('showCalendar', $result)
        && !array_key_exists('calendarDefault', $result),
    'apply_events: legacy view-state flags dropped from output');
test_assert(array_keys($result) === ['events'],
    'apply_events: output is exactly {events: [...]}');

// malformed payload (changes not an array) returns sane defaults
$result = event_list_apply_events($baseExisting, ['changes' => 'not-an-array']);
test_assert(count($result['events']) === 2,
    'apply_events: non-array changes ignored, existing events preserved');

// new id allocation continues past existing (highest id wins)
$result = event_list_apply_events($baseExisting, [
    'changes' => ['create' => [
        array_merge($validEvent, ['name' => 'A']),
        array_merge($validEvent, ['name' => 'B']),
    ]],
]);
test_assert($result['events'][2]['id'] === '8' && $result['events'][3]['id'] === '9',
    'apply_events: sequential ids assigned across multiple creates in one call');

// Server applies deletes before creates — id allocation uses post-delete max.
// admin.js's applyPostSaveState must mirror this: client prediction excludes
// deletedIds from its maxId scan, otherwise client and server diverge.
$result = event_list_apply_events($baseExisting, [
    'changes' => [
        'delete' => ['7'],
        'create' => [array_merge($validEvent, ['name' => 'New'])],
    ],
]);
test_assert(count($result['events']) === 2
        && $result['events'][1]['id'] === '6',
    'apply_events: create after delete uses post-delete max+1 (5+1=6, not 7+1=8)');

// mixed: delete + update + create in one call
$result = event_list_apply_events($baseExisting, [
    'changes' => [
        'delete' => ['7'],
        'update' => [['id' => '5', 'name' => 'Updated']],
        'create' => [array_merge($validEvent, ['name' => 'Fresh'])],
    ],
]);
test_assert(count($result['events']) === 2
        && $result['events'][0]['name'] === 'Updated'
        && $result['events'][1]['name'] === 'Fresh',
    'apply_events: mixed delete + update + create applied in one pass');

// ---- lockedLocally round-trip: scraped-event admin override ----

// Update payload carries lockedLocally:true; merge preserves it on the record.
$result = event_list_apply_events($baseExisting, [
    'changes' => ['update' => [['id' => '5', 'name' => 'Locked', 'lockedLocally' => true]]],
]);
test_assert(!empty($result['events'][0]['lockedLocally']),
    'apply_events: update lands lockedLocally on the existing record');

// Subsequent update without lockedLocally leaves the existing true alone
// (array_merge preserves missing keys; the resume-sync path posts false explicitly).
$lockedExisting = ['events' => [array_merge($validEvent, ['id' => '5', 'name' => 'Locked', 'lockedLocally' => true])]];
$result = event_list_apply_events($lockedExisting, [
    'changes' => ['update' => [['id' => '5', 'name' => 'Renamed']]],
]);
test_assert(!empty($result['events'][0]['lockedLocally']),
    'apply_events: update without lockedLocally preserves prior true');

// Resume-sync: explicit false in payload clears the flag.
$result = event_list_apply_events($lockedExisting, [
    'changes' => ['update' => [['id' => '5', 'name' => 'Locked', 'lockedLocally' => false]]],
]);
test_assert(isset($result['events'][0]['lockedLocally']) && $result['events'][0]['lockedLocally'] === false,
    'apply_events: explicit lockedLocally:false clears the lock');

// ---- ics_filename: download filename from event name ----

test_assert(event_list_ics_filename('Summer Fursuit Walk', '5') === 'Summer-Fursuit-Walk.ics',
    'ics_filename: spaces become single hyphens, name preferred over id');
test_assert(event_list_ics_filename('2026 Awards Ceremony!', '12') === '2026-Awards-Ceremony.ics',
    'ics_filename: punctuation runs collapsed; trailing punctuation stripped');
test_assert(event_list_ics_filename('Hello,    World---test', '1') === 'Hello-World-test.ics',
    'ics_filename: long whitespace + multiple separators collapse to one hyphen');
test_assert(event_list_ics_filename('   leading and trailing   ', '1') === 'leading-and-trailing.ics',
    'ics_filename: leading/trailing whitespace stripped');
test_assert(event_list_ics_filename('', '7') === '7.ics',
    'ics_filename: empty name falls back to event id');
test_assert(event_list_ics_filename('!!!', '7') === '7.ics',
    'ics_filename: punctuation-only name falls back to event id');
test_assert(event_list_ics_filename('', '') === 'event.ics',
    'ics_filename: both empty -> literal "event"');
test_assert(event_list_ics_filename('café concert', '1') === 'caf-concert.ics',
    'ics_filename: unicode chars treated as separators, ascii preserved');
