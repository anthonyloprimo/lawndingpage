<?php
// Tests for upload/path utility helpers in lp-bootstrap.php.
require_once __DIR__ . '/bootstrap.php';

// ---- lawnding_ini_size_to_bytes ----

test_assert(lawnding_ini_size_to_bytes('2M') === 2 * 1024 * 1024, '2M = 2,097,152 bytes');
test_assert(lawnding_ini_size_to_bytes('1G') === 1 * 1024 * 1024 * 1024, '1G = 1,073,741,824 bytes');
test_assert(lawnding_ini_size_to_bytes('512K') === 512 * 1024, '512K = 524,288 bytes');
test_assert(lawnding_ini_size_to_bytes('0') === 0, 'zero string = 0');
test_assert(lawnding_ini_size_to_bytes('') === 0, 'empty string = 0');
test_assert(lawnding_ini_size_to_bytes('8M') === 8 * 1024 * 1024, '8M = 8,388,608 bytes');

// Case-insensitive suffix.
test_assert(lawnding_ini_size_to_bytes('2m') === 2 * 1024 * 1024, 'lowercase m same as uppercase');
test_assert(lawnding_ini_size_to_bytes('1g') === 1 * 1024 * 1024 * 1024, 'lowercase g same as uppercase');

// ---- lawnding_app_upload_max_bytes / lawnding_app_upload_max_label ----

test_assert(lawnding_app_upload_max_bytes() === 5 * 1024 * 1024, 'app upload cap is 5 MB');
test_assert(lawnding_app_upload_max_label() === '5MB', 'label renders as 5MB');

// ---- lawnding_image_mime_map ----

$map = lawnding_image_mime_map();
test_assert($map['image/jpeg'] === 'jpg', 'jpeg → jpg');
test_assert($map['image/png'] === 'png', 'png → png');
test_assert($map['image/gif'] === 'gif', 'gif → gif');
test_assert($map['image/webp'] === 'webp', 'webp → webp');
test_assert(count($map) === 4, '4 image types in the map');

// ---- lawnding_norm_path ----

test_assert(lawnding_norm_path('/foo/bar', true) === '/foo/bar', 'forward slashes unchanged');
test_assert(lawnding_norm_path('\\foo\\bar', true) === '/foo/bar', 'backslashes converted');
test_assert(lawnding_norm_path('/foo/bar/', true) === '/foo/bar', 'trailing slash trimmed');
test_assert(lawnding_norm_path('/foo/bar/', false) === '/foo/bar/', 'trailing slash kept when trim off');

// ---- lawnding_norm_web_path ----

test_assert(lawnding_norm_web_path('/subdir/index.php', true) === '/subdir/index.php', 'web path normalized');
test_assert(lawnding_norm_web_path('/subdir/', true) === '/subdir', 'trailing / trimmed');

// ---- lawnding_join_path ----

test_assert(lawnding_join_path('/foo', 'bar') === '/foo/bar', 'base + relative');
test_assert(lawnding_join_path('/foo/', '/bar') === '/foo/bar', 'both have slashes — normalized');
test_assert(lawnding_join_path('/foo', '') === '/foo', 'empty path returns base');
test_assert(lawnding_join_path('/foo/bar/', 'baz/qux') === '/foo/bar/baz/qux', 'multi-segment relative');

// ---- lawnding_normalize_base_url ----

test_assert(lawnding_normalize_base_url('') === '', 'empty returns empty');
test_assert(lawnding_normalize_base_url('/') === '', 'root slash returns empty');
test_assert(lawnding_normalize_base_url('/subdir') === '/subdir', 'subdir preserved');
test_assert(lawnding_normalize_base_url('/subdir/') === '/subdir', 'trailing slash stripped');

// Hestia multitenant prefix is stripped.
test_assert(lawnding_normalize_base_url('/instances/tenant1/public') === '', 'hestia prefix alone stripped to root');
test_assert(lawnding_normalize_base_url('/instances/tenant1/public/subdir') === '/subdir', 'hestia prefix with subpath keeps subdir only');
