<?php
require_once __DIR__ . '/../../../lp-bootstrap.php';

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

// Signature unchanged so existing callers (media-gallery-{list,replace,
// upload,delete,thumb}.php) continue to work without edits. CSRF is
// handled inside the shared gate for POST requests; GET endpoints (list,
// thumb) skip it.
function media_gallery_require_edit_site(): array {
    $adminAuthPath = function_exists('lawnding_admin_path')
        ? lawnding_admin_path('auth.php')
        : dirname(__DIR__, 3) . '/admin/auth.php';
    require_once $adminAuthPath;

    $identity = lawnding_require_admin_mutation(
        null,
        function ($msg, $code) { media_gallery_json_response(['error' => $msg], $code); }
    );

    return [
        'authUser' => $identity['authUser'],
        'authRecord' => $identity['authRecord'],
        'permissions' => $identity['context']['currentPermissions'],
        'isFullAdmin' => $identity['context']['isFullAdmin'],
        'canEditSite' => $identity['context']['canEditSite'],
    ];
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

function media_gallery_is_valid_pane_id(string $paneId): bool {
    return $paneId !== '' && preg_match('/^[a-zA-Z0-9]+$/', $paneId) === 1;
}

function media_gallery_load_panes(string $panesPath): array {
    $decoded = lawnding_read_json($panesPath);
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
    $decoded = lawnding_read_json($path, ['items' => []]);
    if (!isset($decoded['items']) || !is_array($decoded['items'])) {
        $decoded['items'] = [];
    }
    return $decoded;
}

function media_gallery_write_data(string $path, array $payload): void {
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents($path, $encoded, LOCK_EX) === false) {
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

function media_gallery_safe_ext(string $name, string $mime = ''): string {
    if ($mime !== '') {
        static $mimeExt = [
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/gif'       => 'gif',
            'image/webp'      => 'webp',
            'video/mp4'       => 'mp4',
            'video/webm'      => 'webm',
            'video/quicktime' => 'mov',
        ];
        return $mimeExt[$mime] ?? 'bin';
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]+/', '', $ext);
    return $ext !== '' ? $ext : 'bin';
}

function media_gallery_abs_from_asset(string $dataDir, string $path): ?string {
    if ($path === '' || preg_match('#^https?://#i', $path)) {
        return null;
    }
    $normalized = lawnding_normalize_asset_path($path);
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
            'id'            => isset($item['id']) ? (string) $item['id'] : '',
            'type'          => isset($item['type']) ? (string) $item['type'] : 'image',
            'file'          => $file,
            'thumb'         => $thumb,
            'title'         => isset($item['title']) ? (string) $item['title'] : '',
            'order'         => isset($item['order']) ? (int) $item['order'] : 0,
            'original_size' => isset($item['original_size']) ? (int) $item['original_size'] : 0,
            'saved_size'    => isset($item['saved_size']) ? (int) $item['saved_size'] : 0,
            'displayFile'   => lawnding_make_asset_url($file),
            'displayThumb'  => $thumb !== '' ? lawnding_make_asset_url($thumb) : '',
        ];
    }
    return $output;
}
