<?php
declare(strict_types=1);

// Remember-me persistence: long-lived per-device tokens that auto-create a
// session on cold visits without re-prompting for the password.
//
// Storage: admin/remember_tokens.json (gitignored, LOCK_EX writes, chmod 0640).
// Schema per entry: {user, token_hash, issued_at, expires_at, last_used_at}.
//
// Security: only sha256(token) is stored server-side; raw token lives only in
// the cookie. Tokens rotate on every successful auto-login so a stolen cookie
// has limited replay. 30-day TTL by default, refreshed on each use.
// Failed/expired tokens silently clear the cookie and fall through to
// logged-out state — no error surface, no leak about token validity.

if (!defined('LP_REMEMBER_COOKIE_NAME')) {
    define('LP_REMEMBER_COOKIE_NAME', 'lp_remember');
}
if (!defined('LP_REMEMBER_TOKEN_TTL')) {
    define('LP_REMEMBER_TOKEN_TTL', 30 * 86400);
}

function lawnding_remember_tokens_path(): string {
    return function_exists('lawnding_admin_path')
        ? lawnding_admin_path('remember_tokens.json')
        : __DIR__ . '/../remember_tokens.json';
}

function lawnding_remember_token_hash(string $token): string {
    return hash('sha256', $token);
}

function lawnding_remember_token_create_payload(
    string $user,
    string $tokenHash,
    int $now,
    int $ttl
): array {
    return [
        'user' => $user,
        'token_hash' => $tokenHash,
        'issued_at' => $now,
        'expires_at' => $now + $ttl,
        'last_used_at' => $now,
    ];
}

function lawnding_remember_tokens_prune_expired(array $entries, int $now): array {
    $kept = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $expires = isset($entry['expires_at']) ? (int) $entry['expires_at'] : 0;
        if ($expires > $now) {
            $kept[] = $entry;
        }
    }
    return array_values($kept);
}

function lawnding_remember_tokens_load(string $path): array {
    if (!is_readable($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $entries = $decoded['tokens'] ?? $decoded;
    return is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [];
}

function lawnding_remember_tokens_write(string $path, array $entries): bool {
    $payload = json_encode(['tokens' => array_values($entries)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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

function lawnding_remember_set_cookie(string $rawToken, ?int $expiresAt = null): void {
    if (headers_sent()) {
        return;
    }
    $expires = $expiresAt ?? (time() + LP_REMEMBER_TOKEN_TTL);
    $secure = function_exists('lawnding_request_is_secure') ? lawnding_request_is_secure() : false;
    $path = function_exists('lawnding_config') ? (string) lawnding_config('session_cookie_path', '/') : '/';
    if ($path === '') {
        $path = '/';
    }
    $domain = function_exists('lawnding_config') ? trim((string) lawnding_config('session_cookie_domain', '')) : '';
    $params = [
        'expires' => $expires,
        'path' => $path,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if ($domain !== '') {
        $params['domain'] = $domain;
    }
    setcookie(LP_REMEMBER_COOKIE_NAME, $rawToken, $params);
}

function lawnding_remember_clear_cookie(): void {
    if (headers_sent()) {
        return;
    }
    $path = function_exists('lawnding_config') ? (string) lawnding_config('session_cookie_path', '/') : '/';
    if ($path === '') {
        $path = '/';
    }
    $domain = function_exists('lawnding_config') ? trim((string) lawnding_config('session_cookie_domain', '')) : '';
    $params = [
        'expires' => time() - 3600,
        'path' => $path,
        'secure' => function_exists('lawnding_request_is_secure') ? lawnding_request_is_secure() : false,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if ($domain !== '') {
        $params['domain'] = $domain;
    }
    setcookie(LP_REMEMBER_COOKIE_NAME, '', $params);
    unset($_COOKIE[LP_REMEMBER_COOKIE_NAME]);
}

function lawnding_remember_token_issue(string $user, string $path): string {
    $token = bin2hex(random_bytes(32));
    $hash = lawnding_remember_token_hash($token);
    $now = time();
    $entries = lawnding_remember_tokens_load($path);
    $entries = lawnding_remember_tokens_prune_expired($entries, $now);
    $entries[] = lawnding_remember_token_create_payload($user, $hash, $now, LP_REMEMBER_TOKEN_TTL);
    lawnding_remember_tokens_write($path, $entries);
    lawnding_remember_set_cookie($token, $now + LP_REMEMBER_TOKEN_TTL);
    return $token;
}

function lawnding_remember_token_consume(string $rawToken, string $path): ?string {
    if ($rawToken === '') {
        return null;
    }
    $hash = lawnding_remember_token_hash($rawToken);
    $now = time();
    $entries = lawnding_remember_tokens_prune_expired(
        lawnding_remember_tokens_load($path),
        $now
    );

    $matchedUser = null;
    $remaining = [];
    foreach ($entries as $entry) {
        $entryHash = isset($entry['token_hash']) ? (string) $entry['token_hash'] : '';
        if ($matchedUser === null && hash_equals($entryHash, $hash)) {
            $matchedUser = isset($entry['user']) ? (string) $entry['user'] : '';
            continue;
        }
        $remaining[] = $entry;
    }

    if ($matchedUser === null || $matchedUser === '') {
        // Rewrite anyway to commit the prune.
        lawnding_remember_tokens_write($path, $remaining);
        return null;
    }

    $newToken = bin2hex(random_bytes(32));
    $newHash = lawnding_remember_token_hash($newToken);
    $remaining[] = lawnding_remember_token_create_payload($matchedUser, $newHash, $now, LP_REMEMBER_TOKEN_TTL);
    lawnding_remember_tokens_write($path, $remaining);
    lawnding_remember_set_cookie($newToken, $now + LP_REMEMBER_TOKEN_TTL);

    return $matchedUser;
}

function lawnding_remember_token_revoke(string $rawToken, string $path): void {
    lawnding_remember_clear_cookie();
    if ($rawToken === '') {
        return;
    }
    $hash = lawnding_remember_token_hash($rawToken);
    $entries = lawnding_remember_tokens_load($path);
    $remaining = [];
    foreach ($entries as $entry) {
        $entryHash = isset($entry['token_hash']) ? (string) $entry['token_hash'] : '';
        if (!hash_equals($entryHash, $hash)) {
            $remaining[] = $entry;
        }
    }
    lawnding_remember_tokens_write($path, $remaining);
}

function lawnding_remember_token_revoke_all_for_user(string $user, string $path): void {
    if ($user === '') {
        return;
    }
    $entries = lawnding_remember_tokens_load($path);
    $remaining = [];
    foreach ($entries as $entry) {
        $entryUser = isset($entry['user']) ? (string) $entry['user'] : '';
        if ($entryUser !== $user) {
            $remaining[] = $entry;
        }
    }
    lawnding_remember_tokens_write($path, $remaining);
}

function lawnding_consume_remember_cookie(): void {
    if (!empty($_SESSION['auth_user'])) {
        return;
    }
    $raw = $_COOKIE[LP_REMEMBER_COOKIE_NAME] ?? '';
    if (!is_string($raw) || $raw === '') {
        return;
    }
    $path = lawnding_remember_tokens_path();
    $user = lawnding_remember_token_consume($raw, $path);
    if ($user === null) {
        lawnding_remember_clear_cookie();
        return;
    }
    @session_regenerate_id(true);
    $_SESSION['auth_user'] = $user;
}
