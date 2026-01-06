<?php
// Site version string used for cache-busting and display.
// The guard prevents redefinition if this file is loaded multiple times.
if (!defined('SITE_VERSION')) {
    define('SITE_VERSION', 'v1.4.1');
}

// Schema version for panes.json and module-driven pane configuration.
// Keep this in sync with migration logic and update when the schema changes.
if (!defined('PANE_SCHEMA_VERSION')) {
    define('PANE_SCHEMA_VERSION', 1);
}

if (!function_exists('lawnding_versioned_url')) {
    function lawnding_versioned_url(string $url): string {
        if ($url === '') {
            return $url;
        }
        $separator = strpos($url, '?') === false ? '?' : '&';
        return $url . $separator . 'v=' . urlencode(SITE_VERSION);
    }
}
