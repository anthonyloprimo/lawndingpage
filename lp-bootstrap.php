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

// Fill base_url based on current execution context.
$config['base_url'] = lawnding_detect_base_url($config['public_dir']);

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

// Configure hardened session cookie params and start the session.
function lawnding_init_session(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    $baseUrl = (string) lawnding_config('base_url', '');
    $path = $baseUrl !== '' ? rtrim($baseUrl, '/') . '/' : '/';
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $path,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}
