<?php
// Public landing page entrypoint: load content and render the UI.

// Locate the bootstrap file (supports alternate directory layouts).
$bootstrapPath = __DIR__ . '/../lp-bootstrap.php';
if (!is_readable($bootstrapPath)) {
    $bootstrapPath = __DIR__ . '/../../lp-bootstrap.php';
}
require_once $bootstrapPath;
// Prevent stale HTML/PHP responses from being cached.
$cacheHeadersPath = function_exists('lawnding_public_path')
    ? lawnding_public_path('res/scr/cache_headers.php')
    : __DIR__ . '/res/scr/cache_headers.php';
require_once $cacheHeadersPath;
// Load the authoritative site version and set client cookie if needed.
$versionPath = function_exists('lawnding_public_path')
    ? lawnding_public_path('res/version.php')
    : __DIR__ . '/res/version.php';
require_once $versionPath;
if (!isset($_COOKIE['site_version']) || $_COOKIE['site_version'] !== SITE_VERSION) {
    setcookie('site_version', SITE_VERSION, [
        'expires' => time() + 31536000,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}
// Suppress error output in production responses.
ini_set('display_errors', '0');

// Content Security Policy for the public site.
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: https://www.google.com https://t0.gstatic.com https://t1.gstatic.com https://t2.gstatic.com https://t3.gstatic.com; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');

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

// Decode a JSON file into an array; otherwise return the fallback.
function lawnding_read_json($path, array $fallback = []) {
    if (!is_readable($path)) {
        return $fallback;
    }
    $decoded = json_decode(file_get_contents($path), true);
    return is_array($decoded) ? $decoded : $fallback;
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
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="' . $path . '" /></svg>';
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
    $liClasses = trim(($isFullWidth ? 'fullWidth ' : '') . 'linkItem');
    $aClasses = trim('link linkTelegram ' . ($isCta ? 'cta ' : ''));

    return '<li class="' . htmlspecialchars($liClasses) . '" id="' . htmlspecialchars($id) . '">'
        . '<a class="' . htmlspecialchars($aClasses) . '" href="' . htmlspecialchars($href) . '" title="' . htmlspecialchars($title) . '">'
        . '<span class="linkLabel">' . htmlspecialchars($text) . '</span>'
        . '</a>'
        . '</li>';
}

// Load links configuration used by the links pane.
$linksJsonPath = lawnding_public_data_path('links.json');
$linksData = lawnding_read_json($linksJsonPath, []);

// Load header configuration with defaults if missing.
$headerJsonPath = lawnding_public_data_path('header.json');
$headerData = [
    'logo' => 'res/img/logo.jpg',
    'title' => 'Long Island Furs',
    'subtitle' => 'A Long Island furry community encompassing Queens, Nassau County, and Suffolk County.  And Staten Island, but we do not talk about that.',
    'backgrounds' => ['res/img/bg.jpg'],
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
$headerDataResolved['logo'] = $resolveAssetUrl($headerDataResolved['logo'] ?? '');
if (!empty($headerDataResolved['backgrounds']) && is_array($headerDataResolved['backgrounds'])) {
    $headerDataResolved['backgrounds'] = array_map(function ($bg) use ($resolveAssetUrl) {
        if (is_string($bg)) {
            return $resolveAssetUrl($bg);
        }
        if (is_array($bg)) {
            $bg['url'] = $resolveAssetUrl($bg['url'] ?? '');
            return $bg;
        }
        return $bg;
    }, $headerDataResolved['backgrounds']);
}
$headerDataJson = htmlspecialchars(json_encode($headerDataResolved, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');

// Load pane instances and module manifests for dynamic public rendering.
function lawnding_load_panes(string $path): array {
    if (!is_readable($path)) {
        return [];
    }
    $decoded = json_decode(file_get_contents($path), true);
    if (!is_array($decoded) || !isset($decoded['panes']) || !is_array($decoded['panes'])) {
        return [];
    }
    return $decoded['panes'];
}

// Sort panes by explicit order while preserving original order as tie-breaker.
function lawnding_sort_panes(array $panes): array {
    $indexed = [];
    foreach ($panes as $index => $pane) {
        if (is_array($pane)) {
            $pane['_index'] = $index;
            $indexed[] = $pane;
        }
    }
    usort($indexed, function ($a, $b) {
        $orderA = isset($a['order']) ? (int) $a['order'] : PHP_INT_MAX;
        $orderB = isset($b['order']) ? (int) $b['order'] : PHP_INT_MAX;
        if ($orderA === $orderB) {
            return ($a['_index'] ?? 0) <=> ($b['_index'] ?? 0);
        }
        return $orderA <=> $orderB;
    });
    foreach ($indexed as &$pane) {
        unset($pane['_index']);
    }
    return $indexed;
}

// Minimal SVG sanitizer to block scripts and inline event handlers.
function lawnding_is_safe_svg(?string $svg): bool {
    if (!is_string($svg) || $svg === '') {
        return false;
    }
    if (stripos($svg, '<script') !== false) {
        return false;
    }
    if (preg_match('/\\son[a-z]+\\s*=\\s*["\']?/i', $svg)) {
        return false;
    }
    return true;
}

// Render pane icon from panes.json (SVG string or uploaded file reference).
function lawnding_render_pane_icon(array $pane): string {
    $icon = $pane['icon'] ?? [];
    if (!is_array($icon)) {
        return '';
    }
    $type = $icon['type'] ?? '';
    if ($type === 'svg') {
        $svg = $icon['value'] ?? '';
        return lawnding_is_safe_svg($svg) ? $svg : '';
    }
    if ($type === 'file') {
        $value = $icon['value'] ?? '';
        if (!is_string($value) || $value === '') {
            return '';
        }
        $src = function_exists('lawnding_asset_url')
            ? lawnding_asset_url('res/img/panes/' . ltrim($value, '/'))
            : 'res/img/panes/' . ltrim($value, '/');
        return '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="">';
    }
    return '';
}

$panesPath = lawnding_public_data_path('panes.json');
$panes = lawnding_sort_panes(lawnding_load_panes($panesPath));
?> 

<!DOCTYPE html>
<html lang="en" data-site-version="<?php echo htmlspecialchars(SITE_VERSION, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <script src="<?php echo htmlspecialchars(lawnding_versioned_url(lawnding_asset_url('res/scr/site-version.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script src="<?php echo htmlspecialchars(lawnding_versioned_url(lawnding_asset_url('res/scr/no-zoom.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
    
    <link rel="icon" type="image/jpg" href="<?php echo htmlspecialchars(lawnding_asset_url('res/img/logo.jpg'), ENT_QUOTES, 'UTF-8'); ?>"/>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(lawnding_versioned_url(lawnding_asset_url('res/style.css')), ENT_QUOTES, 'UTF-8'); ?>">

    <script src="<?php echo htmlspecialchars(lawnding_versioned_url(lawnding_asset_url('res/scr/jquery-3.7.1.min.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <noscript>
        <style>
            body.is-loading #header,
            body.is-loading #container,
            body.is-loading nav { opacity: 1; pointer-events: auto; }
            body::before { opacity: 1; }
        </style>
    </noscript>
</head>
<body class="is-loading" data-header-json="<?php echo $headerDataJson; ?>">
    <!-- No-JS fallback for browsers with JavaScript disabled. -->
    <div id="noJsWarning"><noscript>This site requires JavaScript to function properly. Please enable JavaScript in your browser.</noscript></div>
    <!-- Header with logo and title/subtitle. -->
    <header class="header" id="header">
        <div class="logo" id="logo"></div>
        <div class="headline">
            <h1><?php echo htmlspecialchars($headerData['title'] ?? ''); ?></h1>
            <h2><?php echo htmlspecialchars($headerData['subtitle'] ?? ''); ?></h2>
        </div>
    </header>
    <!-- Main content panes. -->
    <div class="container" id="container">
        <div class="pane glassConvex alwaysShow" id="links">
            <h3>LINKS</h3>
            <ul class="linkList" id="linkList">
                <?php foreach ($linksData as $link): ?>
                    <?php echo lawnding_render_link_item($link); ?>
                <?php endforeach; ?>
            </ul>
        </div>
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
    </div>
    <!-- Bottom navigation for pane switching and footer credits. -->
    <nav>
        <div class="navBarWrap" id="navBarWrap">
            <ul class="navBar glassConcave" id="navBar">
            <li><a class="navLink" href="#" data-pane="links" aria-label="Links" title="Links"><?php echo lawnding_icon_svg('links'); ?></a></li>
            <?php // Render dynamic pane nav items from panes.json. ?>
            <?php foreach ($panes as $pane): ?>
                <?php
                $paneId = isset($pane['id']) ? (string) $pane['id'] : '';
                $paneName = isset($pane['name']) ? (string) $pane['name'] : '';
                $icon = lawnding_render_pane_icon($pane);
                if ($paneId === '') {
                    continue;
                }
                ?>
                <li>
                    <a class="navLink" href="#" data-pane="<?php echo htmlspecialchars($paneId); ?>" aria-label="<?php echo htmlspecialchars($paneName); ?>" title="<?php echo htmlspecialchars($paneName); ?>">
                        <?php echo $icon; ?>
                    </a>
                </li>
            <?php endforeach; ?>
            </ul>
            <div class="navBarFadeLayer" aria-hidden="true">
                <div class="navBarFade navBarFadeLeft"></div>
                <div class="navBarFade navBarFadeRight"></div>
            </div>
        </div>
        <div class="footer">
            LawndingPage <?php echo htmlspecialchars(SITE_VERSION, ENT_QUOTES, 'UTF-8'); ?>.  Background image by <span class="authorPlain"></span><a class="authorLink hidden" href="" rel="noopener" target="_blank"><span class="authorName"></span></a>.
        </div>
    </nav>
    <script src="<?php echo htmlspecialchars(lawnding_versioned_url(lawnding_asset_url('res/scr/public-data.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script src="<?php echo htmlspecialchars(lawnding_versioned_url(lawnding_asset_url('res/scr/app.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
