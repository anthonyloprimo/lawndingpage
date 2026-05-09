<?php
// Public landing page entrypoint: load content and render the UI.

// Locate the bootstrap file (supports alternate directory layouts).
$bootstrapPath = __DIR__ . '/../lp-bootstrap.php';
if (!is_readable($bootstrapPath)) {
    $bootstrapPath = __DIR__ . '/../../lp-bootstrap.php';
}
require_once $bootstrapPath;
$tgAuthPath = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('lib/tg-auth.php')
    : __DIR__ . '/../admin/lib/tg-auth.php';
require_once $tgAuthPath;
$rememberLibPath = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('lib/remember-me.php')
    : __DIR__ . '/../admin/lib/remember-me.php';
require_once $rememberLibPath;
lawnding_init_session();
lawnding_consume_remember_cookie();
// Anyone authenticated while viewing the public page is, by definition,
// "coming from public" — flag it so the admin logout flow sends them back
// here instead of the admin login form.
if (!empty($_SESSION['auth_user'])) {
    $_SESSION['logout_return_public'] = true;
}
// Prevent stale HTML/PHP responses from being cached.
$cacheHeadersPath = function_exists('lawnding_public_path')
    ? lawnding_public_path('res/scr/cache_headers.php')
    : __DIR__ . '/res/scr/cache_headers.php';
require_once $cacheHeadersPath;
// Load the authoritative site version for display and shared constants.
$versionPath = function_exists('lawnding_public_path')
    ? lawnding_public_path('res/version.php')
    : __DIR__ . '/res/version.php';
require_once $versionPath;
// Suppress error output in production responses.
ini_set('display_errors', '0');

// Content Security Policy for the public site.
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://telegram.org; style-src 'self'; img-src 'self' data: https://www.google.com https://t0.gstatic.com https://t1.gstatic.com https://t2.gstatic.com https://t3.gstatic.com; font-src 'self' data:; connect-src 'self'; frame-src https://telegram.org https://oauth.telegram.org; frame-ancestors 'none'");
header('X-Frame-Options: DENY');

// Seed missing data files from admin/seed/data/ on first request after deploy.
lawnding_ensure_data_files();

// Hook primitive + plugin autoload now live in lp-bootstrap.php so
// both entrypoints can register and fire hooks. Public-only fallback
// for header_auth_area registers below, after plugins load, so plugin
// callbacks have first crack and the fallback only fires when none did.
lawnding_load_plugins();

// Core: render the upper-right header for non-Telegram auth states. The
// Telegram plugin's own header_auth_area callback owns its chip; this
// callback covers the other two states (bcrypt-only signed in, fully
// signed out) so the three callbacks have disjoint conditions and at most
// one emits per render.
lawnding_register_hook('header_auth_area', function (array $ctx): void {
    $tgUser = is_array($ctx['tgUser'] ?? null) ? $ctx['tgUser'] : null;
    $tgLoggedIn = $tgUser && !empty($tgUser['id']);
    if ($tgLoggedIn) {
        return; // Telegram plugin handles this case.
    }
    $bcryptLoggedIn = !empty($_SESSION['auth_user'] ?? null);
    if ($bcryptLoggedIn) {
        // Bcrypt-only chip. Reuses the .tgHeader* visual classes from the
        // Telegram plugin's stylesheet (always loaded) so the rhythm matches
        // the Telegram-logged-in case: chip with name, logout, admin link.
        // The avatar is omitted — bcrypt users have no avatar to show.
        $username = (string) ($_SESSION['auth_user'] ?? '');
        $adminUrl = function_exists('lawnding_asset_url')
            ? lawnding_asset_url('admin/')
            : '/admin/';
        $csrf = (string) ($_SESSION['csrf_token'] ?? '');
        $logoutAction = htmlspecialchars($adminUrl, ENT_QUOTES, 'UTF-8');
        echo '<div class="tgHeaderAuth">';
        echo   '<div class="tgHeaderAuthRow">';
        echo     '<div class="tgHeaderChip">';
        echo       '<span class="tgHeaderName">'
            . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</span>';
        echo     '</div>';
        // Logout form posts to /admin/ which validates $_POST['csrf_token']
        // and respects the logout_return_public flag set on public-side login.
        echo     '<form method="post" action="' . $logoutAction
            . '" class="lpHeaderLogoutForm">';
        echo       '<input type="hidden" name="action" value="logout">';
        echo       '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') . '">';
        echo       '<button type="submit" class="tgHeaderLogout"'
            . ' title="Log out" aria-label="Log out">';
        echo         '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"'
            . ' focusable="false" aria-hidden="true">';
        echo           '<path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/>';
        echo         '</svg>';
        echo       '</button>';
        echo     '</form>';
        echo   '</div>';
        echo   '<a class="tgHeaderAdmin" href="' . $logoutAction
            . '?from=public" title="Go to admin panel">Admin panel</a>';
        echo '</div>';
        return;
    }
    // Fully signed out — show the modal trigger.
    echo '<button type="button" class="lpHeaderLoginButton" data-lp-login-trigger'
       . ' aria-haspopup="dialog" aria-controls="lpLoginModal">Sign in</button>';
});

// Resolve a data file path using bootstrap helpers when available.
function lawnding_public_data_path($file) {
    return function_exists('lawnding_data_path')
        ? lawnding_data_path($file)
        : __DIR__ . '/res/data/' . $file;
}

// Read a file if it exists; otherwise return the fallback.
function lawnding_read_file($path, $fallback = '') {
    return is_readable($path) ? file_get_contents($path) : $fallback;
}

function lawnding_public_absolute_url(string $path): string {
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) || str_starts_with($path, '//')) {
        return $path;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return $path;
    }
    return $scheme . '://' . $host . (str_starts_with($path, '/') ? $path : '/' . $path);
}

function lawnding_normalize_external_url(string $value): string {
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $trimmed) || str_starts_with($trimmed, '//')) {
        return $trimmed;
    }
    return 'https://' . $trimmed;
}

function lawnding_external_nav_domain(string $url): string {
    $normalized = lawnding_normalize_external_url($url);
    if ($normalized === '') {
        return '';
    }
    $host = parse_url($normalized, PHP_URL_HOST);
    return is_string($host) ? strtolower($host) : '';
}

function lawnding_google_favicon_url(string $domain): string {
    if ($domain === '') {
        return '';
    }
    return 'https://www.google.com/s2/favicons?sz=64&domain=' . rawurlencode($domain);
}

// Render shared SVG icons by name to avoid inline duplication.
function lawnding_icon_svg(string $name): string {
    static $paths = [
        'links' => 'M19,3H5A2,2 0 0,0 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5A2,2 0 0,0 19,3M13.94,14.81L11.73,17C11.08,17.67 10.22,18 9.36,18C8.5,18 7.64,17.67 7,17C5.67,15.71 5.67,13.58 7,12.26L8.35,10.9L8.34,11.5C8.33,12 8.41,12.5 8.57,12.94L8.62,13.09L8.22,13.5C7.91,13.8 7.74,14.21 7.74,14.64C7.74,15.07 7.91,15.47 8.22,15.78C8.83,16.4 9.89,16.4 10.5,15.78L12.7,13.59C13,13.28 13.18,12.87 13.18,12.44C13.18,12 13,11.61 12.7,11.3C12.53,11.14 12.44,10.92 12.44,10.68C12.44,10.45 12.53,10.23 12.7,10.06C13.03,9.73 13.61,9.74 13.94,10.06C14.57,10.7 14.92,11.54 14.92,12.44C14.92,13.34 14.57,14.18 13.94,14.81M17,11.74L15.66,13.1V12.5C15.67,12 15.59,11.5 15.43,11.06L15.38,10.92L15.78,10.5C16.09,10.2 16.26,9.79 16.26,9.36C16.26,8.93 16.09,8.53 15.78,8.22C15.17,7.6 14.1,7.61 13.5,8.22L11.3,10.42C11,10.72 10.82,11.13 10.82,11.56C10.82,12 11,12.39 11.3,12.7C11.47,12.86 11.56,13.08 11.56,13.32C11.56,13.56 11.47,13.78 11.3,13.94C11.13,14.11 10.91,14.19 10.68,14.19C10.46,14.19 10.23,14.11 10.06,13.94C8.75,12.63 8.75,10.5 10.06,9.19L12.27,7C13.58,5.67 15.71,5.68 17,7C17.65,7.62 18,8.46 18,9.36C18,10.26 17.65,11.1 17,11.74Z',
    ];

    if (!isset($paths[$name])) {
        return '';
    }

    $path = htmlspecialchars($paths[$name], ENT_QUOTES, 'UTF-8');
    // Decorative-by-default: every consumer wraps the icon in a button or link
    // that already provides an accessible name. aria-hidden prevents screen
    // readers from announcing the icon as a redundant element.
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' . $path . '" /></svg>';
}

// Render a single link list item (link or separator).
function lawnding_render_link_item($link): string {
    if (!is_array($link)) {
        return '';
    }

    $type = $link['type'] ?? '';
    if ($type === 'separator') {
        return '<li class="separator" aria-hidden="true"><hr></li>';
    }

    if ($type !== 'link') {
        return '';
    }

    $href = $link['href'] ?? '#';
    $title = $link['title'] ?? '';
    $text = $link['text'] ?? '';
    $id = $link['id'] ?? '';
    $isFullWidth = !empty($link['fullWidth']);
    $isCta = !empty($link['cta']);
    $contentLevel = isset($link['content']) && is_string($link['content'])
        ? strtolower(trim($link['content']))
        : 'sfw';
    $isNsfw = $contentLevel === 'nsfw';
    $liClasses = trim(($isFullWidth ? 'fullWidth ' : '') . 'linkItem');
    $aClasses = trim('link linkTelegram ' . ($isCta ? 'cta ' : '') . ($isNsfw ? 'link--nsfw ' : ''));
    $badgeHtml = $isNsfw
        ? '<span class="linkContentBadge linkContentBadge--nsfw" aria-label="NSFW">NSFW</span>'
        : '';

    return '<li class="' . htmlspecialchars($liClasses) . '" id="' . htmlspecialchars($id) . '">'
        . '<a class="' . htmlspecialchars($aClasses) . '" href="' . htmlspecialchars($href) . '" title="' . htmlspecialchars($title) . '">'
        . $badgeHtml
        . '<span class="linkLabel">' . htmlspecialchars($text) . '</span>'
        . '</a>'
        . '</li>';
}

function lawnding_auth_link_allowed($link, string $userContentLevel): bool {
    if (!is_array($link)) {
        return false;
    }
    $type = $link['type'] ?? '';
    if ($type === 'separator') {
        return true;
    }
    if ($type !== 'link') {
        return false;
    }
    $linkContent = isset($link['content']) && is_string($link['content'])
        ? strtolower(trim($link['content']))
        : 'sfw';
    if ($linkContent !== 'nsfw') {
        $linkContent = 'sfw';
    }
    return $userContentLevel === 'nsfw' || $linkContent === 'sfw';
}

function lawnding_filter_auth_links(array $links, string $userContentLevel): array {
    $filtered = [];
    $pendingSeparator = false;
    foreach ($links as $link) {
        if (!is_array($link)) {
            continue;
        }
        if (($link['type'] ?? '') === 'separator') {
            if (!empty($filtered)) {
                $pendingSeparator = true;
            }
            continue;
        }
        if (!lawnding_auth_link_allowed($link, $userContentLevel)) {
            continue;
        }
        if ($pendingSeparator) {
            $filtered[] = ['type' => 'separator'];
            $pendingSeparator = false;
        }
        $filtered[] = $link;
    }
    return $filtered;
}

// Load links configuration used by the links pane.
$linksJsonPath = lawnding_public_data_path('links.json');
$linksPayload = lawnding_read_json($linksJsonPath, []);
$linksSettings = ['show_links' => true, 'auth_links' => false];
$linksData = [];
if (is_array($linksPayload) && array_key_exists('links', $linksPayload)) {
    $linksData = is_array($linksPayload['links']) ? $linksPayload['links'] : [];
    if (isset($linksPayload['settings']) && is_array($linksPayload['settings'])) {
        if (array_key_exists('show_links', $linksPayload['settings'])) {
            $linksSettings['show_links'] = !empty($linksPayload['settings']['show_links']);
        }
        if (array_key_exists('auth_links', $linksPayload['settings'])) {
            $linksSettings['auth_links'] = !empty($linksPayload['settings']['auth_links']);
        }
    }
} elseif (is_array($linksPayload)) {
    $linksData = $linksPayload;
}

$authLinksEnabled = !empty($linksSettings['auth_links']);
$authLinksData = [];
$tgConfig = lawnding_load_tg_config();
$tgBotMessage = (string) ($tgConfig['unauthorized_message'] ?? 'Unable to display member links.  Join the telegram group with the link above, or contact an admin for assistance.');
$tgBotUsername = isset($tgConfig['bot_username']) && is_string($tgConfig['bot_username'])
    ? ltrim(trim($tgConfig['bot_username']), '@')
    : '';
$tgBotId = isset($tgConfig['bot_token']) && is_string($tgConfig['bot_token'])
    ? lawnding_tg_bot_id_from_token($tgConfig['bot_token'])
    : '';
$returnPath = '/';
$authEndpoint = function_exists('lawnding_asset_url')
    ? lawnding_asset_url('res/scr/plugin-endpoint.php?plugin=telegram&endpoint=auth')
    : '/res/scr/plugin-endpoint.php?plugin=telegram&endpoint=auth';
$logoutEndpoint = function_exists('lawnding_asset_url')
    ? lawnding_asset_url('res/scr/plugin-endpoint.php?plugin=telegram&endpoint=logout')
    : '/res/scr/plugin-endpoint.php?plugin=telegram&endpoint=logout';
// $authEndpoint / $logoutEndpoint already carry ?plugin=...&endpoint=...,
// so return joins with '&'. Using '?' produced a double-query the proxy
// rejected as "Invalid endpoint name."
$tgAuthUrl = lawnding_public_absolute_url($authEndpoint . '&return=' . rawurlencode($returnPath));
$tgLogoutUrl = $logoutEndpoint . '&return=' . rawurlencode($returnPath);
$authLinksState = 'logged_out';
$authLinksUserLevel = '';
$markdownGateClearance = 'none';
$tgUser = isset($_SESSION['tg_user']) && is_array($_SESSION['tg_user']) ? $_SESSION['tg_user'] : null;
$tgUserId = $tgUser['id'] ?? ($_SESSION['tg_user_id'] ?? null);
$viewerContentLevel = '';
if (!empty($tgUserId)) {
    $viewerContentLevel = lawnding_tg_user_content_level($tgConfig, $tgUserId, $tgUser);
    if ($viewerContentLevel === '') {
        // Group membership lapsed since login. Drop the session entirely
        // instead of degrading to a half-authorized state, and surface a
        // banner on the next render. Shared with the admin-side revocation
        // path via lawnding_admin_handle_tg_revocation() in admin/auth.php
        // so both surfaces tear down identically.
        $adminAuthPath = function_exists('lawnding_admin_path')
            ? lawnding_admin_path('auth.php')
            : __DIR__ . '/../admin/auth.php';
        require_once $adminAuthPath;
        lawnding_admin_handle_tg_revocation();
        $tgUser = null;
        $tgUserId = null;
    } elseif ($viewerContentLevel === 'nsfw') {
        $markdownGateClearance = 'nsfw';
    } elseif ($viewerContentLevel === 'sfw') {
        $markdownGateClearance = 'sfw';
    }
}
// Telegram-authenticated visitors whose groups grant any admin permission
// get a shortcut button on the public header. Pure cache read — the content
// level check above already warmed the membership cache.
$adminShortcutUrl = '';
if (!empty($tgUserId) && $viewerContentLevel !== '') {
    $tgAdminPerms = lawnding_tg_user_permissions($tgConfig, $tgUserId);
    if (!empty($tgAdminPerms)) {
        // ?from=public is a one-shot arrival marker picked up by admin/index.php
        // so logging out from this session returns the visitor to the public
        // site instead of the admin login form.
        $adminShortcutUrl = lawnding_asset_url('admin/') . '?from=public';
    }
}
if ($authLinksEnabled) {
    $authLinksJsonPath = lawnding_public_data_path('authorizedLinks.json');
    $authLinksPayload = lawnding_read_json($authLinksJsonPath, []);
    if (is_array($authLinksPayload) && array_key_exists('links', $authLinksPayload)) {
        $authLinksData = is_array($authLinksPayload['links']) ? $authLinksPayload['links'] : [];
    } elseif (is_array($authLinksPayload)) {
        $authLinksData = $authLinksPayload;
    }
}
if ($authLinksEnabled) {
    if (!empty($tgUserId)) {
        $authLinksUserLevel = $viewerContentLevel;
        $authLinksState = $authLinksUserLevel !== '' ? 'authorized' : 'unauthorized';
    } else {
        $authLinksState = 'logged_out';
    }
}
if ($authLinksEnabled && $authLinksState === 'authorized') {
    $authLinksData = lawnding_filter_auth_links($authLinksData, $authLinksUserLevel);
}

// Load header configuration with defaults if missing.
$headerJsonPath = lawnding_public_data_path('header.json');
$headerData = [
    'logo' => 'res/img/logo.jpg',
    'title' => 'Title',
    'subtitle' => 'Subtitle',
    'backgrounds' => [],
    'backgroundSettings' => [
        'mode' => 'random_load',
        'duration' => 5
    ]
];
$headerData = array_merge($headerData, lawnding_read_json($headerJsonPath, []));
if (empty($headerData['backgroundSettings']) || !is_array($headerData['backgroundSettings'])) {
    $headerData['backgroundSettings'] = [
        'mode' => 'random_load',
        'duration' => 5
    ];
}
// Resolve asset URLs relative to the detected base URL.
$resolveAssetUrl = function ($path) {
    if (!is_string($path) || $path === '') {
        return $path;
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) || str_starts_with($path, '//')) {
        return $path;
    }
    return function_exists('lawnding_asset_url') ? lawnding_asset_url($path) : $path;
};
$headerDataResolved = $headerData;
$headerDataResolved['logo'] = lawnding_versioned_local_asset_url($headerData['logo'] ?? '');
if (!empty($headerDataResolved['backgrounds']) && is_array($headerDataResolved['backgrounds'])) {
    $headerDataResolved['backgrounds'] = array_map(function ($bg) {
        if (is_string($bg)) {
            return lawnding_versioned_local_asset_url($bg);
        }
        if (is_array($bg)) {
            $bg['url'] = lawnding_versioned_local_asset_url($bg['url'] ?? '');
            return $bg;
        }
        return $bg;
    }, $headerDataResolved['backgrounds']);
}
$faviconRaw = is_string($headerData['logo'] ?? null) && $headerData['logo'] !== ''
    ? $headerData['logo']
    : 'res/img/logo.jpg';
$faviconHref = lawnding_versioned_local_asset_url(
    $faviconRaw,
    defined('SITE_VERSION') ? (string) SITE_VERSION : ''
);
$headerDataJson = htmlspecialchars(json_encode($headerDataResolved, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');

// Render pane icon from panes.json (SVG string or uploaded file reference).
// Falls back to the module manifest's default_icon when the pane has no
// icon set, so panes created before this fallback existed (or any pane
// the admin hasn't manually iconified) still render with a sensible icon
// on the navbar.
function lawnding_render_pane_icon(array $pane): string {
    $icon = $pane['icon'] ?? [];
    if (is_array($icon)) {
        $type = $icon['type'] ?? '';
        if ($type === 'svg') {
            $svg = $icon['value'] ?? '';
            if (is_string($svg) && $svg !== '' && lawnding_is_safe_svg($svg)) {
                return $svg;
            }
        } elseif ($type === 'file') {
            $value = $icon['value'] ?? '';
            if (is_string($value) && $value !== '') {
                $src = lawnding_versioned_local_asset_url('res/img/panes/' . ltrim($value, '/'));
                return '<img class="navLinkIconImage" src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="">';
            }
        }
    }
    $moduleId = $pane['module'] ?? '';
    if (is_string($moduleId) && $moduleId !== '') {
        $default = lawnding_module_default_icon($moduleId);
        if ($default !== '' && lawnding_is_safe_svg($default)) {
            return $default;
        }
    }
    return '';
}

function lawnding_nav_label(string $name): string {
    $trimmed = trim($name);
    if ($trimmed === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($trimmed) > 12 ? mb_substr($trimmed, 0, 10) . '...' : $trimmed;
    }
    return strlen($trimmed) > 12 ? substr($trimmed, 0, 10) . '...' : $trimmed;
}

function lawnding_load_external_nav_settings(array $pane): array {
    $default = ['url' => '', 'openMode' => 'new', 'iconMode' => 'custom'];
    $paneData = isset($pane['data']) && is_array($pane['data']) ? $pane['data'] : [];
    $jsonFile = isset($paneData['json']) && is_string($paneData['json']) ? $paneData['json'] : '';
    if ($jsonFile === '') {
        return $default;
    }
    $jsonPath = lawnding_public_data_path($jsonFile);
    if (!is_readable($jsonPath)) {
        return $default;
    }
    $decoded = json_decode((string) file_get_contents($jsonPath), true);
    if (!is_array($decoded)) {
        return $default;
    }
    $url = isset($decoded['url']) && is_string($decoded['url']) ? trim($decoded['url']) : '';
    $openMode = (isset($decoded['openMode']) && $decoded['openMode'] === 'same') ? 'same' : 'new';
    $iconMode = (isset($decoded['iconMode']) && $decoded['iconMode'] === 'favicon') ? 'favicon' : 'custom';
    return ['url' => $url, 'openMode' => $openMode, 'iconMode' => $iconMode];
}

$panesPath = lawnding_public_data_path('panes.json');
$panes = lawnding_sort_panes(lawnding_load_panes($panesPath));
$showLinks = !empty($linksSettings['show_links']);
$isLinksOnly = $showLinks && count($panes) === 0;
$isLinksHidden = !$showLinks;
?> 

<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo htmlspecialchars($headerData['title'] ?? ''); ?></title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <script src="<?php echo htmlspecialchars(lawnding_asset_url('res/scr/no-zoom.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    
    <link rel="icon" href="<?php echo htmlspecialchars($faviconHref, ENT_QUOTES, 'UTF-8'); ?>"/>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(lawnding_versioned_local_asset_url('res/style.css'), ENT_QUOTES, 'UTF-8'); ?>">

    <script src="<?php echo htmlspecialchars(lawnding_asset_url('res/scr/jquery-3.7.1.min.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <?php lawnding_run_hook('head_assets'); ?>
    <noscript>
        <style>
            body.is-loading #header,
            body.is-loading #container,
            body.is-loading nav { opacity: 1; pointer-events: auto; }
            body::before { opacity: 1; }
        </style>
    </noscript>
</head>
<body class="is-loading<?php echo $isLinksOnly ? ' linksOnly' : ''; ?><?php echo $isLinksHidden ? ' linksHidden' : ''; ?>" data-header-json="<?php echo $headerDataJson; ?>">
    <!-- Keyboard skip target — reveals on focus, jumps past the header. -->
    <a class="skipLink" href="#container">Skip to main content</a>
    <!-- No-JS fallback for browsers with JavaScript disabled. -->
    <div id="noJsWarning"><noscript>This site requires JavaScript to function properly. Please enable JavaScript in your browser.</noscript></div>
    <!-- Flash notice container (drained from $_SESSION['lp_flash']). -->
    <!-- aria-live: notices added/replaced here are announced by screen readers. -->
    <div class="adminNotices" id="adminNotices" aria-live="polite" aria-atomic="true">
        <?php $flash = lawnding_flash_consume(); ?>
        <?php if ($flash !== null): ?>
            <div class="adminNotice adminNotice--<?php echo htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo $flash['type'] === 'danger' ? ' role="alert"' : ''; ?>>
                <span class="adminNoticeText"><?php echo htmlspecialchars($flash['text'], ENT_QUOTES, 'UTF-8'); ?></span>
                <button type="button" class="adminNoticeClose" aria-label="Dismiss notification">×</button>
            </div>
        <?php endif; ?>
    </div>
    <!-- Header with logo and title/subtitle. -->
    <header class="header" id="header">
        <div class="logo" id="logo"></div>
        <div class="headline">
            <h1><?php echo htmlspecialchars($headerData['title'] ?? ''); ?></h1>
            <h2><?php echo htmlspecialchars($headerData['subtitle'] ?? ''); ?></h2>
        </div>
        <?php lawnding_run_hook('header_auth_area', [
            'tgUser' => $tgUser,
            'logoutUrl' => $tgLogoutUrl,
            'adminUrl' => $adminShortcutUrl,
        ]); ?>
    </header>
    <!-- Main content panes. -->
    <main class="container" id="container">
        <?php if ($showLinks): ?>
            <div class="pane glassConvex alwaysShow" id="links">
                <h3>LINKS</h3>
                <ul class="linkList" id="linkList">
                    <?php foreach ($linksData as $link): ?>
                        <?php echo lawnding_render_link_item($link); ?>
                    <?php endforeach; ?>
                    <?php if ($authLinksEnabled && $authLinksState === 'authorized'): ?>
                        <?php foreach ($authLinksData as $link): ?>
                            <?php echo lawnding_render_link_item($link); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php // Render each dynamic pane using its module template. ?>
        <?php foreach ($panes as $pane): ?>
            <?php
            $moduleId = isset($pane['module']) ? (string) $pane['module'] : '';
            $modulePath = function_exists('lawnding_admin_path')
                ? lawnding_admin_path('modules/' . $moduleId . '/public.php')
                : __DIR__ . '/../admin/modules/' . $moduleId . '/public.php';
            if ($moduleId !== '' && is_readable($modulePath)) {
                include $modulePath;
            }
            ?>
        <?php endforeach; ?>
    </main>
    <!-- Bottom navigation for pane switching and footer credits. -->
    <nav>
        <div class="navBarWrap" id="navBarWrap">
            <ul class="navBar glassConcave" id="navBar">
            <?php if ($showLinks): ?>
                <li>
                    <a class="navLink" href="#" data-pane="links" aria-label="Links" title="Links">
                        <?php echo lawnding_icon_svg('links'); ?>
                        <span class="navLinkLabel">Links</span>
                    </a>
                </li>
            <?php endif; ?>
            <?php // Render dynamic pane nav items from panes.json. ?>
            <?php foreach ($panes as $pane): ?>
                <?php
                $paneId = isset($pane['id']) ? (string) $pane['id'] : '';
                $paneName = isset($pane['name']) ? (string) $pane['name'] : '';
                $moduleId = isset($pane['module']) ? (string) $pane['module'] : '';
                $isExternalNav = $moduleId === 'externalLink';
                $icon = lawnding_render_pane_icon($pane);
                $label = lawnding_nav_label($paneName);
                if ($paneId === '') {
                    continue;
                }
                if ($isExternalNav) {
                    $external = lawnding_load_external_nav_settings($pane);
                    $href = lawnding_normalize_external_url((string) ($external['url'] ?? ''));
                    if ($href === '') {
                        $href = '#';
                    }
                    $target = (($external['openMode'] ?? 'new') === 'same') ? '_self' : '_blank';
                    if (($external['iconMode'] ?? 'custom') === 'favicon') {
                        $domain = lawnding_external_nav_domain($href);
                        $favicon = lawnding_google_favicon_url($domain);
                        if ($favicon !== '') {
                            $icon = '<img class="navLinkIconImage" src="' . htmlspecialchars($favicon, ENT_QUOTES, 'UTF-8') . '" alt="">';
                        }
                    }
                }
                ?>
                <li>
                    <?php if ($isExternalNav): ?>
                        <a class="navLink navLinkExternal" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>" target="<?php echo htmlspecialchars($target, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $target === '_blank' ? ' rel="noopener noreferrer"' : ''; ?> data-external="true" aria-label="<?php echo htmlspecialchars($paneName); ?>" title="<?php echo htmlspecialchars($paneName); ?>">
                            <?php echo $icon; ?>
                            <span class="navLinkLabel"><?php echo htmlspecialchars($label); ?></span>
                        </a>
                    <?php else: ?>
                        <a class="navLink" href="#" data-pane="<?php echo htmlspecialchars($paneId); ?>" aria-label="<?php echo htmlspecialchars($paneName); ?>" title="<?php echo htmlspecialchars($paneName); ?>">
                            <?php echo $icon; ?>
                            <span class="navLinkLabel"><?php echo htmlspecialchars($label); ?></span>
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
            <div class="navBarFadeLayer" aria-hidden="true">
                <div class="navBarFade navBarFadeLeft"></div>
                <div class="navBarFade navBarFadeRight"></div>
            </div>
        </div>
        <div class="footer">
            <?php echo lawnding_footer_platform_html(); ?><span class="footerSep"> · </span>Background image by <span class="authorPlain"></span><a class="authorLink hidden" href="" rel="noopener" target="_blank"><span class="authorName"></span></a>.
        </div>
    </nav>
    <?php
    if (!class_exists('Parsedown')) {
        require_once lawnding_public_path('res/scr/Parsedown.php');
    }
    $changelogMdPath = lawnding_config('root_dir', dirname(dirname(__DIR__))) . '/CHANGELOG.md';
    $changelogHtml = '';
    if (is_readable($changelogMdPath)) {
        $clParser = new Parsedown();
        $changelogHtml = $clParser->text((string) file_get_contents($changelogMdPath));
    }
    ?>
    <div class="changelogModal" id="changelogModal" role="dialog" aria-modal="true" aria-label="Changelog" hidden>
        <div class="changelogModalBackdrop"></div>
        <div class="changelogModalInner">
            <div class="changelogModalHeader">
                <span>Changelog</span>
                <button class="changelogModalClose" type="button" aria-label="Close changelog">✕</button>
            </div>
            <div class="changelogModalBody changelogContent">
                <?php echo $changelogHtml; ?>
            </div>
        </div>
    </div>
    <?php
    // Login modal — rendered only for visitors who aren't authenticated by
    // either bcrypt or Telegram. The data-* attributes carry the runtime
    // values the JS needs (CSRF, endpoint URLs, optional Telegram bot info).
    $lpShowLoginModal = empty($_SESSION['auth_user']) && empty($tgUser);
    if ($lpShowLoginModal):
        $lpLoginEndpoint = lawnding_asset_url('res/scr/login.php');
        $lpTgAuthEndpoint = lawnding_asset_url('res/scr/plugin-endpoint.php?plugin=telegram&endpoint=auth');
    ?>
    <div id="lpLoginModal" class="lpLoginModal" role="dialog" aria-modal="true"
         aria-labelledby="lpLoginModalTitle" hidden
         data-csrf-token="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
         data-tg-bot-username="<?php echo htmlspecialchars($tgBotUsername, ENT_QUOTES, 'UTF-8'); ?>"
         data-tg-bot-id="<?php echo htmlspecialchars($tgBotId, ENT_QUOTES, 'UTF-8'); ?>"
         data-login-endpoint="<?php echo htmlspecialchars($lpLoginEndpoint, ENT_QUOTES, 'UTF-8'); ?>"
         data-tg-auth-endpoint="<?php echo htmlspecialchars($lpTgAuthEndpoint, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="lpLoginModal__backdrop" data-lp-login-dismiss aria-hidden="true"></div>
        <div class="lpLoginModal__dialog" role="document">
            <header class="lpLoginModal__header">
                <h2 id="lpLoginModalTitle" class="lpLoginModal__title">Sign in</h2>
                <button type="button" class="lpLoginModal__close"
                        data-lp-login-dismiss aria-label="Close sign-in dialog">×</button>
            </header>
            <form class="lpLoginModal__form" novalidate data-lp-login-form>
                <label class="lpLoginModal__field">
                    <span class="lpLoginModal__fieldLabel">Username</span>
                    <input type="text" name="username" autocomplete="username"
                           class="lpLoginModal__input" required>
                </label>
                <label class="lpLoginModal__field">
                    <span class="lpLoginModal__fieldLabel">Password</span>
                    <input type="password" name="password" autocomplete="current-password"
                           class="lpLoginModal__input" required>
                </label>
                <label class="lpLoginModal__remember">
                    <input type="checkbox" name="remember" value="1">
                    <span>Stay signed in</span>
                </label>
                <output role="alert" aria-live="polite"
                        class="lpLoginModal__error" data-lp-login-error hidden></output>
                <button type="submit" class="lpLoginModal__submit">Sign in</button>
            </form>
            <div class="lpLoginModal__divider" aria-hidden="true">
                <span>or</span>
            </div>
            <button type="button" class="lpLoginModal__telegram"
                    data-lp-login-telegram
                    <?php echo $tgBotId === '' ? 'disabled aria-disabled="true"' : ''; ?>>
                <svg aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 24 24">
                    <path fill="currentColor" d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71l-4.14-3.06-1.99 1.93c-.23.23-.42.42-.83.42z"/>
                </svg>
                <span><?php echo $tgBotId === '' ? 'Telegram login unavailable' : 'Login with Telegram'; ?></span>
            </button>
        </div>
    </div>
    <?php endif; ?>
    <script src="<?php echo htmlspecialchars(lawnding_asset_url('res/scr/public-data.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script src="<?php echo htmlspecialchars(lawnding_asset_url('res/scr/shared-utils.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script src="<?php echo htmlspecialchars(lawnding_asset_url('res/scr/notice-core.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script src="<?php echo htmlspecialchars(lawnding_asset_url('res/scr/modal-core.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script src="<?php echo htmlspecialchars(lawnding_asset_url('res/scr/public-modals.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script src="<?php echo htmlspecialchars(lawnding_asset_url('res/scr/app.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <?php if ($lpShowLoginModal): ?>
    <script src="<?php echo htmlspecialchars(lawnding_versioned_local_asset_url('res/scr/login-modal.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <?php endif; ?>
</body>
</html>
