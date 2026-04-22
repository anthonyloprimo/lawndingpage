<?php
// Shared Telegram auth helpers for public endpoints and page rendering.
require_once __DIR__ . '/tg-bot.php';

function lawnding_tg_config_defaults(): array {
    return [
        'bot_username' => '',
        'bot_token' => '',
        'group_ids' => [],
        'membership_cache_ttl_minutes' => 30,
        'unauthorized_message' => 'Login failed: you must be a member of a configured Telegram group to access this site.',
        'allowed_statuses' => ['member', 'administrator', 'creator'],
    ];
}

function lawnding_tg_normalize_content_level($value, string $default = 'sfw'): string {
    $normalized = is_string($value) ? strtolower(trim($value)) : '';
    if ($normalized !== 'nsfw' && $normalized !== 'sfw') {
        $fallback = strtolower(trim($default));
        if ($fallback === 'nsfw') {
            $normalized = 'nsfw';
        } elseif ($fallback === 'sfw') {
            $normalized = 'sfw';
        } else {
            $normalized = '';
        }
    }
    return $normalized;
}

function lawnding_tg_content_rank(string $value): int {
    if ($value === 'nsfw') {
        return 2;
    }
    if ($value === 'sfw') {
        return 1;
    }
    return 0;
}

function lawnding_tg_higher_content_level(string $left, string $right): string {
    return lawnding_tg_content_rank($right) > lawnding_tg_content_rank($left) ? $right : $left;
}

// Single source of truth for "which admin permissions a Telegram group is
// allowed to grant." DO NOT add `full_admin` here — that permission and the
// `master` flag are intentionally bcrypt-only. Adding `full_admin` would
// silently relax the security invariant; see the project docs auth-model section.
function lawnding_tg_eligible_permissions(): array {
    return ['edit_site', 'add_users', 'edit_users', 'remove_users'];
}

function lawnding_tg_normalize_group_entries($values): array {
    if (!is_array($values)) {
        return [];
    }
    $eligible = lawnding_tg_eligible_permissions();
    $order = [];
    $entriesById = [];
    foreach ($values as $value) {
        $groupId = '';
        $content = 'sfw';
        $permissions = [];
        if (is_string($value) && trim($value) !== '') {
            $groupId = trim($value);
        } elseif (is_array($value)) {
            $groupId = isset($value['id']) && is_string($value['id']) ? trim($value['id']) : '';
            $content = lawnding_tg_normalize_content_level($value['content'] ?? 'sfw');
            if (isset($value['permissions']) && is_array($value['permissions'])) {
                foreach ($value['permissions'] as $perm) {
                    if (!is_string($perm)) {
                        continue;
                    }
                    $perm = trim($perm);
                    if ($perm === '' || !in_array($perm, $eligible, true)) {
                        // Silently drop unknowns and disallowed (e.g. full_admin)
                        // on load — defense against hand-edited or older configs.
                        continue;
                    }
                    if (!in_array($perm, $permissions, true)) {
                        $permissions[] = $perm;
                    }
                }
            }
        }
        if ($groupId === '') {
            continue;
        }
        if (!isset($entriesById[$groupId])) {
            $order[] = $groupId;
            $entriesById[$groupId] = ['id' => $groupId, 'content' => $content, 'permissions' => $permissions];
            continue;
        }
        $entriesById[$groupId]['content'] = lawnding_tg_higher_content_level($entriesById[$groupId]['content'], $content);
        // Merge permissions across duplicate group entries (union).
        foreach ($permissions as $perm) {
            if (!in_array($perm, $entriesById[$groupId]['permissions'], true)) {
                $entriesById[$groupId]['permissions'][] = $perm;
            }
        }
    }
    $entries = [];
    foreach ($order as $groupId) {
        if (isset($entriesById[$groupId])) {
            $entries[] = $entriesById[$groupId];
        }
    }
    return $entries;
}

function lawnding_load_tg_config(): array {
    $defaults = lawnding_tg_config_defaults();
    $adminDir = function_exists('lawnding_config')
        ? lawnding_config('admin_dir', dirname(__DIR__))
        : dirname(__DIR__);
    $path = rtrim((string) $adminDir, '/\\') . '/lp-tgBot.json';
    if (!is_readable($path)) {
        return $defaults;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return $defaults;
    }
    $merged = array_merge($defaults, $decoded);

    $merged['group_ids'] = lawnding_tg_normalize_group_entries($merged['group_ids'] ?? []);

    $statuses = [];
    if (is_array($merged['allowed_statuses'] ?? null)) {
        foreach ($merged['allowed_statuses'] as $status) {
            if (is_string($status) && trim($status) !== '') {
                $statuses[] = trim($status);
            }
        }
    }
    if (empty($statuses)) {
        $statuses = $defaults['allowed_statuses'];
    }
    $merged['allowed_statuses'] = array_values(array_unique($statuses));

    $merged['bot_username'] = is_string($merged['bot_username']) ? ltrim(trim($merged['bot_username']), '@') : '';
    $merged['bot_token'] = is_string($merged['bot_token']) ? trim($merged['bot_token']) : '';
    $merged['unauthorized_message'] = is_string($merged['unauthorized_message'])
        ? trim($merged['unauthorized_message'])
        : $defaults['unauthorized_message'];
    if ($merged['unauthorized_message'] === '') {
        $merged['unauthorized_message'] = $defaults['unauthorized_message'];
    }
    return $merged;
}

function lawnding_tg_verify_login_payload(array $payload, string $botToken): bool {
    $hash = isset($payload['hash']) ? (string) $payload['hash'] : '';
    if ($hash === '' || $botToken === '') {
        return false;
    }
    unset($payload['hash']);
    $parts = [];
    ksort($payload);
    foreach ($payload as $key => $value) {
        if (!is_scalar($value)) {
            continue;
        }
        $parts[] = $key . '=' . (string) $value;
    }
    $dataCheckString = implode("\n", $parts);
    $secretKey = hash('sha256', $botToken, true);
    $computedHash = hash_hmac('sha256', $dataCheckString, $secretKey);
    return hash_equals($computedHash, $hash);
}

function lawnding_tg_cache_path(): string {
    $adminDir = function_exists('lawnding_config')
        ? lawnding_config('admin_dir', dirname(__DIR__))
        : dirname(__DIR__);
    return rtrim((string) $adminDir, '/\\') . '/lp-tgMembershipCache.json';
}

function lawnding_tg_cache_fingerprint(array $tgConfig): string {
    $groupIds = [];
    if (is_array($tgConfig['group_ids'] ?? null)) {
        foreach ($tgConfig['group_ids'] as $entry) {
            if (!is_array($entry) || empty($entry['id'])) {
                continue;
            }
            $groupIds[] = (string) $entry['id'] . ':' . strtoupper(lawnding_tg_normalize_content_level($entry['content'] ?? 'sfw'));
        }
    }
    $allowedStatuses = is_array($tgConfig['allowed_statuses'] ?? null) ? array_values($tgConfig['allowed_statuses']) : [];
    sort($groupIds, SORT_NATURAL);
    sort($allowedStatuses, SORT_NATURAL);
    return hash('sha256', json_encode([
        'bot_token' => (string) ($tgConfig['bot_token'] ?? ''),
        'group_ids' => $groupIds,
        'allowed_statuses' => $allowedStatuses,
    ]));
}

function lawnding_tg_cache_load(): array {
    $path = lawnding_tg_cache_path();
    if (!is_readable($path)) {
        return ['users' => []];
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded) || !is_array($decoded['users'] ?? null)) {
        return ['users' => []];
    }
    return ['users' => $decoded['users']];
}

function lawnding_tg_cache_write(array $cache): void {
    $path = lawnding_tg_cache_path();
    $payload = json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return;
    }
    file_put_contents($path, $payload . PHP_EOL, LOCK_EX);
}

function lawnding_tg_cache_prune(array &$cache, int $now): void {
    if (!is_array($cache['users'] ?? null)) {
        $cache['users'] = [];
        return;
    }
    foreach ($cache['users'] as $userId => $entry) {
        $expiresAt = isset($entry['expires_at']) ? (int) $entry['expires_at'] : 0;
        if ($expiresAt <= 0 || $expiresAt < $now) {
            unset($cache['users'][$userId]);
        }
    }
}

function lawnding_tg_user_content_level(array $tgConfig, $userId, ?array $profile = null): string {
    $token = (string) ($tgConfig['bot_token'] ?? '');
    $groupIds = is_array($tgConfig['group_ids'] ?? null) ? $tgConfig['group_ids'] : [];
    $allowedStatuses = is_array($tgConfig['allowed_statuses'] ?? null) ? $tgConfig['allowed_statuses'] : [];
    if ($token === '' || empty($groupIds) || $userId === null || $userId === '') {
        return '';
    }
    $ttlMinutes = isset($tgConfig['membership_cache_ttl_minutes']) ? (int) $tgConfig['membership_cache_ttl_minutes'] : 30;
    $ttlMinutes = $ttlMinutes > 0 ? $ttlMinutes : 30;
    $ttlSeconds = $ttlMinutes * 60;
    $now = time();
    $cacheKey = (string) $userId;
    $fingerprint = lawnding_tg_cache_fingerprint($tgConfig);
    $forceRefresh = !empty($_SESSION['tg_membership_force_refresh']);
    if ($forceRefresh) {
        unset($_SESSION['tg_membership_force_refresh']);
    }

    $cache = lawnding_tg_cache_load();
    lawnding_tg_cache_prune($cache, $now);

    if (!$forceRefresh && isset($cache['users'][$cacheKey]) && is_array($cache['users'][$cacheKey])) {
        $cached = $cache['users'][$cacheKey];
        $expiresAt = isset($cached['expires_at']) ? (int) $cached['expires_at'] : 0;
        $cachedFingerprint = isset($cached['fingerprint']) ? (string) $cached['fingerprint'] : '';
        if ($expiresAt >= $now && $cachedFingerprint === $fingerprint) {
            if (isset($cached['content_level']) && is_string($cached['content_level'])) {
                return lawnding_tg_normalize_content_level($cached['content_level'], '');
            }
            return !empty($cached['authorized']) ? 'sfw' : '';
        }
    }

    $bot = new TgBotClient($token);
    $authorizedLevel = '';
    $statuses = [];
    foreach ($groupIds as $groupEntry) {
        if (!is_array($groupEntry)) {
            continue;
        }
        $groupId = isset($groupEntry['id']) ? (string) $groupEntry['id'] : '';
        $groupContent = lawnding_tg_normalize_content_level($groupEntry['content'] ?? 'sfw');
        if ($groupId === '') {
            continue;
        }
        $resp = $bot->getChatMember($groupId, $userId);
        if (!is_array($resp) || empty($resp['ok']) || !is_array($resp['result'] ?? null)) {
            $statuses[(string) $groupId] = 'error';
            continue;
        }
        $status = isset($resp['result']['status']) ? (string) $resp['result']['status'] : '';
        $statuses[(string) $groupId] = $status !== '' ? $status : 'unknown';
        if ($status !== '' && in_array($status, $allowedStatuses, true)) {
            $authorizedLevel = lawnding_tg_higher_content_level($authorizedLevel, $groupContent);
        }
    }

    $entry = [
        'authorized' => $authorizedLevel !== '',
        'content_level' => $authorizedLevel,
        'checked_at' => $now,
        'expires_at' => $now + $ttlSeconds,
        'fingerprint' => $fingerprint,
        'statuses' => $statuses,
    ];
    // Snapshot the visitor's display profile when the caller supplies it. This
    // is the only persistent record of *who* a numeric Telegram user_id
    // belongs to — sessions hold names and handles only while active. Update
    // happens on every fresh API check (which login forces via
    // tg_membership_force_refresh), so handle/name changes filter in
    // organically as users come back to the site.
    if (is_array($profile)) {
        $entry['profile'] = [
            'username' => is_string($profile['username'] ?? null) ? trim((string) $profile['username']) : '',
            'first_name' => is_string($profile['first_name'] ?? null) ? trim((string) $profile['first_name']) : '',
            'last_name' => is_string($profile['last_name'] ?? null) ? trim((string) $profile['last_name']) : '',
        ];
    } elseif (isset($cache['users'][$cacheKey]['profile']) && is_array($cache['users'][$cacheKey]['profile'])) {
        // No profile this round — preserve whatever was last captured so the
        // entry doesn't degrade to an anonymous numeric ID.
        $entry['profile'] = $cache['users'][$cacheKey]['profile'];
    }
    $cache['users'][$cacheKey] = $entry;
    lawnding_tg_cache_write($cache);

    return $authorizedLevel;
}

function lawnding_tg_user_authorized(array $tgConfig, $userId): bool {
    return lawnding_tg_user_content_level($tgConfig, $userId) !== '';
}

function lawnding_tg_relative_return_path($value): string {
    if (!is_string($value) || $value === '') {
        return '/';
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) || str_starts_with($value, '//')) {
        return '/';
    }
    return str_starts_with($value, '/') ? $value : '/' . ltrim($value, '/');
}
