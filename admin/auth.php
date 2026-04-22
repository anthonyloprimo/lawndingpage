<?php
// Centralized admin authentication/authorization helpers.
//
// Every admin entrypoint and every mutation endpoint consults
// lawnding_resolve_admin_identity() to decide whether a request is
// authenticated and what permissions it carries.
//
// Two auth sources exist and can coexist in the same PHP session:
//   - Bcrypt admin login  -> $_SESSION['auth_user'] is a real users.json username
//   - Telegram login      -> $_SESSION['tg_user_id'] / $_SESSION['tg_user']
//
// When only a Telegram session is active and its group memberships grant admin
// perms, the resolver synthesizes a record and writes $_SESSION['auth_user'] = "tg:<id>"
// (a sentinel string that cannot collide with a real username — create_admin /
// create_user actions reject tg:-prefixed usernames at signup time).
//
// Master / full_admin / read_only flags always come from the bcrypt record;
// Telegram never grants them. See the project docs auth-model section.

require_once __DIR__ . '/lib/tg-auth.php';

// Load and decode users.json. Returns the raw array or [] on failure.
function lawnding_load_users_file(string $usersPath): array {
    if (!is_readable($usersPath)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($usersPath), true);
    return is_array($decoded) ? $decoded : [];
}

// Find a user record in a loaded users.json array by exact username match.
if (!function_exists('lawnding_find_user')) {
    function lawnding_find_user($users, string $username): ?array {
        if (!is_array($users) || $username === '') {
            return null;
        }
        foreach ($users as $user) {
            if (is_array($user) && (string) ($user['username'] ?? '') === $username) {
                return $user;
            }
        }
        return null;
    }
}

// Keep only known permissions and normalize order.
if (!function_exists('lawnding_normalize_permissions')) {
    function lawnding_normalize_permissions($permissions, array $allowedPermissions): array {
        if (!is_array($permissions)) {
            return [];
        }
        return array_values(array_intersect($permissions, $allowedPermissions));
    }
}

// Permission context for a bcrypt user record. Returns the flag set
// (canEditSite, canAddUsers, etc.) every admin code path reads. Relocated
// here from public/admin/index.php so mutation endpoints can load it without
// pulling in the whole admin entrypoint.
if (!function_exists('build_permission_context')) {
    function build_permission_context($authRecord, array $allowedPermissions): array {
        $isReadOnlyUser = $authRecord && !empty($authRecord['read_only']);
        $currentPermissions = $authRecord
            ? lawnding_normalize_permissions($authRecord['permissions'] ?? [], $allowedPermissions)
            : [];
        if ($isReadOnlyUser) {
            $currentPermissions = [];
        }
        $isMasterUser = $authRecord && !empty($authRecord['master']);
        $isFullAdmin = !$isReadOnlyUser && ($isMasterUser || in_array('full_admin', $currentPermissions, true));
        if ($isFullAdmin) {
            $currentPermissions = $allowedPermissions;
        }
        return [
            'currentPermissions' => $currentPermissions,
            'isReadOnlyUser' => $isReadOnlyUser,
            'isMasterUser' => $isMasterUser,
            'isFullAdmin' => $isFullAdmin,
            'canAddUsers' => !$isReadOnlyUser && ($isFullAdmin || in_array('add_users', $currentPermissions, true)),
            'canEditUsers' => !$isReadOnlyUser && ($isFullAdmin || in_array('edit_users', $currentPermissions, true)),
            'canRemoveUsers' => !$isReadOnlyUser && ($isFullAdmin || in_array('remove_users', $currentPermissions, true)),
            'canEditSite' => !$isReadOnlyUser && ($isFullAdmin || in_array('edit_site', $currentPermissions, true)),
        ];
    }
}

// Empty context — the shape returned when a request is unauthenticated.
function lawnding_empty_permission_context(): array {
    return [
        'currentPermissions' => [],
        'isReadOnlyUser' => false,
        'isMasterUser' => false,
        'isFullAdmin' => false,
        'canAddUsers' => false,
        'canEditUsers' => false,
        'canRemoveUsers' => false,
        'canEditSite' => false,
    ];
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

// Tear down the PHP session after detecting that a Telegram-derived admin's
// group membership has lapsed. Mirrors the public-side revocation in
// public/index.php so both surfaces use the same shape of teardown.
function lawnding_admin_handle_tg_revocation(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_unset();
        session_regenerate_id(true);
    }
    if (function_exists('lawnding_flash_set')) {
        lawnding_flash_set(
            'warning',
            "You've been signed out — your access to the configured Telegram group has been revoked."
        );
    }
}

// Empty identity — returned when no valid auth is present.
function lawnding_resolve_unauthenticated(): array {
    return [
        'isAuthenticated' => false,
        'authUser' => '',
        'displayName' => '',
        'authRecord' => null,
        'sources' => [],
        'context' => lawnding_empty_permission_context(),
    ];
}

// The resolver. Produces a uniform "who is this visitor, what can they do"
// result for bcrypt admins and Telegram-derived editors alike.
//
// Contract:
//   $tgConfig            : output of lawnding_load_tg_config()
//   $users               : output of lawnding_load_users_file()
//   $allowedPermissions  : full list the system recognizes, typically
//                          ['full_admin', 'add_users', 'edit_users',
//                           'remove_users', 'edit_site']
//
// Return shape:
//   [
//     'isAuthenticated' => bool,
//     'authUser'        => string,     // 'username' or 'tg:<id>' sentinel
//     'displayName'     => string,     // for UI render
//     'authRecord'      => ?array,     // real bcrypt record or synthesized for TG-only
//     'sources'         => string[],   // subset of ['bcrypt', 'telegram']
//     'context'         => [...]       // same shape as build_permission_context()
//   ]
//
// Revocation side-effect: when a TG-derived admin's group membership has
// lapsed (lawnding_tg_user_content_level returns ''), the session is torn
// down here and an unauthenticated result is returned. Callers do NOT need
// to replicate that logic.
function lawnding_resolve_admin_identity(array $tgConfig, array $users, array $allowedPermissions): array {
    $rawAuthUser = isset($_SESSION['auth_user']) && is_string($_SESSION['auth_user'])
        ? $_SESSION['auth_user']
        : '';
    $tgUser = isset($_SESSION['tg_user']) && is_array($_SESSION['tg_user'])
        ? $_SESSION['tg_user']
        : null;
    $tgUserId = null;
    if (is_array($tgUser) && isset($tgUser['id'])) {
        $tgUserId = $tgUser['id'];
    } elseif (isset($_SESSION['tg_user_id'])) {
        $tgUserId = $_SESSION['tg_user_id'];
    }

    // Invalidate a tg:<id> sentinel when the underlying TG session is gone
    // (e.g. logout cleared tg_user_id but not auth_user). Belt-and-suspenders
    // alongside logout.php's explicit clear.
    if ($rawAuthUser !== '' && str_starts_with($rawAuthUser, 'tg:') && empty($tgUserId)) {
        $rawAuthUser = '';
    }

    // Bcrypt resolution: only if auth_user is a non-sentinel string.
    $bcryptRecord = null;
    $bcryptContext = lawnding_empty_permission_context();
    if ($rawAuthUser !== '' && !str_starts_with($rawAuthUser, 'tg:')) {
        $bcryptRecord = lawnding_find_user($users, $rawAuthUser);
        if ($bcryptRecord) {
            $bcryptContext = build_permission_context($bcryptRecord, $allowedPermissions);
        }
    }

    // Telegram resolution: revocation check + permission lookup.
    $tgPermissions = [];
    $tgActive = false;
    if (!empty($tgUserId)) {
        $contentLevel = lawnding_tg_user_content_level($tgConfig, $tgUserId, $tgUser);
        if ($contentLevel === '') {
            // Membership revoked since login. Tear down + short-circuit to unauth.
            lawnding_admin_handle_tg_revocation();
            return lawnding_resolve_unauthenticated();
        }
        $tgActive = true;
        $tgPermissions = lawnding_tg_user_permissions($tgConfig, $tgUserId);
        // Read-only bcrypt users stay read-only even if their TG identity
        // would grant permissions. Documented precedence rule.
        if ($bcryptRecord && !empty($bcryptRecord['read_only'])) {
            $tgPermissions = [];
        }
    }

    $sources = [];
    if ($bcryptRecord) {
        $sources[] = 'bcrypt';
    }
    if ($tgActive) {
        $sources[] = 'telegram';
    }

    // No bcrypt AND no TG perms -> unauthenticated.
    if (!$bcryptRecord && empty($tgPermissions)) {
        return lawnding_resolve_unauthenticated();
    }

    // Merge perms: union of bcrypt + TG, capped by the system allowlist.
    // master / full_admin / read_only come strictly from the bcrypt context.
    $merged = $bcryptContext;
    if (!empty($tgPermissions)) {
        $combined = array_values(array_unique(array_merge(
            $bcryptContext['currentPermissions'],
            $tgPermissions
        )));
        $combined = array_values(array_intersect($combined, $allowedPermissions));
        $merged['currentPermissions'] = $combined;
        $isReadOnly = $merged['isReadOnlyUser'];
        $isFullAdmin = $merged['isFullAdmin'];
        $merged['canAddUsers']    = !$isReadOnly && ($isFullAdmin || in_array('add_users',    $combined, true));
        $merged['canEditUsers']   = !$isReadOnly && ($isFullAdmin || in_array('edit_users',   $combined, true));
        $merged['canRemoveUsers'] = !$isReadOnly && ($isFullAdmin || in_array('remove_users', $combined, true));
        $merged['canEditSite']    = !$isReadOnly && ($isFullAdmin || in_array('edit_site',    $combined, true));
    }

    // Synthesize an authRecord for TG-only identities so downstream code that
    // reads $authRecord doesn't blow up. must_change_password is explicitly
    // false — otherwise the admin entrypoint's $forcePasswordChange logic
    // would trap TG users at the password-change form.
    if (!$bcryptRecord) {
        $authUser = 'tg:' . (string) $tgUserId;
        $authRecord = [
            'username' => $authUser,
            'master' => false,
            'read_only' => false,
            'must_change_password' => false,
            'permissions' => $tgPermissions,
        ];
        $displayName = lawnding_format_tg_display_name($tgUser, $tgUserId);
    } else {
        $authUser = $rawAuthUser;
        $authRecord = $bcryptRecord;
        // Bcrypt identity is canonical even when a TG session is also active.
        $displayName = (string) ($bcryptRecord['username'] ?? $authUser);
    }

    return [
        'isAuthenticated' => true,
        'authUser' => $authUser,
        'displayName' => $displayName,
        'authRecord' => $authRecord,
        'sources' => $sources,
        'context' => $merged,
    ];
}
