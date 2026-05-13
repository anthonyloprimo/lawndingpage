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
function lawnding_resolve_root(string $path): string {
    $real = realpath($path);
    $resolved = $real === false ? $path : $real;
    return lawnding_norm_path($resolved, true);
}

function lawnding_file_override(string $path): array {
    if (!is_readable($path)) {
        return [];
    }
    $loaded = include $path;
    return is_array($loaded) ? $loaded : [];
}

$coreRootDir = defined('LAWNDING_CORE_ROOT')
    ? lawnding_resolve_root((string) LAWNDING_CORE_ROOT)
    : lawnding_resolve_root(__DIR__);
$instanceRootDir = defined('LAWNDING_INSTANCE_ROOT')
    ? lawnding_resolve_root((string) LAWNDING_INSTANCE_ROOT)
    : $coreRootDir;

// Base configuration values derived from the root path.
$config = [
    'core_root_dir' => $coreRootDir,
    'core_public_dir' => $coreRootDir . '/public',
    'core_admin_dir' => $coreRootDir . '/admin',
    'instance_root_dir' => $instanceRootDir,
    'instance_public_dir' => $instanceRootDir . '/public',
    'instance_admin_dir' => $instanceRootDir . '/admin',
    'instance_data_dir' => $instanceRootDir . '/data/public/res/data',
    'instance_img_dir' => $instanceRootDir . '/data/public/res/img',
    'instance_private_dir' => $instanceRootDir . '/data',
    'instance_runtime_admin_dir' => $instanceRootDir . '/data/admin',
    'instance_logs_dir' => $instanceRootDir . '/data/logs',
    'instance_state_dir' => $instanceRootDir . '/data/state',
    'instance_modules_dir' => $instanceRootDir . '/modules',
    'instance_config_path' => $instanceRootDir . '/lp-instance.php',
    'seed_root_dir' => $coreRootDir . '/resources/seed-instance',
    'core_overrides_path' => $coreRootDir . '/lp-core-overrides.php',
    'legacy_overrides_path' => $instanceRootDir . '/lp-overrides.php',
    'legacy_users_path' => $instanceRootDir . '/admin/users.json',
    'legacy_errors_path' => $instanceRootDir . '/admin/errors.txt',
    'legacy_tg_bot_path' => $instanceRootDir . '/admin/lp-tgBot.json',
    'legacy_tg_membership_cache_path' => $instanceRootDir . '/admin/lp-tgMembershipCache.json',
    'legacy_instance_data_dir' => $instanceRootDir . '/public/res/data',
    'legacy_instance_img_dir' => $instanceRootDir . '/public/res/img',
    'users_path' => $instanceRootDir . '/data/admin/users.json',
    'errors_path' => $instanceRootDir . '/data/logs/errors.txt',
    'tg_bot_path' => $instanceRootDir . '/data/admin/lp-tgBot.json',
    'tg_membership_cache_path' => $instanceRootDir . '/data/admin/lp-tgMembershipCache.json',
    'initialized_flag_path' => $instanceRootDir . '/data/state/.lawndingpage-initialized',
    // Legacy aliases retained for compatibility with the current codebase.
    'root_dir' => $instanceRootDir,
    'public_dir' => $instanceRootDir . '/public',
    'admin_dir' => $instanceRootDir . '/admin',
    'data_dir' => $instanceRootDir . '/data/public/res/data',
    'img_dir' => $instanceRootDir . '/data/public/res/img',
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
$config['base_url'] = lawnding_normalize_base_url(lawnding_detect_base_url($config['instance_public_dir']));

// Allow layered overrides to replace config entries.
$coreOverrides = lawnding_file_override($config['core_overrides_path']);
if ($coreOverrides !== []) {
    $config = array_replace($config, $coreOverrides);
}

$legacyOverrides = lawnding_file_override($config['legacy_overrides_path']);
if ($legacyOverrides !== []) {
    $config = array_replace($config, $legacyOverrides);
}

$instanceOverrides = lawnding_file_override($config['instance_config_path']);
if ($instanceOverrides !== []) {
    $config = array_replace($config, $instanceOverrides);
}

// Normalize path-like entries after overrides.
$pathKeys = [
    'core_root_dir',
    'core_public_dir',
    'core_admin_dir',
    'instance_root_dir',
    'instance_public_dir',
    'instance_admin_dir',
    'instance_data_dir',
    'instance_img_dir',
    'legacy_instance_data_dir',
    'legacy_instance_img_dir',
    'instance_private_dir',
    'instance_runtime_admin_dir',
    'instance_logs_dir',
    'instance_state_dir',
    'instance_modules_dir',
    'seed_root_dir',
    'root_dir',
    'public_dir',
    'admin_dir',
    'data_dir',
    'img_dir',
];
foreach ($pathKeys as $key) {
    if (isset($config[$key]) && is_string($config[$key]) && $config[$key] !== '') {
        $config[$key] = lawnding_norm_path($config[$key], true);
    }
}

$filePathKeys = [
    'instance_config_path',
    'core_overrides_path',
    'legacy_overrides_path',
    'legacy_users_path',
    'legacy_errors_path',
    'legacy_tg_bot_path',
    'legacy_tg_membership_cache_path',
    'users_path',
    'errors_path',
    'tg_bot_path',
    'tg_membership_cache_path',
    'initialized_flag_path',
];
foreach ($filePathKeys as $key) {
    if (isset($config[$key]) && is_string($config[$key]) && $config[$key] !== '') {
        $config[$key] = lawnding_norm_path($config[$key], false);
    }
}

// Re-sync legacy aliases in case newer keys were overridden directly.
$config['root_dir'] = lawnding_norm_path((string) ($config['instance_root_dir'] ?? $config['root_dir']), true);
$config['public_dir'] = lawnding_norm_path((string) ($config['instance_public_dir'] ?? $config['public_dir']), true);
$config['admin_dir'] = lawnding_norm_path((string) ($config['instance_admin_dir'] ?? $config['admin_dir']), true);
$config['data_dir'] = lawnding_norm_path((string) ($config['instance_data_dir'] ?? $config['data_dir']), true);
$config['img_dir'] = lawnding_norm_path((string) ($config['instance_img_dir'] ?? $config['img_dir']), true);

// Expose config globally for simple access in legacy scripts.
$GLOBALS['LAWNDING_CONFIG'] = $config;

// Read a config key with a default fallback.
function lawnding_config(string $key, $default = null) {
    return $GLOBALS['LAWNDING_CONFIG'][$key] ?? $default;
}

// Join a base path with a relative path using forward slashes.
function lawnding_join_path(string $base, string $path = ''): string {
    $base = lawnding_norm_path($base, true);
    if ($path === '') {
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
    $preferred = lawnding_join_path((string) lawnding_config('data_dir', ''), $path);
    if ($path === '' || $path === null) {
        return $preferred;
    }
    $legacyRoot = (string) lawnding_config('legacy_instance_data_dir', '');
    $legacy = $legacyRoot !== '' ? lawnding_join_path($legacyRoot, $path) : '';
    return lawnding_prefer_existing_path($preferred, $legacy);
}

function lawnding_core_path(string $path = ''): string {
    return lawnding_join_path(lawnding_config('core_root_dir'), $path);
}

function lawnding_core_public_path(string $path = ''): string {
    return lawnding_join_path(lawnding_config('core_public_dir'), $path);
}

function lawnding_core_admin_path(string $path = ''): string {
    return lawnding_join_path(lawnding_config('core_admin_dir'), $path);
}

function lawnding_instance_path(string $path = ''): string {
    return lawnding_join_path(lawnding_config('instance_root_dir'), $path);
}

function lawnding_instance_public_path(string $path = ''): string {
    return lawnding_join_path(lawnding_config('instance_public_dir'), $path);
}

function lawnding_instance_admin_path(string $path = ''): string {
    return lawnding_join_path(lawnding_config('instance_admin_dir'), $path);
}

function lawnding_instance_data_path(string $path = ''): string {
    return lawnding_join_path(lawnding_config('instance_data_dir'), $path);
}

function lawnding_instance_img_path(string $path = ''): string {
    return lawnding_join_path(lawnding_config('instance_img_dir'), $path);
}

function lawnding_instance_private_path(string $path = ''): string {
    return lawnding_join_path(lawnding_config('instance_private_dir'), $path);
}

function lawnding_instance_runtime_admin_path(string $path = ''): string {
    return lawnding_join_path(lawnding_config('instance_runtime_admin_dir'), $path);
}

function lawnding_instance_logs_path(string $path = ''): string {
    return lawnding_join_path(lawnding_config('instance_logs_dir'), $path);
}

function lawnding_instance_state_path(string $path = ''): string {
    return lawnding_join_path(lawnding_config('instance_state_dir'), $path);
}

function lawnding_instance_modules_path(string $path = ''): string {
    return lawnding_join_path(lawnding_config('instance_modules_dir'), $path);
}

function lawnding_instance_asset_path(string $path): string {
    $trimmed = ltrim($path, '/');
    if (str_starts_with($trimmed, 'public/')) {
        $trimmed = substr($trimmed, strlen('public/'));
    }
    if (str_starts_with($trimmed, 'res/data/')) {
        return lawnding_instance_data_path(substr($trimmed, strlen('res/data/')));
    }
    if (str_starts_with($trimmed, 'res/img/')) {
        return lawnding_instance_img_path(substr($trimmed, strlen('res/img/')));
    }
    return lawnding_instance_public_path($trimmed);
}

function lawnding_file_parent_dir(string $path): string {
    $dir = dirname($path);
    return $dir === '.' ? '' : lawnding_norm_path($dir, true);
}

function lawnding_ensure_dir(string $dir, int $mode = 0775): bool {
    if ($dir === '') {
        return false;
    }
    if (is_dir($dir)) {
        return true;
    }
    return @mkdir($dir, $mode, true) || is_dir($dir);
}

function lawnding_ensure_parent_dir(string $path, int $mode = 0775): bool {
    return lawnding_ensure_dir(lawnding_file_parent_dir($path), $mode);
}

function lawnding_prefer_existing_path(string $preferredPath, string $legacyPath = ''): string {
    if ($preferredPath !== '' && file_exists($preferredPath)) {
        return $preferredPath;
    }
    if ($legacyPath !== '' && file_exists($legacyPath)) {
        lawnding_register_legacy_fallback('filesystem', $legacyPath, $preferredPath);
        return $legacyPath;
    }
    return $preferredPath;
}

function lawnding_register_legacy_fallback(string $type, string $legacyValue, string $preferredValue = ''): void {
    if ($legacyValue === '') {
        return;
    }
    if (!isset($GLOBALS['LAWNDING_LEGACY_FALLBACKS']) || !is_array($GLOBALS['LAWNDING_LEGACY_FALLBACKS'])) {
        $GLOBALS['LAWNDING_LEGACY_FALLBACKS'] = [];
    }
    $key = $type . "\n" . $legacyValue . "\n" . $preferredValue;
    $GLOBALS['LAWNDING_LEGACY_FALLBACKS'][$key] = [
        'type' => $type,
        'legacy' => $legacyValue,
        'preferred' => $preferredValue,
    ];
}

function lawnding_legacy_fallback_events(): array {
    $events = $GLOBALS['LAWNDING_LEGACY_FALLBACKS'] ?? [];
    return is_array($events) ? array_values($events) : [];
}

function lawnding_normalize_legacy_public_asset_path($path) {
    if (!is_string($path) || $path === '') {
        return $path;
    }
    $trimmed = ltrim($path, '/');
    if (!str_starts_with($trimmed, 'public/res/')) {
        return $path;
    }
    $normalized = substr($trimmed, strlen('public/'));
    lawnding_register_legacy_fallback('asset', $path, $normalized);
    return str_starts_with($path, '/') ? '/' . $normalized : $normalized;
}

function lawnding_render_legacy_fallback_console_script(): string {
    $events = lawnding_legacy_fallback_events();
    if ($events === []) {
        return '';
    }
    $json = json_encode($events, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if (!is_string($json) || $json === '') {
        return '';
    }
    return "<script>(function(){var events={$json};events.forEach(function(event){if(!event||!event.type||!event.legacy){return;}var message='LawndingPage legacy fallback ('+event.type+'):';if(event.preferred){console.warn(message,event.legacy,'->',event.preferred);}else{console.warn(message,event.legacy);}});}());</script>";
}

function lawnding_runtime_file_path(string $key): string {
    $preferred = (string) lawnding_config($key, '');
    $legacyMap = [
        'users_path' => 'legacy_users_path',
        'errors_path' => 'legacy_errors_path',
        'tg_bot_path' => 'legacy_tg_bot_path',
        'tg_membership_cache_path' => 'legacy_tg_membership_cache_path',
    ];
    $legacyKey = $legacyMap[$key] ?? '';
    $legacy = $legacyKey !== '' ? (string) lawnding_config($legacyKey, '') : '';
    return lawnding_prefer_existing_path($preferred, $legacy);
}

function lawnding_runtime_migration_pairs(): array {
    $instanceRoot = (string) lawnding_config('instance_root_dir', '');
    return [
        [
            'group' => 'runtime',
            'key' => 'users_path',
            'label' => 'users.json',
            'source' => $instanceRoot !== '' ? lawnding_norm_path($instanceRoot . '/admin/users.json', false) : '',
            'destination' => $instanceRoot !== '' ? lawnding_norm_path($instanceRoot . '/data/admin/users.json', false) : '',
        ],
        [
            'group' => 'runtime',
            'key' => 'errors_path',
            'label' => 'errors.txt',
            'source' => $instanceRoot !== '' ? lawnding_norm_path($instanceRoot . '/admin/errors.txt', false) : '',
            'destination' => $instanceRoot !== '' ? lawnding_norm_path($instanceRoot . '/data/logs/errors.txt', false) : '',
        ],
        [
            'group' => 'runtime',
            'key' => 'tg_bot_path',
            'label' => 'lp-tgBot.json',
            'source' => $instanceRoot !== '' ? lawnding_norm_path($instanceRoot . '/admin/lp-tgBot.json', false) : '',
            'destination' => $instanceRoot !== '' ? lawnding_norm_path($instanceRoot . '/data/admin/lp-tgBot.json', false) : '',
        ],
        [
            'group' => 'runtime',
            'key' => 'tg_membership_cache_path',
            'label' => 'lp-tgMembershipCache.json',
            'source' => $instanceRoot !== '' ? lawnding_norm_path($instanceRoot . '/admin/lp-tgMembershipCache.json', false) : '',
            'destination' => $instanceRoot !== '' ? lawnding_norm_path($instanceRoot . '/data/admin/lp-tgMembershipCache.json', false) : '',
        ],
    ];
}

function lawnding_migrated_base_url_value($value): ?string {
    if (!is_string($value)) {
        return null;
    }
    $trimmed = rtrim($value, '/');
    if ($trimmed === '/public') {
        return '';
    }
    if (str_ends_with($trimmed, '/public')) {
        return substr($trimmed, 0, -strlen('/public'));
    }
    return $value;
}

function lawnding_override_path_map(): array {
    return [
        'data_dir' => ['/public/res/data', '/data/public/res/data', true],
        'img_dir' => ['/public/res/img', '/data/public/res/img', true],
        'instance_data_dir' => ['/public/res/data', '/data/public/res/data', true],
        'instance_img_dir' => ['/public/res/img', '/data/public/res/img', true],
        'instance_private_dir' => ['/public', '/data', true],
        'instance_runtime_admin_dir' => ['/admin', '/data/admin', true],
        'instance_logs_dir' => ['/admin', '/data/logs', true],
        'instance_state_dir' => ['/admin', '/data/state', true],
        'users_path' => ['/admin/users.json', '/data/admin/users.json', false],
        'errors_path' => ['/admin/errors.txt', '/data/logs/errors.txt', false],
        'tg_bot_path' => ['/admin/lp-tgBot.json', '/data/admin/lp-tgBot.json', false],
        'tg_membership_cache_path' => ['/admin/lp-tgMembershipCache.json', '/data/admin/lp-tgMembershipCache.json', false],
        'initialized_flag_path' => ['/admin/.lawndingpage-initialized', '/data/state/.lawndingpage-initialized', false],
    ];
}

function lawnding_migrated_path_value(string $value, string $legacySuffix, string $canonicalSuffix, bool $trimTrailingSlash): string {
    $normalized = lawnding_norm_path($value, $trimTrailingSlash);
    $legacySuffix = lawnding_norm_path($legacySuffix, $trimTrailingSlash);
    $canonicalSuffix = lawnding_norm_path($canonicalSuffix, $trimTrailingSlash);

    if ($normalized === '') {
        return $value;
    }
    if (str_ends_with($normalized, $canonicalSuffix)) {
        return $normalized;
    }
    if (!str_ends_with($normalized, $legacySuffix)) {
        return $value;
    }

    $prefix = substr($normalized, 0, -strlen($legacySuffix));
    return lawnding_norm_path($prefix . $canonicalSuffix, $trimTrailingSlash);
}

function lawnding_migrated_override_value(string $key, $value) {
    if ($key === 'base_url') {
        return lawnding_migrated_base_url_value($value);
    }
    if (!is_string($value) || $value === '') {
        return $value;
    }

    $pathMap = lawnding_override_path_map();
    if (isset($pathMap[$key])) {
        [$legacySuffix, $canonicalSuffix, $trimTrailingSlash] = $pathMap[$key];
        if (is_string($legacySuffix) && is_string($canonicalSuffix)) {
            return lawnding_migrated_path_value($value, $legacySuffix, $canonicalSuffix, (bool) $trimTrailingSlash);
        }
    }

    return $value;
}

function lawnding_config_override_update_plan(string $path): array {
    if ($path === '' || !is_readable($path)) {
        return ['needs_update' => false, 'path' => $path, 'config' => [], 'updated' => [], 'changed_keys' => []];
    }

    $config = lawnding_file_override($path);
    if ($config === []) {
        return ['needs_update' => false, 'path' => $path, 'config' => [], 'updated' => [], 'changed_keys' => []];
    }

    $updated = $config;
    $changedKeys = [];
    foreach ($config as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        $newValue = lawnding_migrated_override_value($key, $value);
        if ($newValue !== $value) {
            $updated[$key] = $newValue;
            $changedKeys[] = $key;
        }
    }

    return [
        'needs_update' => $changedKeys !== [],
        'path' => $path,
        'config' => $config,
        'updated' => $updated,
        'changed_keys' => $changedKeys,
    ];
}

function lawnding_write_override_config(string $path, array $config): bool {
    $export = var_export($config, true);
    $contents = "<?php\n// Auto-updated overrides for lp-bootstrap.php.\nreturn " . $export . ";\n";
    if (!lawnding_ensure_parent_dir($path)) {
        return false;
    }
    return file_put_contents($path, $contents, LOCK_EX) !== false;
}

function lawnding_migrate_legacy_public_asset_path($path) {
    if (!is_string($path) || $path === '') {
        return $path;
    }
    return str_replace(
        ['/public/res/', 'public/res/'],
        ['/res/', 'res/'],
        $path
    );
}

function lawnding_normalize_migrated_data_value($value, bool &$changed) {
    if (is_string($value)) {
        $normalized = lawnding_migrate_legacy_public_asset_path($value);
        if ($normalized !== $value) {
            $changed = true;
        }
        return $normalized;
    }
    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = lawnding_normalize_migrated_data_value($item, $changed);
        }
        return $normalized;
    }
    return $value;
}

function lawnding_data_file_update_plan(string $path): array {
    if ($path === '' || !is_readable($path)) {
        return ['needs_update' => false, 'path' => $path, 'contents' => null];
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return ['needs_update' => false, 'path' => $path, 'contents' => null];
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($extension === 'json') {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['needs_update' => false, 'path' => $path, 'contents' => null];
        }

        $changed = false;
        $normalized = lawnding_normalize_migrated_data_value($decoded, $changed);
        if (!$changed) {
            return ['needs_update' => false, 'path' => $path, 'contents' => null];
        }

        $encoded = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return ['needs_update' => false, 'path' => $path, 'contents' => null];
        }

        return [
            'needs_update' => true,
            'path' => $path,
            'contents' => $encoded,
        ];
    }

    $textExtensions = ['md', 'markdown', 'txt', 'html', 'htm'];
    if (!in_array($extension, $textExtensions, true)) {
        return ['needs_update' => false, 'path' => $path, 'contents' => null];
    }

    $normalized = lawnding_migrate_legacy_public_asset_path($raw);
    if ($normalized === $raw) {
        return ['needs_update' => false, 'path' => $path, 'contents' => null];
    }

    return [
        'needs_update' => true,
        'path' => $path,
        'contents' => $normalized,
    ];
}

function lawnding_collect_tree_migration_entries(string $group, string $sourceRoot, string $destinationRoot, string $labelPrefix): array {
    if ($sourceRoot === '' || $destinationRoot === '' || !is_dir($sourceRoot)) {
        return [];
    }

    $entries = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }
        $source = lawnding_norm_path($item->getPathname(), false);
        $relative = ltrim(substr($source, strlen(lawnding_norm_path($sourceRoot, true))), '/');
        if ($relative === '') {
            continue;
        }
        $entries[] = [
            'group' => $group,
            'key' => $group . ':' . $relative,
            'label' => rtrim($labelPrefix, '/') . '/' . $relative,
            'source' => $source,
            'destination' => lawnding_join_path($destinationRoot, $relative),
            'source_root' => lawnding_norm_path($sourceRoot, true),
        ];
    }

    return $entries;
}

function lawnding_migration_files_match(string $source, string $destination): bool {
    if (!is_file($source) || !is_file($destination)) {
        return false;
    }
    $sourceSize = @filesize($source);
    $destinationSize = @filesize($destination);
    if ($sourceSize !== false && $destinationSize !== false && $sourceSize !== $destinationSize) {
        return false;
    }
    $sourceHash = @md5_file($source);
    $destinationHash = @md5_file($destination);
    if (!is_string($sourceHash) || !is_string($destinationHash)) {
        return false;
    }
    return hash_equals($sourceHash, $destinationHash);
}

function lawnding_runtime_migration_choices_path(): string {
    return lawnding_instance_state_path('runtime-migration-choices.json');
}

function lawnding_runtime_migration_choices(): array {
    $path = lawnding_runtime_migration_choices_path();
    if ($path === '' || !is_readable($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function lawnding_write_runtime_migration_choices(array $choices): bool {
    $path = lawnding_runtime_migration_choices_path();
    if ($path === '' || !lawnding_ensure_parent_dir($path)) {
        return false;
    }
    return file_put_contents($path, json_encode($choices, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

function lawnding_instance_migration_entries(): array {
    $instanceRoot = (string) lawnding_config('instance_root_dir', '');
    $legacyDataRoot = $instanceRoot !== '' ? lawnding_norm_path($instanceRoot . '/public/res/data', true) : '';
    $legacyImgRoot = $instanceRoot !== '' ? lawnding_norm_path($instanceRoot . '/public/res/img', true) : '';
    $dataRoot = $instanceRoot !== '' ? lawnding_norm_path($instanceRoot . '/data/public/res/data', true) : '';
    $imgRoot = $instanceRoot !== '' ? lawnding_norm_path($instanceRoot . '/data/public/res/img', true) : '';
    $entries = lawnding_runtime_migration_pairs();
    $entries = array_merge(
        $entries,
        lawnding_collect_tree_migration_entries(
            'site_data',
            $legacyDataRoot,
            $dataRoot,
            'public/res/data'
        ),
        lawnding_collect_tree_migration_entries(
            'site_images',
            $legacyImgRoot,
            $imgRoot,
            'public/res/img'
        )
    );
    $overridePlan = lawnding_config_override_update_plan((string) lawnding_config('legacy_overrides_path', ''));
    if (!empty($overridePlan['needs_update'])) {
        $entries[] = [
            'group' => 'config',
            'key' => 'legacy_overrides_path',
            'label' => 'lp-overrides.php',
            'source' => (string) $overridePlan['path'],
            'destination' => (string) $overridePlan['path'],
            'source_root' => '',
            'requires_update' => true,
            'changed_keys' => $overridePlan['changed_keys'],
        ];
    }
    $destinationDataRoot = $dataRoot;
    if ($destinationDataRoot !== '' && is_dir($destinationDataRoot)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($destinationDataRoot, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $path = lawnding_norm_path($item->getPathname(), false);
            $plan = lawnding_data_file_update_plan($path);
            if (empty($plan['needs_update'])) {
                continue;
            }
            $relative = ltrim(substr($path, strlen(lawnding_norm_path($destinationDataRoot, true))), '/');
            $entries[] = [
                'group' => 'data_update',
                'key' => 'data_update:' . $relative,
                'label' => 'data/public/res/data/' . $relative,
                'source' => $path,
                'destination' => $path,
                'source_root' => $destinationDataRoot,
                'requires_update' => true,
            ];
        }
    }
    return $entries;
}

function lawnding_runtime_migration_status(): array {
    $pending = [];
    $conflicts = [];
    $cleanup = [];
    $all = [];
    $pendingByGroup = [];
    $conflictByGroup = [];
    $cleanupByGroup = [];

    $savedChoices = lawnding_runtime_migration_choices();

    foreach (lawnding_instance_migration_entries() as $entry) {
        $source = $entry['source'] ?? '';
        $destination = $entry['destination'] ?? '';
        if (!is_string($source) || $source === '' || !is_string($destination) || $destination === '') {
            continue;
        }

        $sourceExists = file_exists($source);
        $destinationExists = file_exists($destination);
        $group = (string) ($entry['group'] ?? 'general');
        $normalized = [
            'group' => $group,
            'key' => (string) ($entry['key'] ?? ''),
            'label' => (string) ($entry['label'] ?? basename($destination)),
            'source' => $source,
            'destination' => $destination,
            'source_exists' => $sourceExists,
            'destination_exists' => $destinationExists,
            'source_root' => (string) ($entry['source_root'] ?? ''),
            'requires_update' => !empty($entry['requires_update']),
            'changed_keys' => $entry['changed_keys'] ?? [],
            'is_conflict' => false,
            'conflict_options' => [],
        ];
        $all[] = $normalized;

        if (!empty($normalized['requires_update'])) {
            $pending[] = $normalized;
            $pendingByGroup[$group] = ($pendingByGroup[$group] ?? 0) + 1;
            continue;
        }
        if ($sourceExists && !$destinationExists) {
            $pending[] = $normalized;
            $pendingByGroup[$group] = ($pendingByGroup[$group] ?? 0) + 1;
            continue;
        }
        if ($sourceExists && $destinationExists) {
            $isSiteConflictCandidate = ($group === 'site_data' || $group === 'site_images');
            $savedChoice = (string) ($savedChoices[$normalized['key']] ?? '');
            if ($isSiteConflictCandidate && $savedChoice === 'new') {
                $cleanup[] = $normalized;
                $cleanupByGroup[$group] = ($cleanupByGroup[$group] ?? 0) + 1;
                continue;
            }
            if ($isSiteConflictCandidate && !lawnding_migration_files_match($source, $destination)) {
                $normalized['is_conflict'] = true;
                $normalized['conflict_options'] = [
                    'legacy' => 'Use legacy copy',
                    'new' => 'Keep new copy',
                ];
                $pending[] = $normalized;
                $conflicts[] = $normalized;
                $pendingByGroup[$group] = ($pendingByGroup[$group] ?? 0) + 1;
                $conflictByGroup[$group] = ($conflictByGroup[$group] ?? 0) + 1;
                continue;
            }
            $cleanup[] = $normalized;
            $cleanupByGroup[$group] = ($cleanupByGroup[$group] ?? 0) + 1;
        }
    }

    return [
        'files' => $all,
        'pending' => $pending,
        'conflicts' => $conflicts,
        'cleanup' => $cleanup,
        'pending_by_group' => $pendingByGroup,
        'conflicts_by_group' => $conflictByGroup,
        'cleanup_by_group' => $cleanupByGroup,
        'needs_migration' => $pending !== [],
        'cleanup_pending' => $pending === [] && $cleanup !== [],
    ];
}

function lawnding_run_runtime_migration(string $mode = 'copy', array $conflictChoices = []): array {
    $mode = $mode === 'move' ? 'move' : 'copy';
    $errors = [];
    $processed = [];
    $savedChoices = lawnding_runtime_migration_choices();
    if (!lawnding_sync_instance_public_scaffold()) {
        return [
            'ok' => false,
            'errors' => ['Failed to initialize public instance scaffold.'],
            'processed' => [],
            'status' => lawnding_runtime_migration_status(),
        ];
    }
    $instanceRoot = (string) lawnding_config('instance_root_dir', '');
    $canonicalDirs = [
        $instanceRoot !== '' ? lawnding_norm_path($instanceRoot . '/data', true) : '',
        $instanceRoot !== '' ? lawnding_norm_path($instanceRoot . '/data/admin', true) : '',
        $instanceRoot !== '' ? lawnding_norm_path($instanceRoot . '/data/logs', true) : '',
        $instanceRoot !== '' ? lawnding_norm_path($instanceRoot . '/data/state', true) : '',
        $instanceRoot !== '' ? lawnding_norm_path($instanceRoot . '/data/public/res/data', true) : '',
        $instanceRoot !== '' ? lawnding_norm_path($instanceRoot . '/data/public/res/img', true) : '',
        $instanceRoot !== '' ? lawnding_norm_path($instanceRoot . '/data/public/res/img/panes', true) : '',
    ];
    foreach ($canonicalDirs as $dir) {
        if ($dir === '' || !lawnding_ensure_dir($dir)) {
            return [
                'ok' => false,
                'errors' => ['Failed to initialize instance structure.'],
                'processed' => [],
                'status' => lawnding_runtime_migration_status(),
            ];
        }
    }

    $status = lawnding_runtime_migration_status();
    $entriesToProcess = $status['pending'];

    foreach ($entriesToProcess as $entry) {
        $source = (string) ($entry['source'] ?? '');
        $destination = (string) ($entry['destination'] ?? '');
        $label = (string) ($entry['label'] ?? basename($destination));
        $group = (string) ($entry['group'] ?? '');
        $entryKey = (string) ($entry['key'] ?? '');
        if ($source === '' || $destination === '') {
            continue;
        }
        if ($group === 'config') {
            $plan = lawnding_config_override_update_plan($source);
            if (empty($plan['needs_update'])) {
                continue;
            }
            if (!lawnding_write_override_config($source, $plan['updated'])) {
                $errors[] = 'Failed to update ' . $label . '.';
                continue;
            }
            $processed[] = $entry;
            continue;
        }
        if ($group === 'data_update') {
            $plan = lawnding_data_file_update_plan($source);
            if (empty($plan['needs_update']) || !is_string($plan['contents'])) {
                continue;
            }
            if (file_put_contents($source, $plan['contents'], LOCK_EX) === false) {
                $errors[] = 'Failed to update ' . $label . '.';
                continue;
            }
            $processed[] = $entry;
            continue;
        }
        if (!lawnding_ensure_parent_dir($destination)) {
            $errors[] = 'Failed to create destination directory for ' . $label . '.';
            continue;
        }
        if (!empty($entry['is_conflict'])) {
            $choice = (string) ($conflictChoices[$entryKey] ?? 'legacy');
            if ($choice !== 'legacy' && $choice !== 'new') {
                $errors[] = 'Migration choice missing for ' . $label . '.';
                continue;
            }
            if ($choice === 'new') {
                $savedChoices[$entryKey] = 'new';
                $processed[] = $entry + ['conflict_choice' => 'new'];
                continue;
            }
            $savedChoices[$entryKey] = 'legacy';
        }
        $shouldReplace = ($group === 'site_data' || $group === 'site_images') && file_exists($destination);
        if ($shouldReplace && !@unlink($destination)) {
            $errors[] = 'Failed to replace existing destination for ' . $label . '.';
            continue;
        }
        $ok = false;
        if ($mode === 'move' && !$shouldReplace) {
            $ok = @rename($source, $destination);
        } else {
            $ok = @copy($source, $destination);
            if ($ok && $mode === 'move') {
                @unlink($source);
            }
        }
        if (!$ok) {
            $errors[] = 'Failed to ' . $mode . ' ' . $label . '.';
            continue;
        }
        $processed[] = $entry;
    }

    if ($errors === [] && !lawnding_write_runtime_migration_choices($savedChoices)) {
        $errors[] = 'Failed to save migration conflict choices.';
    }

    $postCopyStatus = lawnding_runtime_migration_status();
    foreach ($postCopyStatus['pending'] as $entry) {
        $source = (string) ($entry['source'] ?? '');
        $label = (string) ($entry['label'] ?? basename($source));
        $group = (string) ($entry['group'] ?? '');
        if ($source === '') {
            continue;
        }
        if ($group === 'config') {
            $plan = lawnding_config_override_update_plan($source);
            if (empty($plan['needs_update'])) {
                continue;
            }
            if (!lawnding_write_override_config($source, $plan['updated'])) {
                $errors[] = 'Failed to update ' . $label . '.';
                continue;
            }
            $processed[] = $entry;
            continue;
        }
        if ($group === 'data_update') {
            $plan = lawnding_data_file_update_plan($source);
            if (empty($plan['needs_update']) || !is_string($plan['contents'])) {
                continue;
            }
            if (file_put_contents($source, $plan['contents'], LOCK_EX) === false) {
                $errors[] = 'Failed to update ' . $label . '.';
                continue;
            }
            $processed[] = $entry;
        }
    }

    $flagPath = $instanceRoot !== '' ? lawnding_norm_path($instanceRoot . '/data/state/.lawndingpage-initialized', false) : '';
    if ($flagPath !== '' && lawnding_ensure_parent_dir($flagPath)) {
        @touch($flagPath);
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'processed' => $processed,
        'status' => lawnding_runtime_migration_status(),
    ];
}

function lawnding_prune_empty_dirs(string $dir, string $stopRoot): void {
    $dir = lawnding_norm_path($dir, true);
    $stopRoot = lawnding_norm_path($stopRoot, true);
    while ($dir !== '' && str_starts_with($dir . '/', $stopRoot . '/')) {
        if (!is_dir($dir)) {
            $dir = lawnding_file_parent_dir($dir);
            continue;
        }
        $entries = scandir($dir);
        if ($entries === false || count($entries) > 2) {
            return;
        }
        @rmdir($dir);
        $dir = lawnding_file_parent_dir($dir);
    }

    if ($stopRoot !== '' && is_dir($stopRoot)) {
        $entries = scandir($stopRoot);
        if ($entries !== false && count($entries) <= 2) {
            @rmdir($stopRoot);
        }
    }
}

function lawnding_finalize_runtime_migration(): array {
    $errors = [];
    $removed = [];
    $status = lawnding_runtime_migration_status();

    if (!empty($status['pending'])) {
        return [
            'ok' => false,
            'errors' => ['Runtime migration is still incomplete. Run the migration before finalizing it.'],
            'removed' => [],
            'status' => $status,
        ];
    }

    foreach ($status['cleanup'] as $entry) {
        $source = (string) ($entry['source'] ?? '');
        $label = (string) ($entry['label'] ?? basename($source));
        if ($source === '' || !file_exists($source)) {
            continue;
        }
        if (!@unlink($source)) {
            $errors[] = 'Failed to delete legacy file ' . $label . '.';
            continue;
        }
        $sourceRoot = (string) ($entry['source_root'] ?? '');
        if ($sourceRoot !== '') {
            lawnding_prune_empty_dirs(dirname($source), $sourceRoot);
        }
        $removed[] = $entry;
    }

    $choicesPath = lawnding_runtime_migration_choices_path();
    if ($choicesPath !== '' && file_exists($choicesPath)) {
        @unlink($choicesPath);
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'removed' => $removed,
        'status' => lawnding_runtime_migration_status(),
    ];
}

function lawnding_module_roots(): array {
    $roots = [];
    $instanceModules = (string) lawnding_config('instance_modules_dir', '');
    $coreModules = lawnding_core_admin_path('modules');
    foreach ([$instanceModules, $coreModules] as $root) {
        if ($root === '') {
            continue;
        }
        $normalized = lawnding_norm_path($root, true);
        if (!in_array($normalized, $roots, true)) {
            $roots[] = $normalized;
        }
    }
    return $roots;
}

function lawnding_module_dir(string $moduleId): string {
    if ($moduleId === '') {
        return '';
    }
    foreach (lawnding_module_roots() as $root) {
        $dir = $root . '/' . $moduleId;
        if (is_dir($dir)) {
            return $dir;
        }
    }
    return '';
}

function lawnding_module_manifest_path(string $moduleId): string {
    $dir = lawnding_module_dir($moduleId);
    if ($dir === '') {
        return '';
    }
    $path = $dir . '/' . $moduleId . '.json';
    return is_readable($path) ? $path : '';
}

function lawnding_module_origin(string $moduleId): string {
    $dir = lawnding_module_dir($moduleId);
    if ($dir === '') {
        return '';
    }
    $instanceRoot = lawnding_norm_path((string) lawnding_config('instance_modules_dir', ''), true);
    return $instanceRoot !== '' && str_starts_with($dir, $instanceRoot . '/') ? 'instance' : 'core';
}

function lawnding_module_file(string $moduleId, string $file): string {
    $dir = lawnding_module_dir($moduleId);
    if ($dir === '') {
        return '';
    }
    $path = $dir . '/' . ltrim($file, '/');
    return is_readable($path) ? $path : '';
}

function lawnding_module_is_override(string $moduleId): bool {
    if ($moduleId === '') {
        return false;
    }
    if (lawnding_module_origin($moduleId) !== 'instance') {
        return false;
    }
    $corePath = lawnding_core_admin_path('modules/' . $moduleId . '/' . $moduleId . '.json');
    return is_readable($corePath);
}

function lawnding_seed_path(string $path = ''): string {
    return lawnding_join_path(lawnding_config('seed_root_dir'), $path);
}

function lawnding_copy_file_if_missing(string $source, string $destination): bool {
    if (file_exists($destination)) {
        return true;
    }
    if (!is_readable($source)) {
        return false;
    }
    if (!lawnding_ensure_parent_dir($destination)) {
        return false;
    }
    return @copy($source, $destination);
}

function lawnding_sync_instance_public_scaffold(): bool {
    $publicDir = (string) lawnding_config('instance_public_dir', '');
    if ($publicDir === '' || !lawnding_ensure_dir($publicDir)) {
        return false;
    }

    $publicHtaccess = lawnding_instance_public_path('.htaccess');
    $candidates = [
        lawnding_seed_path('public/.htaccess'),
        lawnding_core_public_path('.htaccess'),
    ];

    foreach ($candidates as $source) {
        if (is_string($source) && $source !== '' && lawnding_copy_file_if_missing($source, $publicHtaccess)) {
            return true;
        }
    }

    return file_exists($publicHtaccess);
}

function lawnding_instance_initialized(): bool {
    return file_exists((string) lawnding_config('initialized_flag_path', ''));
}

function lawnding_initialize_instance_if_needed(): bool {
    if (!lawnding_sync_instance_public_scaffold()) {
        return false;
    }

    if (lawnding_instance_initialized()) {
        return true;
    }

    $directories = [
        lawnding_config('instance_private_dir'),
        lawnding_config('instance_runtime_admin_dir'),
        lawnding_config('instance_logs_dir'),
        lawnding_config('instance_state_dir'),
        lawnding_config('instance_data_dir'),
        lawnding_config('instance_img_dir'),
        lawnding_instance_img_path('panes'),
    ];
    foreach ($directories as $dir) {
        if (!is_string($dir) || $dir === '' || !lawnding_ensure_dir($dir)) {
            return false;
        }
    }

    $seedDataDir = lawnding_seed_path('public/res/data');
    $seedDataFiles = is_dir($seedDataDir) ? (glob(rtrim($seedDataDir, '/\\') . '/*') ?: []) : [];
    foreach ($seedDataFiles as $source) {
        if (!is_file($source)) {
            continue;
        }
        $destination = lawnding_instance_data_path(basename($source));
        if (!lawnding_copy_file_if_missing($source, $destination)) {
            return false;
        }
    }

    $seedAssetMap = [
        lawnding_seed_path('public/res/img/logo.jpg') => lawnding_instance_img_path('logo.jpg'),
        lawnding_seed_path('public/res/img/bg.jpg') => lawnding_instance_img_path('bg.jpg'),
    ];
    foreach ($seedAssetMap as $source => $destination) {
        if (!lawnding_copy_file_if_missing($source, $destination)) {
            return false;
        }
    }

    $flagPath = (string) lawnding_config('initialized_flag_path', '');
    if ($flagPath === '' || !lawnding_ensure_parent_dir($flagPath)) {
        return false;
    }
    return @touch($flagPath);
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

function lawnding_instance_asset_base_url(): string {
    return rtrim((string) lawnding_config('base_url', ''), '/');
}

function lawnding_instance_asset_url(?string $path = ''): string {
    $path = ltrim((string) ($path ?? ''), '/');
    if ($path !== '' && str_starts_with($path, 'public/')) {
        $path = substr($path, strlen('public/'));
    }
    if ($path === '') {
        $base = rtrim(lawnding_instance_asset_base_url(), '/');
        return $base === '' ? '/' : $base;
    }
    $base = rtrim(lawnding_instance_asset_base_url(), '/');
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
    $fsPath = function_exists('lawnding_instance_asset_path')
        ? lawnding_instance_asset_path($relPath)
        : (function_exists('lawnding_public_path')
            ? lawnding_public_path($relPath)
            : __DIR__ . '/' . $relPath);
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
        return function_exists('lawnding_instance_logs_path')
            ? lawnding_instance_logs_path('errors.jsonl')
            : lawnding_admin_path('errors.jsonl');
    }
}

if (!function_exists('lawnding_logs_rotate_path')) {
    function lawnding_logs_rotate_path(): string {
        return function_exists('lawnding_instance_logs_path')
            ? lawnding_instance_logs_path('errors.jsonl.1')
            : lawnding_admin_path('errors.jsonl.1');
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
                'msg'   => $err['message'],
                'file'  => $err['file'],
                'line'  => $err['line'],
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
/**
 * Compute the resize/crop dimensions for a given mode. Returns a
 * 6-tuple `[destW, destH, cropX, cropY, cropW, cropH]` for use by
 * imagecopyresampled, or null when inputs are invalid or the mode is
 * unrecognized.
 *
 * Modes:
 *   maxbox  — fit inside box; never upscale; never crop. Pass-through
 *             when source already fits.
 *   contain — fit inside box; letterbox the orthogonal axis. Upscales
 *             if needed so output is exactly target dims (caveat:
 *             nothing in this codebase currently feeds it sources
 *             smaller than the target).
 *   cover   — fill box exactly; center-crop the overflow axis. Output
 *             is always exactly target dims.
 *
 * @return array{int, int, int, int, int, int}|null
 */
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

/**
 * Pick the GD output format matching the destination file's extension.
 * Returns null when the dest extension is unrecognized or when the
 * format is unavailable in the current GD build (only 'webp' has a
 * soft dep — `.webp` dest with $gdHasWebp=false returns null so the
 * caller can fall back to a different extension).
 *
 * @return 'jpeg'|'png'|'gif'|'webp'|null
 */
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
        $srcFormat = $info ? match ($info[2]) {
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
    $siteConfig = lawnding_load_site_config();
    $mb = isset($siteConfig['_app']['maxUploadSizeMB']) ? (int) $siteConfig['_app']['maxUploadSizeMB'] : 5;
    if ($mb <= 0) {
        $mb = 5;
    }
    return $mb * 1024 * 1024;
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

// Resolve the absolute path of a module's per-pane settings sidecar for a
// specific pane id. The module's manifest declares the path pattern via a
// `settings_file` field with a `{paneId}` placeholder; this helper reads
// the manifest, substitutes the placeholder, and prepends the data dir.
// Returns '' when the module has no settings_file declaration (modules
// without per_pane_settings don't need a sidecar). Validates moduleId and
// paneId character sets to block path traversal. Per-request static cache
// keyed on module + pane.
function lawnding_module_settings_path(string $moduleId, string $paneId): string {
    static $cache = [];
    $cacheKey = $moduleId . "\x00" . $paneId;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    if ($moduleId === '' || preg_match('/[^a-zA-Z0-9_-]/', $moduleId)) {
        return $cache[$cacheKey] = '';
    }
    if ($paneId === '' || preg_match('/[^a-zA-Z0-9]/', $paneId)) {
        return $cache[$cacheKey] = '';
    }
    $modulesDir = lawnding_admin_path('modules');
    $manifestPath = rtrim($modulesDir, '/\\') . '/' . $moduleId . '/' . $moduleId . '.json';
    if (!is_readable($manifestPath)) {
        return $cache[$cacheKey] = '';
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest)) {
        return $cache[$cacheKey] = '';
    }
    $pattern = $manifest['settings_file'] ?? '';
    if (!is_string($pattern) || $pattern === '' || strpos($pattern, '..') !== false) {
        return $cache[$cacheKey] = '';
    }
    $relative = str_replace('{paneId}', $paneId, $pattern);
    $dataDir = lawnding_data_path('');
    return $cache[$cacheKey] = rtrim($dataDir, '/\\') . '/' . ltrim($relative, '/');
}

// Read a module's per_pane_settings declaration from its manifest. Each
// entry: { key, label, type } where type is currently always 'bool'. The
// shared per-pane Settings modal (admin/config.php) walks this to render
// module-specific override controls; modules that don't declare per_pane_
// settings get only the universal icon section in the modal. Per-request
// static cache keyed by module id; module id whitelisted to [a-zA-Z0-9_-]
// to block path traversal. Invalid entries are silently dropped (defensive
// against hand-edited manifests); the function returns [] rather than
// surfacing errors so the modal still renders.
function lawnding_module_per_pane_settings(string $moduleId): array {
    static $cache = [];
    if (array_key_exists($moduleId, $cache)) {
        return $cache[$moduleId];
    }
    if ($moduleId === '' || preg_match('/[^a-zA-Z0-9_-]/', $moduleId)) {
        return $cache[$moduleId] = [];
    }
    $modulesDir = lawnding_admin_path('modules');
    $manifestPath = rtrim($modulesDir, '/\\') . '/' . $moduleId . '/' . $moduleId . '.json';
    if (!is_readable($manifestPath)) {
        return $cache[$moduleId] = [];
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest) || !isset($manifest['per_pane_settings']) || !is_array($manifest['per_pane_settings'])) {
        return $cache[$moduleId] = [];
    }
    $allowedTypes = ['bool'];
    $clean = [];
    $seenKeys = [];
    foreach ($manifest['per_pane_settings'] as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $key = isset($entry['key']) ? (string) $entry['key'] : '';
        $label = isset($entry['label']) ? (string) $entry['label'] : '';
        $type = isset($entry['type']) ? (string) $entry['type'] : '';
        if ($key === '' || preg_match('/[^a-zA-Z0-9_]/', $key) || $label === '' || !in_array($type, $allowedTypes, true)) {
            continue;
        }
        if (isset($seenKeys[$key])) {
            continue;
        }
        $seenKeys[$key] = true;
        $clean[] = ['key' => $key, 'label' => $label, 'type' => $type];
    }
    return $cache[$moduleId] = $clean;
}

/**
 * Read a module's manifest as a decoded array. Returns [] when the
 * manifest is missing, unreadable, or not valid JSON. Per-request
 * static cache keyed by module id; module id whitelisted to
 * `[a-zA-Z0-9_-]` to block path traversal. Centralizes the read-and-
 * decode dance so the manifest-reader helpers below don't each
 * re-implement it.
 *
 * The returned shape is module-specific (each module defines its own
 * manifest keys per the schema in admin/modules/README.md), so callers
 * typically read into specific keys rather than treating the whole
 * shape as canonical.
 *
 * @return array<string, mixed>
 */
function lawnding_module_manifest(string $moduleId): array {
    static $cache = [];
    if (array_key_exists($moduleId, $cache)) {
        return $cache[$moduleId];
    }
    if ($moduleId === '' || preg_match('/[^a-zA-Z0-9_-]/', $moduleId)) {
        return $cache[$moduleId] = [];
    }
    $modulesDir = lawnding_admin_path('modules');
    $manifestPath = rtrim($modulesDir, '/\\') . '/' . $moduleId . '/' . $moduleId . '.json';
    if (!is_readable($manifestPath)) {
        return $cache[$moduleId] = [];
    }
    $decoded = json_decode((string) file_get_contents($manifestPath), true);
    return $cache[$moduleId] = is_array($decoded) ? $decoded : [];
}

// Walk admin/modules/ and return the ids of every discoverable module.
// "Discoverable" = directory contains a matching <id>.json manifest AND no
// IGNORE_ME.txt sentinel (the convention used by _template/ to opt out of
// discovery). Per-request memoized. Result is sorted for deterministic
// iteration order so manifest-walker output (Site Config defaults, labels)
// is stable across requests.
function lawnding_discover_module_ids(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $modulesDir = lawnding_admin_path('modules');
    if (!is_dir($modulesDir)) {
        return $cache = [];
    }
    $entries = @scandir($modulesDir);
    if (!is_array($entries)) {
        return $cache = [];
    }
    $ids = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (preg_match('/[^a-zA-Z0-9_-]/', $entry)) {
            continue;
        }
        $dir = rtrim($modulesDir, '/\\') . '/' . $entry;
        if (!is_dir($dir)) {
            continue;
        }
        if (is_file($dir . '/IGNORE_ME.txt')) {
            continue;
        }
        if (!is_readable($dir . '/' . $entry . '.json')) {
            continue;
        }
        $ids[] = $entry;
    }
    sort($ids);
    return $cache = $ids;
}

// Resolve a module's per-pane content directory (e.g. mediaGalleryContent-
// <paneId>) to an absolute filesystem path under data_dir. The manifest
// declares the relative pattern via `pane_content_dir` with a `{paneId}`
// placeholder. Returns '' when the module declares no pane_content_dir
// (most modules don't need one) or when the pane id is malformed.
// Path-traversal guards: pane id whitelisted to [a-zA-Z0-9]; pattern
// rejected if it contains "..".
function lawnding_module_pane_content_dir(string $moduleId, string $paneId): string {
    if ($paneId === '' || preg_match('/[^a-zA-Z0-9]/', $paneId)) {
        return '';
    }
    $manifest = lawnding_module_manifest($moduleId);
    $pattern = isset($manifest['pane_content_dir']) ? (string) $manifest['pane_content_dir'] : '';
    if ($pattern === '' || strpos($pattern, '..') !== false) {
        return '';
    }
    $relative = str_replace('{paneId}', $paneId, $pattern);
    $dataDir = lawnding_data_path('');
    return rtrim($dataDir, '/\\') . '/' . ltrim($relative, '/');
}

// Look up a module's `on_rename` callback name from its manifest. Returns
// '' when not declared. Caller is responsible for require'ing the module's
// helpers.php (via lawnding_module_load_helpers) and verifying with
// function_exists() before invoking. The callback signature is
// fn(string $prevId, string $currentId, array $context): void where
// $context carries `data_dir` and `manifest`.
function lawnding_module_on_rename_callback(string $moduleId): string {
    $manifest = lawnding_module_manifest($moduleId);
    $name = isset($manifest['on_rename']) ? (string) $manifest['on_rename'] : '';
    return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) === 1 ? $name : '';
}

// Look up a module's save_map validator function name for a given form key.
// The manifest declares `save_map[].validator` for entries that need
// custom merge/validate logic instead of plain JSON write-through. Returns
// '' when no validator is declared. Caller require's helpers.php and
// function_exists-checks. Callback signature: fn(array $existing, array
// $payload): array — returns the merged payload to persist.
function lawnding_module_save_map_validator(string $moduleId, string $key): string {
    $manifest = lawnding_module_manifest($moduleId);
    $entries = $manifest['save_map'] ?? [];
    if (!is_array($entries)) {
        return '';
    }
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if (isset($entry['key']) && (string) $entry['key'] === $key) {
            $name = isset($entry['validator']) ? (string) $entry['validator'] : '';
            return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) === 1 ? $name : '';
        }
    }
    return '';
}

// Look up the `initial` payload declared on a module's data_files entry
// matching $pattern. Returns null when no initial is declared (caller
// should fall back to the empty-object default). The manifest's `initial`
// is whatever JSON value the module wants its first-write to contain
// (e.g. {"items": []} for mediaGallery).
function lawnding_module_data_file_initial(string $moduleId, string $pattern): ?array {
    $manifest = lawnding_module_manifest($moduleId);
    $entries = $manifest['data_files'] ?? [];
    if (!is_array($entries)) {
        return null;
    }
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if (isset($entry['pattern']) && (string) $entry['pattern'] === $pattern) {
            $initial = $entry['initial'] ?? null;
            return is_array($initial) ? $initial : null;
        }
    }
    return null;
}

// Read a module's `site_config` schema declaration. Returns
//   ['label' => string, 'settings' => [{key, label, type, default}, ...]]
// or [] when the module declares no site_config block. Settings entries
// are filtered to require key + type in {bool, int}; other types and
// malformed entries are silently dropped (defensive against hand-edited
// manifests). The Site Config admin UI walks every discoverable module's
// site_config to build its form; modules without a site_config block
// don't appear there at all.
/**
 * Read a module's `site_config` block from its manifest, normalizing
 * the declared settings. Returns [] when the module has no site_config
 * (most modules don't need one) or when every declared setting is
 * malformed; otherwise returns the populated shape. Settings with
 * unknown types, missing keys, or duplicate keys are silently dropped.
 *
 * @return array{}|array{
 *     label: string,
 *     settings: non-empty-list<array{key: string, label: string, type: string, default: mixed}>,
 * }
 */
function lawnding_module_site_config(string $moduleId): array {
    $manifest = lawnding_module_manifest($moduleId);
    $schema = $manifest['site_config'] ?? null;
    if (!is_array($schema)) {
        return [];
    }
    $label = isset($schema['label']) ? (string) $schema['label'] : '';
    $rawSettings = $schema['settings'] ?? [];
    if (!is_array($rawSettings)) {
        return [];
    }
    $allowedTypes = ['bool', 'int'];
    $clean = [];
    $seenKeys = [];
    foreach ($rawSettings as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $key = isset($entry['key']) ? (string) $entry['key'] : '';
        $entryLabel = isset($entry['label']) ? (string) $entry['label'] : '';
        $type = isset($entry['type']) ? (string) $entry['type'] : '';
        if ($key === '' || preg_match('/[^a-zA-Z0-9_]/', $key) || $entryLabel === '' || !in_array($type, $allowedTypes, true)) {
            continue;
        }
        if (isset($seenKeys[$key])) {
            continue;
        }
        $seenKeys[$key] = true;
        $clean[] = [
            'key'     => $key,
            'label'   => $entryLabel,
            'type'    => $type,
            'default' => $entry['default'] ?? ($type === 'bool' ? false : 0),
        ];
    }
    if (empty($clean)) {
        return [];
    }
    return ['label' => $label, 'settings' => $clean];
}

// Lazy-load admin/modules/<id>/helpers.php so manifest-named callbacks
// (validators, on_rename) resolve at dispatch time. Idempotent —
// require_once guards against double-include. Returns true when the file
// was loaded or already loaded; false when no helpers.php exists for the
// module (which is not an error — most modules don't need one).
function lawnding_module_load_helpers(string $moduleId): bool {
    static $loaded = [];
    if (isset($loaded[$moduleId])) {
        return $loaded[$moduleId];
    }
    if ($moduleId === '' || preg_match('/[^a-zA-Z0-9_-]/', $moduleId)) {
        return $loaded[$moduleId] = false;
    }
    $modulesDir = lawnding_admin_path('modules');
    $path = rtrim($modulesDir, '/\\') . '/' . $moduleId . '/helpers.php';
    if (!is_readable($path)) {
        return $loaded[$moduleId] = false;
    }
    require_once $path;
    return $loaded[$moduleId] = true;
}

// Recursively remove a directory and its contents. Used by save-config.php
// when a pane is deleted or its module is changed (the old module's
// pane_content_dir gets cleaned up). No-op when $dir doesn't exist or
// isn't a directory — callers don't have to is_dir-check first. Mirrors
// the pattern of the previous module-specific media_gallery_remove_dir
// helper but generic for any module declaring pane_content_dir.
function lawnding_remove_directory(string $dir): void {
    if ($dir === '' || !is_dir($dir)) {
        return;
    }
    $items = @scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            lawnding_remove_directory($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
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

// Canonical site-config baseline. Walks every discoverable module's
// manifest `site_config.settings` block and aggregates defaults nested by
// module id (mediaGallery.customThumbs, not gallery_custom_thumbs at top
// level). Adding a key to a module's manifest makes it appear here — and
// in the admin Site Config UI — without admins needing to re-save
// lp-siteConfig.json. Per-pane overrides (sidecar settings.json next to
// items.json) shadow these values when the pane has useSiteDefaults ===
// false. Per-request memoized via lawnding_module_site_config()'s
// upstream cache + lawnding_discover_module_ids()'s static cache.
//
// The `_app` namespace (underscore prefix sidesteps the module-id regex
// used in security paths) is reserved for app-wide settings that belong
// to no module — currently just maxUploadSizeMB, which is enforced by
// every upload endpoint regardless of which module owns the destination.
// It renders first in the Site Config UI by being inserted before the
// per-module loop.
function lawnding_site_config_defaults(): array {
    $defaults = [
        '_app' => [
            'maxUploadSizeMB' => 5,
        ],
    ];
    foreach (lawnding_discover_module_ids() as $moduleId) {
        $schema = lawnding_module_site_config($moduleId);
        $settings = $schema['settings'] ?? [];
        if (empty($settings)) {
            continue;
        }
        $bucket = [];
        foreach ($settings as $setting) {
            $key = $setting['key'];
            $type = $setting['type'];
            $default = $setting['default'] ?? null;
            if ($type === 'bool') {
                $bucket[$key] = (bool) $default;
            } else {
                $bucket[$key] = max(0, (int) $default);
            }
        }
        $defaults[$moduleId] = $bucket;
    }
    return $defaults;
}

// Label registry for the SITE CONFIG admin UI. Sibling of
// lawnding_site_config_defaults() — same iteration, different transform.
// Walks every discoverable module's site_config.label (fieldset legend)
// and per-setting labels. Modules without a site_config block don't
// appear in the UI. Adding a new flag to a module's manifest without a
// label gets a camelCase-split fallback (lawnding_camel_to_label() in
// admin/config.php) at render time. The `_modules` sub-bucket also
// holds the legend for the `_app` namespace (overloaded — it's now the
// "fieldset legends" registry, not strictly modules).
function lawnding_site_config_labels(): array {
    $labels = [
        '_modules' => [
            '_app' => 'App',
        ],
        '_app' => [
            'maxUploadSizeMB' => 'Max upload size (MB)',
        ],
    ];
    foreach (lawnding_discover_module_ids() as $moduleId) {
        $schema = lawnding_module_site_config($moduleId);
        if (empty($schema)) {
            continue;
        }
        $moduleLabel = $schema['label'];
        if ($moduleLabel !== '') {
            $labels['_modules'][$moduleId] = $moduleLabel;
        }
        $bucket = [];
        foreach ($schema['settings'] as $setting) {
            $key = $setting['key'];
            $entryLabel = $setting['label'];
            if ($key !== '' && $entryLabel !== '') {
                $bucket[$key] = $entryLabel;
            }
        }
        if (!empty($bucket)) {
            $labels[$moduleId] = $bucket;
        }
    }
    return $labels;
}

function lawnding_site_config_path(): string {
    if (function_exists('lawnding_instance_runtime_admin_path')) {
        $path = lawnding_instance_runtime_admin_path('lp-siteConfig.json');
        lawnding_ensure_parent_dir($path);
        return $path;
    }
    $adminDir = lawnding_config('admin_dir', dirname(__DIR__) . '/admin');
    $path = rtrim((string) $adminDir, '/\\') . '/lp-siteConfig.json';
    lawnding_ensure_parent_dir($path);
    return $path;
}

// Per-request cache slot for lawnding_load_site_config(). $replace
// overwrites (write-through on save); $reset clears (tests).
function lawnding_site_config_cache_slot(?array $replace = null, bool $reset = false): ?array {
    static $cache = null;
    if ($reset) {
        $cache = null;
        return null;
    }
    if ($replace !== null) {
        $cache = $replace;
    }
    return $cache;
}

/**
 * Load admin/lp-siteConfig.json merged on top of manifest-declared
 * defaults. Values missing or of the wrong type fall back to their
 * default — defensive against hand-edited JSON or schema additions
 * made between sessions. Memoized via lawnding_site_config_cache_slot
 * so N×K resolve_pane_setting calls per admin render collapse to one
 * read.
 *
 * The outer keys are module ids (each module's manifest contributes
 * its block); the inner shape varies per module. Open at both levels.
 *
 * @return array<string, array<string, mixed>>
 */
function lawnding_load_site_config(): array {
    $cached = lawnding_site_config_cache_slot();
    if ($cached !== null) {
        return $cached;
    }
    $defaults = lawnding_site_config_defaults();
    $saved = lawnding_read_json(lawnding_site_config_path());
    $merged = $defaults;
    foreach ($defaults as $module => $flags) {
        if (!is_array($saved[$module] ?? null)) {
            continue;
        }
        foreach ($flags as $key => $defaultValue) {
            if (!isset($saved[$module][$key])) {
                continue;
            }
            $savedValue = $saved[$module][$key];
            // Type-dispatch validation: each saved value must match the
            // PHP type of the default. Mixed-type schemas (some bools,
            // some ints) all flow through this single branch.
            if (is_bool($defaultValue) && is_bool($savedValue)) {
                $merged[$module][$key] = $savedValue;
            } elseif (is_int($defaultValue) && (is_int($savedValue) || (is_string($savedValue) && is_numeric($savedValue)))) {
                $merged[$module][$key] = max(0, (int) $savedValue);
            }
        }
    }
    lawnding_site_config_cache_slot($merged);
    return $merged;
}

// Persist a fully-formed site-config array. Caller is responsible for shape
// (typically: load defaults, override with admin form values, pass here).
// Uses LOCK_EX matching the project standard. Returns true on success.
// Write-throughs to the load cache so read→write→read in one process
// (test runner, future endpoints) sees the just-saved state.
function lawnding_save_site_config(array $config): bool {
    $encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return false;
    }
    $ok = file_put_contents(lawnding_site_config_path(), $encoded, LOCK_EX) !== false;
    if ($ok) {
        lawnding_site_config_cache_slot($config);
    }
    return $ok;
}

// Emit the standard SITE CONFIG <fieldset> for a module's manifest-declared
// site_config.settings. Modules that only need bool/int rendering omit
// site_config.render and let the dispatcher in admin/config.php auto-call
// this; modules that want richer blocks (custom record editors, status
// indicators, etc.) declare site_config.render and call this themselves
// to keep the standard fieldset on top.
//
// Memoizes the manifest walks (defaults + labels) so a multi-module page
// walks them once. The site config itself is cached in
// lawnding_load_site_config(). No-op when the module has no settings.
function lawnding_render_site_config_fieldset(string $moduleId): void {
    static $defaults = null;
    static $labels = null;
    if ($defaults === null) {
        $defaults = lawnding_site_config_defaults();
        $labels = lawnding_site_config_labels();
    }
    $config = lawnding_load_site_config();
    $flags = $defaults[$moduleId] ?? [];
    if (!is_array($flags) || empty($flags)) {
        return;
    }
    $moduleLabels = is_array($labels['_modules'] ?? null) ? $labels['_modules'] : [];
    $moduleLabel = $moduleLabels[$moduleId] ?? lawnding_camel_to_label($moduleId);
    ?>
    <fieldset class="siteConfigGroup">
        <legend><?php echo htmlspecialchars($moduleLabel); ?></legend>
        <?php foreach ($flags as $flagKey => $defaultValue): ?>
            <?php
                $flagLabel = $labels[$moduleId][$flagKey] ?? lawnding_camel_to_label($flagKey);
                $name = 'siteConfig[' . $moduleId . '][' . $flagKey . ']';
                $current = $config[$moduleId][$flagKey] ?? $defaultValue;
            ?>
            <?php if (is_bool($defaultValue)): ?>
                <label class="siteConfigToggle">
                    <input type="checkbox" name="<?php echo htmlspecialchars($name); ?>" <?php echo !empty($current) ? 'checked' : ''; ?>>
                    <span><?php echo htmlspecialchars($flagLabel); ?></span>
                </label>
            <?php elseif (is_int($defaultValue)): ?>
                <label class="siteConfigField">
                    <span><?php echo htmlspecialchars($flagLabel); ?></span>
                    <input type="number" min="1" step="1" name="<?php echo htmlspecialchars($name); ?>" value="<?php echo htmlspecialchars((string) (int) $current); ?>">
                </label>
            <?php endif; ?>
        <?php endforeach; ?>
    </fieldset>
    <?php
}

/**
 * Per-pane settings live in a sidecar settings.json inside each pane's
 * content directory (e.g. public/res/data/mediaGalleryContent-<paneId>/
 * settings.json). The file is sparse — present only when the admin has
 * unchecked "Use site defaults" for this pane. Absence means "follow
 * site defaults", so a galleries-without-overrides install carries no
 * per-pane settings files at all.
 *
 * Returns [] on missing file, unreadable file, or malformed JSON so
 * callers can branch on emptiness without null-checking.
 *
 * @return array<string, mixed>
 */
function lawnding_load_pane_settings(string $settingsPath): array {
    if (!is_readable($settingsPath)) {
        return [];
    }
    $decoded = lawnding_read_json($settingsPath);
    return is_array($decoded) ? $decoded : [];
}

// Persist per-pane settings. Creates the parent directory if needed (so
// modules don't have to mkdir before calling). Returns true on success.
function lawnding_save_pane_settings(string $settingsPath, array $settings): bool {
    $dir = dirname($settingsPath);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $encoded = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return false;
    }
    return file_put_contents($settingsPath, $encoded, LOCK_EX) !== false;
}

// Resolve the effective value of a single setting given the pane's loaded
// override array (from lawnding_load_pane_settings) and the desired
// module/key. Pure function — caller does I/O once and resolves multiple
// keys without re-reading the sidecar file. When useSiteDefaults is true
// or missing (the default state for a pane with no settings.json), the
// value is read live from lp-siteConfig — so changes to site-wide
// defaults propagate immediately to every pane that's still inheriting.
function lawnding_resolve_pane_setting(array $paneSettings, string $module, string $key): bool {
    $useDefaults = !isset($paneSettings['useSiteDefaults']) || $paneSettings['useSiteDefaults'] !== false;
    if ($useDefaults) {
        $siteConfig = lawnding_load_site_config();
        return !empty($siteConfig[$module][$key]);
    }
    return isset($paneSettings[$key]) && $paneSettings[$key] === true;
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
            return $a['_index'] <=> $b['_index'];
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

// Resolve the client IP behind common reverse proxies. Reads X-Real-IP first
// (Traefik / nginx), then the first entry of X-Forwarded-For (Traefik also
// sets this; nginx too), then falls back to REMOTE_ADDR for bare-metal
// deployments. Same trust model as lawnding_request_is_secure(): we trust
// proxy headers when present because the proxy is part of the controlled
// stack. Used by rate-limit lookups on the login endpoints.
function lawnding_client_ip(): string {
    $realIp = $_SERVER['HTTP_X_REAL_IP'] ?? '';
    if (is_string($realIp) && trim($realIp) !== '') {
        return trim($realIp);
    }
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if (is_string($forwarded) && $forwarded !== '') {
        $first = trim(explode(',', $forwarded)[0]);
        if ($first !== '') {
            return $first;
        }
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
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

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// Plugin hook primitive. Plugins ship admin/plugins/<id>/init.php that
// calls lawnding_register_hook('<point>', fn). Points fire at fixed
// spots in entrypoint templates via lawnding_run_hook('<point>', $ctx).
// Intentionally minimal -- no priorities, filters, or deregistration.
if (!isset($GLOBALS['LAWNDING_HOOKS'])) {
    $GLOBALS['LAWNDING_HOOKS'] = [];
}
function lawnding_register_hook(string $point, callable $fn): void {
    $GLOBALS['LAWNDING_HOOKS'][$point][] = $fn;
}
function lawnding_run_hook(string $point, array $ctx = []): void {
    foreach ($GLOBALS['LAWNDING_HOOKS'][$point] ?? [] as $fn) {
        $fn($ctx);
    }
}

// Restore missing data files from the split seed-instance defaults. Never
// overwrites: admin edits survive deploys.
function lawnding_ensure_data_files(): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;
    $seedDir = function_exists('lawnding_seed_path')
        ? lawnding_seed_path('public/res/data')
        : __DIR__ . '/resources/seed-instance/public/res/data';
    $liveDir = function_exists('lawnding_data_path')
        ? lawnding_data_path('')
        : __DIR__ . '/public/res/data';
    if (!is_dir($seedDir) || !is_dir($liveDir)) {
        return;
    }
    foreach (glob(rtrim($seedDir, '/\\') . '/*') ?: [] as $seedPath) {
        if (!is_file($seedPath)) {
            continue;
        }
        $basename = basename($seedPath);
        if ($basename === '' || $basename[0] === '.') {
            continue;
        }
        $livePath = rtrim($liveDir, '/\\') . '/' . $basename;
        if (file_exists($livePath)) {
            continue;
        }
        @copy($seedPath, $livePath);
    }
}

// Autoload all plugin init.php files. Idempotent -- safe to call from
// both entrypoints. Plugins live at admin/plugins/<id>/ alongside
// admin/modules/. URL-routed plugin endpoints (auth callbacks, webhooks,
// proxied static assets) reach the same folder via plugin-endpoint.php /
// plugin-style.php / plugin-script.php. Endpoint-only plugins simply
// have no init.php and are not loaded here.
function lawnding_load_plugins(): void {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    $dir = function_exists('lawnding_admin_path')
        ? lawnding_admin_path('plugins')
        : __DIR__ . '/admin/plugins';
    foreach (glob(rtrim($dir, '/\\') . '/*/init.php') ?: [] as $init) {
        require_once $init;
    }
}

// Single source of truth for the changelog modal — emitted on both the
// public site and the admin panel. Parses CHANGELOG.md from the project
// root, renders the modal shell, and lets app.js's lpBindChangelog wire
// the click + Esc handling. Carries lpModalNoDrag (read-this-and-dismiss
// content; drag would be a footgun) and lpModalCloseX on the dismiss ×.
function lawnding_render_changelog_modal(): void {
    if (!class_exists('Parsedown')) {
        $parsedownPath = function_exists('lawnding_core_public_path')
            ? lawnding_core_public_path('res/scr/Parsedown.php')
            : __DIR__ . '/public/res/scr/Parsedown.php';
        if (is_readable($parsedownPath)) {
            require_once $parsedownPath;
        }
    }
    $rootDir = function_exists('lawnding_config')
        ? lawnding_config('root_dir', __DIR__)
        : __DIR__;
    $changelogPath = rtrim($rootDir, '/') . '/CHANGELOG.md';
    $html = '';
    if (class_exists('Parsedown') && is_readable($changelogPath)) {
        $parser = new Parsedown();
        $html = $parser->text((string) file_get_contents($changelogPath));
    }
    ?>
    <div class="changelogModal" id="changelogModal" role="dialog" aria-modal="true" aria-label="Changelog" hidden>
        <div class="changelogModalBackdrop"></div>
        <div class="userModal changelogModalInner lpModalNoDrag">
            <h4 class="changelogModalHeader">Changelog</h4>
            <button class="changelogModalClose lpModalCloseX" type="button" aria-label="Close changelog">×</button>
            <div class="changelogModalBody changelogContent">
                <?php echo $html; ?>
            </div>
        </div>
    </div>
    <?php
}
