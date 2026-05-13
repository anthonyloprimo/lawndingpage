<?php
// Builds appConfigPayload.perPaneSettings consumed by the per-pane Settings
// modal. Shape:
//   panes: { <paneId>: {id, name, module, icon, useSiteDefaults, resolvedValues} }
//   modules: { <moduleId>: {default_icon, per_pane_settings, siteDefaults} }
//   iconLibrary: [{key, label, svg}, ...]
// resolvedValues is always populated so unchecking the toggle pre-populates
// override controls — see feedback_use_site_defaults_pattern.

if (!function_exists('lawnding_pane_icon_library')) {
    // Curated subset of lawnding_icon_svg(); skips UI-action icons
    // (save/delete/eye_*/settings) that don't read as pane identities.
    // Keys "lib:*" leave room for future "module:*" / "custom:*" namespaces.
    function lawnding_pane_icon_library(): array {
        $names = [
            'about'       => 'About',
            'rules'       => 'Rules',
            'faq'         => 'FAQ',
            'events'      => 'Events',
            'donate'      => 'Donate',
            'links'       => 'Links',
            'auth_links'  => 'Members links',
            'users'       => 'Users',
            'backgrounds' => 'Backgrounds',
            'changelog'   => 'Changelog',
            'help'        => 'Help',
        ];
        $library = [];
        foreach ($names as $name => $label) {
            $svg = function_exists('lawnding_icon_svg') ? lawnding_icon_svg($name) : '';
            if ($svg === '') {
                continue;
            }
            $library[] = [
                'key'   => 'lib:' . $name,
                'label' => $label,
                'svg'   => $svg,
            ];
        }
        return $library;
    }
}

if (!function_exists('lawnding_build_per_pane_settings_data')) {
    function lawnding_build_per_pane_settings_data(array $panes): array {
        $panesOut = [];
        $modulesOut = [];

        foreach ($panes as $pane) {
            if (!is_array($pane)) {
                continue;
            }
            $paneId = isset($pane['id']) && is_string($pane['id']) ? $pane['id'] : '';
            $moduleId = isset($pane['module']) && is_string($pane['module']) ? $pane['module'] : '';
            if ($paneId === '' || $moduleId === '') {
                continue;
            }

            $perPaneSettings = lawnding_module_per_pane_settings($moduleId);

            $useSiteDefaults = true;
            $loadedSidecar = [];
            if (!empty($perPaneSettings)) {
                $settingsPath = lawnding_module_settings_path($moduleId, $paneId);
                if ($settingsPath !== '') {
                    $loadedSidecar = lawnding_load_pane_settings($settingsPath);
                }
                if (isset($loadedSidecar['useSiteDefaults']) && $loadedSidecar['useSiteDefaults'] === false) {
                    $useSiteDefaults = false;
                }
            }

            $resolvedValues = [];
            foreach ($perPaneSettings as $entry) {
                $key = $entry['key'];
                $resolvedValues[$key] = lawnding_resolve_pane_setting($loadedSidecar, $moduleId, $key);
            }

            $iconRecord = isset($pane['icon']) && is_array($pane['icon']) ? $pane['icon'] : [];
            $iconType = isset($iconRecord['type']) && is_string($iconRecord['type'])
                && in_array($iconRecord['type'], ['none', 'svg', 'file'], true)
                ? $iconRecord['type']
                : 'none';
            $iconValue = isset($iconRecord['value']) && is_string($iconRecord['value'])
                ? $iconRecord['value']
                : '';

            $panesOut[$paneId] = [
                'id' => $paneId,
                'name' => isset($pane['name']) && is_string($pane['name']) ? $pane['name'] : '',
                'module' => $moduleId,
                'icon' => ['type' => $iconType, 'value' => $iconValue],
                'useSiteDefaults' => $useSiteDefaults,
                'resolvedValues' => $resolvedValues,
            ];

            if (!isset($modulesOut[$moduleId])) {
                // siteDefaults snapshot: JS post-save reads this when
                // useSiteDefaults=true so the next modal open shows the
                // actual inherited values, not stale per-pane overrides.
                $siteConfig = function_exists('lawnding_load_site_config')
                    ? lawnding_load_site_config()
                    : [];
                $moduleSiteConfig = is_array($siteConfig[$moduleId] ?? null) ? $siteConfig[$moduleId] : [];
                $siteDefaults = [];
                foreach ($perPaneSettings as $declaredEntry) {
                    $declaredKey = $declaredEntry['key'];
                    $siteDefaults[$declaredKey] = !empty($moduleSiteConfig[$declaredKey]);
                }
                $modulesOut[$moduleId] = [
                    'default_icon' => lawnding_module_default_icon($moduleId),
                    'per_pane_settings' => $perPaneSettings,
                    'siteDefaults' => $siteDefaults,
                ];
            }
        }

        return [
            'panes' => $panesOut,
            'modules' => $modulesOut,
            'iconLibrary' => lawnding_pane_icon_library(),
        ];
    }
}
