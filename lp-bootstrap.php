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

// Infer the base URL (subdirectory prefix) from the executing script path.
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
