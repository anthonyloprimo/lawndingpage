<?php
// Event Scraper plugin.
//
// Ingests events from external sources into a
// chosen eventList pane. Admin selects which conventions to publish; daily
// cron + admin "Refresh now" button fetch + apply changes.
//
// Hooks:
//   head_assets                  -> emits <link>/<script> tags. The JS no-ops
//                                   when not on an admin page (no .eventScraperConfig
//                                   in DOM); the CSS is scoped under that selector.
//   eventlist_site_config_extras -> renders the picker UI inside the
//                                   eventList SITE CONFIG section.

if (!function_exists('lawnding_register_hook')) {
    return;
}

lawnding_register_hook('head_assets', function (): void {
    $cssUrl = function_exists('lawnding_asset_url')
        ? lawnding_asset_url('res/scr/plugin-style.php?plugin=eventScraper')
        : '/res/scr/plugin-style.php?plugin=eventScraper';
    $jsUrl = function_exists('lawnding_asset_url')
        ? lawnding_asset_url('res/scr/plugin-script.php?plugin=eventScraper&file=admin.js')
        : '/res/scr/plugin-script.php?plugin=eventScraper&file=admin.js';
    echo '<link rel="stylesheet" href="' . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">';
    echo '<script src="' . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8') . '" defer></script>';
});

lawnding_register_hook('eventlist_site_config_extras', function (array $ctx): void {
    if (($ctx['moduleId'] ?? '') !== 'eventList') {
        return;
    }
    require_once __DIR__ . '/helpers.php';
    event_scraper_render_eventlist_extras();
});
