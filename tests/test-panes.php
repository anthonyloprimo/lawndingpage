<?php
// Tests for lawnding_sort_panes() — stable sort by order key.
require_once __DIR__ . '/bootstrap.php';

// Empty array.
test_assert(lawnding_sort_panes([]) === [], 'empty array returns empty');

// Single pane.
$single = [['id' => 'welcome', 'order' => 0]];
test_assert(lawnding_sort_panes($single) === $single, 'single pane passes through unchanged');

// Explicit order takes priority.
$unordered = [
    ['id' => 'c', 'order' => 3],
    ['id' => 'a', 'order' => 1],
    ['id' => 'b', 'order' => 2],
];
$sorted = lawnding_sort_panes($unordered);
test_assert($sorted[0]['id'] === 'a', 'lowest order comes first');
test_assert($sorted[1]['id'] === 'b', 'middle order in middle');
test_assert($sorted[2]['id'] === 'c', 'highest order comes last');

// Same order — original position breaks the tie (stable sort).
$sameOrder = [
    ['id' => 'first',  'order' => 0],
    ['id' => 'second', 'order' => 0],
    ['id' => 'third',  'order' => 0],
];
$sorted = lawnding_sort_panes($sameOrder);
test_assert($sorted[0]['id'] === 'first', 'same order preserves first position');
test_assert($sorted[1]['id'] === 'second', 'same order preserves second position');
test_assert($sorted[2]['id'] === 'third', 'same order preserves third position');

// Missing order key defaults to PHP_INT_MAX (sinks to end).
$mixed = [
    ['id' => 'noOrder'],
    ['id' => 'ordered', 'order' => 0],
];
$sorted = lawnding_sort_panes($mixed);
test_assert($sorted[0]['id'] === 'ordered', 'pane with explicit order comes before panes without');
test_assert($sorted[1]['id'] === 'noOrder', 'pane without order sinks to end');

// Non-array entries are silently dropped.
$withJunk = [
    'not-an-array',
    ['id' => 'real', 'order' => 0],
    null,
];
$sorted = lawnding_sort_panes($withJunk);
test_assert(count($sorted) === 1, 'non-array entries are filtered out');
test_assert($sorted[0]['id'] === 'real', 'only array entry survives');

// _index keys are stripped from output.
$input = [['id' => 'x', 'order' => 0]];
$sorted = lawnding_sort_panes($input);
test_assert(!array_key_exists('_index', $sorted[0]), '_index key is removed from output');
