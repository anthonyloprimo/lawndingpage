<?php

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
