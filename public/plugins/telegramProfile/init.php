<?php
// Telegram profile chip plugin.
//
// Renders a name + avatar chip and a logout icon in the page header when the
// visitor is signed into Telegram. Hooks:
//   head_assets       -> emits the plugin's <link rel="stylesheet">.
//   header_auth_area  -> renders the chip + logout icon inside <header>.

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

lawnding_register_hook('header_auth_area', function (array $ctx): void {
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

    $logoutUrl = isset($ctx['logoutUrl']) && is_string($ctx['logoutUrl']) ? $ctx['logoutUrl'] : '';

    echo '<div class="tgHeaderAuth">';
    echo   '<div class="tgHeaderChip">';
    echo     '<img class="tgHeaderAvatar" src="'
        . htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') . '" alt="">';
    echo     '<span class="tgHeaderName">'
        . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span>';
    echo   '</div>';
    if ($logoutUrl !== '') {
        echo '<a class="tgHeaderLogout" href="'
            . htmlspecialchars($logoutUrl, ENT_QUOTES, 'UTF-8') . '" title="Log out" aria-label="Log out">';
        echo   '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">';
        echo     '<path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/>';
        echo   '</svg>';
        echo '</a>';
    }
    echo '</div>';
});
