<?php
// Shared helpers for background-related admin endpoints.

// Return a JSON response and terminate execution.
function backgrounds_json_response($payload, $code = 200): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

// Enforce a specific HTTP method.
function backgrounds_require_method(string $method): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        backgrounds_json_response(['error' => 'Method not allowed'], 405);
    }
}

// Allowed admin permissions used across the admin UI.
function backgrounds_allowed_permissions(): array {
    return ['full_admin', 'add_users', 'edit_users', 'remove_users', 'edit_site'];
}

// Resolve the users.json path using bootstrap config when available.
function backgrounds_users_path(): string {
    return function_exists('lawnding_config')
        ? lawnding_config('users_path', dirname(__DIR__, 3) . '/admin/users.json')
        : dirname(__DIR__, 3) . '/admin/users.json';
}

// Load users.json into an array (empty on failure).
function backgrounds_load_users(string $usersPath): array {
    if (!is_readable($usersPath)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($usersPath), true);
    return is_array($decoded) ? $decoded : [];
}

// Find a user record by username.
function backgrounds_find_user(array $users, string $username): ?array {
    foreach ($users as $user) {
        if (is_array($user) && ($user['username'] ?? '') === $username) {
            return $user;
        }
    }
    return null;
}

// Require an authenticated session and edit_site permission.
// Signature unchanged so existing callers (backgrounds-upload/delete/list)
// continue to work without edits.
function backgrounds_require_edit_site(): array {
    $adminAuthPath = function_exists('lawnding_admin_path')
        ? lawnding_admin_path('auth.php')
        : dirname(__DIR__, 3) . '/admin/auth.php';
    require_once $adminAuthPath;

    $allowed = backgrounds_allowed_permissions();
    $usersPath = backgrounds_users_path();
    $users = lawnding_load_users_file($usersPath);
    $tgConfig = lawnding_load_tg_config();

    $identity = lawnding_resolve_admin_identity($tgConfig, $users, $allowed);
    if (!$identity['isAuthenticated']) {
        backgrounds_json_response(['error' => 'Unauthorized'], 401);
    }
    if (!$identity['context']['canEditSite']) {
        backgrounds_json_response(['error' => 'Forbidden'], 403);
    }

    return [
        'authUser' => $identity['authUser'],
        'authRecord' => $identity['authRecord'],
        'permissions' => $identity['context']['currentPermissions'],
        'isFullAdmin' => $identity['context']['isFullAdmin'],
        'canEditSite' => $identity['context']['canEditSite'],
    ];
}

// Require a valid CSRF token for state-changing requests.
function backgrounds_require_csrf(): void {
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($sessionToken) || $sessionToken === '' || !is_string($postedToken) || $postedToken === '') {
        backgrounds_json_response(['error' => 'Forbidden'], 403);
    }
    if (!hash_equals($sessionToken, $postedToken)) {
        backgrounds_json_response(['error' => 'Forbidden'], 403);
    }
}

// Resolve paths used by the backgrounds endpoints.
function backgrounds_paths(): array {
    $publicDir = function_exists('lawnding_config')
        ? lawnding_config('public_dir', dirname(__DIR__, 2))
        : dirname(__DIR__, 2);
    $dataDir = function_exists('lawnding_config')
        ? lawnding_config('data_dir', $publicDir . '/res/data')
        : $publicDir . '/res/data';
    $imgDir = function_exists('lawnding_config')
        ? lawnding_config('img_dir', $publicDir . '/res/img')
        : $publicDir . '/res/img';

    return [
        'public_dir' => $publicDir,
        'data_dir' => $dataDir,
        'img_dir' => rtrim($imgDir, '/\\') . '/',
        'header_path' => $dataDir . '/header.json',
    ];
}

// Load header.json with a minimal fallback structure.
function backgrounds_load_header(string $headerPath): array {
    return array_merge(['backgrounds' => []], lawnding_read_json($headerPath));
}

// Convert raw backgrounds into a normalized payload for the UI.
function backgrounds_build_payload(array $backgroundsRaw): array {
    $backgrounds = [];
    foreach ($backgroundsRaw as $index => $bg) {
        $url = '';
        $author = '';
        $authorUrl = '';
        if (is_string($bg)) {
            $url = $bg;
        } elseif (is_array($bg)) {
            $url = $bg['url'] ?? '';
            $author = $bg['author'] ?? '';
            $authorUrl = $bg['authorUrl'] ?? '';
        }
        $url = lawnding_normalize_asset_path($url);
        $backgrounds[] = [
            'url'           => $url,
            'author'        => $author,
            'authorUrl'     => $authorUrl ?: '',
            'displayUrl'    => lawnding_make_asset_url($url),
            'index'         => $index,
            'original_size' => isset($bg['original_size']) ? (int) $bg['original_size'] : 0,
            'saved_size'    => isset($bg['saved_size']) ? (int) $bg['saved_size'] : 0,
        ];
    }
    return $backgrounds;
}
