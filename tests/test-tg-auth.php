<?php
// Tests for pure Telegram auth helpers in admin/lib/tg-auth.php.
// Functions that require the bot API (lawnding_tg_user_access, lawnding_tg_user_content_level,
// lawnding_tg_user_permissions, lawnding_load_tg_config, lawnding_tg_cache_*) are skipped.
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../admin/lib/tg-auth.php';

// ---- lawnding_tg_config_defaults ----

$defaults = lawnding_tg_config_defaults();
test_assert($defaults['bot_username'] === '', 'default bot_username is empty');
test_assert($defaults['bot_token'] === '', 'default bot_token is empty');
test_assert($defaults['group_ids'] === [], 'default group_ids is empty array');
test_assert($defaults['allowed_statuses'] === ['member', 'administrator', 'creator'], 'default allowed_statuses');
test_assert($defaults['membership_cache_ttl_minutes'] === 30, 'default cache TTL is 30');
test_assert(is_string($defaults['unauthorized_message']) && $defaults['unauthorized_message'] !== '', 'default unauthorized message is non-empty');

// ---- lawnding_tg_normalize_content_level ----

test_assert(lawnding_tg_normalize_content_level('sfw') === 'sfw', 'sfw → sfw');
test_assert(lawnding_tg_normalize_content_level('SFW') === 'sfw', 'SFW → sfw (lowercased)');
test_assert(lawnding_tg_normalize_content_level('nsfw') === 'nsfw', 'nsfw → nsfw');
test_assert(lawnding_tg_normalize_content_level('NSFW') === 'nsfw', 'NSFW → nsfw (lowercased)');
test_assert(lawnding_tg_normalize_content_level('garbage') === 'sfw', 'unknown → sfw (default)');
test_assert(lawnding_tg_normalize_content_level('garbage', 'nsfw') === 'nsfw', 'unknown + nsfw default → nsfw');
test_assert(lawnding_tg_normalize_content_level('', '') === '', 'empty value + empty default → empty');

// ---- lawnding_tg_content_rank ----

test_assert(lawnding_tg_content_rank('nsfw') > lawnding_tg_content_rank('sfw'), 'nsfw ranks higher than sfw');
test_assert(lawnding_tg_content_rank('sfw') > lawnding_tg_content_rank(''), 'sfw ranks higher than empty');
test_assert(lawnding_tg_content_rank('') === 0, 'empty ranks 0');
test_assert(lawnding_tg_content_rank('sfw') === 1, 'sfw ranks 1');
test_assert(lawnding_tg_content_rank('nsfw') === 2, 'nsfw ranks 2');

// ---- lawnding_tg_higher_content_level ----

test_assert(lawnding_tg_higher_content_level('sfw', 'nsfw') === 'nsfw', 'nsfw > sfw');
test_assert(lawnding_tg_higher_content_level('nsfw', 'sfw') === 'nsfw', 'nsfw > sfw (swapped args)');
test_assert(lawnding_tg_higher_content_level('sfw', 'sfw') === 'sfw', 'equal → returns second');
test_assert(lawnding_tg_higher_content_level('', 'sfw') === 'sfw', 'sfw > empty');

// ---- lawnding_tg_eligible_permissions ----

$eligible = lawnding_tg_eligible_permissions();
test_assert(in_array('edit_site', $eligible, true), 'edit_site is eligible');
test_assert(in_array('add_users', $eligible, true), 'add_users is eligible');
test_assert(in_array('edit_users', $eligible, true), 'edit_users is eligible');
test_assert(in_array('remove_users', $eligible, true), 'remove_users is eligible');
test_assert(!in_array('full_admin', $eligible, true), 'full_admin is NOT eligible (bcrypt-only)');
test_assert(!in_array('master', $eligible, true), 'master is NOT eligible');

// ---- lawnding_tg_normalize_user_scope ----

test_assert(lawnding_tg_normalize_user_scope('sfw') === 'sfw', 'sfw scope → sfw');
test_assert(lawnding_tg_normalize_user_scope('nsfw') === 'nsfw', 'nsfw scope → nsfw');
test_assert(lawnding_tg_normalize_user_scope('invalid') === 'sfw', 'invalid scope → sfw (default)');
test_assert(lawnding_tg_normalize_user_scope('invalid', 'nsfw') === 'nsfw', 'invalid + nsfw default → nsfw');

// ---- lawnding_tg_normalize_user_entries ----

// Bare string (legacy format).
$bare = lawnding_tg_normalize_user_entries(['123456789']);
test_assert(count($bare) === 1 && $bare[0]['id'] === '123456789' && $bare[0]['content'] === 'sfw', 'bare string user entry normalises');

// Structured entries.
$structured = lawnding_tg_normalize_user_entries([
    ['id' => '111', 'content' => 'nsfw'],
    ['id' => '222', 'content' => 'sfw'],
]);
test_assert(count($structured) === 2, 'two structured entries');
test_assert($structured[0]['content'] === 'nsfw', 'first entry nsfw');

// Duplicate IDs — keeps higher content level.
$dup = lawnding_tg_normalize_user_entries([
    ['id' => '111', 'content' => 'sfw'],
    ['id' => '111', 'content' => 'nsfw'],
]);
test_assert(count($dup) === 1, 'duplicates merged into one entry');
test_assert($dup[0]['content'] === 'nsfw', 'higher content level wins on duplicate');

// Invalid entries dropped.
test_assert(count(lawnding_tg_normalize_user_entries(['not numeric'])) === 0, 'non-numeric user ID dropped');
test_assert(count(lawnding_tg_normalize_user_entries([])) === 0, 'empty array → empty');
test_assert(count(lawnding_tg_normalize_user_entries(null)) === 0, 'null → empty');

// ---- lawnding_tg_normalize_group_entries ----

// Legacy bare string.
$legacyGroup = lawnding_tg_normalize_group_entries(['-1001234567890']);
test_assert(count($legacyGroup) === 1 && $legacyGroup[0]['id'] === '-1001234567890' && $legacyGroup[0]['content'] === 'sfw', 'legacy bare string group normalises');

// Structured with permissions.
$withPerms = lawnding_tg_normalize_group_entries([
    ['id' => '-100111', 'content' => 'sfw', 'permissions' => ['edit_site', 'add_users']],
]);
test_assert(count($withPerms) === 1, 'structured group entry');
test_assert(count($withPerms[0]['permissions']) === 2, 'both permissions kept');
test_assert(in_array('edit_site', $withPerms[0]['permissions'], true), 'edit_site kept');

// Disallowed permissions silently dropped.
$badPerm = lawnding_tg_normalize_group_entries([
    ['id' => '-100999', 'permissions' => ['full_admin', 'edit_site', 'garbage']],
]);
test_assert(count($badPerm[0]['permissions']) === 1, 'only edit_site kept, others dropped');
test_assert($badPerm[0]['permissions'][0] === 'edit_site', 'edit_site survived');

// Duplicates merged, permissions unioned.
$dupGroup = lawnding_tg_normalize_group_entries([
    ['id' => '-100111', 'content' => 'sfw', 'permissions' => ['edit_site']],
    ['id' => '-100111', 'content' => 'nsfw', 'permissions' => ['add_users']],
]);
test_assert(count($dupGroup) === 1, 'duplicate groups merged');
test_assert($dupGroup[0]['content'] === 'nsfw', 'higher content wins');
test_assert(count($dupGroup[0]['permissions']) === 2, 'permissions unioned');

// ---- lawnding_tg_access_clearance ----

test_assert(lawnding_tg_access_clearance(['sfw' => true, 'nsfw' => true]) === 'nsfw', 'both flags → nsfw');
test_assert(lawnding_tg_access_clearance(['sfw' => true, 'nsfw' => false]) === 'sfw', 'sfw only → sfw');
test_assert(lawnding_tg_access_clearance(['sfw' => false, 'nsfw' => false]) === '', 'neither → empty');
test_assert(lawnding_tg_access_clearance(['sfw' => false, 'nsfw' => true]) === 'nsfw', 'nsfw=true yields nsfw (access bits → string only; the sfw-prerequisite check is the caller\'s job)');

// ---- lawnding_tg_access_from_clearance (round-trip) ----

test_assert(lawnding_tg_access_from_clearance('nsfw') === ['sfw' => true, 'nsfw' => true], 'nsfw clearance → both flags');
test_assert(lawnding_tg_access_from_clearance('sfw') === ['sfw' => true, 'nsfw' => false], 'sfw clearance → sfw flag only');
test_assert(lawnding_tg_access_from_clearance('') === ['sfw' => false, 'nsfw' => false], 'empty clearance → no flags');

// ---- lawnding_tg_apply_user_override ----

// Whitelist: sfw scope grants sfw (no nsfw).
$wlSfw = ['sfw' => false, 'nsfw' => false];
lawnding_tg_apply_user_override($wlSfw, ['id' => '123', 'content' => 'sfw'], false);
test_assert($wlSfw['sfw'] === true && $wlSfw['nsfw'] === false, 'whitelist sfw grants sfw only');

// Whitelist: nsfw scope grants both.
$wlNsfw = ['sfw' => false, 'nsfw' => false];
lawnding_tg_apply_user_override($wlNsfw, ['id' => '123', 'content' => 'nsfw'], false);
test_assert($wlNsfw['sfw'] === true && $wlNsfw['nsfw'] === true, 'whitelist nsfw grants both');

// Blacklist: sfw scope denies sfw AND nsfw (nsfw requires sfw prerequisite).
$blSfw = ['sfw' => true, 'nsfw' => true];
lawnding_tg_apply_user_override($blSfw, ['id' => '123', 'content' => 'sfw'], true);
test_assert($blSfw['sfw'] === false && $blSfw['nsfw'] === false, 'blacklist sfw denies both');

// Blacklist: nsfw scope denies both.
$blNsfw = ['sfw' => true, 'nsfw' => true];
lawnding_tg_apply_user_override($blNsfw, ['id' => '123', 'content' => 'nsfw'], true);
test_assert($blNsfw['sfw'] === false && $blNsfw['nsfw'] === false, 'blacklist nsfw denies both');

// Null entry is a no-op.
$access = ['sfw' => true, 'nsfw' => false];
lawnding_tg_apply_user_override($access, null, false);
test_assert($access['sfw'] === true, 'null entry is no-op on whitelist');

// ---- lawnding_tg_user_entry_by_id ----

$entries = [
    ['id' => '111', 'content' => 'sfw'],
    ['id' => '222', 'content' => 'nsfw'],
];
test_assert(lawnding_tg_user_entry_by_id($entries, '111') !== null, 'found matching entry');
test_assert(lawnding_tg_user_entry_by_id($entries, '999') === null, 'not found → null');
test_assert(lawnding_tg_user_entry_by_id([], '111') === null, 'empty array → null');

// ---- lawnding_format_tg_display_name ----

// Prefers @handle.
test_assert(lawnding_format_tg_display_name(['username' => 'jdoe', 'first_name' => 'John'], 12345) === '@jdoe', 'prefers @handle');

// Falls back to first + last.
test_assert(lawnding_format_tg_display_name(['first_name' => 'John', 'last_name' => 'Doe'], 12345) === 'John Doe', 'first+last when no handle');
test_assert(lawnding_format_tg_display_name(['first_name' => 'John'], 12345) === 'John', 'first only');

// Falls back to User #id.
test_assert(lawnding_format_tg_display_name([], 12345) === 'User #12345', 'User #id when no profile');
test_assert(lawnding_format_tg_display_name(null, 12345) === 'User #12345', 'null profile → User #id');

// Empty when nothing provided.
test_assert(lawnding_format_tg_display_name(null, null) === '', 'null + null → empty');

// ---- lawnding_tg_relative_return_path ----

test_assert(lawnding_tg_relative_return_path('/admin/') === '/admin/', 'absolute path passes through');
test_assert(lawnding_tg_relative_return_path('admin') === '/admin', 'relative gets leading /');
test_assert(lawnding_tg_relative_return_path('') === '/', 'empty → /');
test_assert(lawnding_tg_relative_return_path('https://example.com') === '/', 'external URL → / (safety)');
test_assert(lawnding_tg_relative_return_path('//example.com') === '/', 'protocol-relative → / (safety)');
