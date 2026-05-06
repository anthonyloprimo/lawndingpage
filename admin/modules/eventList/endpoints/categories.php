<?php
// Site-wide event categories CRUD.
// Reached via /res/scr/module-endpoint.php?module=eventList&endpoint=categories
// — module-endpoint.php loads bootstrap and validates module/endpoint
// names against [a-zA-Z0-9_-] before including this handler.
//
// POST { changes: <json-string>, csrf_token } where the JSON is
//   {create: [...], update: [...], delete: [...]}.
// Returns 200 { categories: [...] } reflecting the post-merge state.
// Validator silently drops invalid rows (mediaGallery pattern); the
// caller compares expected vs returned to surface per-row UX feedback.
//
// Read path is intentionally absent: the SITE CONFIG render embeds the
// current list as a data-* attribute on initial page load, and POST
// responses carry the new list back, so admin.js never needs a GET.
// The public renderer reads eventCategories.json directly via PHP, not
// through this endpoint.

require_once lawnding_admin_path('modules/eventList/helpers.php');
require_once lawnding_admin_path('auth.php');
lawnding_init_session();

function event_list_categories_respond($payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    event_list_categories_respond(['error' => 'Method not allowed.'], 405);
}

lawnding_require_admin_mutation(
    null,
    static function (string $msg, int $code): void {
        event_list_categories_respond(['error' => $msg], $code);
    }
);

$changesRaw = $_POST['changes'] ?? '';
if (!is_string($changesRaw) || trim($changesRaw) === '') {
    event_list_categories_respond(['error' => 'Missing changes payload.'], 400);
}
$changes = json_decode($changesRaw, true);
if (!is_array($changes)) {
    event_list_categories_respond(['error' => 'Invalid JSON in changes payload.'], 400);
}

$existing = ['categories' => event_list_load_categories()];
$updated = event_list_apply_categories($existing, ['changes' => $changes]);

$path = lawnding_data_path('eventCategories.json');
$encoded = json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($encoded === false || file_put_contents($path, $encoded, LOCK_EX) === false) {
    event_list_categories_respond(['error' => 'Failed to write categories.'], 500);
}

event_list_categories_respond($updated, 200);
