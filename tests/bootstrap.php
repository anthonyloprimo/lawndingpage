<?php
// Test bootstrap — shared setup for all test files.

// Prevent the production error handler from interfering with test output.
// Set this before requiring lp-bootstrap.php so the handler registration
// at the bottom of the bootstrap runs but we swap it out below.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/../public/index.php';

require_once __DIR__ . '/../lp-bootstrap.php';

// Swap out the production error handler so assertion warnings and test
// diagnostics print to stderr instead of being captured to errors.jsonl.
restore_error_handler();

// Per-test counters, reset by the runner before each file.
$_tests_assertions = 0;
$_tests_failures = 0;
$_tests_skips = [];

function test_assert(bool $condition, string $description): void {
    global $_tests_assertions, $_tests_failures;
    $_tests_assertions++;
    if (!$condition) {
        $_tests_failures++;
        fwrite(STDERR, "    FAIL: $description\n");
        // Walk the call stack to find the test file that called us.
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
        fwrite(STDERR, "          " . ($trace['file'] ?? '') . ":" . ($trace['line'] ?? '') . "\n");
    }
}

// ---- Test-environment requirement helpers ----
//
// Tests that depend on external tooling (PHP extensions, system binaries,
// optional functions) MUST declare what they need via test_require_*
// (hard skip — aborts the file) or test_check_* (soft skip — gates a block,
// returns bool). Raw extension_loaded() / function_exists() guards in tests
// silently hide whether the assertions ran; the helpers below record skips
// so the runner surfaces them in output and exits non-zero when any tool
// is missing. The rule: the test environment must have all tools the suite
// needs, and the runner enforces that loudly.

class TestSkipException extends \RuntimeException {}

// Abort the rest of the current test file with a recorded reason. Used by
// the test_require_* helpers; available directly for one-off skip cases.
function test_skip(string $reason): void {
    throw new TestSkipException($reason);
}

// Hard skip: abort the file if a PHP extension isn't loaded. Use at the
// top of a test file when every assertion depends on the extension.
function test_require_extension(string $extension): void {
    if (!extension_loaded($extension)) {
        test_skip("PHP extension not loaded: $extension");
    }
}

// Hard skip: abort if a function isn't available (e.g., optional functions
// inside an installed extension like imagewebp() within GD).
function test_require_function(string $function): void {
    if (!function_exists($function)) {
        test_skip("function not available: $function");
    }
}

// Hard skip: abort if a binary isn't on PATH (ffmpeg, convert, etc.).
function test_require_command(string $command): void {
    $output = [];
    $code = 1;
    @exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $output, $code);
    if ($code !== 0) {
        test_skip("command not on PATH: $command");
    }
}

// Soft skip: returns true if the extension is loaded; otherwise records a
// skip note for the current file and returns false. Use to gate an
// integration block while pure-logic assertions in the same file keep
// running:
//
//   if (test_check_extension('gd')) {
//       // GD-dependent integration tests
//   }
function test_check_extension(string $extension): bool {
    if (extension_loaded($extension)) {
        return true;
    }
    test_record_skip("PHP extension not loaded: $extension");
    return false;
}

function test_check_function(string $function): bool {
    if (function_exists($function)) {
        return true;
    }
    test_record_skip("function not available: $function");
    return false;
}

function test_check_command(string $command): bool {
    $output = [];
    $code = 1;
    @exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $output, $code);
    if ($code === 0) {
        return true;
    }
    test_record_skip("command not on PATH: $command");
    return false;
}

// Internal: append a skip reason to the runner-tracked array. Both the
// soft-skip helpers above and the runner (when catching TestSkipException)
// write to the same array.
function test_record_skip(string $reason): void {
    global $_tests_skips;
    if (!isset($_tests_skips)) {
        $_tests_skips = [];
    }
    $_tests_skips[] = $reason;
}
