<?php
if (!defined('SITE_VERSION')) {
    define('SITE_VERSION', 'v1.3.0');
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
