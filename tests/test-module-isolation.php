<?php
// Guard test: enforces that "fully migrated" modules don't have their ids
// hardcoded into core code. Pairs with admin/modules/MIGRATION.md — when
// you finish migrating a quasi-module per that workflow, add its id to
// $migratedModules and this test ratchets up to prevent regression.
//
// Trade-off (intentional): this test is opt-in by module. Modules NOT in
// $migratedModules (today: externalLink) still have hardcoded branches;
// they have their own backlog memories tracking the work. The test exists
// to keep migrated modules clean and to make the next migration trivial —
// add the id to the list and the guard rail is up.
//
// Opt-out marker: a line containing `allow-module-coupling:` (in a
// comment, on the same line as the violation OR in a contiguous comment
// block immediately above) exempts that occurrence. Use only with a
// FIXME / backlog reference explaining why the coupling is tolerated;
// the marker forces a justification at the call site.
require_once __DIR__ . '/bootstrap.php';

$migratedModules = [
    'mediaGallery',
    'eventList',
];

// Core files that participate in the manifest-driven dispatch and should
// stay module-agnostic. Files inside admin/modules/<id>/ are excluded by
// definition (module-id references inside their own folder are allowed).
// PHP and JS share `//` and `/* */` comment syntax, so the same comment-
// stripping logic below handles both — keeping them in one list.
$coreFiles = [
    'lp-bootstrap.php',
    'public/index.php',
    'public/admin/index.php',
    'public/res/scr/save-config.php',
    'admin/config.php',
    'public/res/scr/config.js',
    'public/res/scr/app.js',
];

$repoRoot = realpath(__DIR__ . '/..');

// Walk backward from $startIdx through any contiguous comment lines and
// return true if any of them contains the opt-out marker. Stops at the
// first non-comment line or start-of-file.
$hasMarkerAbove = function (array $lines, int $startIdx): bool {
    for ($i = $startIdx; $i >= 0; $i--) {
        $prev = $lines[$i];
        if (!preg_match('#^\s*(//|\#|\*)#', $prev)) {
            return false;
        }
        if (stripos($prev, 'allow-module-coupling:') !== false) {
            return true;
        }
    }
    return false;
};

foreach ($migratedModules as $modId) {
    foreach ($coreFiles as $relPath) {
        $absPath = $repoRoot . '/' . $relPath;
        if (!is_readable($absPath)) {
            test_assert(false, "core file expected by module-isolation test is missing: {$relPath}");
            continue;
        }

        $violations = [];
        $lines = file($absPath);
        foreach ($lines as $idx => $line) {
            $lineNum = $idx + 1;
            // Skip lines that are entirely comments. Covers PHP // and #
            // single-line comments, plus block-comment continuation lines
            // that start with ` * ` or `*/`. Doesn't track multi-line
            // block-comment state — line-by-line is good enough for this
            // codebase (no narrative /* */ blocks containing module ids).
            if (preg_match('#^\s*(//|\#|\*)#', $line)) {
                continue;
            }
            // Strip trailing line comments and inline /* */ fragments
            // before checking, so `$x = $y; // mediaGallery is fine` and
            // `$x = $y; /* mediaGallery note */` don't trigger.
            $stripped = preg_replace('#//.*$#', '', $line);
            $stripped = preg_replace('#/\*.*?\*/#', '', $stripped);
            if (trim($stripped) === '') {
                continue;
            }
            // Word-boundary match catches 'mediaGallery', "mediaGallery",
            // mediaGalleryContent-, mediaGallery's, etc., but NOT
            // lawnding_module_* (different prefix entirely) or
            // media_gallery_* (snake_case; different string at the
            // character level).
            if (!preg_match('#\b' . preg_quote($modId, '#') . '\b#', $stripped)) {
                continue;
            }
            // Exemption: `allow-module-coupling:` marker on the same line
            // (trailing comment) or in a contiguous comment block
            // immediately above this line.
            $sameLineMarker = stripos($line, 'allow-module-coupling:') !== false;
            if ($sameLineMarker || $hasMarkerAbove($lines, $idx - 1)) {
                continue;
            }
            $violations[] = "    {$relPath}:{$lineNum}: " . trim($line);
        }

        test_assert(
            empty($violations),
            "Module '{$modId}' is in \$migratedModules but appears in non-comment code in {$relPath}.\n"
            . "  Either remove the coupling (see admin/modules/MIGRATION.md for the workflow) or, if it's\n"
            . "  an intentional exception, mark it with `allow-module-coupling: <reason>` in a comment\n"
            . "  on the same line or in a contiguous comment block immediately above. Pair the marker\n"
            . "  with a FIXME and a backlog reference so the tech debt stays visible.\n"
            . "  Offending lines:\n" . implode("\n", $violations)
        );
    }
}
