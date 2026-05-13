<?php
// Markdown editor plugin.
//
// Provides the toolbar + textarea + preview UI for any module's admin
// field that wants markdown editing. Register hooks emit the plugin's
// CSS + JS into the admin head; the click + preview handlers live in
// public/res/scr/config.js (delegated, so they fire on any
// .markdownToolbar regardless of where it's rendered). The PHP render
// helper lives in helpers.php; a JS counterpart (window.lpMarkdownEditor.
// toolbarHtml) is exposed by editor.js so dynamic JS callers emit
// byte-identical DOM.

if (!function_exists('lawnding_register_hook')) {
    return;
}

lawnding_register_hook('head_assets', function (): void {
    $cssUrl = function_exists('lawnding_asset_url')
        ? lawnding_asset_url('res/scr/plugin-style.php?plugin=markdownEditor')
        : '/res/scr/plugin-style.php?plugin=markdownEditor';
    echo '<link rel="stylesheet" href="'
        . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">';

    $jsUrl = function_exists('lawnding_asset_url')
        ? lawnding_asset_url('res/scr/plugin-script.php?plugin=markdownEditor&file=editor.js')
        : '/res/scr/plugin-script.php?plugin=markdownEditor&file=editor.js';
    echo '<script src="'
        . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8') . '" defer></script>';
});

require_once __DIR__ . '/helpers.php';
