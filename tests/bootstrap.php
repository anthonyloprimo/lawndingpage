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

// Per-test assertion counter, reset by the runner before each file.
$_tests_assertions = 0;
$_tests_failures = 0;

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
