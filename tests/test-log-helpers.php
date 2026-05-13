<?php
// Tests for error-log helpers in lp-bootstrap.php.
require_once __DIR__ . '/bootstrap.php';

// ---- lawnding_logs_php_severity_name ----

// Single error types.
test_assert(lawnding_logs_php_severity_name(E_ERROR) === 'error', 'E_ERROR → error');
test_assert(lawnding_logs_php_severity_name(E_USER_ERROR) === 'error', 'E_USER_ERROR → error');
test_assert(lawnding_logs_php_severity_name(E_RECOVERABLE_ERROR) === 'error', 'E_RECOVERABLE_ERROR → error');
test_assert(lawnding_logs_php_severity_name(E_CORE_ERROR) === 'error', 'E_CORE_ERROR → error');
test_assert(lawnding_logs_php_severity_name(E_COMPILE_ERROR) === 'error', 'E_COMPILE_ERROR → error');
test_assert(lawnding_logs_php_severity_name(E_PARSE) === 'error', 'E_PARSE → error');

test_assert(lawnding_logs_php_severity_name(E_WARNING) === 'warn', 'E_WARNING → warn');
test_assert(lawnding_logs_php_severity_name(E_USER_WARNING) === 'warn', 'E_USER_WARNING → warn');
test_assert(lawnding_logs_php_severity_name(E_CORE_WARNING) === 'warn', 'E_CORE_WARNING → warn');
test_assert(lawnding_logs_php_severity_name(E_COMPILE_WARNING) === 'warn', 'E_COMPILE_WARNING → warn');

test_assert(lawnding_logs_php_severity_name(E_NOTICE) === 'info', 'E_NOTICE → info');
test_assert(lawnding_logs_php_severity_name(E_DEPRECATED) === 'info', 'E_DEPRECATED → info');
test_assert(lawnding_logs_php_severity_name(E_STRICT) === 'info', 'E_STRICT → info');

// Combined bits — the match() uses & so higher bits win.
test_assert(lawnding_logs_php_severity_name(E_ERROR | E_WARNING) === 'error', 'error+warning → error (higher wins)');
test_assert(lawnding_logs_php_severity_name(E_WARNING | E_NOTICE) === 'warn', 'warning+notice → warn');

// ---- lawnding_logs_should_capture ----

test_assert(lawnding_logs_should_capture(E_ERROR) === true, 'E_ERROR should be captured');
test_assert(lawnding_logs_should_capture(E_WARNING) === true, 'E_WARNING should be captured');
test_assert(lawnding_logs_should_capture(E_USER_ERROR) === true, 'E_USER_ERROR should be captured');
test_assert(lawnding_logs_should_capture(E_USER_WARNING) === true, 'E_USER_WARNING should be captured');
test_assert(lawnding_logs_should_capture(E_PARSE) === true, 'E_PARSE should be captured');

test_assert(lawnding_logs_should_capture(E_NOTICE) === false, 'E_NOTICE should not be captured');
test_assert(lawnding_logs_should_capture(E_DEPRECATED) === false, 'E_DEPRECATED should not be captured');
test_assert(lawnding_logs_should_capture(E_STRICT) === false, 'E_STRICT should not be captured');
