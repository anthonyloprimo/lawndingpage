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
// No dedup (admin-UX concern, not data integrity).
function event_list_event_is_valid(array $event): bool {
    foreach (['name', 'startDate', 'startTime', 'address', 'description'] as $field) {
        $val = $event[$field] ?? '';
        if (!is_string($val) || trim($val) === '') {
            return false;
        }
    }
    $endDate = (string) ($event['endDate'] ?? '');
    $endTime = (string) ($event['endTime'] ?? '');
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
    $gated = lawnding_markdown_gate_apply((string) $event['description'], 'admin', false);
    if (empty($gated['ok'])) {
        return false;
    }
    return true;
}
