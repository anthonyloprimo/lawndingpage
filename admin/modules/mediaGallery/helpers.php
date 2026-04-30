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
    // Fallback when pane.data.json isn't set: items.json lives inside
    // the per-instance content directory so all data for one gallery
    // (metadata + source files + thumbs) is colocated under one
    // mediaGalleryContent-{paneId}/ folder. Pane renames just rename
    // that folder; no separate JSON-rename step needed.
    return 'mediaGalleryContent-' . $paneId . '/items.json';
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

function media_gallery_thumbs_dir(string $dataDir, string $paneId): string {
    return media_gallery_media_dir($dataDir, $paneId) . '/thumbs';
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

// Pure math: compute a cover-style crop window anchored on a focal
// point. Returns [cropX, cropY, cropW, cropH] in source coords. Focal
// coords are normalized 0.0-1.0 and clamp to that range. The crop
// window is also clamped to the source bounds so a focal point near an
// edge doesn't slide the window off the image.
//
// Pairs with media_gallery_focal_crop_to_temp(); split for unit
// testability without GD.
function media_gallery_focal_crop_window(int $srcW, int $srcH, int $targetW, int $targetH, float $focalX, float $focalY): array {
    if ($srcW <= 0 || $srcH <= 0 || $targetW <= 0 || $targetH <= 0) {
        return [0, 0, max(1, $srcW), max(1, $srcH)];
    }
    $ratio = max($targetW / $srcW, $targetH / $srcH);
    $cropW = $targetW / $ratio;
    $cropH = $targetH / $ratio;
    $focalX = max(0.0, min(1.0, $focalX));
    $focalY = max(0.0, min(1.0, $focalY));
    $cropOffsetX = max(0.0, min((float) $srcW - $cropW, $srcW * $focalX - $cropW / 2));
    $cropOffsetY = max(0.0, min((float) $srcH - $cropH, $srcH * $focalY - $cropH / 2));
    return [
        (int) round($cropOffsetX),
        (int) round($cropOffsetY),
        (int) round($cropW),
        (int) round($cropH),
    ];
}

// GD: crop $srcAbsPath to a focal-aware window and write a lossless PNG
// to a tempfile. Returns the temp path on success, null on failure
// (GD missing, decode failed, crop failed, write failed). Caller is
// responsible for unlinking the temp file after use.
//
// The intermediate is PNG so the bytes pass through losslessly; the
// final thumb format (webp/jpg) is selected by the caller's subsequent
// lawnding_image_resize() call.
function media_gallery_focal_crop_to_temp(string $srcAbsPath, int $targetW, int $targetH, float $focalX, float $focalY): ?string {
    if (!extension_loaded('gd')) {
        return null;
    }
    $info = @getimagesize($srcAbsPath);
    if (!is_array($info) || ($info[0] ?? 0) <= 0 || ($info[1] ?? 0) <= 0) {
        return null;
    }
    [$srcW, $srcH] = $info;
    [$cropX, $cropY, $cropW, $cropH] = media_gallery_focal_crop_window($srcW, $srcH, $targetW, $targetH, $focalX, $focalY);

    $bytes = @file_get_contents($srcAbsPath);
    if ($bytes === false) {
        return null;
    }
    $src = @imagecreatefromstring($bytes);
    if (!$src) {
        return null;
    }

    $cropped = @imagecrop($src, ['x' => $cropX, 'y' => $cropY, 'width' => $cropW, 'height' => $cropH]);
    imagedestroy($src);
    if (!$cropped) {
        return null;
    }

    $tempPath = tempnam(sys_get_temp_dir(), 'lp-focal-crop-');
    if ($tempPath === false) {
        imagedestroy($cropped);
        return null;
    }
    // tempnam created a file with no extension; rename to .png so the
    // engine's format detector picks PNG output for the intermediate.
    $tempPngPath = $tempPath . '.png';
    @rename($tempPath, $tempPngPath);
    $ok = imagepng($cropped, $tempPngPath);
    imagedestroy($cropped);
    if (!$ok) {
        @unlink($tempPngPath);
        return null;
    }
    return $tempPngPath;
}

// Derive a 1:1 400x400 thumbnail from a saved source image at
// $srcAbsPath, writing it to the gallery's thumbs/ subdir as
// media-{itemId}-thumb.{webp|jpg}. Returns the storage-form relative
// path on success, '' on any failure (video item, GD missing, resize
// returned false). Callers write the result to item.thumb; an empty
// return value lets the renderer's existing fallback chain (item.file
// when item.thumb is empty) take over.
//
// When focal coords are non-null, gallery does its own GD-side crop
// to a focal-aware window first (via focal_crop_to_temp), then hands
// the cropped intermediate to lawnding_image_resize for the final
// resize/encode. Without focal coords, the engine's centered cover
// mode is used directly. Engine stays feature-blind.
function media_gallery_derive_thumb(string $srcAbsPath, string $dataDir, string $paneId, string $itemId, bool $isVideo, ?float $focalX = null, ?float $focalY = null): string {
    if ($isVideo) {
        return '';
    }
    if (!function_exists('lawnding_image_resize')) {
        return '';
    }
    $thumbsDir = media_gallery_thumbs_dir($dataDir, $paneId);
    media_gallery_ensure_dir($thumbsDir);
    $thumbExt = function_exists('imagewebp') ? 'webp' : 'jpg';
    $thumbFilename = 'media-' . $itemId . '-thumb.' . $thumbExt;
    $thumbAbsPath = rtrim($thumbsDir, '/\\') . '/' . $thumbFilename;
    $thumbRelative = lawnding_normalize_asset_path(
        'res/data/mediaGalleryContent-' . $paneId . '/thumbs/' . $thumbFilename
    );

    if ($focalX !== null && $focalY !== null) {
        $tempPath = media_gallery_focal_crop_to_temp($srcAbsPath, 400, 400, $focalX, $focalY);
        if ($tempPath === null) {
            return '';
        }
        $resized = lawnding_image_resize($tempPath, $thumbAbsPath, 400, 400, 'maxbox');
        @unlink($tempPath);
        return $resized ? $thumbRelative : '';
    }

    if (!lawnding_image_resize($srcAbsPath, $thumbAbsPath, 400, 400, 'cover')) {
        return '';
    }
    return $thumbRelative;
}

// Distinguish admin-uploaded custom thumbnails (thumb-{itemId}.{ext},
// written by media-gallery-thumb.php) from auto-derived ones
// (media-{itemId}-thumb.{ext}, written by media_gallery_derive_thumb).
// Source-replace keeps custom thumbs intact and re-derives the rest.
// Prefix-based check is fragile to renames; if the naming conventions
// change in either thumb.php or derive_thumb above, update both at once.
function media_gallery_thumb_is_custom(string $thumbPath): bool {
    if ($thumbPath === '') {
        return false;
    }
    $basename = basename($thumbPath);
    return $basename !== '' && str_starts_with($basename, 'thumb-');
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
            'focal_x'       => isset($item['focal_x']) && is_numeric($item['focal_x']) ? (float) $item['focal_x'] : null,
            'focal_y'       => isset($item['focal_y']) && is_numeric($item['focal_y']) ? (float) $item['focal_y'] : null,
            'displayFile'   => $file !== '' ? lawnding_versioned_local_asset_url($file) : '',
            'displayThumb'  => $thumb !== '' ? lawnding_versioned_local_asset_url($thumb) : '',
        ];
    }
    return $output;
}
