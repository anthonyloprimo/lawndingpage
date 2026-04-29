<?php
// Tests for asset URL / path helpers in lp-bootstrap.php.
// These functions depend on $_SERVER context; we set DOCUMENT_ROOT to
// get consistent web-root behaviour rather than the CLI fallback of /public.
require_once __DIR__ . '/bootstrap.php';

$_SERVER['DOCUMENT_ROOT'] = lawnding_public_path();

// ---- lawnding_asset_url ----

test_assert(lawnding_asset_url('') === '/', 'empty path → /');
test_assert(lawnding_asset_url(null) === '/', 'null path → /');
test_assert(lawnding_asset_url('res/img/logo.jpg') === '/res/img/logo.jpg', 'path prefixed with /');
test_assert(lawnding_asset_url('/res/img/logo.jpg') === '/res/img/logo.jpg', 'leading / in path normalised');

// ---- lawnding_normalize_asset_path ----

test_assert(lawnding_normalize_asset_path('https://example.com/icon.svg') === 'https://example.com/icon.svg', 'external URL passes through');
test_assert(lawnding_normalize_asset_path('http://example.com/icon.svg') === 'http://example.com/icon.svg', 'http URL passes through');
test_assert(lawnding_normalize_asset_path('/res/img/logo.jpg') === 'res/img/logo.jpg', 'absolute res path → relative res/');
test_assert(lawnding_normalize_asset_path('res/img/logo.jpg') === 'res/img/logo.jpg', 'relative res path unchanged');
test_assert(lawnding_normalize_asset_path('/public/res/img/logo.jpg') === 'res/img/logo.jpg', 'absolute public/res → res/');
test_assert(lawnding_normalize_asset_path('public/res/img/logo.jpg') === 'res/img/logo.jpg', 'relative public/res → res/');
test_assert(lawnding_normalize_asset_path('') === '', 'empty → empty');
test_assert(lawnding_normalize_asset_path(null) === null, 'null → null');

// ---- lawnding_make_asset_url ----

test_assert(lawnding_make_asset_url('https://example.com/bg.jpg') === 'https://example.com/bg.jpg', 'external URL passes through unchanged');
test_assert(lawnding_make_asset_url('http://example.com/bg.jpg') === 'http://example.com/bg.jpg', 'http URL passes through unchanged');
test_assert(lawnding_make_asset_url('res/img/bg.jpg') === '/res/img/bg.jpg', 'res/ receives base prefix');
test_assert(lawnding_make_asset_url('/res/img/bg.jpg') === '/res/img/bg.jpg', '/res/ prefixed correctly');
test_assert(lawnding_make_asset_url('public/res/img/bg.jpg') === '/res/img/bg.jpg', 'public/res/ collapsed to res/');
test_assert(lawnding_make_asset_url('') === '', 'empty → empty');
test_assert(lawnding_make_asset_url(null) === null, 'null → null');
test_assert(lawnding_make_asset_url('plugins/telegram/auth.php') === 'plugins/telegram/auth.php', 'non-res path passes through');

// ---- lawnding_versioned_local_asset_url (pure branches) ----

test_assert(lawnding_versioned_local_asset_url('') === '', 'empty path returns empty');

$extResult = lawnding_versioned_local_asset_url('https://example.com/bg.jpg', 'v1.12.7');
test_assert(str_contains($extResult, 'v=v1.12.7'), 'external URL receives fallback version token');
test_assert(str_starts_with($extResult, 'https://example.com/bg.jpg'), 'external URL preserved');

test_assert(lawnding_versioned_local_asset_url('https://example.com/bg.jpg', '') === 'https://example.com/bg.jpg', 'external URL unchanged when no fallback token');
test_assert(str_contains(lawnding_versioned_local_asset_url('//example.com/bg.jpg', '123'), 'v=123'), 'protocol-relative URL receives fallback token');
test_assert(str_contains(lawnding_versioned_local_asset_url('plugins/telegram/auth.php', 'abc'), 'v=abc'), 'non-res path receives fallback token');

// ---- lawnding_footer_platform_html ----

$footer = lawnding_footer_platform_html();
test_assert(str_contains($footer, 'href="https://github.com/anthonyloprimo/lawndingpage"'), 'footer: GitHub link present');
test_assert(str_contains($footer, 'lawnding.page'), 'footer: lawnding.page link present');
test_assert(str_contains($footer, '[CHANGELOG]'), 'footer: changelog link present');
test_assert(str_contains($footer, '<svg'), 'footer: GitHub SVG icon present');
test_assert(str_contains($footer, 'footerGhLink'), 'footer: CSS class present');

// With SITE_VERSION defined, the version string appears.
if (!defined('SITE_VERSION')) {
    define('SITE_VERSION', 'v9.9.9-test');
}
$footerWithVersion = lawnding_footer_platform_html();
test_assert(str_contains($footerWithVersion, 'v9.9.9-test'), 'footer: SITE_VERSION rendered when defined');
test_assert(!str_contains($footerWithVersion, 'v1.12.7'), 'footer: version from define() is used, not hardcoded');
