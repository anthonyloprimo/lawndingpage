<?php
// Shared helpers for pane-icon-save.php and pane-settings-save.php
// (auth/CSRF gate, pane lookup, panes.json writer).

if (!function_exists('pane_settings_json_response')) {
    function pane_settings_json_response($payload, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }

    function pane_settings_require_method(string $method): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
            pane_settings_json_response(['error' => 'Method not allowed'], 405);
        }
    }

    // Auth + CSRF gate; returns a flat identity snapshot.
    function pane_settings_require_edit_site(): array {
        require_once lawnding_admin_path('auth.php');
        $identity = lawnding_require_admin_mutation(
            null,
            function ($msg, $code) { pane_settings_json_response(['error' => $msg], $code); }
        );
        return [
            'authUser' => $identity['authUser'],
            'canEditSite' => $identity['context']['canEditSite'],
        ];
    }

    // Charset must match lawnding_module_settings_path's whitelist —
    // otherwise a hand-crafted id could slip past path-traversal checks there.
    function pane_settings_is_valid_pane_id(string $paneId): bool {
        return $paneId !== '' && preg_match('/^[a-zA-Z0-9]+$/', $paneId) === 1;
    }

    function pane_settings_paths(): array {
        $publicDir = function_exists('lawnding_config')
            ? lawnding_config('public_dir', dirname(__DIR__, 2))
            : dirname(__DIR__, 2);
        $dataDir = function_exists('lawnding_config')
            ? lawnding_config('data_dir', $publicDir . '/res/data')
            : $publicDir . '/res/data';
        return [
            'public_dir' => $publicDir,
            'data_dir' => $dataDir,
            'panes_path' => rtrim($dataDir, '/\\') . '/panes.json',
        ];
    }

    function pane_settings_find_pane_index(array $panes, string $paneId): int {
        foreach ($panes as $i => $p) {
            if (is_array($p) && ($p['id'] ?? '') === $paneId) {
                return (int) $i;
            }
        }
        return -1;
    }

    // Re-emits the {schema_version, panes} envelope save-config.php uses;
    // preserves the existing schema_version on disk if present.
    function pane_settings_write_panes(string $path, array $panes): bool {
        $schemaVersion = defined('PANE_SCHEMA_VERSION') ? PANE_SCHEMA_VERSION : 1;
        $existing = lawnding_read_json($path);
        if (is_array($existing) && isset($existing['schema_version']) && is_int($existing['schema_version'])) {
            $schemaVersion = $existing['schema_version'];
        }
        $payload = ['schema_version' => $schemaVersion, 'panes' => $panes];
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return false;
        }
        return file_put_contents($path, $encoded, LOCK_EX) !== false;
    }
}
