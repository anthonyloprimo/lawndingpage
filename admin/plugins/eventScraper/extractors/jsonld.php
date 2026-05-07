<?php
// schema.org Event JSON-LD extractor.
//
// Pure function: HTML string + adapter config -> normalized event array.
// No filesystem or network I/O. Unit-testable from tests/.
//
// Output shape (per docs/plans/event-scraper-plugin.md):
//   [
//     [
//       'sourceUid'       => '26609',
//       'name'            => 'Furry Weekend Atlanta 2026',
//       'startDate'       => '2026-05-07',
//       'endDate'         => '2026-05-10',
//       'location'        => 'Atlanta Marriott Marquis, Atlanta, GA',
//       'description'     => '...',
//       'url'             => 'http://furrycons.com/event/26609/...',
//       'registrationUrl' => 'https://furryweekend.com/register/',
//       'image'           => 'https://media.animecons.com/.../logo_C26609.png'
//     ],
//     ...
//   ]
//
// Events without a sourceUid, name, or startDate are skipped (the source
// is assumed buggy, not the parser; logging is the caller's responsibility).

function event_scraper_extract_jsonld(string $html, array $adapter): array {
    $blocks = event_scraper_jsonld_blocks($html);
    if (!$blocks) {
        return [];
    }

    $uidPattern = (string) ($adapter['uidFromUrlPattern'] ?? '');
    $stripYearSuffix = !empty($adapter['stripYearSuffix']);
    $events = [];

    foreach ($blocks as $raw) {
        // Sanitize before decoding: some real-world sites (furrycons.com is
        // one) emit JSON-LD with literal newlines inside description strings,
        // which RFC 8259 forbids. Browsers never parse JSON-LD; SEO crawlers
        // use forgiving parsers, so the bug ships unnoticed. PHP's json_decode
        // is strict, so we make the input compliant first.
        $sanitized = event_scraper_sanitize_jsonld($raw);
        $decoded = json_decode($sanitized, true);
        if (!is_array($decoded)) {
            continue;
        }
        // Top-level may be a single object or an array of objects.
        $items = isset($decoded[0]) ? $decoded : [$decoded];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = $item['@type'] ?? '';
            if ($type !== 'Event') {
                continue;
            }
            $normalized = event_scraper_normalize_jsonld_event($item, $uidPattern, $stripYearSuffix);
            if ($normalized !== null) {
                $events[] = $normalized;
            }
        }
    }

    return $events;
}

// State-machine pass: escape unescaped control characters (0x00-0x1F) found
// inside JSON string values. RFC 8259 requires these to be escaped; some
// sources emit raw newlines inside descriptions. Whitespace BETWEEN tokens
// is left untouched — only chars inside quoted strings are rewritten. A
// no-op on already-valid JSON.
function event_scraper_sanitize_jsonld(string $raw): string {
    $out = '';
    $inString = false;
    $escape = false;
    $len = strlen($raw);
    for ($i = 0; $i < $len; $i++) {
        $c = $raw[$i];
        if ($escape) {
            $out .= $c;
            $escape = false;
            continue;
        }
        if ($c === '\\') {
            $out .= $c;
            $escape = true;
            continue;
        }
        if ($c === '"') {
            $out .= $c;
            $inString = !$inString;
            continue;
        }
        if ($inString && ord($c) < 0x20) {
            switch ($c) {
                case "\n": $out .= '\n'; break;
                case "\r": $out .= '\r'; break;
                case "\t": $out .= '\t'; break;
                case "\b": $out .= '\b'; break;
                case "\f": $out .= '\f'; break;
                default:   $out .= sprintf('\u%04x', ord($c));
            }
            continue;
        }
        $out .= $c;
    }
    return $out;
}

// Returns the JSON text of every <script type="application/ld+json"> block.
// The opening-tag regex tolerates extra attributes and whitespace; the
// content match is non-greedy so multiple blocks on the same page survive.
function event_scraper_jsonld_blocks(string $html): array {
    $pattern = '#<script[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is';
    if (!preg_match_all($pattern, $html, $matches)) {
        return [];
    }
    return $matches[1];
}

// Pure transform: schema.org Event object -> plugin's normalized shape.
// Returns null when required fields are missing or the URL doesn't match
// the adapter's UID pattern.
function event_scraper_normalize_jsonld_event(array $item, string $uidPattern, bool $stripYearSuffix = false): ?array {
    $name = isset($item['name']) && is_string($item['name']) ? trim($item['name']) : '';
    $startDate = isset($item['startDate']) && is_string($item['startDate']) ? trim($item['startDate']) : '';
    $url = isset($item['url']) && is_string($item['url']) ? trim($item['url']) : '';
    if ($name === '' || $startDate === '' || $url === '') {
        return null;
    }

    if ($stripYearSuffix) {
        $name = event_scraper_strip_year_suffix($name);
    }

    $sourceUid = event_scraper_extract_uid($url, $uidPattern);
    if ($sourceUid === '') {
        return null;
    }

    $endDate = isset($item['endDate']) && is_string($item['endDate']) ? trim($item['endDate']) : '';
    $description = isset($item['description']) && is_string($item['description']) ? trim($item['description']) : '';

    return [
        'sourceUid'       => $sourceUid,
        'name'            => $name,
        'startDate'       => $startDate,
        'endDate'         => $endDate,
        'location'        => event_scraper_format_location($item['location'] ?? null),
        'description'     => $description,
        'url'             => $url,
        'registrationUrl' => event_scraper_pick_offer_url($item['offers'] ?? null),
        'image'           => event_scraper_pick_first_string($item['image'] ?? null),
    ];
}

// Strip a trailing 19xx/20xx year (with surrounding whitespace) from an event
// name. Conservative: only matches year-shaped tokens at end of string so
// accidental 4-digit numbers mid-name aren't touched.
function event_scraper_strip_year_suffix(string $name): string {
    return rtrim(preg_replace('/\s+(?:19|20)\d{2}\s*$/', '', $name));
}

// Apply the adapter's UID pattern; empty pattern = no UID derivable.
function event_scraper_extract_uid(string $url, string $pattern): string {
    if ($pattern === '') {
        return '';
    }
    if (preg_match($pattern, $url, $m) && isset($m[1])) {
        return (string) $m[1];
    }
    return '';
}

// Build "Venue, City, State" / "Venue, City" / "City" / "" depending on
// what the source supplies. Tolerates schema.org's two shapes: a plain
// string (just a venue name) or a Place object with optional PostalAddress.
function event_scraper_format_location($location): string {
    if (is_string($location)) {
        return trim($location);
    }
    if (!is_array($location)) {
        return '';
    }

    $parts = [];
    $venue = isset($location['name']) && is_string($location['name']) ? trim($location['name']) : '';
    if ($venue !== '') {
        $parts[] = $venue;
    }

    $address = $location['address'] ?? null;
    if (is_array($address)) {
        $locality = isset($address['addressLocality']) && is_string($address['addressLocality'])
            ? trim($address['addressLocality']) : '';
        $region = isset($address['addressRegion']) && is_string($address['addressRegion'])
            ? trim($address['addressRegion']) : '';
        if ($locality !== '') {
            $parts[] = $locality;
        }
        if ($region !== '') {
            $parts[] = $region;
        }
    }

    return implode(', ', $parts);
}

// schema.org `offers` may be a single Offer or an array. Take the first
// non-empty url. Empty string when none is usable.
function event_scraper_pick_offer_url($offers): string {
    if (is_array($offers) && isset($offers[0])) {
        // Array of Offer objects.
        foreach ($offers as $offer) {
            if (is_array($offer) && isset($offer['url']) && is_string($offer['url']) && trim($offer['url']) !== '') {
                return trim($offer['url']);
            }
        }
        return '';
    }
    if (is_array($offers) && isset($offers['url']) && is_string($offers['url'])) {
        return trim($offers['url']);
    }
    return '';
}

// schema.org `image` may be a string URL, an ImageObject, or an array. Take
// the first usable string.
function event_scraper_pick_first_string($value): string {
    if (is_string($value)) {
        return trim($value);
    }
    if (is_array($value)) {
        if (isset($value[0])) {
            return event_scraper_pick_first_string($value[0]);
        }
        if (isset($value['url']) && is_string($value['url'])) {
            return trim($value['url']);
        }
    }
    return '';
}
