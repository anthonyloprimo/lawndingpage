<?php
// Centralized paths + URL base detection for drop-in installs.

$rootDir = realpath(__DIR__);
if ($rootDir === false) {
    $rootDir = __DIR__;
}
$rootDir = rtrim(str_replace('\\', '/', $rootDir), '/');

$config = [
    'root_dir' => $rootDir,
    'public_dir' => $rootDir . '/public',
    'admin_dir' => $rootDir . '/admin',
    'data_dir' => $rootDir . '/public/res/data',
    'img_dir' => $rootDir . '/public/res/img',
    'users_path' => $rootDir . '/admin/users.json',
    'base_url' => '',
];

function lawnding_detect_base_url($publicDir) {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $scriptFile = $_SERVER['SCRIPT_FILENAME'] ?? '';
    if ($scriptName === '' || $scriptFile === '') {
        return '';
    }

    $publicReal = realpath($publicDir);
    $scriptReal = realpath($scriptFile);
    if ($publicReal === false || $scriptReal === false) {
        return '';
    }

    $publicReal = rtrim(str_replace('\\', '/', $publicReal), '/');
    $scriptReal = str_replace('\\', '/', $scriptReal);
    $scriptName = str_replace('\\', '/', $scriptName);

    if (strpos($scriptReal, $publicReal . '/') !== 0 && $scriptReal !== $publicReal) {
        return '';
    }

    $relPath = ltrim(substr($scriptReal, strlen($publicReal)), '/');
    if ($relPath !== '') {
        $suffix = '/' . $relPath;
        if (str_ends_with($scriptName, $suffix)) {
            return rtrim(substr($scriptName, 0, -strlen($suffix)), '/');
        }
    }

    return rtrim(dirname($scriptName), '/');
}

$config['base_url'] = lawnding_detect_base_url($config['public_dir']);

$overridesFile = $config['root_dir'] . '/lp-overrides.php';
if (is_readable($overridesFile)) {
    $overrides = include $overridesFile;
    if (is_array($overrides)) {
        $config = array_replace($config, $overrides);
    }
}

$GLOBALS['LAWNDING_CONFIG'] = $config;

function lawnding_config($key, $default = null) {
    return $GLOBALS['LAWNDING_CONFIG'][$key] ?? $default;
}

function lawnding_join_path($base, $path = '') {
    $base = rtrim(str_replace('\\', '/', $base), '/');
    if ($path === '' || $path === null) {
        return $base;
    }
    return $base . '/' . ltrim(str_replace('\\', '/', $path), '/');
}

function lawnding_public_path($path = '') {
    return lawnding_join_path(lawnding_config('public_dir'), $path);
}

function lawnding_admin_path($path = '') {
    return lawnding_join_path(lawnding_config('admin_dir'), $path);
}

function lawnding_data_path($path = '') {
    return lawnding_join_path(lawnding_config('data_dir'), $path);
}

function lawnding_asset_url($path = '') {
    $base = rtrim(lawnding_config('base_url', ''), '/');
    $path = ltrim($path ?? '', '/');
    if ($path === '') {
        return $base === '' ? '/' : $base;
    }
    if ($base === '') {
        return '/' . $path;
    }
    return $base . '/' . $path;
}
