<?php
// Shared Telegram auth helpers for public endpoints and page rendering.
require_once __DIR__ . '/tg-bot.php';

function lawnding_tg_config_defaults(): array {
    return [
        'bot_username' => '',
        'bot_token' => '',
        'group_ids' => [],
        'whitelist_user_ids' => [],
        'blacklist_user_ids' => [],
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

function lawnding_tg_normalize_user_scope($value, string $default = 'sfw'): string {
    return lawnding_tg_normalize_content_level($value, $default);
}

function lawnding_tg_normalize_user_entries($values): array {
    if (!is_array($values)) {
        return [];
    }
    $order = [];
    $entriesById = [];
    foreach ($values as $value) {
        $userId = '';
        $content = 'sfw';
        if (is_string($value) && trim($value) !== '') {
            $userId = trim($value);
        } elseif (is_array($value)) {
            $userId = isset($value['id']) && is_scalar($value['id']) ? trim((string) $value['id']) : '';
            $content = lawnding_tg_normalize_user_scope($value['content'] ?? 'sfw');
        }
        if ($userId === '' || !preg_match('/^-?\d+$/', $userId)) {
            continue;
        }
        if (!isset($entriesById[$userId])) {
            $order[] = $userId;
            $entriesById[$userId] = ['id' => $userId, 'content' => $content];
            continue;
        }
        $entriesById[$userId]['content'] = lawnding_tg_higher_content_level($entriesById[$userId]['content'], $content);
    }
    $entries = [];
    foreach ($order as $userId) {
        if (isset($entriesById[$userId])) {
            $entries[] = $entriesById[$userId];
        }
    }
    return $entries;
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
    $merged['whitelist_user_ids'] = lawnding_tg_normalize_user_entries($merged['whitelist_user_ids'] ?? []);
    $merged['blacklist_user_ids'] = lawnding_tg_normalize_user_entries($merged['blacklist_user_ids'] ?? []);

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
    $whitelistUserIds = [];
    $blacklistUserIds = [];
    if (is_array($tgConfig['group_ids'] ?? null)) {
        foreach ($tgConfig['group_ids'] as $entry) {
            if (!is_array($entry) || empty($entry['id'])) {
                continue;
            }
            $groupIds[] = (string) $entry['id'] . ':' . strtoupper(lawnding_tg_normalize_content_level($entry['content'] ?? 'sfw'));
        }
    }
    if (is_array($tgConfig['whitelist_user_ids'] ?? null)) {
        foreach ($tgConfig['whitelist_user_ids'] as $entry) {
            if (!is_array($entry) || empty($entry['id'])) {
                continue;
            }
            $whitelistUserIds[] = (string) $entry['id'] . ':' . strtoupper(lawnding_tg_normalize_user_scope($entry['content'] ?? 'sfw'));
        }
    }
    if (is_array($tgConfig['blacklist_user_ids'] ?? null)) {
        foreach ($tgConfig['blacklist_user_ids'] as $entry) {
            if (!is_array($entry) || empty($entry['id'])) {
                continue;
            }
            $blacklistUserIds[] = (string) $entry['id'] . ':' . strtoupper(lawnding_tg_normalize_user_scope($entry['content'] ?? 'sfw'));
        }
    }
    $allowedStatuses = is_array($tgConfig['allowed_statuses'] ?? null) ? array_values($tgConfig['allowed_statuses']) : [];
    sort($groupIds, SORT_NATURAL);
    sort($whitelistUserIds, SORT_NATURAL);
    sort($blacklistUserIds, SORT_NATURAL);
    sort($allowedStatuses, SORT_NATURAL);
    return hash('sha256', json_encode([
        'bot_token' => (string) ($tgConfig['bot_token'] ?? ''),
        'group_ids' => $groupIds,
        'whitelist_user_ids' => $whitelistUserIds,
        'blacklist_user_ids' => $blacklistUserIds,
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

// Converts a {sfw, nsfw} access array to a content-level string.
// Returns 'nsfw' when both flags are set, 'sfw' when only sfw, '' when neither.
// Callers that check === '' for "no access" continue to work unchanged.
function lawnding_tg_access_clearance(array $access): string {
    if (!empty($access['nsfw'])) {
        return 'nsfw';
    }
    if (!empty($access['sfw'])) {
        return 'sfw';
    }
    return '';
}

// Converts a content-level string back to a {sfw, nsfw} access array.
function lawnding_tg_access_from_clearance(string $clearance): array {
    if ($clearance === 'nsfw') {
        return ['sfw' => true, 'nsfw' => true];
    }
    if ($clearance === 'sfw') {
        return ['sfw' => true, 'nsfw' => false];
    }
    return ['sfw' => false, 'nsfw' => false];
}

function lawnding_tg_user_entry_by_id(array $entries, string $userId): ?array {
    foreach ($entries as $entry) {
        if (is_array($entry) && isset($entry['id']) && (string) $entry['id'] === $userId) {
            return $entry;
        }
    }
    return null;
}

// Applies a whitelist or blacklist entry to an access array in-place.
function lawnding_tg_apply_user_override(array &$access, ?array $entry, bool $isBlacklist): void {
    if (!$entry) {
        return;
    }
    $scope = lawnding_tg_normalize_user_scope($entry['content'] ?? 'sfw');
    if ($scope === 'nsfw') {
        $access['sfw'] = !$isBlacklist;
        $access['nsfw'] = !$isBlacklist;
        return;
    }
    $access['sfw'] = !$isBlacklist;
    // Denying SFW must also deny NSFW — NSFW requires SFW as a prerequisite, so
    // leaving nsfw:true here would let a blacklisted user bypass via lawnding_tg_access_clearance().
    if ($isBlacklist) {
        $access['nsfw'] = false;
    }
}

// Core membership check. Returns {sfw, nsfw} boolean flags for the given user.
// Whitelist entries grant access regardless of group membership; blacklist
// entries deny it. Profile is written to the cache when supplied so the
// membership cache doubles as a visitor directory (numeric ID → handle/name).
function lawnding_tg_user_access(array $tgConfig, $userId, ?array $profile = null): array {
    $token = (string) ($tgConfig['bot_token'] ?? '');
    $groupIds = is_array($tgConfig['group_ids'] ?? null) ? $tgConfig['group_ids'] : [];
    $whitelistUserIds = is_array($tgConfig['whitelist_user_ids'] ?? null) ? $tgConfig['whitelist_user_ids'] : [];
    $blacklistUserIds = is_array($tgConfig['blacklist_user_ids'] ?? null) ? $tgConfig['blacklist_user_ids'] : [];
    $allowedStatuses = is_array($tgConfig['allowed_statuses'] ?? null) ? $tgConfig['allowed_statuses'] : [];
    if ($userId === null || $userId === '') {
        return ['sfw' => false, 'nsfw' => false];
    }
    if ($token === '' && empty($groupIds) && empty($whitelistUserIds) && empty($blacklistUserIds)) {
        return ['sfw' => false, 'nsfw' => false];
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
            if (isset($cached['access']) && is_array($cached['access'])) {
                return [
                    'sfw' => !empty($cached['access']['sfw']),
                    'nsfw' => !empty($cached['access']['nsfw']),
                ];
            }
            if (isset($cached['content_level']) && is_string($cached['content_level'])) {
                return lawnding_tg_access_from_clearance(lawnding_tg_normalize_content_level($cached['content_level'], ''));
            }
            return !empty($cached['authorized']) ? ['sfw' => true, 'nsfw' => false] : ['sfw' => false, 'nsfw' => false];
        }
    }

    $access = ['sfw' => false, 'nsfw' => false];
    $statuses = [];
    if ($token !== '' && !empty($groupIds)) {
        $bot = new TgBotClient($token);
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
                $access['sfw'] = true;
                if ($groupContent === 'nsfw') {
                    $access['nsfw'] = true;
                }
            }
        }
    }

    lawnding_tg_apply_user_override($access, lawnding_tg_user_entry_by_id($whitelistUserIds, $cacheKey), false);
    lawnding_tg_apply_user_override($access, lawnding_tg_user_entry_by_id($blacklistUserIds, $cacheKey), true);

    $entry = [
        'authorized' => $access['sfw'] || $access['nsfw'],
        'content_level' => lawnding_tg_access_clearance($access),
        'access' => $access,
        'checked_at' => $now,
        'expires_at' => $now + $ttlSeconds,
        'fingerprint' => $fingerprint,
        'statuses' => $statuses,
    ];
    // Snapshot the visitor's display profile when the caller supplies it.
    if (is_array($profile)) {
        $entry['profile'] = [
            'username' => is_string($profile['username'] ?? null) ? trim((string) $profile['username']) : '',
            'first_name' => is_string($profile['first_name'] ?? null) ? trim((string) $profile['first_name']) : '',
            'last_name' => is_string($profile['last_name'] ?? null) ? trim((string) $profile['last_name']) : '',
        ];
    } elseif (isset($cache['users'][$cacheKey]['profile']) && is_array($cache['users'][$cacheKey]['profile'])) {
        $entry['profile'] = $cache['users'][$cacheKey]['profile'];
    }
    $cache['users'][$cacheKey] = $entry;
    lawnding_tg_cache_write($cache);

    return $access;
}

// Returns a content-level string ('' | 'sfw' | 'nsfw') for backward compat
// with all callers that check === '' for "no access".
function lawnding_tg_user_content_level(array $tgConfig, $userId, ?array $profile = null): string {
    return lawnding_tg_access_clearance(lawnding_tg_user_access($tgConfig, $userId, $profile));
}

// Return the union of admin permissions granted by the Telegram groups the
// user is currently a member of (per the cached membership statuses). Intended
// for consumers that have already called lawnding_tg_user_content_level() in
// the same request — this function is a pure cache reader and does NOT trigger
// API calls. If the cache is cold for this user, returns [].
function lawnding_tg_user_permissions(array $tgConfig, $userId): array {
    if ($userId === null || $userId === '') {
        return [];
    }
    $cache = lawnding_tg_cache_load();
    $cacheKey = (string) $userId;
    if (!isset($cache['users'][$cacheKey]) || !is_array($cache['users'][$cacheKey])) {
        return [];
    }
    $entry = $cache['users'][$cacheKey];
    if (empty($entry['authorized'])) {
        return [];
    }
    $statuses = isset($entry['statuses']) && is_array($entry['statuses']) ? $entry['statuses'] : [];
    $allowedStatuses = is_array($tgConfig['allowed_statuses'] ?? null) ? $tgConfig['allowed_statuses'] : [];
    $groupIds = is_array($tgConfig['group_ids'] ?? null) ? $tgConfig['group_ids'] : [];
    $eligible = lawnding_tg_eligible_permissions();
    $merged = [];
    foreach ($groupIds as $groupEntry) {
        if (!is_array($groupEntry)) {
            continue;
        }
        $groupId = (string) ($groupEntry['id'] ?? '');
        if ($groupId === '') {
            continue;
        }
        $status = isset($statuses[$groupId]) ? (string) $statuses[$groupId] : '';
        if ($status === '' || !in_array($status, $allowedStatuses, true)) {
            continue;
        }
        $groupPerms = isset($groupEntry['permissions']) && is_array($groupEntry['permissions'])
            ? $groupEntry['permissions']
            : [];
        foreach ($groupPerms as $perm) {
            if (!is_string($perm)) {
                continue;
            }
            $perm = trim($perm);
            if ($perm === '' || !in_array($perm, $eligible, true)) {
                continue;
            }
            if (!in_array($perm, $merged, true)) {
                $merged[] = $perm;
            }
        }
    }
    return $merged;
}

// Format a display-friendly name for a Telegram identity.
// Prefers @handle, falls back to first+last, then to "User #<id>".
function lawnding_format_tg_display_name(?array $tgUser, $tgUserId): string {
    $handle = is_array($tgUser) && isset($tgUser['username']) ? trim((string) $tgUser['username']) : '';
    if ($handle !== '') {
        return '@' . $handle;
    }
    $first = is_array($tgUser) && isset($tgUser['first_name']) ? trim((string) $tgUser['first_name']) : '';
    $last  = is_array($tgUser) && isset($tgUser['last_name'])  ? trim((string) $tgUser['last_name'])  : '';
    if ($first !== '') {
        return $first . ($last !== '' ? ' ' . $last : '');
    }
    if ($tgUserId !== null && $tgUserId !== '') {
        return 'User #' . (int) $tgUserId;
    }
    return '';
}

function lawnding_tg_user_authorized(array $tgConfig, $userId): bool {
    $access = lawnding_tg_user_access($tgConfig, $userId);
    return !empty($access['sfw']) || !empty($access['nsfw']);
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
