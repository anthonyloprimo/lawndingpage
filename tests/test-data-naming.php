<?php
require_once __DIR__ . '/bootstrap.php';

// Project convention: JSON data record fields use camelCase.
// Manifest keys (data_files, save_map, etc.) stay snake_case and are not
// scanned here. See admin/modules/README.md "Naming Conventions" and
// project_data_field_naming_normalize.md for the rename backlog.

function data_naming_walk_keys($value, array &$found, array $path = []): void {
    if (!is_array($value)) {
        return;
    }
    foreach ($value as $k => $v) {
        $nextPath = $path;
        if (is_string($k)) {
            $found[] = ['key' => $k, 'path' => implode('.', array_merge($path, [$k]))];
            $nextPath[] = $k;
        } else {
            $nextPath[] = '[' . $k . ']';
        }
        data_naming_walk_keys($v, $found, $nextPath);
    }
}

function data_naming_is_camel_case(string $key): bool {
    return (bool) preg_match('/^[a-z][a-zA-Z0-9]*$/', $key);
}

// Files where keys are user data (domain names, etc.) rather than field names.
$skipFiles = ['favicon-cache.json'];

// Pre-existing snake_case keys waiting on the rename PR. Each entry is the
// literal key string. Keep this list shrinking — see
// project_data_field_naming_normalize.md for targets and call sites.
$allowlist = [
    'schema_version', // panes.json
    'show_links',     // links.json settings
    'auth_links',     // links.json settings
    'mock_state',     // authorizedLinks.json settings
];

$seedDir = realpath(__DIR__ . '/../admin/seed/data');
test_assert($seedDir !== false && is_dir($seedDir), 'admin/seed/data/ exists');

$violations = [];
foreach (glob($seedDir . '/*.json') ?: [] as $jsonPath) {
    $name = basename($jsonPath);
    if (in_array($name, $skipFiles, true)) {
        continue;
    }
    $raw = file_get_contents($jsonPath);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        continue;
    }
    $keys = [];
    data_naming_walk_keys($data, $keys);
    foreach ($keys as $entry) {
        if (data_naming_is_camel_case($entry['key'])) {
            continue;
        }
        if (in_array($entry['key'], $allowlist, true)) {
            continue;
        }
        $violations[] = $name . ': ' . $entry['path'];
    }
}

if ($violations) {
    fwrite(STDERR, "  snake_case keys (not in allowlist):\n");
    foreach ($violations as $v) {
        fwrite(STDERR, "    - $v\n");
    }
}
test_assert(empty($violations), 'all seed data keys are camelCase or explicitly allowlisted');

// Sanity: confirm the allowlist itself isn't stale -- every allowlisted key
// must actually appear in the scanned files. Otherwise the list rots silently.
$allFoundKeys = [];
foreach (glob($seedDir . '/*.json') ?: [] as $jsonPath) {
    if (in_array(basename($jsonPath), $skipFiles, true)) {
        continue;
    }
    $data = json_decode((string) file_get_contents($jsonPath), true);
    if (!is_array($data)) {
        continue;
    }
    $keys = [];
    data_naming_walk_keys($data, $keys);
    foreach ($keys as $entry) {
        $allFoundKeys[$entry['key']] = true;
    }
}
$staleAllowed = [];
foreach ($allowlist as $key) {
    if (!isset($allFoundKeys[$key])) {
        $staleAllowed[] = $key;
    }
}
if ($staleAllowed) {
    fwrite(STDERR, "  allowlist entries no longer present in seed data:\n");
    foreach ($staleAllowed as $k) {
        fwrite(STDERR, "    - $k\n");
    }
}
test_assert(empty($staleAllowed), 'allowlist contains no stale entries');
