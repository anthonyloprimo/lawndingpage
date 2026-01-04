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
        'httponly' => false,
        'samesite' => 'Lax'
    ]);
}
// Suppress error output in production responses.
ini_set('display_errors', '0');

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
        'about' => 'M13,9H11V7H13M13,17H11V11H13M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2Z',
        'rules' => 'M7,13V11H21V13H7M7,19V17H21V19H7M7,7V5H21V7H7M3,8V5H2V4H4V8H3M2,17V16H5V20H2V19H4V18.5H3V17.5H4V17H2M4.25,10A0.75,0.75 0 0,1 5,10.75C5,10.95 4.92,11.14 4.79,11.27L3.12,13H5V14H2V13.08L4,11H2V10H4.25Z',
        'faq' => 'M18,15H6L2,19V3A1,1 0 0,1 3,2H18A1,1 0 0,1 19,3V14A1,1 0 0,1 18,15M23,9V23L19,19H8A1,1 0 0,1 7,18V17H21V8H22A1,1 0 0,1 23,9M8.19,4C7.32,4 6.62,4.2 6.08,4.59C5.56,5 5.3,5.57 5.31,6.36L5.32,6.39H7.25C7.26,6.09 7.35,5.86 7.53,5.7C7.71,5.55 7.93,5.47 8.19,5.47C8.5,5.47 8.76,5.57 8.94,5.75C9.12,5.94 9.2,6.2 9.2,6.5C9.2,6.82 9.13,7.09 8.97,7.32C8.83,7.55 8.62,7.75 8.36,7.91C7.85,8.25 7.5,8.55 7.31,8.82C7.11,9.08 7,9.5 7,10H9C9,9.69 9.04,9.44 9.13,9.26C9.22,9.08 9.39,8.9 9.64,8.74C10.09,8.5 10.46,8.21 10.75,7.81C11.04,7.41 11.19,7 11.19,6.5C11.19,5.74 10.92,5.13 10.38,4.68C9.85,4.23 9.12,4 8.19,4M7,11V13H9V11H7M13,13H15V11H13V13M13,4V10H15V4H13Z',
        'events' => 'M19,19V8H5V19H19M16,1H18V3H19A2,2 0 0,1 21,5V19A2,2 0 0,1 19,21H5C3.89,21 3,20.1 3,19V5C3,3.89 3.89,3 5,3H6V1H8V3H16V1M7,10H9V12H7V10M15,10H17V12H15V10M11,14H13V16H11V14M15,14H17V16H15V14Z',
        'donate' => 'M7,15H9C9,16.08 10.37,17 12,17C13.63,17 15,16.08 15,15C15,13.9 13.96,13.5 11.76,12.97C9.64,12.44 7,11.78 7,9C7,7.21 8.47,5.69 10.5,5.18V3H13.5V5.18C15.53,5.69 17,7.21 17,9H15C15,7.92 13.63,7 12,7C10.37,7 9,7.92 9,9C9,10.1 10.04,10.5 12.24,11.03C14.36,11.56 17,12.22 17,15C17,16.79 15.53,18.31 13.5,18.82V21H10.5V18.82C8.47,18.31 7,16.79 7,15Z',
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

// Load Parsedown for Markdown rendering.
// Load Parsedown for Markdown rendering.
$parsedownPath = function_exists('lawnding_public_path')
    ? lawnding_public_path('res/scr/Parsedown.php')
    : __DIR__ . '/res/scr/Parsedown.php';
require $parsedownPath;

// Load markdown content from res/data.
$rulesMdPath = lawnding_public_data_path('rules.md');
$rulesMarkdown = lawnding_read_file($rulesMdPath);
$Parsedown = new Parsedown();
$rules = $Parsedown->text($rulesMarkdown);

$aboutMdPath = lawnding_public_data_path('about.md');
$aboutMarkdown = lawnding_read_file($aboutMdPath);
$about = $Parsedown->text($aboutMarkdown);

$faqMdPath = lawnding_public_data_path('faq.md');
$faqMarkdown = lawnding_read_file($faqMdPath);
$faq = $Parsedown->text($faqMarkdown);

// Load links configuration used by the links pane.
$linksJsonPath = lawnding_public_data_path('links.json');
$linksData = lawnding_read_json($linksJsonPath, []);

// Load header configuration with defaults if missing.
$headerJsonPath = lawnding_public_data_path('header.json');
$headerData = [
    'logo' => 'res/img/logo.jpg',
    'title' => 'Long Island Furs',
    'subtitle' => 'A Long Island furry community encompassing Queens, Nassau County, and Suffolk County.  And Staten Island, but we do not talk about that.',
    'backgrounds' => ['res/img/bg.jpg']
];
$headerData = array_merge($headerData, lawnding_read_json($headerJsonPath, []));
?> 

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <script>
        (function () {
            var serverVersion = "<?php echo htmlspecialchars(SITE_VERSION, ENT_QUOTES, 'UTF-8'); ?>";
            var match = document.cookie.match(/(?:^|;)\s*site_version=([^;]*)/);
            var cookieVersion = match ? decodeURIComponent(match[1]) : "";
            var encodedVersion = encodeURIComponent(serverVersion);
            if (cookieVersion === serverVersion) {
                return;
            }
            if (window.location.search.indexOf("__v=" + encodedVersion) !== -1) {
                document.cookie = "site_version=" + encodedVersion + "; path=/; SameSite=Lax";
                return;
            }
            document.cookie = "site_version=" + encodedVersion + "; path=/; SameSite=Lax";
            var sep = window.location.search ? "&" : "?";
            var target = window.location.pathname + window.location.search + sep
                + "__v=" + encodedVersion + "&__t=" + Date.now() + window.location.hash;
            window.location.replace(target);
        })();
    </script>
    
    <link rel="icon" type="image/jpg" href="res/img/logo.jpg"/>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(lawnding_versioned_url('res/style.css'), ENT_QUOTES, 'UTF-8'); ?>">

    <script src="<?php echo htmlspecialchars(lawnding_versioned_url('res/scr/jquery-3.7.1.min.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
</head>
<body>
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
        <div class="pane glassConvex" id="about">
            <?php echo $about; ?>
        </div>
        <div class="pane glassConvex" id="rules">
            <?php echo $rules ?>
        </div>
        <div class="pane glassConvex" id="faq">
            <?php echo $faq; ?>
        </div>
        <div class="pane glassConvex" id="events">Coming soon...</div>
        <!-- <div class="pane glassConvex" id="donate">Coming soon...</div> -->
    </div>
    <!-- Bottom navigation for pane switching and footer credits. -->
    <nav>
        <ul class="navBar glassConcave" id="navBar">
            <li><a class="navLink" href="#" data-pane="links" aria-label="Links" title="Links"><?php echo lawnding_icon_svg('links'); ?></a></li>
            <li><a class="navLink" href="#" data-pane="about" aria-label="About" title="About"><?php echo lawnding_icon_svg('about'); ?></a></li>
            <li><a class="navLink" href="#" data-pane="rules" aria-label="Rules" title="Rules"><?php echo lawnding_icon_svg('rules'); ?></a></li>
            <li><a class="navLink" href="#" data-pane="faq" aria-label="FAQ" title="FAQ"><?php echo lawnding_icon_svg('faq'); ?></a></li>
            <li><a class="navLink" href="#" data-pane="events" aria-label="Events" title="Events"><?php echo lawnding_icon_svg('events'); ?></a></li>
            <!-- <li><a class="navLink" href="#" data-pane="donate" aria-label="Donate" title="Donate"><?php echo lawnding_icon_svg('donate'); ?></a></li> -->
        </ul>
        <div class="footer">
            LawndingPage <?php echo htmlspecialchars(SITE_VERSION, ENT_QUOTES, 'UTF-8'); ?>.  Background image by <span class="authorPlain"></span><a class="authorLink hidden" href="" rel="noopener" target="_blank"><span class="authorName"></span></a>.
        </div>
    </nav>
    <script>
        // Expose header data to JS for assets like the logo background.
        window.headerData = <?php echo json_encode($headerData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script src="<?php echo htmlspecialchars(lawnding_versioned_url('res/scr/app.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
