<?php

require_once __DIR__ . '/../../../public/res/scr/markdown-gating.php';

// Returns int; caller stringifies for storage (records on disk are strings).
function event_list_generate_id(array $existing): int {
    $max = 0;
    foreach ($existing as $rec) {
        if (!is_array($rec)) {
            continue;
        }
        $id = (int) ($rec['id'] ?? 0);
        if ($id > $max) {
            $max = $id;
        }
    }
    return $max + 1;
}

// Mirror of event_list_generate_id for category records. Two copies is
// fine per Rule of Three; if a third sequential-int-id site appears,
// extract event_list_next_seq_id($existing) and have both call it.
function event_list_generate_category_id(array $existing): int {
    $max = 0;
    foreach ($existing as $rec) {
        if (!is_array($rec)) {
            continue;
        }
        $id = (int) ($rec['id'] ?? 0);
        if ($id > $max) {
            $max = $id;
        }
    }
    return $max + 1;
}

// Pure-transform additive merge dispatched via manifest save_map.
// Silent-drop on per-event validation failures (mediaGallery pattern).
function event_list_apply_events(array $existing, array $payload): array {
    $events = is_array($existing['events'] ?? null) ? $existing['events'] : [];
    $changes = is_array($payload['changes'] ?? null) ? $payload['changes'] : [];

    $deletes = is_array($changes['delete'] ?? null) ? $changes['delete'] : [];
    if ($deletes) {
        $deleteSet = [];
        foreach ($deletes as $id) {
            if (is_scalar($id)) {
                $deleteSet[(string) $id] = true;
            }
        }
        $events = array_values(array_filter($events, function ($ev) use ($deleteSet) {
            if (!is_array($ev)) {
                return false;
            }
            return !isset($deleteSet[(string) ($ev['id'] ?? '')]);
        }));
    }

    $updates = is_array($changes['update'] ?? null) ? $changes['update'] : [];
    if ($updates) {
        $idIndex = [];
        foreach ($events as $idx => $ev) {
            if (is_array($ev) && isset($ev['id'])) {
                $idIndex[(string) $ev['id']] = $idx;
            }
        }
        foreach ($updates as $update) {
            if (!is_array($update)) {
                continue;
            }
            $id = (string) ($update['id'] ?? '');
            if ($id === '' || !isset($idIndex[$id])) {
                continue;
            }
            $merged = array_merge($events[$idIndex[$id]], $update);
            if (!event_list_event_is_valid($merged)) {
                continue;
            }
            $events[$idIndex[$id]] = $merged;
        }
    }

    $creates = is_array($changes['create'] ?? null) ? $changes['create'] : [];
    if ($creates) {
        foreach ($creates as $create) {
            if (!is_array($create)) {
                continue;
            }
            if (!event_list_event_is_valid($create)) {
                continue;
            }
            $events[] = array_merge($create, [
                'id' => (string) event_list_generate_id($events),
            ]);
        }
    }

    return [
        'events' => $events,
    ];
}

// Build the .ics download filename from the event name, falling back to
// the (post-v1.15.1 sequential int) event id, then a literal 'event'.
// Word separators preserved as '-' so the file reads cleanly in a
// Downloads folder, unlike the ICS UID which strips them entirely.
function event_list_ics_filename(string $name, string $eventId): string {
    $base = preg_replace('/[^A-Za-z0-9]+/', '-', $name);
    $base = trim((string) $base, '-');
    if ($base === '') {
        $base = preg_replace('/[^A-Za-z0-9_-]/', '', $eventId);
    }
    if ($base === '') {
        $base = 'event';
    }
    return $base . '.ics';
}

// Required fields, paired end date/time, end >= start, valid markdown gating.
// All-day events skip startTime/endTime requirements but must still have
// startDate (and an endDate >= startDate when set).
// categoryId optional: empty/missing/string OK; deleted-category orphans
// stay (renderer falls back to default flag color, no validation failure).
// No dedup (admin-UX concern, not data integrity).
function event_list_event_is_valid(array $event): bool {
    $allDay = !empty($event['allDay']);
    $required = $allDay
        ? ['name', 'startDate', 'address', 'description']
        : ['name', 'startDate', 'startTime', 'address', 'description'];
    foreach ($required as $field) {
        $val = $event[$field] ?? '';
        if (!is_string($val) || trim($val) === '') {
            return false;
        }
    }
    if (isset($event['categoryId']) && !is_string($event['categoryId'])) {
        return false;
    }
    $endDate = (string) ($event['endDate'] ?? '');
    $endTime = (string) ($event['endTime'] ?? '');
    if ($allDay) {
        if ($endDate !== '' && $endDate < (string) $event['startDate']) {
            return false;
        }
    } else {
        if (($endDate === '' && $endTime !== '') || ($endDate !== '' && $endTime === '')) {
            return false;
        }
        if ($endDate !== '' && $endTime !== '') {
            $startKey = (string) $event['startDate'] . ' ' . (string) $event['startTime'];
            $endKey = $endDate . ' ' . $endTime;
            if ($endKey < $startKey) {
                return false;
            }
        }
    }
    $gated = lawnding_markdown_gate_apply((string) $event['description'], 'admin', false);
    if (empty($gated['ok'])) {
        return false;
    }
    return true;
}

// Pure normalizer: takes a decoded JSON array shape and returns the
// cleaned categories list. Drops malformed rows (defensive against
// hand-edited files). Extracted from event_list_load_categories so the
// shape-tolerance behavior is unit-testable without filesystem I/O.
function event_list_normalize_categories(array $decoded): array {
    $cats = $decoded['categories'] ?? [];
    if (!is_array($cats)) {
        return [];
    }
    $clean = [];
    foreach ($cats as $cat) {
        if (!is_array($cat)) {
            continue;
        }
        $id = isset($cat['id']) ? (string) $cat['id'] : '';
        $name = isset($cat['name']) ? (string) $cat['name'] : '';
        $color = isset($cat['color']) ? (string) $cat['color'] : '';
        if ($id === '' || $name === '' || $color === '') {
            continue;
        }
        $clean[] = ['id' => $id, 'name' => $name, 'color' => $color];
    }
    return $clean;
}

// Site-wide category list lives at public/res/data/eventCategories.json.
// Returns [] on missing file, malformed JSON, or any per-row defect — the
// admin UI re-creates entries cleanly on next save, and the public render
// silently falls back to the default flag color for any unknown id.
function event_list_load_categories(): array {
    $path = function_exists('lawnding_data_path')
        ? lawnding_data_path('eventCategories.json')
        : __DIR__ . '/../../../public/res/data/eventCategories.json';
    if (!is_readable($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim((string) $raw) === '') {
        return [];
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    return event_list_normalize_categories($decoded);
}

// Name non-empty after trim, color a strict 6-digit hex. Pure — no I/O.
function event_list_category_is_valid(array $category): bool {
    $name = $category['name'] ?? '';
    if (!is_string($name) || trim($name) === '') {
        return false;
    }
    $color = $category['color'] ?? '';
    if (!is_string($color) || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        return false;
    }
    return true;
}

// Pure-transform additive merge for the categories changeset, mirror of
// event_list_apply_events. Silent-drop on per-record validation failure.
// Apply order is delete → update → create so id collisions can't occur
// when an admin deletes-then-recreates inside the same payload.
function event_list_apply_categories(array $existing, array $payload): array {
    $cats = is_array($existing['categories'] ?? null) ? $existing['categories'] : [];
    $changes = is_array($payload['changes'] ?? null) ? $payload['changes'] : [];

    $deletes = is_array($changes['delete'] ?? null) ? $changes['delete'] : [];
    if ($deletes) {
        $deleteSet = [];
        foreach ($deletes as $id) {
            if (is_scalar($id)) {
                $deleteSet[(string) $id] = true;
            }
        }
        $cats = array_values(array_filter($cats, function ($cat) use ($deleteSet) {
            if (!is_array($cat)) {
                return false;
            }
            return !isset($deleteSet[(string) ($cat['id'] ?? '')]);
        }));
    }

    $updates = is_array($changes['update'] ?? null) ? $changes['update'] : [];
    if ($updates) {
        $idIndex = [];
        foreach ($cats as $idx => $cat) {
            if (is_array($cat) && isset($cat['id'])) {
                $idIndex[(string) $cat['id']] = $idx;
            }
        }
        foreach ($updates as $update) {
            if (!is_array($update)) {
                continue;
            }
            $id = (string) ($update['id'] ?? '');
            if ($id === '' || !isset($idIndex[$id])) {
                continue;
            }
            $merged = array_merge($cats[$idIndex[$id]], $update);
            if (!event_list_category_is_valid($merged)) {
                continue;
            }
            $cats[$idIndex[$id]] = $merged;
        }
    }

    $creates = is_array($changes['create'] ?? null) ? $changes['create'] : [];
    if ($creates) {
        foreach ($creates as $create) {
            if (!is_array($create)) {
                continue;
            }
            if (!event_list_category_is_valid($create)) {
                continue;
            }
            $cats[] = array_merge($create, [
                'id' => (string) event_list_generate_category_id($cats),
            ]);
        }
    }

    return [
        'categories' => $cats,
    ];
}

// SITE CONFIG render callback (manifest.site_config.render).
// Standard fieldset (showCalendar, calendarDefault, showPast) on top via
// the platform helper; categories editor below. Categories storage is
// module-private and edited via the out-of-band module-endpoint.php
// proxy — NOT through Save All — so this block is JS-driven on the
// client. Server emits the empty shell + a data-categories JSON island
// (CSP-safe; no inline scripts, no inline styles).
function event_list_render_site_config(): void {
    lawnding_render_site_config_fieldset('eventList');

    $categories = event_list_load_categories();
    $payloadJson = json_encode(
        ['categories' => $categories],
        JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    if ($payloadJson === false) {
        $payloadJson = '{"categories":[]}';
    }
    ?>
    <fieldset class="siteConfigGroup eventListCategoriesConfig" data-categories="<?php echo htmlspecialchars($payloadJson, ENT_QUOTES, 'UTF-8'); ?>">
        <legend>Categories</legend>
        <p class="paneHint">Color-code events on the calendar grid. Categories are shared across every Event List pane on the site.</p>
        <div class="eventCategoriesEditor">
            <div class="eventCategoriesList" aria-live="polite"></div>
            <div class="eventCategoriesActions">
                <button type="button" class="eventCategoriesAdd">Add Category</button>
                <button type="button" class="eventCategoriesSave" disabled>Save Categories</button>
                <span class="eventCategoriesStatus" aria-live="polite"></span>
            </div>
        </div>
    </fieldset>
    <?php

    // Plugin contribution slot. Plugins extend the eventList SITE CONFIG
    // section by registering for this hook and emitting their own sibling
    // fieldset(s). Per admin/plugins/README.md, plugins do not register their
    // own SITE CONFIG sections — they extend their host module's.
    if (function_exists('lawnding_run_hook')) {
        lawnding_run_hook('eventlist_site_config_extras', [
            'moduleId' => 'eventList',
        ]);
    }
}
