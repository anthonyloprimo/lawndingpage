<?php
/**
 * LawndingPage bootstrap.
 *
 * Invariants:
 * - Filesystem paths are normalized to forward slashes.
 * - Config directories have no trailing slash (so joining is consistent).
 * - base_url is a web path prefix like "" or "/subdir" (no trailing slash).
 */

// Normalize a filesystem path to forward slashes.
// Optionally trim trailing "/" for stable path joining.
function lawnding_norm_path(string $path, bool $trimTrailingSlash = true): string {
    $path = str_replace('\\', '/', $path);
    return $trimTrailingSlash ? rtrim($path, '/') : $path;
}

// Normalize a web path (SCRIPT_NAME style). Keeps leading "/" intact.
// Optionally trim trailing "/" for stable URL joining.
function lawnding_norm_web_path(string $path, bool $trimTrailingSlash = true): string {
    $path = str_replace('\\', '/', $path);
    return $trimTrailingSlash ? rtrim($path, '/') : $path;
}

// Resolve and normalize a real directory path. Returns "" if it can't be resolved.
function lawnding_real_dir(string $path): string {
    $real = realpath($path);
    return $real === false ? '' : lawnding_norm_path($real, true);
}

// Resolve and normalize a real file path. Returns "" if it can't be resolved.
function lawnding_real_file(string $path): string {
    $real = realpath($path);
    return $real === false ? '' : lawnding_norm_path($real, false);
}

// Resolve the project root directory, preferring canonical realpath.
$rootDir = realpath(__DIR__);
$rootDir = ($rootDir === false) ? __DIR__ : $rootDir;
$rootDir = lawnding_norm_path($rootDir, true);

// Base configuration values derived from the root path.
$config = [
    'root_dir' => $rootDir,
    'public_dir' => $rootDir . '/public',
    'admin_dir' => $rootDir . '/admin',
    'data_dir' => $rootDir . '/public/res/data',
    'img_dir' => $rootDir . '/public/res/img',
    'users_path' => $rootDir . '/admin/users.json',
    'base_url' => '',
    'session_cookie_name' => '',
    'session_cookie_path' => '',
    'session_cookie_domain' => '',
    'session_cookie_secure' => null,
    'session_cookie_httponly' => true,
    'session_cookie_samesite' => 'Strict',
];

// Infer the base URL (subdirectory prefix) from the executing script path.  Returns '' if being served from the web root.
function lawnding_detect_base_url(string $publicDir): string {
    // Pull routing info from the server environment.
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $scriptFile = $_SERVER['SCRIPT_FILENAME'] ?? '';
    if ($scriptName === '' || $scriptFile === '') {
        return '';
    }

    // Canonicalize paths so comparisons are reliable.
    $publicReal = lawnding_real_dir($publicDir);
    $scriptReal = lawnding_real_file($scriptFile);
    if ($publicReal === '' || $scriptReal === '') {
        return '';
    }

    // Normalize the web path for suffix trimming.
    $scriptName = lawnding_norm_web_path($scriptName, false);

    // Ensure the executed script is inside /public on disk.
    if (strpos($scriptReal, $publicReal . '/') !== 0 && $scriptReal !== $publicReal) {
        return '';
    }

    // scriptReal relative to publicReal; remove that suffix from SCRIPT_NAME.
    $relPath = ltrim(substr($scriptReal, strlen($publicReal)), '/');
    if ($relPath !== '') {
        $suffix = '/' . $relPath;
        if (str_ends_with($scriptName, $suffix)) {
            return rtrim(substr($scriptName, 0, -strlen($suffix)), '/');
        }
    }

    // Fallback: directory portion of SCRIPT_NAME.
    $dir = dirname($scriptName);
    return ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
}

// Strip internal proxy-only prefixes from a detected public base URL.
function lawnding_normalize_base_url(string $baseUrl): string {
    $baseUrl = lawnding_norm_web_path($baseUrl, true);
    if ($baseUrl === '' || $baseUrl === '/') {
        return '';
    }

    // Hestia multitenant nginx templates internally rewrite tenant requests to
    // /instances/<tenant>/public/... before proxying to PHP. That prefix is not
    // browser-facing and must never appear in generated asset URLs.
    $baseUrl = preg_replace('#^/instances/[^/]+/public(?=/|$)#', '', $baseUrl);
    if (!is_string($baseUrl) || $baseUrl === '' || $baseUrl === '/') {
        return '';
    }

    return rtrim($baseUrl, '/');
}

// Fill base_url based on current execution context.
$config['base_url'] = lawnding_normalize_base_url(lawnding_detect_base_url($config['public_dir']));

// Allow local overrides to replace any config entries.
$overridesFile = $config['root_dir'] . '/lp-overrides.php';
if (is_readable($overridesFile)) {
    // The overrides file should return an array of config keys.
    $overrides = include $overridesFile;
    if (is_array($overrides)) {
        // Merge, letting overrides take precedence.
        $config = array_replace($config, $overrides);
    }
}

// Expose config globally for simple access in legacy scripts.
$GLOBALS['LAWNDING_CONFIG'] = $config;

// Read a config key with a default fallback.
function lawnding_config(string $key, $default = null) {
    return $GLOBALS['LAWNDING_CONFIG'][$key] ?? $default;
}

// Join a base path with a relative path using forward slashes.
function lawnding_join_path(string $base, string $path = ''): string {
    $base = lawnding_norm_path($base, true);
    if ($path === '' || $path === null) {
        return $base;
    }
    return $base . '/' . ltrim(lawnding_norm_path($path, false), '/');
}

// Convenience path helpers for common directories.
function lawnding_public_path(string $path = ''): string {
    return lawnding_join_path(lawnding_config('public_dir'), $path);
}

function lawnding_admin_path(string $path = ''): string {
    return lawnding_join_path(lawnding_config('admin_dir'), $path);
}

function lawnding_data_path(string $path = ''): string {
    return lawnding_join_path(lawnding_config('data_dir'), $path);
}

// Build a URL for assets with the detected base URL prefix.
function lawnding_asset_url(?string $path = ''): string {
    $base = rtrim(lawnding_config('base_url', ''), '/');
    $path = ltrim($path ?? '', '/');
    if ($path === '') {
        return $base === '' ? '/' : $base;
    }
    return $base === '' ? '/' . $path : $base . '/' . $path;
}

// Append a `?v=<mtime>` cache-busting query string to local asset URLs under
// /res/, so admin-uploaded images (logo, backgrounds, etc.) refresh in the
// browser the moment the underlying file changes. External URLs and paths
// outside /res/ fall back to $fallbackToken (typically SITE_VERSION) when
// provided — this is what the favicon uses so cache-busting still works for
// externally-hosted favicon URLs. Pass '' to suppress the fallback (existing
// logo/background callers do this — they only want mtime versioning, no
// SITE_VERSION fallback for non-local paths).
function lawnding_versioned_local_asset_url(string $rawPath, string $fallbackToken = ''): string {
    if ($rawPath === '') {
        return '';
    }
    $appendVersion = static function (string $url, string $token): string {
        if ($token === '') {
            return $url;
        }
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . 'v=' . rawurlencode($token);
    };
    $resolveUrl = function (string $p): string {
        return function_exists('lawnding_asset_url') ? lawnding_asset_url($p) : $p;
    };
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $rawPath) || str_starts_with($rawPath, '//')) {
        return $appendVersion($rawPath, $fallbackToken);
    }
    $relPath = ltrim($rawPath, '/');
    if (str_starts_with($relPath, 'public/')) {
        $relPath = substr($relPath, strlen('public/'));
    }
    $resolved = $resolveUrl($rawPath);
    if (!str_starts_with($relPath, 'res/')) {
        return $appendVersion($resolved, $fallbackToken);
    }
    $fsPath = function_exists('lawnding_public_path')
        ? lawnding_public_path($relPath)
        : __DIR__ . '/' . $relPath;
    if (!is_file($fsPath)) {
        return $appendVersion($resolved, $fallbackToken);
    }
    $mtime = @filemtime($fsPath);
    if (!is_int($mtime) || $mtime <= 0) {
        return $appendVersion($resolved, $fallbackToken);
    }
    return $appendVersion($resolved, (string) $mtime);
}

// Decode a JSON file into an array; otherwise return the fallback. Canonical
// reader for the project's flat-file JSON storage — endpoint scripts and
// helpers should call this rather than reimplement the
// is_readable + file_get_contents + json_decode + is_array dance locally.
function lawnding_read_json($path, array $fallback = []) {
    if (!is_readable($path)) {
        return $fallback;
    }
    $decoded = json_decode(file_get_contents($path), true);
    return is_array($decoded) ? $decoded : $fallback;
}

// Resolve the URL prefix for served assets. Combines lawnding_config('base_url')
// with two legacy fallback heuristics that fire when base_url isn't detected:
// SCRIPT_NAME starting with '/public/', or DOCUMENT_ROOT not having a sibling
// 'res' dir. Either heuristic catches the case of an Apache vhost rooted at
// the repo root rather than at public/. Returns the rtrim'd base ('' for
// site-root, '/some/prefix' otherwise). Used internally by
// lawnding_make_asset_url; admin entrypoints that need the value for inline
// HTML (script/link tags) can read it directly.
function lawnding_asset_base_url(): string {
    $base = function_exists('lawnding_config')
        ? (string) lawnding_config('base_url', '')
        : '';
    if ($base === '') {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if (is_string($scriptName) && str_starts_with($scriptName, '/public/')) {
            $base = '/public';
        } elseif (empty($_SERVER['DOCUMENT_ROOT']) || !is_dir($_SERVER['DOCUMENT_ROOT'] . '/res')) {
            $base = '/public';
        }
    }
    return rtrim($base, '/');
}

// Take a stored asset path (possibly relative `res/...`, absolute `/res/...`,
// legacy `public/res/...`, or an external URL) and produce a browser-accessible
// URL. Idempotent: a path already prefixed with the configured base URL passes
// through unchanged. External URLs (http://, https://) pass through. The
// inverse (URL back to storage form) is lawnding_normalize_asset_path.
function lawnding_make_asset_url($path) {
    if (!is_string($path) || $path === '') {
        return $path;
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $assetBase = lawnding_asset_base_url();
    if ($assetBase !== '' && str_starts_with($path, $assetBase . '/')) {
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

// Normalize an asset path back to its `res/...` storage form, regardless of
// whether it's stored as a URL with the base URL prefixed or with a `public/`
// prefix or as a bare relative path. Inverse of lawnding_make_asset_url, used
// at write boundaries (save-config) so panes.json / header.json store paths
// in a consistent shape independent of the deployment's base URL.
function lawnding_normalize_asset_path($path) {
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

// One-shot session-backed flash messages, surfaced as banner notices on the
// next page render. Consumer is public/index.php (drains $_SESSION['lp_flash']
// into the #adminNotices container).
function lawnding_flash_set(string $type, string $text): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $allowed = ['ok', 'warning', 'danger'];
    if (!in_array($type, $allowed, true)) {
        $type = 'ok';
    }
    $_SESSION['lp_flash'] = ['type' => $type, 'text' => $text];
}

function lawnding_flash_consume(): ?array {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    if (!isset($_SESSION['lp_flash']) || !is_array($_SESSION['lp_flash'])) {
        return null;
    }
    $flash = $_SESSION['lp_flash'];
    unset($_SESSION['lp_flash']);
    $type = isset($flash['type']) && is_string($flash['type']) ? $flash['type'] : 'ok';
    $text = isset($flash['text']) && is_string($flash['text']) ? $flash['text'] : '';
    if ($text === '') {
        return null;
    }
    return ['type' => $type, 'text' => $text];
}

// Append-only error/event log used by the admin Diagnostics view.
// JSONL format (one entry per line) so multi-line PHP error messages don't
// fragment the file the way they would in CSV. Lives outside the webroot at
// admin/errors.jsonl. Replaces the legacy admin/errors.txt as of v1.12.5.

if (!function_exists('lawnding_logs_path')) {
    function lawnding_logs_path(): string {
        return lawnding_admin_path('errors.jsonl');
    }
}

if (!function_exists('lawnding_logs_rotate_path')) {
    function lawnding_logs_rotate_path(): string {
        return lawnding_admin_path('errors.jsonl.1');
    }
}

if (!function_exists('lawnding_logs_max_bytes')) {
    function lawnding_logs_max_bytes(): int {
        return 5 * 1024 * 1024;
    }
}

if (!function_exists('lawnding_logs_append')) {
    // Append a single entry to the JSONL log. Auto-rotates when the file
    // exceeds lawnding_logs_max_bytes(). Best-effort and silent on failure
    // because callers may run from PHP's own error handler — re-triggering
    // error reporting from inside an error handler risks infinite loops.
    function lawnding_logs_append(array $entry): void {
        $path = lawnding_logs_path();
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }
        if (is_file($path) && @filesize($path) >= lawnding_logs_max_bytes()) {
            @rename($path, lawnding_logs_rotate_path());
        }
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            return;
        }
        $created = !is_file($path);
        if (@file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            return;
        }
        if ($created) {
            @chmod($path, 0640);
        }
    }
}

if (!function_exists('lawnding_log_event')) {
    // Log an application-level event. Use this when surfacing a condition
    // PHP's error handler wouldn't normally catch — e.g. a TG group
    // revocation or a failed login attempt. Severity is one of
    // 'error', 'warn', 'info', 'debug'.
    function lawnding_log_event(string $severity, string $event, array $context = []): void {
        lawnding_logs_append([
            'ts'    => date('c'),
            'sev'   => $severity,
            'src'   => 'app',
            'event' => $event,
            'ctx'   => $context,
            'uri'   => $_SERVER['REQUEST_URI'] ?? null,
            'who'   => $_SESSION['auth_user'] ?? null,
        ]);
    }
}

if (!function_exists('lawnding_logs_php_severity_name')) {
    function lawnding_logs_php_severity_name(int $errno): string {
        return match (true) {
            (bool) ($errno & (E_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR
                            | E_CORE_ERROR | E_COMPILE_ERROR | E_PARSE)) => 'error',
            (bool) ($errno & (E_WARNING | E_USER_WARNING
                            | E_CORE_WARNING | E_COMPILE_WARNING))      => 'warn',
            default                                                      => 'info',
        };
    }
}

if (!function_exists('lawnding_logs_should_capture')) {
    function lawnding_logs_should_capture(int $errno): bool {
        // Capture E_WARNING and above; skip the noise floor (notices,
        // deprecations, strict). Sub-threshold errors fall through to PHP's
        // default error_log destination (typically the system log).
        $captured = E_ERROR | E_WARNING | E_PARSE
                  | E_CORE_ERROR | E_CORE_WARNING
                  | E_COMPILE_ERROR | E_COMPILE_WARNING
                  | E_USER_ERROR | E_USER_WARNING
                  | E_RECOVERABLE_ERROR;
        return ($errno & $captured) !== 0;
    }
}

if (!function_exists('lawnding_logs_register_handlers')) {
    function lawnding_logs_register_handlers(): void {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        set_error_handler(function (int $errno, string $msg, string $file, int $line): bool {
            if (!(error_reporting() & $errno)) {
                return false;
            }
            if (!lawnding_logs_should_capture($errno)) {
                return false;
            }
            lawnding_logs_append([
                'ts'    => date('c'),
                'sev'   => lawnding_logs_php_severity_name($errno),
                'src'   => 'php',
                'event' => 'php_error',
                'msg'   => $msg,
                'file'  => $file,
                'line'  => $line,
                'uri'   => $_SERVER['REQUEST_URI'] ?? null,
                'who'   => $_SESSION['auth_user'] ?? null,
            ]);
            return true;
        });

        register_shutdown_function(function (): void {
            $err = error_get_last();
            if (!$err) {
                return;
            }
            $fatalMask = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
            if (!($err['type'] & $fatalMask)) {
                return;
            }
            lawnding_logs_append([
                'ts'    => date('c'),
                'sev'   => 'error',
                'src'   => 'php',
                'event' => 'fatal',
                'msg'   => $err['message'] ?? '',
                'file'  => $err['file'] ?? '',
                'line'  => (int) ($err['line'] ?? 0),
                'uri'   => $_SERVER['REQUEST_URI'] ?? null,
                'who'   => $_SESSION['auth_user'] ?? null,
            ]);
        });
    }
}

// Activate handlers for the rest of the request. Idempotent.
lawnding_logs_register_handlers();

// Return the shared platform-credit HTML for the page footer: GitHub link with
// inline-SVG mark, lawnding.page link, and version string. Both the public page
// and admin panel echo this and append their own page-specific suffix.
function lawnding_footer_platform_html(): string {
    $version = defined('SITE_VERSION') ? ' ' . htmlspecialchars(SITE_VERSION, ENT_QUOTES, 'UTF-8') : '';
    $ghPath = 'M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57'
        . ' 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695'
        . '-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99'
        . '.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225'
        . '-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405'
        . 'c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225'
        . ' 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3'
        . ' 0 .315.225.69.825.57A12.02 12.02 0 0 0 24 12c0-6.63-5.37-12-12-12Z';
    return '<a href="https://github.com/anthonyloprimo/lawndingpage" class="footerGhLink"'
        . ' rel="noopener" target="_blank" aria-label="LawndingPage on GitHub">'
        . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"'
        . ' width="1em" height="1em"'
        . ' class="footerGhIcon" aria-hidden="true"><path d="' . $ghPath . '"/></svg>'
        . ' GitHub</a>'
        . '<span class="footerSep"> · </span><a href="https://lawnding.page" rel="noopener" target="_blank">lawnding.page</a>'
        . $version
        . ' <a href="#" data-changelog-trigger>[CHANGELOG]</a>';
}

// Compute resize/crop dimensions for an image.
// Returns [newW, newH, cropX, cropY, cropW, cropH] where the crop fields describe
// the source-coords region to read FROM (used by GD's imagecopyresampled), and
// newW/newH describe the output dimensions. Returns null for invalid inputs or
// unrecognized modes — callers must treat null as a failure.
//
// Modes:
//   maxbox  — fit inside box; never upscale; never crop. Pass-through when
//             source already fits.
//   contain — fit inside box; letterbox the orthogonal axis. Upscales if needed
//             so output is exactly target dims (caveat: nothing in this codebase
//             currently feeds it sources smaller than the target).
//   cover   — fill box exactly; center-crop the overflow axis. Output is always
//             exactly target dims.
function lawnding_image_resize_dimensions(int $srcW, int $srcH, int $targetW, int $targetH, string $mode): ?array {
    if ($srcW <= 0 || $srcH <= 0 || $targetW <= 0 || $targetH <= 0) {
        return null;
    }
    if ($mode === 'maxbox') {
        if ($srcW <= $targetW && $srcH <= $targetH) {
            return [$srcW, $srcH, 0, 0, $srcW, $srcH];
        }
        $ratio = min($targetW / $srcW, $targetH / $srcH);
        return [(int) round($srcW * $ratio), (int) round($srcH * $ratio), 0, 0, $srcW, $srcH];
    }
    if ($mode === 'contain') {
        $ratio = min($targetW / $srcW, $targetH / $srcH);
        return [(int) round($srcW * $ratio), (int) round($srcH * $ratio), 0, 0, $srcW, $srcH];
    }
    if ($mode === 'cover') {
        $ratio = max($targetW / $srcW, $targetH / $srcH);
        $cropW = $targetW / $ratio;
        $cropH = $targetH / $ratio;
        return [
            $targetW,
            $targetH,
            (int) round(($srcW - $cropW) / 2),
            (int) round(($srcH - $cropH) / 2),
            (int) round($cropW),
            (int) round($cropH),
        ];
    }
    return null;
}

// Pick the GD output format matching the destination file's extension.
// Returns the format token ('jpeg'|'png'|'gif'|'webp') or null if the requested
// format is unavailable in the current GD build (only 'webp' has a soft dep —
// .webp dest with $gdHasWebp=false returns null so the caller can fall back to
// a different extension).
function lawnding_image_resize_output_format(string $destPath, bool $gdHasWebp): ?string {
    $ext = strtolower(pathinfo($destPath, PATHINFO_EXTENSION));
    if ($ext === 'webp') {
        return $gdHasWebp ? 'webp' : null;
    }
    return match ($ext) {
        'jpg', 'jpeg' => 'jpeg',
        'png'         => 'png',
        'gif'         => 'gif',
        default       => null,
    };
}

// Resize (and optionally crop) an image using GD. Replaces the legacy
// lawnding_gd_resize_image() with explicit fit modes.
//
// Returns true when the image was written to $destPath. Returns false if GD is
// unavailable, the source can't be decoded, the dest extension's format is
// unsupported by the current GD build, or the file write failed — callers
// (e.g. lawnding_validate_and_save_image) must fall back to move_uploaded_file
// so the upload always succeeds.
function lawnding_image_resize(string $srcPath, string $destPath, int $width, int $height, string $mode = 'maxbox'): bool {
    if (!extension_loaded('gd')) {
        return false;
    }
    $format = lawnding_image_resize_output_format($destPath, function_exists('imagewebp'));
    if ($format === null) {
        return false;
    }
    $bytes = file_get_contents($srcPath);
    if ($bytes === false) {
        return false;
    }
    $src = @imagecreatefromstring($bytes);
    if (!$src) {
        return false;
    }
    $origW = imagesx($src);
    $origH = imagesy($src);
    $dims = lawnding_image_resize_dimensions($origW, $origH, $width, $height, $mode);
    if ($dims === null) {
        imagedestroy($src);
        return false;
    }
    [$newW, $newH, $cropX, $cropY, $cropW, $cropH] = $dims;

    // Fast path: maxbox passthrough when the source already fits and its bytes
    // are already in the dest format. Detect source format from the bytes
    // rather than the source path's extension — upload tempfiles
    // (e.g. /tmp/phpAbCdEf) have no extension, so an extension comparison
    // never matches and the fast path would never trigger for the most
    // common caller. Failing to passthrough means re-encoding small uploads
    // through GD, which subtly changes their bytes; the prior implementation
    // preserved them exactly.
    $isPassthrough = $mode === 'maxbox' && $newW === $origW && $newH === $origH
        && $cropX === 0 && $cropY === 0 && $cropW === $origW && $cropH === $origH;
    if ($isPassthrough) {
        $info = @getimagesizefromstring($bytes);
        $srcFormat = $info && isset($info[2]) ? match ((int) $info[2]) {
            IMAGETYPE_JPEG => 'jpeg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_GIF  => 'gif',
            IMAGETYPE_WEBP => 'webp',
            default        => null,
        } : null;
        if ($srcFormat !== null && $srcFormat === $format) {
            imagedestroy($src);
            return file_put_contents($destPath, $bytes, LOCK_EX) !== false;
        }
    }

    $dst = imagecreatetruecolor($newW, $newH);
    if (!$dst) {
        imagedestroy($src);
        return false;
    }
    // Preserve transparency for PNG/GIF dest.
    if ($format === 'png' || $format === 'gif') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
    }
    $copied = imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $newW, $newH, $cropW, $cropH);
    imagedestroy($src);
    if (!$copied) {
        imagedestroy($dst);
        return false;
    }

    ob_start();
    $ok = match ($format) {
        'jpeg' => imagejpeg($dst, null, 85),
        'webp' => imagewebp($dst, null, 85),
        'png'  => imagepng($dst),
        'gif'  => imagegif($dst),
    };
    $out = ob_get_clean();
    imagedestroy($dst);
    if (!$ok || $out === false || $out === '') {
        return false;
    }
    return file_put_contents($destPath, $out, LOCK_EX) !== false;
}

// Standard image MIME→extension map shared across upload endpoints.
function lawnding_image_mime_map(): array {
    return [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
}

// Validate and save an uploaded image. Combines the duplicated pipeline of
// is_uploaded_file, finfo MIME validation, MIME→ext derivation, GD resize,
// and move_uploaded_file fallback. The $allowedMimes parameter is a map of
// MIME type → file extension (use lawnding_image_mime_map() for image-only
// uploads, or add video entries for media gallery).
function lawnding_validate_and_save_image(
    array $file,
    string $destDir,
    ?string $destName,
    int $maxW,
    int $maxH,
    array $allowedMimes
): array {
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'No file uploaded.'];
    }
    $uploadSize = (int) ($file['size'] ?? 0);
    if ($uploadSize > lawnding_app_upload_max_bytes()) {
        return ['ok' => false, 'error' => 'Upload too large. Image must be under ' . lawnding_app_upload_max_label() . '.'];
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!is_string($mime) || !isset($allowedMimes[$mime])) {
        return ['ok' => false, 'error' => 'Invalid file type.'];
    }
    $ext = $allowedMimes[$mime];
    if ($destName !== null && $destName !== '') {
        $safeName = $destName . '.' . $ext;
    } else {
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME)) . '.' . $ext;
    }
    $targetPath = rtrim($destDir, '/\\') . '/' . $safeName;
    $resized = function_exists('lawnding_image_resize')
        && lawnding_image_resize($file['tmp_name'], $targetPath, $maxW, $maxH, 'maxbox');
    if (!$resized && !move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['ok' => false, 'error' => 'Failed to save uploaded file.'];
    }
    return ['ok' => true, 'filename' => $safeName];
}

// App-level upload cap. Single source of truth for both server-side
// enforcement (in upload endpoints) and the UI label rendered in
// admin module templates. PHP's upload_max_filesize / post_max_size
// can still cap below this; their lower value wins.
function lawnding_app_upload_max_bytes(): int {
    return 5 * 1024 * 1024;
}

function lawnding_app_upload_max_label(): string {
    $bytes = lawnding_app_upload_max_bytes();
    if ($bytes >= 1024 * 1024) {
        return (int) ($bytes / (1024 * 1024)) . 'MB';
    }
    if ($bytes >= 1024) {
        return (int) ($bytes / 1024) . 'KB';
    }
    return $bytes . 'B';
}

// Convert a php.ini size shorthand (e.g. "2M", "512K", "1G") to bytes. Used by
// upload endpoints to reconcile our app-level cap with the lower of
// upload_max_filesize / post_max_size from PHP's runtime config.
function lawnding_ini_size_to_bytes($value): int {
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

// Look up a module's default_icon (an SVG string) from its manifest.
// Used by the pane-icon renderer as a fallback when panes.json has no
// icon set, and by save-config.php to pre-populate newly-created panes
// so they show a sensible icon on the navbar without admin intervention.
// Returns '' when the module id is invalid, the manifest is missing,
// or the manifest has no default_icon. Caller validates SVG safety.
function lawnding_module_default_icon(string $moduleId): string {
    static $cache = [];
    if (array_key_exists($moduleId, $cache)) {
        return $cache[$moduleId];
    }
    if ($moduleId === '' || preg_match('/[^a-zA-Z0-9_-]/', $moduleId)) {
        return $cache[$moduleId] = '';
    }
    $modulesDir = lawnding_admin_path('modules');
    $manifestPath = rtrim($modulesDir, '/\\') . '/' . $moduleId . '/' . $moduleId . '.json';
    if (!is_readable($manifestPath)) {
        return $cache[$moduleId] = '';
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest)) {
        return $cache[$moduleId] = '';
    }
    $icon = $manifest['default_icon'] ?? '';
    return $cache[$moduleId] = is_string($icon) ? $icon : '';
}

// Load pane instances from panes.json. Returns the raw pane array or [] when
// the file is missing or malformed. Caller typically pipes through
// lawnding_sort_panes() before rendering.
function lawnding_load_panes(string $path): array {
    if (!is_readable($path)) {
        return [];
    }
    $decoded = json_decode(file_get_contents($path), true);
    if (!is_array($decoded) || !isset($decoded['panes']) || !is_array($decoded['panes'])) {
        return [];
    }
    return $decoded['panes'];
}

// Sort panes by explicit order while preserving original order as tie-breaker.
function lawnding_sort_panes(array $panes): array {
    $indexed = [];
    foreach ($panes as $index => $pane) {
        if (is_array($pane)) {
            $pane['_index'] = $index;
            $indexed[] = $pane;
        }
    }
    usort($indexed, function ($a, $b) {
        $orderA = isset($a['order']) ? (int) $a['order'] : PHP_INT_MAX;
        $orderB = isset($b['order']) ? (int) $b['order'] : PHP_INT_MAX;
        if ($orderA === $orderB) {
            return ($a['_index'] ?? 0) <=> ($b['_index'] ?? 0);
        }
        return $orderA <=> $orderB;
    });
    foreach ($indexed as &$pane) {
        unset($pane['_index']);
    }
    return $indexed;
}

// Minimal SVG sanitizer to block scripts and inline event handlers. Intentionally
// not exhaustive — admin-authored SVG is trusted; this gate exists to catch
// the obvious XSS shapes (`<script>` tags, on*= attributes) so they don't
// leak through panes.json into the public navbar render.
function lawnding_is_safe_svg(?string $svg): bool {
    if (!is_string($svg) || $svg === '') {
        return false;
    }
    if (stripos($svg, '<script') !== false) {
        return false;
    }
    if (preg_match('/\\son[a-z]+\\s*=\\s*["\']?/i', $svg)) {
        return false;
    }
    return true;
}

// Detect whether the current request should be treated as HTTPS.
// Checks common proxy headers so the Secure cookie flag is set correctly
// when running behind an SSL-terminating reverse proxy.
function lawnding_request_is_secure(): bool {
    $override = lawnding_config('session_cookie_secure', null);
    if ($override !== null) {
        return filter_var($override, FILTER_VALIDATE_BOOL);
    }

    $https = $_SERVER['HTTPS'] ?? '';
    if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
        return true;
    }

    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if (is_string($forwardedProto) && $forwardedProto !== '') {
        $proto = strtolower(trim(explode(',', $forwardedProto)[0]));
        if ($proto === 'https') {
            return true;
        }
    }

    $requestScheme = $_SERVER['REQUEST_SCHEME'] ?? '';
    if (is_string($requestScheme) && strtolower($requestScheme) === 'https') {
        return true;
    }

    $frontEndHttps = $_SERVER['HTTP_FRONT_END_HTTPS'] ?? '';
    if (is_string($frontEndHttps) && $frontEndHttps !== '' && strtolower($frontEndHttps) !== 'off') {
        return true;
    }

    return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

// Configure hardened session cookie params and start the session.
function lawnding_init_session(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $cookieName = trim((string) lawnding_config('session_cookie_name', ''));
    if ($cookieName !== '') {
        session_name($cookieName);
    }

    $baseUrl = (string) lawnding_config('base_url', '');
    $configuredPath = trim((string) lawnding_config('session_cookie_path', ''));
    $path = $configuredPath !== '' ? $configuredPath : ($baseUrl !== '' ? rtrim($baseUrl, '/') . '/' : '/');
    $domain = trim((string) lawnding_config('session_cookie_domain', ''));
    $secure = lawnding_request_is_secure();
    $httpOnly = (bool) lawnding_config('session_cookie_httponly', true);
    $sameSite = (string) lawnding_config('session_cookie_samesite', 'Strict');

    $cookieParams = [
        'lifetime' => 0,
        'path' => $path,
        'secure' => $secure,
        'httponly' => $httpOnly,
        'samesite' => $sameSite,
    ];
    if ($domain !== '') {
        $cookieParams['domain'] = $domain;
    }

    session_set_cookie_params($cookieParams);
    session_start();
}
