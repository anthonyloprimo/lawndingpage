<?php
// Tests for content-gating helpers in markdown-gating.php.
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../public/res/scr/markdown-gating.php';

// ---- lawnding_markdown_gate_normalize_clearance ----

test_assert(lawnding_markdown_gate_normalize_clearance('sfw') === 'sfw', 'sfw → sfw');
test_assert(lawnding_markdown_gate_normalize_clearance('SFW') === 'sfw', 'SFW → sfw (lowercased)');
test_assert(lawnding_markdown_gate_normalize_clearance('nsfw') === 'nsfw', 'nsfw → nsfw');
test_assert(lawnding_markdown_gate_normalize_clearance('NSFW') === 'nsfw', 'NSFW → nsfw (lowercased)');
test_assert(lawnding_markdown_gate_normalize_clearance('none') === 'none', 'none → none');
test_assert(lawnding_markdown_gate_normalize_clearance('') === 'none', 'empty → none');
test_assert(lawnding_markdown_gate_normalize_clearance(null) === 'none', 'non-string → none');

// ---- lawnding_markdown_gate_match_tag ----

// Open tags.
$openSfw = lawnding_markdown_gate_match_tag('[sfw]hello', 0);
test_assert($openSfw !== null && $openSfw['type'] === 'open' && $openSfw['gate'] === 'sfw' && $openSfw['length'] === 5, '[sfw] recognised as sfw open tag');

$openNsfw = lawnding_markdown_gate_match_tag('[nsfw]hello', 0);
test_assert($openNsfw !== null && $openNsfw['type'] === 'open' && $openNsfw['gate'] === 'nsfw' && $openNsfw['length'] === 6, '[nsfw] recognised as nsfw open tag');

// Close tags.
$closeSfw = lawnding_markdown_gate_match_tag('[/sfw] more', 0);
test_assert($closeSfw !== null && $closeSfw['type'] === 'close' && $closeSfw['gate'] === 'sfw' && $closeSfw['length'] === 6, '[/sfw] recognised as sfw close tag');

$closeNsfw = lawnding_markdown_gate_match_tag('[/nsfw] more', 0);
test_assert($closeNsfw !== null && $closeNsfw['type'] === 'close' && $closeNsfw['gate'] === 'nsfw' && $closeNsfw['length'] === 7, '[/nsfw] recognised as nsfw close tag');

// Not a tag.
test_assert(lawnding_markdown_gate_match_tag('hello [sfw]', 0) === null, 'text at offset is not a tag');
test_assert(lawnding_markdown_gate_match_tag(' [sfw]', 0) === null, 'leading space not a tag');
test_assert(lawnding_markdown_gate_match_tag('[foo]', 0) === null, '[foo] is not a gating tag');

// Tags in the middle of the string (non-zero offset).
test_assert(lawnding_markdown_gate_match_tag('abc[sfw]xyz', 3) !== null, 'tag found at correct offset');
test_assert(lawnding_markdown_gate_match_tag('abc[sfw]xyz', 0) === null, 'no tag at wrong offset');

// ---- lawnding_markdown_gate_parse ----

// SFW clearance — sfw content shown, nsfw content suppressed.
$sfwResult = lawnding_markdown_gate_parse('Public [sfw]members[/sfw] [nsfw]private[/nsfw] End', 'sfw');
test_assert($sfwResult['ok'] === true, 'sfw parse: ok');
test_assert($sfwResult['markdown'] === 'Public members  End', 'sfw parse: sfw shown, nsfw hidden');

// NSFW clearance — both shown.
$nsfwResult = lawnding_markdown_gate_parse('Public [sfw]members[/sfw] [nsfw]private[/nsfw] End', 'nsfw');
test_assert($nsfwResult['ok'] === true, 'nsfw parse: ok');
test_assert($nsfwResult['markdown'] === 'Public members private End', 'nsfw parse: both shown');

// No clearance — both hidden.
$noneResult = lawnding_markdown_gate_parse('Public [sfw]members[/sfw] [nsfw]private[/nsfw] End', 'none');
test_assert($noneResult['ok'] === true, 'none parse: ok');
test_assert($noneResult['markdown'] === 'Public   End', 'none parse: both hidden');

// Nested tags (sfw inside nsfw or vice versa) — valid if properly nested.
$nestedResult = lawnding_markdown_gate_parse('[sfw]outer [nsfw]inner[/nsfw] tail[/sfw]', 'nsfw');
test_assert($nestedResult['ok'] === true, 'nested tags: ok');
test_assert(str_contains($nestedResult['markdown'], 'outer'), 'nested: outer visible');
test_assert(str_contains($nestedResult['markdown'], 'inner'), 'nested: inner visible');

// Overlapping tags — error.
$overlap = lawnding_markdown_gate_parse('[sfw]text [nsfw]more[/sfw][/nsfw]', 'nsfw');
test_assert($overlap['ok'] === false, 'overlapping tags: error');
test_assert($overlap['error'] !== '', 'overlapping tags: error message set');

// Unclosed tag — error.
$unclosed = lawnding_markdown_gate_parse('[sfw]text without closing', 'sfw');
test_assert($unclosed['ok'] === false, 'unclosed tag: error');
test_assert($unclosed['error'] !== '', 'unclosed tag: error message set');

// Unmatched close — error.
$unmatchedClose = lawnding_markdown_gate_parse('[/sfw] text with no open', 'sfw');
test_assert($unmatchedClose['ok'] === false, 'unmatched close tag: error');

// Fenced code blocks — tags inside ``` should be treated as literal text.
$fenced = lawnding_markdown_gate_parse("```\n[sfw]code[/sfw]\n```\noutside", 'sfw');
test_assert($fenced['ok'] === true, 'fenced code: ok');
test_assert(str_contains($fenced['markdown'], '[sfw]code[/sfw]'), 'fenced code: tags preserved literally');
test_assert(str_contains($fenced['markdown'], 'outside'), 'fenced code: outside text visible');

// Inline code — `[sfw]` should be literal.
$inlined = lawnding_markdown_gate_parse('`[nsfw]hidden?[/nsfw]` visible', 'sfw');
test_assert($inlined['ok'] === true, 'inline code: ok');
test_assert(str_contains($inlined['markdown'], '[nsfw]'), 'inline code: open tag preserved');
test_assert(str_contains($inlined['markdown'], '[/nsfw]'), 'inline code: close tag preserved');

// ---- lawnding_markdown_gate_fail_closed ----

// Strips gated content entirely — "fail closed" means the reader sees nothing gated.
test_assert(lawnding_markdown_gate_fail_closed('[sfw]hello[/sfw] world') === ' world', 'fail_closed: gated content stripped, surrounding text kept');
test_assert(lawnding_markdown_gate_fail_closed('[nsfw]private[/nsfw]') === '', 'fail_closed: nsfw gated content stripped entirely');

// ---- lawnding_markdown_gate_apply ----

// Non-strict mode passes through even with errors (showing stripped version).
$applyNonStrict = lawnding_markdown_gate_apply('[sfw]text[/sfw]', 'sfw', false);
test_assert($applyNonStrict['markdown'] === 'text', 'apply (non-strict): content rendered');

// Strict mode with good input passes.
$applyStrict = lawnding_markdown_gate_apply('[sfw]text[/sfw]', 'sfw', true);
test_assert($applyStrict['ok'] === true && $applyStrict['markdown'] === 'text', 'apply (strict): valid input passes');

// Strict mode with bad input returns error.
$applyStrictBad = lawnding_markdown_gate_apply('[sfw]unclosed', 'sfw', true);
test_assert($applyStrictBad['ok'] === false, 'apply (strict): invalid input fails');
