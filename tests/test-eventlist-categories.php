<?php
require_once __DIR__ . '/bootstrap.php';
require_once lawnding_admin_path('modules/eventList/helpers.php');

// ---- generate_category_id: sequential int allocation ----

test_assert(event_list_generate_category_id([]) === 1,
    'generate_category_id: empty list -> 1');
test_assert(event_list_generate_category_id([['id' => '5']]) === 6,
    'generate_category_id: single record with id "5" -> 6');
test_assert(event_list_generate_category_id([
        ['id' => '2'], ['id' => '7'], ['id' => '4']
    ]) === 8,
    'generate_category_id: max id wins regardless of position');
test_assert(event_list_generate_category_id([['id' => 12]]) === 13,
    'generate_category_id: int id (not string) handled');
test_assert(event_list_generate_category_id(['not-an-array', ['id' => '3']]) === 4,
    'generate_category_id: non-array entries skipped defensively');

// ---- category_is_valid: name + 6-digit hex color ----

test_assert(event_list_category_is_valid(['name' => 'Social', 'color' => '#7ec7ed']) === true,
    'category_is_valid: name + 6-digit lowercase hex -> true');
test_assert(event_list_category_is_valid(['name' => 'Social', 'color' => '#7EC7ED']) === true,
    'category_is_valid: uppercase hex accepted');
test_assert(event_list_category_is_valid(['name' => '', 'color' => '#7ec7ed']) === false,
    'category_is_valid: empty name -> false');
test_assert(event_list_category_is_valid(['name' => '   ', 'color' => '#7ec7ed']) === false,
    'category_is_valid: whitespace-only name -> false');
test_assert(event_list_category_is_valid(['color' => '#7ec7ed']) === false,
    'category_is_valid: missing name -> false');
test_assert(event_list_category_is_valid(['name' => 'Social']) === false,
    'category_is_valid: missing color -> false');
test_assert(event_list_category_is_valid(['name' => 'Social', 'color' => '7ec7ed']) === false,
    'category_is_valid: hex without # -> false');
test_assert(event_list_category_is_valid(['name' => 'Social', 'color' => '#7ec7e']) === false,
    'category_is_valid: 5-digit hex -> false');
test_assert(event_list_category_is_valid(['name' => 'Social', 'color' => '#7ec7edd']) === false,
    'category_is_valid: 7-digit hex -> false');
test_assert(event_list_category_is_valid(['name' => 'Social', 'color' => '#zzzzzz']) === false,
    'category_is_valid: non-hex characters in color -> false');
test_assert(event_list_category_is_valid(['name' => 'Social', 'color' => 'rgb(126,199,237)']) === false,
    'category_is_valid: non-hex format rejected (no rgb()/hsl()/named)');

// ---- normalize_categories: pure shape-tolerant cleanup ----

test_assert(event_list_normalize_categories([]) === [],
    'normalize_categories: empty input -> []');
test_assert(event_list_normalize_categories(['categories' => []]) === [],
    'normalize_categories: empty categories array -> []');
test_assert(event_list_normalize_categories(['categories' => 'not-an-array']) === [],
    'normalize_categories: non-array categories key -> []');
test_assert(event_list_normalize_categories(['unrelated' => 'data']) === [],
    'normalize_categories: missing categories key -> []');

$normalized = event_list_normalize_categories([
    'categories' => [
        ['id' => '1', 'name' => 'Social', 'color' => '#7ec7ed'],
        ['id' => '2', 'name' => 'Volunteer', 'color' => '#a7d96a'],
    ],
]);
test_assert(count($normalized) === 2 && $normalized[0]['id'] === '1' && $normalized[1]['name'] === 'Volunteer',
    'normalize_categories: well-formed entries preserved');

$normalized = event_list_normalize_categories([
    'categories' => [
        ['id' => '1', 'name' => 'Good', 'color' => '#7ec7ed'],
        'not-an-array',
        ['id' => '2', 'color' => '#000000'],     // missing name
        ['name' => 'No id', 'color' => '#000000'],
        ['id' => '3', 'name' => 'No color'],
        ['id' => '4', 'name' => 'Good 2', 'color' => '#a7d96a'],
    ],
]);
test_assert(count($normalized) === 2
        && $normalized[0]['name'] === 'Good'
        && $normalized[1]['name'] === 'Good 2',
    'normalize_categories: malformed rows silently dropped, valid rows preserved in order');

$normalized = event_list_normalize_categories([
    'categories' => [
        ['id' => 5, 'name' => 'Numeric id', 'color' => '#7ec7ed'],
    ],
]);
test_assert($normalized[0]['id'] === '5',
    'normalize_categories: numeric id coerced to string');

// ---- apply_categories: pure-transform additive merge ----

$baseExisting = [
    'categories' => [
        ['id' => '1', 'name' => 'Social', 'color' => '#7ec7ed'],
        ['id' => '2', 'name' => 'Volunteer', 'color' => '#a7d96a'],
    ],
];

// create assigns sequential id and appends
$result = event_list_apply_categories($baseExisting, [
    'changes' => ['create' => [['name' => 'Fundraiser', 'color' => '#f0b85a']]],
]);
test_assert(count($result['categories']) === 3
        && $result['categories'][2]['id'] === '3'
        && $result['categories'][2]['name'] === 'Fundraiser',
    'apply_categories: create assigns next sequential id');

// invalid create silently dropped
$result = event_list_apply_categories($baseExisting, [
    'changes' => ['create' => [['name' => 'NoColor']]],
]);
test_assert(count($result['categories']) === 2,
    'apply_categories: create missing color silently dropped');

$result = event_list_apply_categories($baseExisting, [
    'changes' => ['create' => [['name' => '', 'color' => '#000000']]],
]);
test_assert(count($result['categories']) === 2,
    'apply_categories: create with empty name silently dropped');

// update merges by id
$result = event_list_apply_categories($baseExisting, [
    'changes' => ['update' => [['id' => '1', 'name' => 'Hangout']]],
]);
test_assert($result['categories'][0]['name'] === 'Hangout'
        && $result['categories'][0]['color'] === '#7ec7ed',
    'apply_categories: update merges fields, preserves untouched fields');

// update with non-existent id silently dropped
$result = event_list_apply_categories($baseExisting, [
    'changes' => ['update' => [['id' => '999', 'name' => 'Ghost']]],
]);
test_assert(count($result['categories']) === 2 && $result['categories'][0]['name'] === 'Social',
    'apply_categories: update with non-existent id is no-op');

// update that would invalidate the merged record silently dropped
$result = event_list_apply_categories($baseExisting, [
    'changes' => ['update' => [['id' => '1', 'color' => 'not-a-hex']]],
]);
test_assert($result['categories'][0]['color'] === '#7ec7ed',
    'apply_categories: update that fails validation does not modify category');

// delete removes by id
$result = event_list_apply_categories($baseExisting, [
    'changes' => ['delete' => ['1']],
]);
test_assert(count($result['categories']) === 1 && $result['categories'][0]['id'] === '2',
    'apply_categories: delete removes by id');

// delete with non-existent id is no-op
$result = event_list_apply_categories($baseExisting, [
    'changes' => ['delete' => ['999']],
]);
test_assert(count($result['categories']) === 2,
    'apply_categories: delete with non-existent id is no-op');

// Server applies deletes before creates — id allocation uses post-delete max.
$result = event_list_apply_categories($baseExisting, [
    'changes' => [
        'delete' => ['2'],
        'create' => [['name' => 'Fresh', 'color' => '#000000']],
    ],
]);
test_assert(count($result['categories']) === 2
        && $result['categories'][1]['id'] === '2',
    'apply_categories: create after delete uses post-delete max+1 (1+1=2, not 2+1=3)');

// mixed: delete + update + create in one call
$result = event_list_apply_categories($baseExisting, [
    'changes' => [
        'delete' => ['2'],
        'update' => [['id' => '1', 'name' => 'Updated']],
        'create' => [['name' => 'Fresh', 'color' => '#000000']],
    ],
]);
test_assert(count($result['categories']) === 2
        && $result['categories'][0]['name'] === 'Updated'
        && $result['categories'][1]['name'] === 'Fresh',
    'apply_categories: mixed delete + update + create applied in one pass');

// malformed payload (changes not an array) returns sane defaults
$result = event_list_apply_categories($baseExisting, ['changes' => 'not-an-array']);
test_assert(count($result['categories']) === 2,
    'apply_categories: non-array changes ignored, existing categories preserved');

// output shape is exactly {categories: [...]}
$result = event_list_apply_categories([], []);
test_assert(array_keys($result) === ['categories'] && $result['categories'] === [],
    'apply_categories: output is exactly {categories: [...]}');

// ---- event_is_valid: categoryId field is optional + must be string when present ----

$validEvent = [
    'name' => 'Walk',
    'startDate' => '2026-06-15',
    'startTime' => '14:00',
    'address' => '123 Main St',
    'description' => 'desc',
];

test_assert(event_list_event_is_valid(array_merge($validEvent, ['categoryId' => '1'])) === true,
    'event_is_valid: string categoryId accepted');
test_assert(event_list_event_is_valid(array_merge($validEvent, ['categoryId' => ''])) === true,
    'event_is_valid: empty-string categoryId accepted (uncategorized)');
test_assert(event_list_event_is_valid($validEvent) === true,
    'event_is_valid: missing categoryId accepted (older records)');
test_assert(event_list_event_is_valid(array_merge($validEvent, ['categoryId' => 'orphan-id-of-deleted-cat'])) === true,
    'event_is_valid: orphan categoryId accepted (renderer handles fallback)');
test_assert(event_list_event_is_valid(array_merge($validEvent, ['categoryId' => 5])) === false,
    'event_is_valid: non-string categoryId (int) rejected');
test_assert(event_list_event_is_valid(array_merge($validEvent, ['categoryId' => ['nested']])) === false,
    'event_is_valid: non-string categoryId (array) rejected');
