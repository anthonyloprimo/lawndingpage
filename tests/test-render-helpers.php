<?php
// Tests for pure render helpers in public/index.php.
// public/index.php is a full page template — suppress the expected
// CLI header warnings (output started before require) and buffer its
// output so we can reach the function definitions without side effects.
require_once __DIR__ . '/bootstrap.php';

$oldLevel = error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
ob_start();
require_once __DIR__ . '/../public/index.php';
ob_end_clean();
error_reporting($oldLevel);

// ---- lawnding_icon_svg ----

$linksIcon = lawnding_icon_svg('links');
test_assert(str_starts_with($linksIcon, '<svg'), 'links icon returns SVG element');
test_assert(str_contains($linksIcon, 'viewBox="0 0 24 24"'), 'links icon has viewBox');
test_assert(str_contains($linksIcon, '<path d="'), 'links icon has path');
test_assert(lawnding_icon_svg('nonexistent') === '', 'unknown icon name → empty string');
test_assert(lawnding_icon_svg('') === '', 'empty icon name → empty string');
test_assert(!str_contains($linksIcon, '<script'), 'icon SVG contains no script tag');

// ---- lawnding_render_link_item ----

// Standard link.
$link = lawnding_render_link_item([
    'type' => 'link',
    'id' => 'example-link',
    'href' => 'https://example.com',
    'text' => 'Example',
    'title' => 'An example link',
]);
test_assert(str_contains($link, 'linkItem'), 'link: has linkItem class');
test_assert(str_contains($link, 'id="example-link"'), 'link: id attribute rendered');
test_assert(str_contains($link, 'href="https://example.com"'), 'link: href rendered');
test_assert(str_contains($link, '>Example<'), 'link: text content rendered');
test_assert(str_contains($link, 'title="An example link"'), 'link: title attribute rendered');

// Full-width link.
$fullWidth = lawnding_render_link_item([
    'type' => 'link', 'id' => 'fw', 'href' => '#', 'text' => 'Wide', 'title' => '',
    'fullWidth' => true,
]);
test_assert(str_contains($fullWidth, 'fullWidth'), 'link: fullWidth class added');

// CTA link.
$cta = lawnding_render_link_item([
    'type' => 'link', 'id' => 'cta-link', 'href' => '#', 'text' => 'Join', 'title' => '',
    'cta' => true,
]);
test_assert(str_contains($cta, 'linkTelegram cta'), 'link: cta class on anchor');

// Separator.
$sep = lawnding_render_link_item(['type' => 'separator']);
test_assert(str_contains($sep, 'separator'), 'separator: li class');
test_assert(str_contains($sep, 'aria-hidden="true"'), 'separator: aria-hidden set');
test_assert(str_contains($sep, '<hr>'), 'separator: hr element rendered');

// Edge cases.
test_assert(lawnding_render_link_item(['type' => 'widget']) === '', 'unknown type → empty string');
test_assert(lawnding_render_link_item([]) === '', 'missing type → empty string');
test_assert(lawnding_render_link_item('garbage') === '', 'non-array → empty string');

// ---- lawnding_normalize_external_url ----

test_assert(lawnding_normalize_external_url('https://example.com') === 'https://example.com', 'https passes through');
test_assert(lawnding_normalize_external_url('http://example.com') === 'http://example.com', 'http passes through');
test_assert(lawnding_normalize_external_url('example.com') === 'https://example.com', 'bare domain gets https:// prefixed');
test_assert(lawnding_normalize_external_url('') === '', 'empty → empty');
test_assert(lawnding_normalize_external_url('  https://example.com  ') === 'https://example.com', 'whitespace trimmed');

// ---- lawnding_external_nav_domain ----

test_assert(lawnding_external_nav_domain('https://example.com/page') === 'example.com', 'domain extracted from full URL');
test_assert(lawnding_external_nav_domain('https://sub.example.com/path') === 'sub.example.com', 'subdomain preserved');
test_assert(lawnding_external_nav_domain('https://example.com') === 'example.com', 'domain-only URL');
test_assert(lawnding_external_nav_domain('example.com') === 'example.com', 'bare domain passes through');
test_assert(lawnding_external_nav_domain('') === '', 'empty → empty');

// ---- lawnding_google_favicon_url ----

$favicon = lawnding_google_favicon_url('example.com');
test_assert(str_starts_with($favicon, 'https://www.google.com/s2/favicons'), 'favicon: google favicon service URL');
test_assert(str_contains($favicon, 'sz=64'), 'favicon: size param');
test_assert(str_contains($favicon, 'domain=example.com'), 'favicon: domain param');
test_assert(lawnding_google_favicon_url('') === '', 'favicon: empty domain → empty');

// ---- lawnding_nav_label ----

test_assert(lawnding_nav_label('About Us') === 'About Us', 'plain name passes through');
$longLabel = lawnding_nav_label('VeryLongNameThatExceedsTheLimit');
test_assert(strlen($longLabel) < 20, 'long name truncated');
test_assert(str_ends_with($longLabel, '...'), 'truncated name ends with three dots');

// ---- lawnding_public_absolute_url ----

$absUrl = lawnding_public_absolute_url('admin/');
// In CLI context this may or may not get a / prefix — just check the path is there.
test_assert(str_contains($absUrl, 'admin/'), 'absolute url contains the admin path');

// ---- lawnding_auth_link_allowed ----
// Note: links MUST have 'type' => 'link' — the function gates on type.

test_assert(lawnding_auth_link_allowed(['type' => 'link', 'content' => 'sfw'], 'sfw') === true, 'sfw link allowed for sfw user');
test_assert(lawnding_auth_link_allowed(['type' => 'link', 'content' => 'sfw'], 'nsfw') === true, 'sfw link allowed for nsfw user');
test_assert(lawnding_auth_link_allowed(['type' => 'link', 'content' => 'nsfw'], 'sfw') === false, 'nsfw link denied for sfw user');
test_assert(lawnding_auth_link_allowed(['type' => 'link', 'content' => 'nsfw'], 'nsfw') === true, 'nsfw link allowed for nsfw user');
test_assert(lawnding_auth_link_allowed(['type' => 'link', 'href' => '#'], 'sfw') === true, 'link without content field defaults to sfw (allowed)');
test_assert(lawnding_auth_link_allowed(['type' => 'link', 'href' => '#'], '') === true, 'sfw-default link allowed even with empty clearance');
test_assert(lawnding_auth_link_allowed(['type' => 'separator'], 'sfw') === true, 'separators always allowed');
test_assert(lawnding_auth_link_allowed([], 'sfw') === false, 'missing type → denied');

// ---- lawnding_filter_auth_links ----

$links = [
    ['type' => 'link', 'id' => 'a', 'href' => '#', 'text' => 'Public'],
    ['type' => 'link', 'id' => 'b', 'href' => '#', 'text' => 'SFW', 'content' => 'sfw'],
    ['type' => 'separator'],
    ['type' => 'link', 'id' => 'c', 'href' => '#', 'text' => 'NSFW', 'content' => 'nsfw'],
    ['type' => 'link', 'id' => 'd', 'href' => '#', 'text' => 'Also SFW', 'content' => 'sfw'],
];

// SFW user sees sfw links + public, not nsfw.
$sfwFiltered = lawnding_filter_auth_links($links, 'sfw');
$sfwIds = array_map(fn($item) => $item['id'] ?? ($item['type'] ?? ''), $sfwFiltered);
test_assert(in_array('a', $sfwIds, true), 'sfw filter: public link kept');
test_assert(in_array('b', $sfwIds, true), 'sfw filter: sfw link kept');
test_assert(!in_array('c', $sfwIds, true), 'sfw filter: nsfw link removed');
test_assert(in_array('d', $sfwIds, true), 'sfw filter: second sfw link kept');

// NSFW user sees everything.
$nsfwFiltered = lawnding_filter_auth_links($links, 'nsfw');
test_assert(count($nsfwFiltered) >= 5, 'nsfw filter: all items kept (links + separator)');

// Empty clearance — sfw links (including the default) are visible; only nsfw is hidden.
$noneFiltered = lawnding_filter_auth_links($links, '');
$noneIds = array_map(fn($item) => $item['id'] ?? ($item['type'] ?? ''), $noneFiltered);
test_assert(in_array('a', $noneIds, true), 'no clearance: public link (default sfw) kept');
test_assert(in_array('b', $noneIds, true), 'no clearance: explicit sfw link kept');
test_assert(!in_array('c', $noneIds, true), 'no clearance: nsfw-gated link removed');
test_assert(in_array('d', $noneIds, true), 'no clearance: second sfw link kept');

// Separator after a removed link is dropped (no leading content to separate).
$linksWithSepAfterGated = [
    ['type' => 'link', 'id' => 'gated', 'href' => '#', 'text' => 'Gated', 'content' => 'nsfw'],
    ['type' => 'separator'],
    ['type' => 'link', 'id' => 'pub', 'href' => '#', 'text' => 'Public'],
];
$filtered = lawnding_filter_auth_links($linksWithSepAfterGated, 'sfw');
$filteredIds = array_map(fn($item) => $item['id'] ?? ($item['type'] ?? ''), $filtered);
test_assert(count($filteredIds) === 1, 'gated link removed, trailing separator dropped');
test_assert($filteredIds[0] === 'pub', 'remaining item is the public link');
