<?php
require_once __DIR__ . '/../../../lp-bootstrap.php';

function media_gallery_ini_size_to_bytes($value): int {
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    $last = strtolower($value[strlen($value) - 1]);
    $number = (float) $value;
    switch ($last) {
        case 'g':
            $number *= 1024;
            // no break
        case 'm':
            $number *= 1024;
            // no break
        case 'k':
            $number *= 1024;
            break;
    }
    return (int) $number;
}

function media_gallery_json_response($payload, $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function media_gallery_require_method(string $method): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        media_gallery_json_response(['error' => 'Method not allowed'], 405);
    }
}

function media_gallery_allowed_permissions(): array {
    return ['full_admin', 'add_users', 'edit_users', 'remove_users', 'edit_site'];
}

function media_gallery_users_path(): string {
    return function_exists('lawnding_config')
        ? lawnding_config('users_path', dirname(__DIR__, 3) . '/admin/users.json')
        : dirname(__DIR__, 3) . '/admin/users.json';
}

function media_gallery_load_users(string $usersPath): array {
    if (!is_readable($usersPath)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($usersPath), true);
    return is_array($decoded) ? $decoded : [];
}

function media_gallery_find_user(array $users, string $username): ?array {
    foreach ($users as $user) {
        if (is_array($user) && ($user['username'] ?? '') === $username) {
            return $user;
        }
    }
    return null;
}

function media_gallery_require_edit_site(): array {
    if (empty($_SESSION['auth_user'])) {
        media_gallery_json_response(['error' => 'Unauthorized'], 401);
    }

    $authUser = (string) $_SESSION['auth_user'];
    $usersPath = media_gallery_users_path();
    $users = media_gallery_load_users($usersPath);
    $authRecord = media_gallery_find_user($users, $authUser);
    if (!$authRecord) {
        media_gallery_json_response(['error' => 'Unauthorized'], 401);
    }
    if (!empty($authRecord['read_only']) && empty($authRecord['master'])) {
        media_gallery_json_response(['error' => 'Forbidden'], 403);
    }

    $permissions = $authRecord['permissions'] ?? [];
    if (!is_array($permissions)) {
        $permissions = [];
    }
    $allowed = media_gallery_allowed_permissions();
    $permissions = array_values(array_intersect($permissions, $allowed));
    $isFullAdmin = !empty($authRecord['master']) || in_array('full_admin', $permissions, true);
    $canEditSite = $isFullAdmin || in_array('edit_site', $permissions, true);
    if (!$canEditSite) {
        media_gallery_json_response(['error' => 'Forbidden'], 403);
    }

    return [
        'authUser' => $authUser,
        'authRecord' => $authRecord,
        'permissions' => $permissions,
        'isFullAdmin' => $isFullAdmin,
        'canEditSite' => $canEditSite,
    ];
}

function media_gallery_require_csrf(): void {
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($sessionToken) || $sessionToken === '' || !is_string($postedToken) || $postedToken === '') {
        media_gallery_json_response(['error' => 'Forbidden'], 403);
    }
    if (!hash_equals($sessionToken, $postedToken)) {
        media_gallery_json_response(['error' => 'Forbidden'], 403);
    }
}

function media_gallery_paths(): array {
    $publicDir = function_exists('lawnding_config')
        ? lawnding_config('public_dir', dirname(__DIR__, 2))
        : dirname(__DIR__, 2);
    $dataDir = function_exists('lawnding_config')
        ? lawnding_config('data_dir', $publicDir . '/res/data')
        : $publicDir . '/res/data';
    $panesPath = $dataDir . '/panes.json';

    return [
        'public_dir' => $publicDir,
        'data_dir' => $dataDir,
        'panes_path' => $panesPath,
    ];
}

function media_gallery_make_asset_url($path) {
    if (!is_string($path) || $path === '') {
        return $path;
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $assetBase = '';
    if (function_exists('lawnding_config')) {
        $assetBase = (string) lawnding_config('base_url', '');
    }
    if ($assetBase === '') {
        if (empty($_SERVER['DOCUMENT_ROOT']) || !is_dir($_SERVER['DOCUMENT_ROOT'] . '/res')) {
            $assetBase = '/public';
        }
    }
    $assetBase = rtrim($assetBase, '/');
    if (str_starts_with($path, $assetBase . '/')) {
        return $path;
    }
    if (str_starts_with($path, '/res/')) {
        return $assetBase . $path;
    }
    if (str_starts_with($path, 'res/')) {
        return $assetBase !== '' ? $assetBase . '/' . $path : '/' . $path;
    }
    if (str_starts_with($path, 'public/res/')) {
        $trimmed = substr($path, strlen('public/'));
        return $assetBase !== '' ? $assetBase . '/' . $trimmed : '/' . $trimmed;
    }
    return $path;
}

function media_gallery_normalize_asset_path($path) {
    if (!is_string($path) || $path === '') {
        return $path;
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $trimmed = ltrim($path, '/');
    $baseUrl = function_exists('lawnding_config')
        ? trim((string) lawnding_config('base_url', ''), '/')
        : '';
    if ($baseUrl !== '' && str_starts_with($trimmed, $baseUrl . '/res/')) {
        return substr($trimmed, strlen($baseUrl) + 1);
    }
    if (str_starts_with($trimmed, 'public/res/')) {
        return substr($trimmed, strlen('public/'));
    }
    if (str_starts_with($trimmed, 'res/')) {
        return $trimmed;
    }
    return $path;
}

function media_gallery_is_valid_pane_id(string $paneId): bool {
    return $paneId !== '' && preg_match('/^[a-zA-Z0-9]+$/', $paneId) === 1;
}

function media_gallery_load_panes(string $panesPath): array {
    if (!is_readable($panesPath)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($panesPath), true);
    if (!is_array($decoded)) {
        return [];
    }
    $panes = $decoded['panes'] ?? $decoded;
    return is_array($panes) ? $panes : [];
}

function media_gallery_find_pane(array $panes, string $paneId): ?array {
    foreach ($panes as $pane) {
        if (!is_array($pane)) {
            continue;
        }
        if (($pane['id'] ?? '') === $paneId && ($pane['module'] ?? '') === 'mediaGallery') {
            return $pane;
        }
    }
    return null;
}

function media_gallery_pane_json_file(array $pane, string $paneId): string {
    $data = $pane['data'] ?? [];
    if (is_array($data) && !empty($data['json']) && is_string($data['json'])) {
        return $data['json'];
    }
    return $paneId . '.json';
}

function media_gallery_load_data(string $path): array {
    if (!is_readable($path)) {
        return ['items' => []];
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return ['items' => []];
    }
    if (!isset($decoded['items']) || !is_array($decoded['items'])) {
        $decoded['items'] = [];
    }
    return $decoded;
}

function media_gallery_write_data(string $path, array $payload): void {
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents($path, $encoded) === false) {
        media_gallery_json_response(['error' => 'Failed to write media gallery data'], 500);
    }
}

function media_gallery_media_dir(string $dataDir, string $paneId): string {
    return rtrim($dataDir, '/\\') . '/mediaGalleryContent-' . $paneId;
}

function media_gallery_ensure_dir(string $dir): void {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

function media_gallery_collect_ids(array $items): array {
    $ids = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = $item['id'] ?? '';
        if (is_string($id) && $id !== '') {
            $ids[$id] = true;
        }
    }
    return $ids;
}

function media_gallery_generate_id(array $existingIds): string {
    if (count($existingIds) >= 9000) {
        media_gallery_json_response(['error' => 'Media gallery is full.'], 400);
    }
    for ($i = 0; $i < 200; $i += 1) {
        $candidate = (string) random_int(1000, 9999);
        if (!isset($existingIds[$candidate])) {
            return $candidate;
        }
    }
    media_gallery_json_response(['error' => 'Unable to allocate media id.'], 500);
}

function media_gallery_find_item_index(array $items, string $itemId): int {
    foreach ($items as $index => $item) {
        if (is_array($item) && ($item['id'] ?? '') === $itemId) {
            return (int) $index;
        }
    }
    return -1;
}

function media_gallery_reindex_orders(array $items): array {
    usort($items, function ($a, $b) {
        $orderA = is_array($a) && isset($a['order']) ? (int) $a['order'] : 0;
        $orderB = is_array($b) && isset($b['order']) ? (int) $b['order'] : 0;
        return $orderA <=> $orderB;
    });
    $order = 1;
    foreach ($items as &$item) {
        if (!is_array($item)) {
            $item = [];
        }
        $item['order'] = $order;
        $order += 1;
    }
    unset($item);
    return $items;
}

function media_gallery_safe_ext(string $name): string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]+/', '', $ext);
    return $ext !== '' ? $ext : 'bin';
}

function media_gallery_abs_from_asset(string $dataDir, string $path): ?string {
    if ($path === '' || preg_match('#^https?://#i', $path)) {
        return null;
    }
    $normalized = media_gallery_normalize_asset_path($path);
    if (!is_string($normalized)) {
        return null;
    }
    $normalized = ltrim($normalized, '/');
    if (!str_starts_with($normalized, 'res/data/')) {
        return null;
    }
    $relative = substr($normalized, strlen('res/data/'));
    return rtrim($dataDir, '/\\') . '/' . $relative;
}

function media_gallery_build_payload(array $items): array {
    $output = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $file = isset($item['file']) ? (string) $item['file'] : '';
        $thumb = isset($item['thumb']) ? (string) $item['thumb'] : '';
        $output[] = [
            'id' => isset($item['id']) ? (string) $item['id'] : '',
            'type' => isset($item['type']) ? (string) $item['type'] : 'image',
            'file' => $file,
            'thumb' => $thumb,
            'title' => isset($item['title']) ? (string) $item['title'] : '',
            'order' => isset($item['order']) ? (int) $item['order'] : 0,
            'displayFile' => media_gallery_make_asset_url($file),
            'displayThumb' => $thumb !== '' ? media_gallery_make_asset_url($thumb) : '',
        ];
    }
    return $output;
}
