<?php
// Tests for remember-me pure-logic helpers in admin/lib/remember-me.php.
// I/O paths (file read/write, cookies, sessions) are not covered here —
// they require the project's missing session/file mocking infra. These
// tests cover the parts that are deterministic functions of their inputs.
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../admin/lib/remember-me.php';

// ---- lawnding_remember_token_hash ----

test_assert(
    lawnding_remember_token_hash('hello') === hash('sha256', 'hello'),
    'hash wrapper matches sha256'
);
test_assert(
    lawnding_remember_token_hash('') === hash('sha256', ''),
    'hash wrapper handles empty string'
);
test_assert(
    strlen(lawnding_remember_token_hash('any')) === 64,
    'hash output is 64-char hex (256 bits)'
);

// ---- lawnding_remember_token_create_payload ----

$payload = lawnding_remember_token_create_payload('alice', 'h0', 1000, 100);
test_assert($payload['user'] === 'alice', 'payload carries user');
test_assert($payload['token_hash'] === 'h0', 'payload carries hash');
test_assert($payload['issued_at'] === 1000, 'issued_at = now');
test_assert($payload['expires_at'] === 1100, 'expires_at = now + ttl');
test_assert($payload['last_used_at'] === 1000, 'last_used_at = now on creation');

// ---- lawnding_remember_tokens_prune_expired ----

$now = 2000;
$entries = [
    ['user' => 'a', 'token_hash' => 'h1', 'expires_at' => 1500],  // expired
    ['user' => 'b', 'token_hash' => 'h2', 'expires_at' => 2500],  // active
    ['user' => 'c', 'token_hash' => 'h3', 'expires_at' => 2000],  // exactly at boundary → pruned (>, not >=)
    ['user' => 'd', 'token_hash' => 'h4', 'expires_at' => 9999],  // active
];
$pruned = lawnding_remember_tokens_prune_expired($entries, $now);
test_assert(count($pruned) === 2, 'prune drops both expired and exactly-at-boundary');
test_assert($pruned[0]['user'] === 'b', 'first active entry preserved');
test_assert($pruned[1]['user'] === 'd', 'second active entry preserved');

test_assert(
    lawnding_remember_tokens_prune_expired([], 1000) === [],
    'empty array prunes to empty'
);
test_assert(
    count(lawnding_remember_tokens_prune_expired([
        ['user' => 'x', 'expires_at' => 100],
        ['user' => 'y', 'expires_at' => 200],
    ], 5000)) === 0,
    'all expired prunes to empty'
);
test_assert(
    count(lawnding_remember_tokens_prune_expired([
        'not an array',
        null,
        ['user' => 'ok', 'expires_at' => 9999],
    ], 1000)) === 1,
    'malformed entries silently dropped'
);
test_assert(
    count(lawnding_remember_tokens_prune_expired([
        ['user' => 'no_expiry'],  // missing expires_at
    ], 1000)) === 0,
    'entries missing expires_at are pruned'
);

// ---- constants ----

test_assert(LP_REMEMBER_COOKIE_NAME === 'lp_remember', 'cookie name constant');
test_assert(LP_REMEMBER_TOKEN_TTL === 30 * 86400, 'TTL is 30 days in seconds');
