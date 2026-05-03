<?php
// Pane-icon upload helper shared by save-config.php (bulk pane-management)
// and pane-icon-save.php (per-pane Settings).

if (!function_exists('lawnding_save_pane_icon_upload')) {
    // Saves a $_FILES entry into $iconDir (res/img/panes). Filename is
    // keyed on $paneId so re-uploads overwrite. Returns saved basename
    // or null on failure.
    function lawnding_save_pane_icon_upload(array $fileArray, string $paneId, string $iconDir): ?string {
        $base = preg_replace('/[^a-z0-9]/i', '', $paneId);
        if ($base === '') {
            return null;
        }
        if (!is_dir($iconDir)) {
            mkdir($iconDir, 0755, true);
        }
        $result = lawnding_validate_and_save_image(
            $fileArray,
            $iconDir,
            $base,
            256,
            256,
            lawnding_image_mime_map()
        );
        return !empty($result['ok']) ? $result['filename'] : null;
    }
}
