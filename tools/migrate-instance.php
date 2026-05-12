<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$options = getopt('', ['root:', 'move', 'copy', 'dry-run', 'help']);
if ($options === false || isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/migrate-instance.php [--root=/path/to/instance] [--copy|--move] [--dry-run]\n");
    exit(0);
}

$instanceRoot = isset($options['root']) && is_string($options['root']) && $options['root'] !== ''
    ? $options['root']
    : dirname(__DIR__);
$mode = isset($options['move']) ? 'move' : 'copy';
$dryRun = isset($options['dry-run']);

define('LAWNDING_CORE_ROOT', dirname(__DIR__));
define('LAWNDING_INSTANCE_ROOT', $instanceRoot);
require dirname(__DIR__) . '/lp-bootstrap.php';

$instanceRoot = (string) lawnding_config('instance_root_dir', $instanceRoot);
if (!is_dir($instanceRoot)) {
    fwrite(STDERR, "Instance root does not exist: {$instanceRoot}\n");
    exit(1);
}

if ($dryRun) {
    $pairs = function_exists('lawnding_instance_migration_entries')
        ? lawnding_instance_migration_entries()
        : lawnding_runtime_migration_pairs();
    $status = lawnding_runtime_migration_status();
    $pendingBySource = [];
    foreach ($status['pending'] as $entry) {
        $pendingBySource[(string) ($entry['source'] ?? '')] = true;
    }
    $cleanupBySource = [];
    foreach ($status['cleanup'] as $entry) {
        $cleanupBySource[(string) ($entry['source'] ?? '')] = true;
    }

    foreach ($pairs as $entry) {
        $source = (string) ($entry['source'] ?? '');
        $destination = (string) ($entry['destination'] ?? '');
        $group = (string) ($entry['group'] ?? '');
        if ($source === '' || $destination === '') {
            continue;
        }
        if (isset($pendingBySource[$source])) {
            if ($group === 'config') {
                fwrite(STDOUT, "would update overrides: {$source}\n");
                continue;
            }
            if ($group === 'data_update') {
                fwrite(STDOUT, "would normalize data file: {$source}\n");
                continue;
            }
            fwrite(STDOUT, "would {$mode}: {$source} -> {$destination}\n");
            continue;
        }
        if (isset($cleanupBySource[$source])) {
            fwrite(STDOUT, "skip existing destination: {$destination}\n");
            continue;
        }
        fwrite(STDOUT, "skip missing: {$source}\n");
    }

    $flagPath = (string) lawnding_config('initialized_flag_path', '');
    if ($flagPath !== '') {
        fwrite(STDOUT, "would touch flag: {$flagPath}\n");
    }
} else {
    $result = lawnding_run_runtime_migration($mode);
    foreach ($result['processed'] as $entry) {
        $source = (string) ($entry['source'] ?? '');
        $destination = (string) ($entry['destination'] ?? '');
        $group = (string) ($entry['group'] ?? '');
        if ($group === 'config') {
            fwrite(STDOUT, "updated overrides: {$source}\n");
            continue;
        }
        if ($group === 'data_update') {
            fwrite(STDOUT, "normalized data file: {$source}\n");
            continue;
        }
        fwrite(STDOUT, "{$mode}: {$source} -> {$destination}\n");
    }
    $status = $result['status'];
    foreach ($status['cleanup'] as $entry) {
        $destination = (string) ($entry['destination'] ?? '');
        fwrite(STDOUT, "skip existing destination: {$destination}\n");
    }
    $pairs = function_exists('lawnding_instance_migration_entries')
        ? lawnding_instance_migration_entries()
        : lawnding_runtime_migration_pairs();
    foreach ($pairs as $entry) {
        $source = (string) ($entry['source'] ?? '');
        $destination = (string) ($entry['destination'] ?? '');
        if ($source === '' || $destination === '') {
            continue;
        }
        $isProcessed = false;
        foreach ($result['processed'] as $processed) {
            if (($processed['source'] ?? '') === $source) {
                $isProcessed = true;
                break;
            }
        }
        if ($isProcessed || file_exists($destination)) {
            continue;
        }
        fwrite(STDOUT, "skip missing: {$source}\n");
    }
    $flagPath = (string) lawnding_config('initialized_flag_path', '');
    if ($flagPath !== '') {
        fwrite(STDOUT, "touch flag: {$flagPath}\n");
    }
    if (!$result['ok']) {
        foreach ($result['errors'] as $error) {
            fwrite(STDERR, $error . "\n");
        }
        exit(1);
    }
}

fwrite(STDOUT, "Migration complete for {$instanceRoot}\n");
