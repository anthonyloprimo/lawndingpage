<?php
// LawndingPage test runner.
// Usage: php -d zend.assertions=1 tests/run.php
//
// Phase 1 — php -l on every .php file under public/, admin/, and repo root.
// Phase 2 — node --check on every .js file under public/ and admin/.
// Phase 3 — run unit tests (tests/test-*.php).
// Phase 4 — PHPStan static analysis (hard-requires tools/phpstan.phar;
//           install via `bash tools/install-phpstan.sh`).
// All four phases must pass for a clean exit.

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
$totalSkips = 0;
$passedFiles = 0;
$failedFiles = 0;
$skippedFiles = 0;

foreach ($testFiles as $file) {
    $base = basename($file);
    $_tests_assertions = 0;
    $_tests_failures = 0;
    $_tests_skips = [];

    try {
        // Wrap in a closure so the test file's top-level variables stay
        // scoped to the closure rather than leaking into the runner's
        // foreach loop. Without this, a test that sets `$base = [...]`
        // would clobber the runner's `$base` filename label and the
        // PASS/FAIL line would print "PASS Array" instead of the file
        // name. test_assert and friends use `global` declarations, so
        // they continue to reach the runner-set $_tests_* counters at
        // script scope independent of this isolation.
        (function () use ($file) {
            require $file;
        })();
    } catch (TestSkipException $e) {
        $_tests_skips[] = $e->getMessage();
    } catch (Throwable $e) {
        $_tests_failures++;
        fwrite(STDERR, "    {$red}ERROR{$reset}: " . $e->getMessage() . "\n");
        fwrite(STDERR, "           " . $e->getFile() . ":" . $e->getLine() . "\n");
    }

    $totalAssertions += $_tests_assertions;
    $totalFailures += $_tests_failures;
    $totalSkips += count($_tests_skips);

    $skipNote = empty($_tests_skips) ? '' : ', ' . count($_tests_skips) . ' SKIPPED';

    if ($_tests_failures > 0) {
        $failedFiles++;
        echo "{$red}FAIL{$reset}  $base  ({$_tests_assertions} assertions, {$_tests_failures} failures{$skipNote})\n";
    } elseif (!empty($_tests_skips) && $_tests_assertions === 0) {
        // Hard skip: test_require_* aborted before any assertions could run.
        $skippedFiles++;
        echo "{$yellow}SKIP{$reset}  $base  (file aborted before any assertions)\n";
    } elseif ($_tests_assertions > 0) {
        $passedFiles++;
        $color = empty($_tests_skips) ? $green : $yellow;
        $label = empty($_tests_skips) ? 'PASS' : 'PASS';
        echo "{$color}{$label}{$reset}  $base  ({$_tests_assertions} assertions{$skipNote})\n";
    } else {
        $failedFiles++;
        echo "{$yellow}WARN{$reset}  $base  (0 assertions)\n";
    }

    foreach ($_tests_skips as $reason) {
        echo "      {$yellow}↪ skipped:{$reset} $reason\n";
    }
}

// Tests must confirm the environment has all the tools they need. Skips —
// whether soft (test_check_*) or hard (test_require_*) — mean a tool is
// missing; the suite fails loudly so the user installs the dep rather than
// shipping with silent gaps in coverage.
if ($totalFailures > 0 || $totalSkips > 0) {
    $exitCode = 1;
}

echo "\n";

// ---- Phase 4: PHPStan static analysis ----

echo "{$yellow}--- PHPStan ---{$reset}\n";

$phpstanPhar   = realpath(__DIR__ . '/..') . '/tools/phpstan.phar';
$phpstanConfig = realpath(__DIR__ . '/..') . '/phpstan.neon';
$phpstanErrors = 0;
$phpstanReady  = is_file($phpstanPhar);

if (!$phpstanReady) {
    // Mirror the test_require_* contract: missing tool fails loudly so
    // the operator installs it rather than shipping with silent gaps.
    echo "{$red}FAIL{$reset}  tools/phpstan.phar not found.\n";
    echo "      Install with: bash tools/install-phpstan.sh\n";
    $phpstanErrors = 1;
    $exitCode = 1;
} else {
    $cmd = sprintf(
        'php %s analyse --configuration=%s --no-progress --memory-limit=512M 2>&1',
        escapeshellarg($phpstanPhar),
        escapeshellarg($phpstanConfig),
    );
    $phpstanOutput = [];
    $phpstanCode   = 0;
    exec($cmd, $phpstanOutput, $phpstanCode);
    if ($phpstanCode !== 0) {
        $phpstanErrors = 1;
        $exitCode = 1;
        echo "{$red}FAIL{$reset}\n";
        foreach ($phpstanOutput as $line) {
            echo "      $line\n";
        }
    } else {
        // PHPStan's success line is informative enough on its own; pass
        // it through so users see [OK] No errors with the native styling.
        foreach ($phpstanOutput as $line) {
            echo "$line\n";
        }
        echo "{$green}PASS{$reset}\n";
    }
}

echo "\n";

echo str_repeat('-', 50) . "\n";
echo "PHP:     $phpErrors errors\n";
if ($hasNode) {
    echo "JS:      $jsErrors errors\n";
}
$totalFiles = $passedFiles + $failedFiles + $skippedFiles;
echo "Tests:   $passedFiles/$totalFiles passed";
if ($skippedFiles > 0) {
    echo " ({$skippedFiles} file" . ($skippedFiles === 1 ? '' : 's') . " fully skipped)";
}
echo "\n";
echo "Asserts: $totalAssertions total, $totalFailures failures";
if ($totalSkips > 0) {
    echo ", $totalSkips skip notice" . ($totalSkips === 1 ? '' : 's');
}
echo "\n";
echo "PHPStan: $phpstanErrors errors" . ($phpstanReady ? '' : ' (analyzer not installed)') . "\n";

exit($exitCode);
