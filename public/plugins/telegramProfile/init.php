<?php
// Telegram profile chip plugin.
//
// First consumer of the plugin hook system introduced alongside this plugin.
// Registers two hooks:
//   head_assets     -> emits the plugin's <link rel="stylesheet">.
//   auth_profile_area -> renders a name + avatar chip next to Log out.

if (!function_exists('lawnding_register_hook')) {
    return;
}

lawnding_register_hook('head_assets', function (): void {
    $href = function_exists('lawnding_asset_url')
        ? lawnding_asset_url('plugins/telegramProfile/style.css')
        : '/plugins/telegramProfile/style.css';
    echo '<link rel="stylesheet" href="'
        . htmlspecialchars($href, ENT_QUOTES, 'UTF-8')
        . '">';
});

// Renders for BOTH authorized and unauthorized states. Someone who's logged
// into Telegram but isn't in a gated group is still logged in; they still
// want to see who the session belongs to.
lawnding_register_hook('auth_profile_area', function (array $ctx): void {
    $tgUser = is_array($ctx['tgUser'] ?? null) ? $ctx['tgUser'] : null;
    if (!$tgUser || empty($tgUser['id'])) {
        return;
    }

    $handle = trim((string) ($tgUser['username']   ?? ''));
    $first  = trim((string) ($tgUser['first_name'] ?? ''));
    $last   = trim((string) ($tgUser['last_name']  ?? ''));
    if ($handle !== '') {
        $name = '@' . $handle;
    } elseif ($first !== '') {
        $name = $first . ($last !== '' ? ' ' . $last : '');
    } else {
        $name = 'User #' . (int) $tgUser['id'];
    }

    $avatarUrl = function_exists('lawnding_asset_url')
        ? lawnding_asset_url('plugins/telegramProfile/avatar.php')
        : '/plugins/telegramProfile/avatar.php';

    echo '<li class="linkItem fullWidth tgProfileChip">';
    echo   '<div class="tgProfileChipInner">';
    echo     '<img class="tgProfileAvatar" src="'
        . htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') . '" alt="">';
    echo     '<span class="tgProfileName">'
        . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span>';
    echo   '</div>';
    echo '</li>';
});
