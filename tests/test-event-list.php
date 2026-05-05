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
