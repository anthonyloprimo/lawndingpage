<?php
// LawndingPage test runner.
// Usage: php -d zend.assertions=1 tests/run.php
//
// Phase 1 — php -l on every .php file under public/, admin/, and repo root.
// Phase 2 — node --check on every .js file under public/ and admin/.
// Phase 3 — run unit tests (tests/test-*.php).
// All three phases must pass for a clean exit.

if (PHP_VERSION_ID < 80000) {
    fwrite(STDERR, "PHP 8.0+ required.\n");
    exit(1);
}

$isTerm = function_exists('posix_isatty') && posix_isatty(STDOUT);
$green  = $isTerm ? "\033[32m" : '';
$red    = $isTerm ? "\033[31m" : '';
$yellow = $isTerm ? "\033[33m" : '';
$reset  = $isTerm ? "\033[0m" : '';
$exitCode = 0;

// ---- helpers ----

function find_files_by_ext(string $dir, string $ext): array {
    $files = [];
    if (!is_dir($dir)) {
        return $files;
    }
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iter as $file) {
        if ($file->isFile() && $file->getExtension() === $ext) {
            $files[] = $file->getPathname();
        }
    }
    return $files;
}

// ---- Phase 1: PHP lint ----

echo "{$yellow}--- PHP Lint ---{$reset}\n";

$phpFiles = array_merge(
    glob(__DIR__ . '/../*.php') ?: [],
    find_files_by_ext(__DIR__ . '/../public', 'php'),
    find_files_by_ext(__DIR__ . '/../admin', 'php'),
);

sort($phpFiles, SORT_NATURAL);
$phpErrors = 0;
$phpCount  = 0;
$rootLen   = strlen(realpath(__DIR__ . '/..')) + 1;

foreach ($phpFiles as $file) {
    $phpCount++;
    $rel = substr($file, $rootLen);
    $cmd = sprintf('php -l %s 2>&1', escapeshellarg($file));
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    if ($code !== 0) {
        $phpErrors++;
        echo "{$red}FAIL{$reset}  $rel\n";
        foreach ($output as $line) {
            echo "      $line\n";
        }
    }
}

if ($phpErrors > 0) {
    $exitCode = 1;
    echo "{$red}ERROR: $phpErrors PHP file(s) failed lint.{$reset}\n";
} elseif ($phpCount > 0) {
    echo "{$green}$phpCount PHP files, 0 errors{$reset}\n";
}

echo "\n";

// ---- Phase 2: JS syntax check ----

$hasNode = false;
exec('node --version 2>&1', $nodeVersionOut, $nodeVersionCode);
$hasNode = $nodeVersionCode === 0;

echo "{$yellow}--- JS Check ---{$reset}\n";

$jsFiles = array_merge(
    find_files_by_ext(__DIR__ . '/../public', 'js'),
    find_files_by_ext(__DIR__ . '/../admin', 'js'),
);

sort($jsFiles, SORT_NATURAL);
$jsErrors = 0;
$jsCount  = 0;

if (!$hasNode) {
    echo "{$yellow}WARN:  Node not found — skipping JS syntax check.{$reset}\n";
} else {
    foreach ($jsFiles as $file) {
        $jsCount++;
        $rel = substr($file, $rootLen);
        $cmd = sprintf('node --check %s 2>&1', escapeshellarg($file));
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        if ($code !== 0) {
            $jsErrors++;
            echo "{$red}FAIL{$reset}  $rel\n";
            foreach ($output as $line) {
                echo "      $line\n";
            }
        }
    }

    if ($jsErrors > 0) {
        $exitCode = 1;
        echo "{$red}ERROR: $jsErrors JS file(s) failed syntax check.{$reset}\n";
    } elseif ($jsCount > 0) {
        echo "{$green}$jsCount JS files, 0 errors{$reset}\n";
    }
}

echo "\n";

// ---- Phase 3: unit tests ----

echo "{$yellow}--- Tests ---{$reset}\n";

$testFiles = glob(__DIR__ . '/test-*.php') ?: [];
if (empty($testFiles)) {
    fwrite(STDERR, "No test files found in " . __DIR__ . "\n");
    exit(1);
}

sort($testFiles, SORT_NATURAL);

$totalAssertions = 0;
$totalFailures = 0;
$passedFiles = 0;
$failedFiles = 0;

foreach ($testFiles as $file) {
    $base = basename($file);
    $_tests_assertions = 0;
    $_tests_failures = 0;

    try {
        require $file;
    } catch (Throwable $e) {
        $_tests_failures++;
        fwrite(STDERR, "    {$red}ERROR{$reset}: " . $e->getMessage() . "\n");
        fwrite(STDERR, "           " . $e->getFile() . ":" . $e->getLine() . "\n");
    }

    $totalAssertions += $_tests_assertions;
    $totalFailures += $_tests_failures;

    if ($_tests_failures > 0) {
        $failedFiles++;
        echo "{$red}FAIL{$reset}  $base  ({$_tests_assertions} assertions, {$_tests_failures} failures)\n";
    } elseif ($_tests_assertions > 0) {
        $passedFiles++;
        echo "{$green}PASS{$reset}  $base  ({$_tests_assertions} assertions)\n";
    } else {
        $failedFiles++;
        echo "{$yellow}WARN{$reset}  $base  (0 assertions)\n";
    }
}

if ($totalFailures > 0) {
    $exitCode = 1;
}

echo str_repeat('-', 50) . "\n";
echo "PHP:     $phpErrors errors\n";
if ($hasNode) {
    echo "JS:      $jsErrors errors\n";
}
echo "Tests:   $passedFiles/" . ($passedFiles + $failedFiles) . " passed\n";
echo "Asserts: $totalAssertions total, $totalFailures failures\n";

exit($exitCode);
