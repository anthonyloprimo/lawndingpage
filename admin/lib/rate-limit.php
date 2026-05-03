<?php
declare(strict_types=1);

// Login rate limiter. Tracks failed bcrypt password attempts in a flatfile
// sidecar so brute-force / credential-stuffing campaigns can't hammer
// /admin/ or the modal endpoint indefinitely.
//
// Storage: admin/lp-loginAttempts.json (gitignored, LOCK_EX, chmod 0640).
//   {
//     "by_ip":   { "<ip>":   {"fails":[<unix_ts>,...],"locked_until":<unix_ts>} },
//     "by_user": { "<user>": {"fails":[<unix_ts>,...],"locked_until":<unix_ts>} }
//   }
//
// Strategy: per-IP AND per-username, whichever trips first. 5 fails in a
// 15-minute rolling window → 15-minute lockout from the most-recent failure.
// Successful login clears both buckets (the requester knew the password,
// so by definition they aren't the attacker we were locking out).
//
// Self-pruning: every write filters expired fails + cleared lockouts so the
// JSON file doesn't grow unboundedly.

if (!defined('LP_RATE_LIMIT_THRESHOLD')) {
    define('LP_RATE_LIMIT_THRESHOLD', 5);
}
if (!defined('LP_RATE_LIMIT_WINDOW_SECONDS')) {
    define('LP_RATE_LIMIT_WINDOW_SECONDS', 900); // 15 minutes
}
if (!defined('LP_RATE_LIMIT_LOCKOUT_SECONDS')) {
    define('LP_RATE_LIMIT_LOCKOUT_SECONDS', 900); // 15 minutes
}

function lawnding_rate_limit_path(): string {
    return function_exists('lawnding_admin_path')
        ? lawnding_admin_path('lp-loginAttempts.json')
        : __DIR__ . '/../lp-loginAttempts.json';
}

function lawnding_rate_limit_filter_recent(array $timestamps, int $now, int $window): array {
    $cutoff = $now - $window;
    $kept = [];
    foreach ($timestamps as $ts) {
        if (is_int($ts) && $ts > $cutoff && $ts <= $now) {
            $kept[] = $ts;
        }
    }
    return $kept;
}

function lawnding_rate_limit_should_lock(array $recent, int $threshold): bool {
    return count($recent) >= $threshold;
}

function lawnding_rate_limit_seconds_remaining(int $lockedUntil, int $now): int {
    $diff = $lockedUntil - $now;
    return $diff > 0 ? $diff : 0;
}

// Strip stale fails and cleared lockouts from a single entry. Returns the
// pruned entry, or null if the entry is fully drainable (zero recent fails
// AND no active lockout) — caller should then drop the bucket key.
function lawnding_rate_limit_prune_entry(array $entry, int $now, int $window): ?array {
    $fails = isset($entry['fails']) && is_array($entry['fails']) ? $entry['fails'] : [];
    $recent = lawnding_rate_limit_filter_recent($fails, $now, $window);
    $lockedUntil = isset($entry['locked_until']) ? (int) $entry['locked_until'] : 0;
    $activeLock = $lockedUntil > $now;
    if (count($recent) === 0 && !$activeLock) {
        return null;
    }
    $out = ['fails' => $recent];
    if ($activeLock) {
        $out['locked_until'] = $lockedUntil;
    }
    return $out;
}

function lawnding_rate_limit_prune_state(array $state, int $now, int $window): array {
    $out = ['by_ip' => [], 'by_user' => []];
    foreach (['by_ip', 'by_user'] as $bucket) {
        $entries = isset($state[$bucket]) && is_array($state[$bucket]) ? $state[$bucket] : [];
        foreach ($entries as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $pruned = lawnding_rate_limit_prune_entry($entry, $now, $window);
            if ($pruned !== null) {
                $out[$bucket][(string) $key] = $pruned;
            }
        }
    }
    return $out;
}

function lawnding_rate_limit_load(string $path): array {
    $base = ['by_ip' => [], 'by_user' => []];
    if (!is_readable($path)) {
        return $base;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return $base;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $base;
    }
    $base['by_ip'] = isset($decoded['by_ip']) && is_array($decoded['by_ip']) ? $decoded['by_ip'] : [];
    $base['by_user'] = isset($decoded['by_user']) && is_array($decoded['by_user']) ? $decoded['by_user'] : [];
    return $base;
}

function lawnding_rate_limit_write(string $path, array $state): bool {
    $payload = json_encode([
        'by_ip' => isset($state['by_ip']) && is_array($state['by_ip']) ? $state['by_ip'] : [],
        'by_user' => isset($state['by_user']) && is_array($state['by_user']) ? $state['by_user'] : [],
    ], JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return false;
    }
    $ok = @file_put_contents($path, $payload, LOCK_EX);
    if ($ok === false) {
        return false;
    }
    @chmod($path, 0640);
    return true;
}

// Inspect current lockout status. Returns null if not locked, otherwise an
// array describing which scope tripped (ip vs user) and how long until it
// clears. IP scope wins ties because it's the more common attack pattern.
function lawnding_rate_limit_check(string $ip, string $username, string $path): ?array {
    if ($ip === '' && $username === '') {
        return null;
    }
    $state = lawnding_rate_limit_load($path);
    $now = time();
    foreach (['ip' => $ip, 'user' => $username] as $scope => $key) {
        if ($key === '') {
            continue;
        }
        $bucket = $scope === 'ip' ? 'by_ip' : 'by_user';
        $entry = $state[$bucket][$key] ?? null;
        if (!is_array($entry)) {
            continue;
        }
        $lockedUntil = isset($entry['locked_until']) ? (int) $entry['locked_until'] : 0;
        $remaining = lawnding_rate_limit_seconds_remaining($lockedUntil, $now);
        if ($remaining > 0) {
            return ['scope' => $scope, 'seconds_remaining' => $remaining];
        }
    }
    return null;
}

// Append a failed attempt to both the IP and username buckets. If either
// bucket's recent-fail count crosses the threshold, set/extend its lockout.
function lawnding_rate_limit_record_failure(string $ip, string $username, string $path): void {
    if ($ip === '' && $username === '') {
        return;
    }
    $state = lawnding_rate_limit_load($path);
    $now = time();
    $window = (int) LP_RATE_LIMIT_WINDOW_SECONDS;

    foreach (['ip' => $ip, 'user' => $username] as $scope => $key) {
        if ($key === '') {
            continue;
        }
        $bucket = $scope === 'ip' ? 'by_ip' : 'by_user';
        $existing = $state[$bucket][$key] ?? ['fails' => []];
        $fails = isset($existing['fails']) && is_array($existing['fails']) ? $existing['fails'] : [];
        $fails = lawnding_rate_limit_filter_recent($fails, $now, $window);
        $fails[] = $now;
        $entry = ['fails' => $fails];
        if (lawnding_rate_limit_should_lock($fails, (int) LP_RATE_LIMIT_THRESHOLD)) {
            $entry['locked_until'] = $now + (int) LP_RATE_LIMIT_LOCKOUT_SECONDS;
        } elseif (isset($existing['locked_until']) && (int) $existing['locked_until'] > $now) {
            // Preserve an active lockout that hasn't expired yet (e.g. a
            // stray attempt during the cooldown).
            $entry['locked_until'] = (int) $existing['locked_until'];
        }
        $state[$bucket][$key] = $entry;
    }

    $state = lawnding_rate_limit_prune_state($state, $now, $window);
    lawnding_rate_limit_write($path, $state);
}

// Drop all fail records for the given IP and username. Called on successful
// login — the requester knew the password, so they're not the attacker we
// were locking out.
function lawnding_rate_limit_record_success(string $ip, string $username, string $path): void {
    if ($ip === '' && $username === '') {
        return;
    }
    $state = lawnding_rate_limit_load($path);
    if ($ip !== '') {
        unset($state['by_ip'][$ip]);
    }
    if ($username !== '') {
        unset($state['by_user'][$username]);
    }
    $state = lawnding_rate_limit_prune_state($state, time(), (int) LP_RATE_LIMIT_WINDOW_SECONDS);
    lawnding_rate_limit_write($path, $state);
}
