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

    $seedFileMap = [
        lawnding_seed_path('public/res/data/.htaccess') => lawnding_instance_data_path('.htaccess'),
        lawnding_seed_path('public/res/data/header.json') => lawnding_instance_data_path('header.json'),
        lawnding_seed_path('public/res/data/links.json') => lawnding_instance_data_path('links.json'),
        lawnding_seed_path('public/res/data/authorizedLinks.json') => lawnding_instance_data_path('authorizedLinks.json'),
        lawnding_seed_path('public/res/data/panes.json') => lawnding_instance_data_path('panes.json'),
        lawnding_seed_path('public/res/data/welcome.md') => lawnding_instance_data_path('welcome.md'),
    ];
    foreach ($seedFileMap as $source => $destination) {
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

// Detect whether the current request should be treated as HTTPS.
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
