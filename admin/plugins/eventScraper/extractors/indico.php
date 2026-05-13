<?php
// Indico HTTP export API extractor.
//
// Indico's per-category JSON export (/export/categ/<id>.json) returns a
// {results: [...]} envelope. Each result is one event with dates as nested
// {date, time, tz} sub-objects, location/address as separate strings,
// description as raw HTML, and posters under material[].resources[]. We
// normalize that into the scraper's canonical event shape.
//
// Divergence from jsonld:
//   - sourceUid is read from top-level `id` (Indico exposes it
//     structurally; the adapter does not need uidFromUrlPattern).
//   - No filter on _type. Indico tags meets, lectures, and online events
//     all as 'Conference'; the per-event admin allowlist is the final gate.
//   - description is HTML→plaintext at extraction time. Source content is
//     not admin-authored, so the canonical shape contract ('description'
//     => '<plaintext>') requires the extractor to do the normalization.

function event_scraper_extract_indico(string $body, array $adapter): array {
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return [];
    }
    $results = $decoded['results'] ?? null;
    if (!is_array($results)) {
        return [];
    }
    $events = [];
    foreach ($results as $item) {
        if (!is_array($item)) {
            continue;
        }
        $normalized = event_scraper_indico_normalize_event($item);
        if ($normalized !== null) {
            $events[] = $normalized;
        }
    }
    return $events;
}

// Pure transform: Indico Conference object -> normalized scraper shape.
// Returns null when required fields are missing or unparseable.
function event_scraper_indico_normalize_event(array $item): ?array {
    $sourceUid = isset($item['id']) && is_scalar($item['id']) ? trim((string) $item['id']) : '';
    $name = isset($item['title']) && is_string($item['title']) ? trim($item['title']) : '';
    $url = isset($item['url']) && is_string($item['url']) ? trim($item['url']) : '';
    $startDate = event_scraper_indico_pick_date($item['startDate'] ?? null);

    if ($sourceUid === '' || $name === '' || $url === '' || $startDate === '') {
        return null;
    }

    return [
        'sourceUid'       => $sourceUid,
        'name'            => $name,
        'startDate'       => $startDate,
        'endDate'         => event_scraper_indico_pick_date($item['endDate'] ?? null),
        'startTime'       => event_scraper_indico_pick_time($item['startDate'] ?? null),
        'endTime'         => event_scraper_indico_pick_time($item['endDate'] ?? null),
        'location'        => event_scraper_indico_format_location(
            $item['location'] ?? '',
            $item['address'] ?? ''
        ),
        'description'     => event_scraper_indico_html_to_text(
            isset($item['description']) && is_string($item['description']) ? $item['description'] : ''
        ),
        'url'             => $url,
        'registrationUrl' => '',
        'image'           => event_scraper_indico_pick_image($item['material'] ?? null),
        'keywords'        => event_scraper_indico_pick_keywords($item['keywords'] ?? null),
        'host'            => event_scraper_indico_pick_host($item['creator'] ?? null),
    ];
}

// Indico dates are {date, time, tz}; tolerate string fallback.
// Tz is dropped — eventList stores HH:MM in viewer-local time today.
function event_scraper_indico_pick_date($value): string {
    if (is_array($value) && isset($value['date']) && is_string($value['date'])) {
        return trim($value['date']);
    }
    if (is_string($value)) {
        return trim($value);
    }
    return '';
}

// Strip seconds: Indico emits "HH:MM:SS"; eventList stores "HH:MM" to
// match <input type="time">. Empty when source has no time field.
function event_scraper_indico_pick_time($value): string {
    if (!is_array($value) || !isset($value['time']) || !is_string($value['time'])) {
        return '';
    }
    if (preg_match('/^(\d{2}):(\d{2})/', trim($value['time']), $m)) {
        return $m[1] . ':' . $m[2];
    }
    return '';
}

// Combine Indico's two location strings into one. Either may be empty;
// pattern mirrors jsonld's parts-joined-with-comma shape.
function event_scraper_indico_format_location($location, $address): string {
    $parts = [];
    if (is_string($location) && trim($location) !== '') {
        $parts[] = trim($location);
    }
    if (is_string($address) && trim($address) !== '') {
        $parts[] = trim($address);
    }
    return implode(', ', $parts);
}

// HTML -> plaintext, in this order:
//   1. Replace <br> and block-closing tags with newline placeholders so
//      paragraph structure survives strip_tags.
//   2. strip_tags removes the rest.
//   3. Decode entities AFTER strip_tags so encoded < > don't get parsed
//      as tags partway through.
//   4. Collapse runs of horizontal whitespace; cap blank lines at one.
function event_scraper_indico_html_to_text(string $html): string {
    if ($html === '') {
        return '';
    }
    $withBreaks = preg_replace('#<br\s*/?>#i', "\n", $html);
    $withBreaks = preg_replace('#</(p|div|li|h[1-6])>#i', "\n", $withBreaks);
    $stripped = strip_tags($withBreaks);
    $decoded = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Collapse non-breaking spaces to regular spaces; PHP's trim() and
    // /[ \t]+/ are ASCII-only, so nbsp would otherwise survive as a stray
    // 0xc2 0xa0 sequence and show up as a "weird character" downstream.
    $decoded = str_replace("\xc2\xa0", ' ', $decoded);
    $decoded = preg_replace('/[ \t]+/', ' ', $decoded);
    $decoded = preg_replace("/\n{3,}/", "\n\n", $decoded);
    $lines = array_map('trim', explode("\n", $decoded));
    return trim(implode("\n", $lines));
}

// Walk material[].resources[] for the first URL ending in an image
// extension. Indico uses material as the attachment-folder shape; not
// every event has a poster. Returns '' when none found.
function event_scraper_indico_pick_image($material): string {
    if (!is_array($material)) {
        return '';
    }
    foreach ($material as $folder) {
        if (!is_array($folder)) {
            continue;
        }
        $resources = $folder['resources'] ?? null;
        if (!is_array($resources)) {
            continue;
        }
        foreach ($resources as $resource) {
            if (!is_array($resource)) {
                continue;
            }
            $url = $resource['url'] ?? '';
            if (!is_string($url) || $url === '') {
                continue;
            }
            if (preg_match('/\.(png|jpe?g|webp|gif)(?:\?.*)?$/i', $url)) {
                return trim($url);
            }
        }
    }
    return '';
}

// Indico keywords are a flat string array. Filter to non-empty trimmed
// strings; preserve order. Adapter's keywordBadges map is consulted at
// ingest time to resolve which keywords get rendered as badges.
function event_scraper_indico_pick_keywords($value): array {
    if (!is_array($value)) {
        return [];
    }
    $out = [];
    foreach ($value as $kw) {
        if (!is_string($kw)) {
            continue;
        }
        $kw = trim($kw);
        if ($kw !== '') {
            $out[] = $kw;
        }
    }
    return $out;
}

// Indico creator is {first_name, last_name, fullName, …}. Prefer fullName
// when present; fall back to "first last". Email is intentionally NOT
// extracted — Indico exposes only emailHash (gravatar-style), by design.
function event_scraper_indico_pick_host($value): string {
    if (!is_array($value)) {
        return '';
    }
    if (isset($value['fullName']) && is_string($value['fullName']) && trim($value['fullName']) !== '') {
        return trim($value['fullName']);
    }
    $first = isset($value['first_name']) && is_string($value['first_name']) ? trim($value['first_name']) : '';
    $last = isset($value['last_name']) && is_string($value['last_name']) ? trim($value['last_name']) : '';
    $combined = trim($first . ' ' . $last);
    return $combined;
}
