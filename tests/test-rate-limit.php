<?php
// Tests for rate-limit pure-logic helpers in admin/lib/rate-limit.php.
// I/O paths (file read/write, hand-off to the orchestrators that touch the
// filesystem) are not covered here — those need the project's missing
// integration-test infra. This file covers the deterministic functions of
// their inputs.
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../admin/lib/rate-limit.php';

// ---- Constants ----

test_assert(LP_RATE_LIMIT_THRESHOLD === 5, 'threshold is 5');
test_assert(LP_RATE_LIMIT_WINDOW_SECONDS === 900, 'window is 15 minutes');
test_assert(LP_RATE_LIMIT_LOCKOUT_SECONDS === 900, 'lockout is 15 minutes');

// ---- lawnding_rate_limit_filter_recent ----

$now = 10000;
$window = 900;

test_assert(
    lawnding_rate_limit_filter_recent([], $now, $window) === [],
    'empty list filters to empty'
);
test_assert(
    lawnding_rate_limit_filter_recent([9100, 9500, 9900], $now, $window) === [9500, 9900],
    'drops timestamps at or before cutoff (now - window = 9100)'
);
test_assert(
    lawnding_rate_limit_filter_recent([8000, 8500, 9000], $now, $window) === [],
    'all stale → empty'
);
test_assert(
    lawnding_rate_limit_filter_recent([9200, 9500, 9999], $now, $window) === [9200, 9500, 9999],
    'all recent → full list'
);
test_assert(
    lawnding_rate_limit_filter_recent([10001, 10500], $now, $window) === [],
    'future timestamps dropped (clock skew protection)'
);
test_assert(
    lawnding_rate_limit_filter_recent([10000], $now, $window) === [10000],
    'exactly now → kept'
);

// ---- lawnding_rate_limit_should_lock ----

test_assert(
    lawnding_rate_limit_should_lock([], 5) === false,
    'zero fails → not locked'
);
test_assert(
    lawnding_rate_limit_should_lock([1, 2, 3, 4], 5) === false,
    'under threshold → not locked'
);
test_assert(
    lawnding_rate_limit_should_lock([1, 2, 3, 4, 5], 5) === true,
    'exactly threshold → locked'
);
test_assert(
    lawnding_rate_limit_should_lock([1, 2, 3, 4, 5, 6], 5) === true,
    'over threshold → locked'
);

// ---- lawnding_rate_limit_seconds_remaining ----

test_assert(
    lawnding_rate_limit_seconds_remaining(10500, 10000) === 500,
    'future lockout → seconds remaining'
);
test_assert(
    lawnding_rate_limit_seconds_remaining(9000, 10000) === 0,
    'past lockout → 0 (clamped, no negatives)'
);
test_assert(
    lawnding_rate_limit_seconds_remaining(10000, 10000) === 0,
    'lockout exactly at now → 0'
);

// ---- lawnding_rate_limit_prune_entry ----

$prunable = lawnding_rate_limit_prune_entry(
    ['fails' => [8000, 8500], 'locked_until' => 9000],
    $now,
    $window
);
test_assert($prunable === null, 'fully drainable entry → null');

$staleFailsActiveLock = lawnding_rate_limit_prune_entry(
    ['fails' => [8000, 8500], 'locked_until' => 11000],
    $now,
    $window
);
test_assert(
    $staleFailsActiveLock === ['fails' => [], 'locked_until' => 11000],
    'stale fails dropped, active lockout preserved'
);

$mixed = lawnding_rate_limit_prune_entry(
    ['fails' => [8500, 9500, 9900], 'locked_until' => 9000],
    $now,
    $window
);
test_assert(
    $mixed === ['fails' => [9500, 9900]],
    'recent fails kept, stale dropped, expired lockout removed'
);

$noLockKey = lawnding_rate_limit_prune_entry(['fails' => [9500]], $now, $window);
test_assert(
    $noLockKey === ['fails' => [9500]],
    'entry without locked_until → only fails returned'
);

// ---- lawnding_rate_limit_prune_state ----

$state = [
    'by_ip' => [
        '1.2.3.4' => ['fails' => [9500, 9900]],          // recent → kept
        '5.6.7.8' => ['fails' => [8000]],                // stale → dropped
        '9.9.9.9' => ['fails' => [], 'locked_until' => 11000], // active lock → kept
    ],
    'by_user' => [
        'alice' => ['fails' => [9500]],                  // recent → kept
        'bob' => 'invalid',                              // malformed → dropped
    ],
];
$pruned = lawnding_rate_limit_prune_state($state, $now, $window);
test_assert(
    isset($pruned['by_ip']['1.2.3.4']) && !isset($pruned['by_ip']['5.6.7.8']),
    'state prune: stale IP dropped, recent IP kept'
);
test_assert(
    isset($pruned['by_ip']['9.9.9.9']) && $pruned['by_ip']['9.9.9.9']['locked_until'] === 11000,
    'state prune: active-lock IP kept with lockout intact'
);
test_assert(
    isset($pruned['by_user']['alice']) && !isset($pruned['by_user']['bob']),
    'state prune: recent user kept, malformed user dropped'
);
test_assert(
    lawnding_rate_limit_prune_state([], $now, $window) === ['by_ip' => [], 'by_user' => []],
    'empty input → empty buckets'
);
