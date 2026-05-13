<?php
// Returns the last N entries from admin/errors.jsonl as JSON.
// Polled by diagnostics.js when the admin Diagnostics view is active.
// Gated on canEditSite per the v1 permission decision (any auth source).

require_once __DIR__ . '/../../../lp-bootstrap.php';
require_once lawnding_admin_path('lib/tg-auth.php');
require_once lawnding_admin_path('auth.php');

lawnding_init_session();

header('Content-Type: application/json; charset=utf-8');

function diag_tail_respond($payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

// Map a tg:<id> sentinel in an entry's `who` field to "@handle (tg:<id>)" or
// "First Last (tg:<id>)" when the membership cache has profile data. Bcrypt
// usernames pass through unchanged (they're already human-readable). Returns
// the original value when no profile is available.
//
// Delegates name formatting to lawnding_format_tg_display_name() from
// admin/auth.php. Passes null for the id arg so it doesn't fall back to
// "User #<id>" — we want the bare sentinel in that case so we don't render
// the numeric id twice.
function diag_format_who($who, array $tgProfiles) {
    if (!is_string($who) || $who === '') {
        return $who;
    }
    if (!preg_match('/^tg:(\d+)$/', $who, $m)) {
        return $who;
    }
    $profile = $tgProfiles[$m[1]]['profile'] ?? null;
    if (!is_array($profile)) {
        return $who;
    }
    $name = lawnding_format_tg_display_name($profile, null);
    if ($name === '') {
        return $who;
    }
    return $name . ' (' . $who . ')';
}

$allowedPermissions = ['full_admin', 'add_users', 'edit_users', 'remove_users', 'edit_site'];
$tgConfig = lawnding_load_tg_config();
$users = lawnding_load_users_file(lawnding_config('users_path'));
$identity = lawnding_resolve_admin_identity($tgConfig, $users, $allowedPermissions);

if (!$identity['isAuthenticated'] || empty($identity['context']['canEditSite'])) {
    diag_tail_respond(['error' => 'Forbidden'], 403);
}

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
if ($limit < 1) {
    $limit = 1;
} elseif ($limit > 500) {
    $limit = 500;
}

$logPath = lawnding_logs_path();
$entries = [];
if (is_readable($logPath)) {
    // For files within the rotation cap (~5MB) a full slurp is the simplest
    // correct read. Larger files would warrant a tail-from-end implementation;
    // we don't expect that here because the bootstrap rotates at 5MB.
    $contents = @file_get_contents($logPath);
    if ($contents !== false && $contents !== '') {
        $lines = preg_split('/\r?\n/', rtrim($contents, "\r\n"));
        $tail = array_slice($lines, -$limit);
        foreach ($tail as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }
    }
}

// Newest first for the viewer.
$entries = array_reverse($entries);

// Enrich any tg:<id> sentinels in `who` fields with the cached display name.
// Single cache load amortized across all entries in this response.
$tgCache = function_exists('lawnding_tg_cache_load') ? lawnding_tg_cache_load() : ['users' => []];
$tgProfiles = is_array($tgCache['users'] ?? null) ? $tgCache['users'] : [];
foreach ($entries as &$entry) {
    if (isset($entry['who'])) {
        $entry['who'] = diag_format_who($entry['who'], $tgProfiles);
    }
}
unset($entry);

diag_tail_respond([
    'entries' => $entries,
    'count' => count($entries),
    'limit' => $limit,
]);
