<?php
// Shared markdown content-gating helpers for [sfw]...[/sfw] and [nsfw]...[/nsfw].

function lawnding_markdown_gate_normalize_clearance($clearance) {
    $level = is_string($clearance) ? strtolower(trim($clearance)) : '';
    if ($level === 'sfw' || $level === 'nsfw') {
        return $level;
    }
    return 'none';
}

function lawnding_markdown_gate_match_tag(string $markdown, int $offset): ?array {
    $remaining = strlen($markdown) - $offset;
    if ($remaining < 5 || $markdown[$offset] !== '[') {
        return null;
    }
    $slice7 = $remaining >= 7 ? strtolower(substr($markdown, $offset, 7)) : '';
    $slice6 = $remaining >= 6 ? strtolower(substr($markdown, $offset, 6)) : '';
    $slice5 = strtolower(substr($markdown, $offset, 5));
    if ($slice5 === '[sfw]') {
        return ['type' => 'open', 'gate' => 'sfw', 'length' => 5];
    }
    if ($slice6 === '[nsfw]') {
        return ['type' => 'open', 'gate' => 'nsfw', 'length' => 6];
    }
    if ($slice6 === '[/sfw]') {
        return ['type' => 'close', 'gate' => 'sfw', 'length' => 6];
    }
    if ($slice7 === '[/nsfw]') {
        return ['type' => 'close', 'gate' => 'nsfw', 'length' => 7];
    }
    return null;
}

function lawnding_markdown_gate_append(string &$out, string $chunk, int $suppressedDepth): void {
    if ($suppressedDepth <= 0) {
        $out .= $chunk;
    }
}

function lawnding_markdown_gate_parse(string $markdown, string $clearance): array {
    $clearance = lawnding_markdown_gate_normalize_clearance($clearance);
    $allowSfw = $clearance === 'sfw' || $clearance === 'nsfw';
    $allowNsfw = $clearance === 'nsfw';
    $len = strlen($markdown);
    $out = '';
    $stack = [];
    $suppressedDepth = 0;
    $inFence = false;
    $fenceTicks = 0;
    $inInline = false;
    $inlineTicks = 0;

    $i = 0;
    while ($i < $len) {
        $atLineStart = $i === 0 || $markdown[$i - 1] === "\n";

        if ($atLineStart) {
            $probe = $i;
            $indent = 0;
            while ($probe < $len && $indent < 3 && $markdown[$probe] === ' ') {
                $probe++;
                $indent++;
            }
            $ticks = 0;
            while (($probe + $ticks) < $len && $markdown[$probe + $ticks] === '`') {
                $ticks++;
            }
            if ($ticks >= 3) {
                if (!$inFence) {
                    $inFence = true;
                    $fenceTicks = $ticks;
                } elseif ($ticks >= $fenceTicks) {
                    $inFence = false;
                    $fenceTicks = 0;
                }
            }
        }

        if (!$inFence && $markdown[$i] === '`') {
            $tickRun = 0;
            while (($i + $tickRun) < $len && $markdown[$i + $tickRun] === '`') {
                $tickRun++;
            }
            $chunk = substr($markdown, $i, $tickRun);
            lawnding_markdown_gate_append($out, $chunk, $suppressedDepth);
            if ($inInline) {
                if ($tickRun >= $inlineTicks) {
                    $inInline = false;
                    $inlineTicks = 0;
                }
            } else {
                $inInline = true;
                $inlineTicks = $tickRun;
            }
            $i += $tickRun;
            continue;
        }

        if (!$inFence && !$inInline) {
            $tag = lawnding_markdown_gate_match_tag($markdown, $i);
            if ($tag !== null) {
                if ($tag['type'] === 'open') {
                    $gate = $tag['gate'];
                    $isAllowed = $gate === 'sfw' ? $allowSfw : $allowNsfw;
                    $stack[] = ['gate' => $gate, 'visible' => $isAllowed];
                    if (!$isAllowed) {
                        $suppressedDepth++;
                    }
                    $i += $tag['length'];
                    continue;
                }

                if (empty($stack)) {
                    return [
                        'ok' => false,
                        'error' => 'Unmatched closing content-gating tag.',
                        'markdown' => lawnding_markdown_gate_fail_closed($markdown),
                    ];
                }

                $top = array_pop($stack);
                if (($top['gate'] ?? '') !== $tag['gate']) {
                    return [
                        'ok' => false,
                        'error' => 'Overlapping or mismatched content-gating tags are not allowed.',
                        'markdown' => lawnding_markdown_gate_fail_closed($markdown),
                    ];
                }
                if (empty($top['visible'])) {
                    $suppressedDepth = max(0, $suppressedDepth - 1);
                }
                $i += $tag['length'];
                continue;
            }
        }

        lawnding_markdown_gate_append($out, $markdown[$i], $suppressedDepth);
        $i++;
    }

    if (!empty($stack)) {
        return [
            'ok' => false,
            'error' => 'Unclosed content-gating tag.',
            'markdown' => lawnding_markdown_gate_fail_closed($markdown),
        ];
    }

    return ['ok' => true, 'error' => '', 'markdown' => $out];
}

function lawnding_markdown_gate_fail_closed(string $markdown): string {
    $len = strlen($markdown);
    $out = '';
    $depth = 0;
    $inFence = false;
    $fenceTicks = 0;
    $inInline = false;
    $inlineTicks = 0;
    $i = 0;

    while ($i < $len) {
        $atLineStart = $i === 0 || $markdown[$i - 1] === "\n";

        if ($atLineStart) {
            $probe = $i;
            $indent = 0;
            while ($probe < $len && $indent < 3 && $markdown[$probe] === ' ') {
                $probe++;
                $indent++;
            }
            $ticks = 0;
            while (($probe + $ticks) < $len && $markdown[$probe + $ticks] === '`') {
                $ticks++;
            }
            if ($ticks >= 3) {
                if (!$inFence) {
                    $inFence = true;
                    $fenceTicks = $ticks;
                } elseif ($ticks >= $fenceTicks) {
                    $inFence = false;
                    $fenceTicks = 0;
                }
            }
        }

        if (!$inFence && $markdown[$i] === '`') {
            $tickRun = 0;
            while (($i + $tickRun) < $len && $markdown[$i + $tickRun] === '`') {
                $tickRun++;
            }
            if ($depth <= 0) {
                $out .= substr($markdown, $i, $tickRun);
            }
            if ($inInline) {
                if ($tickRun >= $inlineTicks) {
                    $inInline = false;
                    $inlineTicks = 0;
                }
            } else {
                $inInline = true;
                $inlineTicks = $tickRun;
            }
            $i += $tickRun;
            continue;
        }

        if (!$inFence && !$inInline) {
            $tag = lawnding_markdown_gate_match_tag($markdown, $i);
            if ($tag !== null) {
                if ($tag['type'] === 'open') {
                    $depth++;
                } elseif ($depth > 0) {
                    $depth--;
                }
                $i += $tag['length'];
                continue;
            }
        }

        if ($depth <= 0) {
            $out .= $markdown[$i];
        }
        $i++;
    }

    return $out;
}

function lawnding_markdown_gate_apply(string $markdown, string $clearance, bool $strict = false): array {
    $parsed = lawnding_markdown_gate_parse($markdown, $clearance);
    if ($parsed['ok'] || !$strict) {
        return $parsed;
    }
    return $parsed;
}

