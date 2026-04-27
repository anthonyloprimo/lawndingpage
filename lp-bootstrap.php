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

// Resize an uploaded image using GD if the image exceeds the given dimensions.
// Returns true when the image was written to $destPath (resized or already within
// limits). Returns false if GD is unavailable or the operation failed — callers
// must fall back to move_uploaded_file() so the upload always succeeds.
function lawnding_gd_resize_image(string $srcPath, string $destPath, int $maxW, int $maxH): bool {
    if (!extension_loaded('gd')) {
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
    if ($origW <= $maxW && $origH <= $maxH) {
        // Already within limits — write as-is so the caller doesn't need move_uploaded_file.
        imagedestroy($src);
        return file_put_contents($destPath, $bytes, LOCK_EX) !== false;
    }
    $ratio = min($maxW / $origW, $maxH / $origH);
    $newW  = (int) round($origW * $ratio);
    $newH  = (int) round($origH * $ratio);
    $dst = imagescale($src, $newW, $newH, IMG_BICUBIC);
    imagedestroy($src);
    if (!$dst) {
        return false;
    }
    $ext = strtolower(pathinfo($destPath, PATHINFO_EXTENSION));
    ob_start();
    $ok = match ($ext) {
        'jpg', 'jpeg' => imagejpeg($dst, null, 85),
        'webp'        => imagewebp($dst, null, 85),
        'png'         => imagepng($dst),
        'gif'         => imagegif($dst),
        default       => false,
    };
    $out = ob_get_clean();
    imagedestroy($dst);
    if (!$ok || $out === false || $out === '') {
        return false;
    }
    return file_put_contents($destPath, $out, LOCK_EX) !== false;
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
