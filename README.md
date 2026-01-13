# Lawnding Page
A single-page, responsive, flatfile no-cms landing site platform for various types of online communities.

The project is intended to be a bit more robust than a simple carrd site, but easy for people to work with and modify/expand on.  It's feasible to even create a simple site based off of this.

It's created with HTML wrapped in PHP, as well as CSS and JS.

Note: Inline JavaScript and inline `<style>` blocks are avoided for CSP compatibility. Runtime data is embedded in HTML data attributes and read by external JS files in `public/res/scr/`.

## Getting Started
~~After cloning the repo, drop everything in your site's root.  As long as PHP is installed, running index.php should do the trick!~~
### File Structure
To set up LawndingPage, understand the structure of the files.

```
- admin/
    - modules/
        - _template/
        - basicText/
        - eventList/
        - README.md
    - auth.php
    - config.php
    - .htaccess
    - users.json (after first run)
- public/
    - admin/
        - index.php
    - res/
        - data/
            - eventList.json
            - favicon-cache.json
            - header.json
            - links.json
            - panes.json
            - welcome.md
        - img/
            - logo.jpg
            - bg.jpg
        - scr/
            - admin-data.js
            - app.js
            - backgrounds-delete.php
            - backgrounds-helpers.php
            - backgrounds-list.php
            - backgrounds-upload.php
            - cache_headers.php
            - config.js
            - event-ical.php
            - favicon.php
            - jquery-#.#.#.min.js
            - markdown-preview.php
            - module-preview.php
            - module-style.php
            - no-zoom.js
            - Parsedown.php
            - public-data.js
            - save-config.php
            - site-version.js
        - admin.css
        - config.css
        - style.css
        - version.php
    - index.php
- CHANGELOG.md
- LICENSE.md
- lp-bootstrap.php
- lp-overrides.php (optional)
- README.md
- THIRD-PARTY-LICENSES.md
```

- `admin/` contains the important files for user authentication, and contains the modules that the page loads for each pane, defined by you.
    - NOTE: `users.json` doesn't immediately exist when you set up a LawndingPage instance.  It will be created when you create the master account upon first running the site.  If for some reason you aren't prompted to create a master account, go into the root `admin/` folder and delete `users.json`, and then load the page again.
    - NOTE: Deleting `users.json` does not harm the rest of your site; all users who can access and modify the site will need to be re-added with appropriate permissions re-defined for them.
- `public/` is the main site.  If you don't have a pre-configured web server, this should be your website root.  It contains all of the data for the page, images, and scripts to make the site work.
- `lp-bootstrap.php` provides runtime path defaults.  `lp-overrides.php` is optional and can override defaults (i.e. if your website root is in `public_html/` instead of just `public/`).
- `CHANGELOG.md`, `LICENSE.md`, `THIRD-PARTY-LICENSES.md`, and `README.md` are supplemental files - the changelog to keep up to date on changes (viewable in-app), the license for this software, the third-party libraries used to make it work, as well as this readme file you're looking at right now!

### Quick Start
For this walkthrough, we'll assume your website is `http://www.awesomelandingpage.com/` because clearly your landing page is going to be the most awesome page ever.

For purposes of this walkthrough, it's assumed you've got a web host situated, and you own a domain name.  It's also assumed you've downloaded/cloned this repo.

If you don't have an existing web site, then drop everything into your server's root directory, make sure `public/` is set as the website's root.  The `admin/` folder that is OUTSIDE of `public/` should stay outside of the public folder, in your server's root.  This should ensure it cannot be accessed from the internet and only internal scripts cna touch it.  Now if you go to the website, it'll display the default page.  Note, for some shared hosting, they already have a public folder.  I use Hostinger, and their website root is named `public_html/`, so you can copy everything in this project's `public/` folder into that one.  The website should display with a logo placeholder, a title, subtitle, along with one or two panes, depending on if you're on mobile or desktop.  Navigation bar should be on the bottom.  If that's good, we can move on.

At the end of the url, enter `/admin` at the end of the website.  A login page should appear.  As this is the first time you're using it, you will be prompted to create a master admin account.  This allows full access to the site, including any future functions pertaining to the very back-end of the site.  Then, you'll be prompted to log-in to the admin panel.

The admin panel will appear similar to the site, with a few extra buttons in the header.  Ideally you'll want a normal full-admin account.  To create one - and any future accounts, go to the user management page (leftmost button), and under "Create user", enter a username and temporary password.  Click the "Create User" button, and the new user should appear underneath the master account with the temporary password displayed so you can copy/paste it to give to the new user,  Click the "Permissions" button next to the new user and set it to "Full admin".  Log out of the master account, sign in as the new user, create a new password, and you're good to go!

From there, you can add links, edit the title and subtitle of the page, the logo, add other users, remove them, add and remove background images, and modify the text contents of each page.  Once you're done editing the pages, click the "Save All Changes" button.  Once you see the confirmation message at the top of the screen, go back to your web page and the changes should be instant.

TODO: Add a proper write-up for the admin health check warnings and what they mean.
TODO: Add guidance on checking `admin/errors.txt` for troubleshooting.

## Overview
LawndingPage uses a straightforward system to display content, known as "panes".

The header pane is a standard full-width display, that shows the logo of the group, the title, and a subtitle.

Depending on the display mode (desktop or mobile), users will see 1 or 2 panes for the content.  A navbar is displayed at the bottom, with any footer text displayed at the bottom.

The first pane is for Links.  For those familiar with services like carrd or linktree, this is the prominent feature of those sites.  It's the first visible pane in mobile mode, and it's featured to the left of the site in desktop mode (or on larger screens, like tablets)!

Each subsequent pane is user-defined.  In our example site, we have a Welcome pane and an Events pane.  All user-defined panes come from modules.
- As of version 1.6.2 there are two default modules that come with the software - a `Basic Text` module, which is a markdown-friendly freeform text editor (supports inline HTML, but no inline CSS, JS, and PHP) and an `Event List` module, which allows you to set a list of events and allow visitors to easily add events like these to their calendar of choice (Google Calendar, Apple Calendar, Outlook, etc).  Users can add as few or as many panes as they want.

Aside from the header, links list, and navigation bar, all other panes are loaded dynamically from the site's configuration.  When the user accesses the web site, it loads all panes into `index.php` when delivering it to the user.  Styling is handled in a few locations.  For the main site, `res/style.css` handles it.  It contains basic styling for consistent appearance on the site.  Individual modules contain their own `style.css` files that allow module creators to define how their module appears on the site.

To keep things visually consistent, `config.php` is derived off the main page, and allows users to configure every part of what currently exists.  They can set background images, the logo, the title, subtitle, add and remove links/separators, add and remove panes, and customize each pane's contents.

## Page Breakdown
Panes are dynamically loaded into `index.php`, based on what the user defines.  In this example, there are two user-defined panes - a Basic Text pane called "Welcome" and an Event List pane called "Event List".

These panes pull data from `res/data/`, defined per pane in `panes.json` vua a data map and depending on the type of content they deliver, may include `json` files or `md` files, or any number of other file types, as required by a module.

This is not unlike a WordPress page, where users can add/remove various plugins to give their site unique pages or features, except we call them "modules".  'Cause we're fancy, like that.

### `index.php`
Before we even get to the HTML part of this page, there's a ton of environment variables that we set, ensuring everything runs smoothly:

```php
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
```

Firstly, we initialize some environment variables, such as the file paths, which ensures no matter how the site is saved on the user's server, it will behave as-expected.  For example, if you have an existing website and are adding an instance of LawndingPage as a *part* of the website, this ensures that the `lp-overrides.php` file is read and the correct directories are referenced.  We also require any other libraries, such as `Parsdown`, so the site works.  We also ensure certain headers are sent to maintain security.

The PHP parses the JSON files as well - for the links and the header information, after all of that, it populates the data as we get lower down in the page.

#### Header & Background
The header has 3 parts, plus the background.  The data for this is stored in `res/data/header.json`:
```json
{
    "logo": "res/img/logo.jpg",
    "title": "LawndingPage Title",
    "subtitle": "Snazzy community subtitle goes here",
    "backgrounds": [
        {
            "url": "res/img/bg.jpg",
            "author": "Doug S",
            "authorUrl": "google.com"
        }
    ],
    "backgroundSettings": {
        "mode": "random_load",
        "duration": 10
    }
}
```
1. `"logo"`
    - The logo is a 128px square image.  Any image will be resized to fit within a 128x128 px square.  Any image type is allowed.  It gets applied to the style, so use a file path.  No need to use `url("path/to/file")`, just the path in quotes will suffice.
2. `"title"`
    - The title is placed within a `<h1>` tag and ideally contains the name of your community or name of your site.  Just use a string, here.
3. `"subtitle"`
    - The subtitle is placed within `<h2>` tags and ideally you'll include some sort of snazzy, catchy phrase under it.  Same as with `"title"`, just use a string in quotes.
4. `"backgrounds"`
    - The background(s) are applied to `<body>` and are designed to cover the div, ensuring the image will always fill the page.  If you have prominent subject matters in the image, we suggest keeping it centralized; because background images are designed to `cover` the page, the image may be slightly cut off at the sides or the top/bottom of the page, depending on whether the page is viewed on desktop or mobile, in portrait or landscape mode.
    - The author name is a string that is displayed in the footer, after the platform version information.  It displays as `Background image by <author>`.  If a website (string) is specified for `authorUrl`, their name will become a clickable link to their website.
5. `"backgroundSettings"`
    - The background settings object defines the display mode of the background, and if applicable the duration.  LawndingPage supports four display modes:
        - Random on-load: Displays one randomly selected background image when the page is loaded.  Changes when refreshed.
        - Sequential on-load: Displays a background image, selected based on the order it was defined in the admin panel.  Cycles through them one by one when loaded.
        - Random slideshow: Displays a random background image for a set duration.  The order is randomized on load, ensuring each image is displayed at least once.
        - Sequential slideshow: Displays a background image for a set duration starting from the first uploaded image and cycles one by one until it reaches the last one, and then loops around.

#### Container and Panes
The `div` named `#container` contains all of the panes for the site.  The links list is hardcoded here to pull the links and their information from `links.json`.  All other panes are pulled in dynamically.

Here's the structure of `#container`:

```php
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
```

1. `#links`
    - The `#links` pane is a special pane that provides a list of links, if it wasn't obvious enough.  It pulls it's data from `res/data/links.json`, and the code above cycles through that file and populates the `<div>` with links and separators.
    ```json
    [
        {
            "type": "link",
            "id": "butts",
            "href": "https://www.example.com",
            "text": "This is an example link.",
            "title": "This is a tool tip.  Do people even use this anymore?",
            "fullWidth": true,
            "cta": true
        },
        {
            "type": "separator"
        },
        ...
    ]
    ```
    - The JSON object contains objects with everything you need, attributes and all, to generate an `<a>` element on the page.
        - The `type` tells the script whether to parse it as a `link` or a `separator`.  If it's a `separator`, it just displays a line.  If it's a `link`...
        - The `id` is a unique identifier that is derived from the name of the link and pupulates the `id` attribute of any `<a>` element generated (normal HTML shenanigans).
        - Then we populate the `href` attribute, which is the URL.
        - The `text` is the label that the user will see in the link list.  In the above example, the button will say "This is an example link." instead of displaying the URL.
        - The `title` is a tool tip - the title attribute in HTML is generally what you see if you hover your mouse over a button.  It's not often seen in mobile sites, so do not put anything important on here, especially if your userbase are mostly zoomers who have their smartphones surgically attached to their hand and never touch grass.  Helpful information is good but be aware of your user base.
        - `fullWidth` and `cta` are boolean values. The former determines if the link takes up the full width of the list, or only half.  If it's half, links will appear side by side, 2 for each row.  The latter stands for "Call To Action" and will have the link appear distinct.  You can apply this to any link you want, but it's recommended to use this sparingly, i.e. if you have a main group, it's better to use this flag for that link only.
        - `separator` type objects are boring `<hr>` elements that take up width and aid in visual organization.  Nothing special happend with them, so we don't have additional data for them.  They're very lonely.  Use them so they'll be happy.
2. Any other Panes
    - As you can see above, php handles inserting additional panes into the site so they display here (and later in the navbar).  That's all.  There used to be a part here where I rambled about pineapple on pizza and arch users, but it's out of place and isn't as funny now, so you get this, instead.  That said, pineapple absolutely blongs on pizza - if you like it!  ....What was I talking about, again?
    - Content for each pane is derived from whatever data files they require.  The types of files are defined in the module, but when being displayed on the main page, they are set to the relevant data file.  For instance, the Welcome pane pulls from `welcome.md`.  The Event List pane pulls from `eventList.json`.  Markdown and JSON files are the most common types of files you'll want to work with, and the modules for these two types of panes are where you define "hey, you need to pull data from a markdown file" or something like that.  It's relatively painless.  Or should I say, *pane*-less?  Hahaha.  Ahah.
    - The bulk of the text-based content for LawndingPage utilizes Markdown.  Markdown is very powerful, and lets you easily create nice-looking documents without needing to know how to program.
        - If you use Discord, formatting is generally handled through markdown, so this might be familiar to you.
        - If you're anything like me, you're lucky to remember that to get '*this*' you need to type it like '`*this*`'.  To make editing the site more user-friendly, the most common types of markdown can be inserted into the page by using the toolbar that's included with any text field that supports Markdown.  There's more than what's offered, but the majority of users are just going to use what's here.  A preview button is included so you don't have to save changes and view the live site every time you make an edit.
        - For reference, you can check out an awesome site like [commonmark](https://commonmark.org/help/)
        - If the preview mode wasn't immediate enough for you, another awesome option is [StackEdit.io](https://stackedit.io/app#) as it provides a more user-friendly editor similar to what's provided here, with the added bonus of seeing the final result in real-time.  Be warned; formatting is still defined by the website, so the appearance in StackEdit may differ from how markdown text is displayed in GitHub, LawndingPage instances, or any other site that renders markdown content to the screen.  Ideally, use the built-in editor for LawndingPage for the most consistent preview.
        - At one point I had some snarky text about you thinking I'm terrible for making you use markdown without an editor.  However, I've since added a GUI for editing your text in a far more user-friendly way.  Don't worry.  I still happily develop with JavaScript.  I'm still a terrible person, just ever-so-slightly-less terrible.  Or maybe not.  That's your perogative.  But if you like what I've made and use it, then I guess I'm not *that* terrible.  Or you pity me.  Either way, you're using this software so it's a win for me, I guess?

#### Navigation Bar & Footer
Also known as the navbar, or `<nav>` because I'm trying to be semantic, this is where the user navigates to the various panes.  If you look at index.php in a browser and think to yourself "navigation bar that's translucent at the bottom of the page, with rounded edges?  Is this guy an Apple Sheep or something that's obsessed with liquid glass?" you'd be *partially* correct.  I like some of Apple's UI design philosophy, and since I'm developing this to be pleasant to use on mobile, I'm trying this design.  I also always liked unusual navigation bar placements, so having it at the bottom was a thing I've wanted to do.  It looks good, so for now, I'm keeping it.

Also, if you look closer, the content looks more like something out of Windows 7.  Am I one of those Fruitger Aero enthusiasts?  ....Yes, yes I am.  One day I'll ensure the site has sufficient capabilities for styling the page.  For now, enjoy a blend of modern and "retro" aesthetics.  I feel like I aged 100 years for calling the "Fruitger Aero" aesthetic retro.  *Barf.*

And if you think I'm even dumber for placing the footer inside of `<nav>` instead of outside....  I ask that you refer to my above mention of me willingly developing in JavaScript.  What?  You don't know what I mean?  It's almost like I'm making an excuse to paste another code block below!  What?  I'd never do such-
```html
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
```
The navbar dynamically updates based on the panes defined for the site in the admin panel.  Everything about the navbar is defined in `panes.json`, which detail the name of the pane, it's icon, and it's position.  It ensures each link in the navbar matches up to the relevant pane on the site.
- Once upon a time, you had to manually add navbar links, define attributes for them, and do a few other things otherwise it would break.  Because I'm so awesome and totally *not* a terrible peson, you don't have to do this anymore.
- In the footer, you can see where the author name is added.  Not sure what I mean by author name?  Did you not read earlier?  Is TikTok and YouTube Shorts destroying your short term memory or something?  JESUS CHRIST SWEET SUMMER CHILD YOU'RE A DEVELOPER USE YOUR BRAI-  *ahem*  ...The `.authorName` span is the attribution to whatever background image is displaying.  As mentioned above, if no name is specified, it's populated with "anonymous".  How mysterious.

#### Beyond The Footer
After that is just some php script stuff (not shown above) that makes the page do fun things.  By fun things, I mean it makes it does some trickery to ensure we force the website to re-cache itself whenever an instance of LawndingPage is updated. Why do this?  Ever go to a website but some or all of it appears to be unchanged, but sometimes when reloading different parts suddenly change?  Or, maybe the site appears broken, because something was updated, and is attempting to work with files that are out of date, despite it expecting updateed files?  I have.  I don't like that.  While testing, I had this happen a few times.  This prevents it from happening.  Don't like it?  You must like broken websites.  I don't.  Of course, this whole thing is open source, so if you don't like what I did, you can change it!  ...Or if you can do it better, I would absolutely love to hear more about it and would love you in a platonic, non-romantic or sexual way.
- Jokes aside, I consider this a key part of the site, ensuring it's always going to be up to date, any time you push updates to the site.  Ensure when updating the site - whether from the official channels or on your own, to update the version of the site in `version.php`, ensuring it re-caches all critical files.



### Admin Panel
The core design of `config.php` is directly based on `index.php`.  Literally, I duplicated the file and started editing things.  The config page is responsive, as a result, and navigation is virtually identical, except for the addition of a few other links in the navbar that lets you manage the site.

- Just about everything you would modify for the site is handled in the same locations you would see them on the public page.  That is, the logo, title, and subtitle is in the header, the links list is in the links list area, and all of the panes you add to the site are in the same places they will be on the public-facing page.

#### Header
- In the admin panel, in addition to the logo, title, subtitle, there are a few key differences here as well.
    - Firstly, the logged-in user appears in the top-right corner of the screen.  Underneath that is the "Log Out" button.  It's pretty self-explanatory.
    - Next is a help button.  Clicking this pulls up a basic tutorial that walks you through the admin panel, and how to user it.
    - Lastly is a "Save all changes" button which has a floppy disk icon.  Anything that doesn't auto-saved (uploading images, re-configuring pane order, etc) will be saved once you click this button.

#### Navigation Bar
- The navigation bar serves the same general purpose, but has a few additional elements to it that isn't on the public-facing site.  There are some additional buttons and separators on it.
    - There's "User Management" (icon with a group of people), "Background Image Management" (icon with a pencil over an image), then a separator followed by the website's specified panes, a "Pane Management" button (yes.... that's what it's called), and another separator followed by a changelog button (an icon of a document with angle brackets (`<>`) in it).

#### User Management
- The User Management pane provides a way to manage all users for your instance of LawndingPage.
- You can create a new user (with a temporary password specified).
    - Usernames must be 3-32 characters.
    - Passwords, temporary or not must be 8-128 characters.
- Under "Existing Users", you can manage permissions for each user (except for the master account), reset the password, or remove a user (except for the master account).
    - Users who have not yet created their account will have their temporary password displayed until it's changed.
    - Account Types & Permissions...
        - `Master Accounts` have full access over the site.  Ensure the password is kept safe!
            - Master Accounts cannot be modified once created, with the exception of resetting their password.  NOTE: Only "Full admin" users can reset a Master Account password.
            - Master Accounts can do everything on the site.  It's recommended to create at least one "Full Admin" or other Admin user before starting to use the site normally.
            - Only a Master Account may set another user as "Full Admin".
            - Master Accounts will eventually allow full site management (billing, etc).
        - `Full Admin` accounts have all permissions, and are able to reset the Master Account password, giving at least one option if the password is forgotten.
            - They are given all permissions by design, and should be used as the only main account when updating the LawndingPage instance.
        - All other `User Accounts` have permissions defined for them.  Currently, they can modify all accounts; they will be updated to only be able to affect non-"Full Admin" accounts.
            - `Add users` allow them to create new accounts.
            - `Edit users` allow them to update permissions and reset passwords (for all except the Master Account).
            - `Remove users` allow them to remove a user account
            - `Edit site` allows them to modify all parts of the site - the header, links list, add/remove panees, modify their content, update te background images, etc...

#### Background Image Managemenet
- Background images can be added or removed, re-ordereed, have attributions specified (author and URL), as well as modify the display mode.
- The main list shows a thumbnail of the image, with an option to change it, uploading a new file.
    - The Author and URL fields are self-explanatory.
    - The up/down arrows allow you to re-order the image, which only applies for sequential displaying of background images.
    - The remove button (red button with a trash can) will remove the background from the list and delete it off the server.  This saves immediately upon confirming the action.
    - Aside from the remove button, changes are saved when you click the "Save all changees" button in the header.
- Under the main list lets you set display options or add a new image.
    - Display modes include...
        - Random on load
        - Sequential on load
        - Random slideshow
        - Sequential slideshow
    - The duration input applies to either of the slideshow modes and is ignored for on-load modes.
    - To save these settings, click the "Save all changes" button in the header.
    - The "Add background image" (icon with an image with a + over it) will let you select a picture, and upload/save immediately to the server.

#### Links List
- The Links List contains everything you need to modify a link.  Just like for the public-facing page, the Links List appears at the left in desktop mode, and as the first link in the list of panes in the navbar
    - You can configure the name of the link (saves to `"text"` in the json), it's URL (saves to `"url"`), and the tootlip - the text that appears when hovering over a link (saves as `"title"`).
    - You can specify a link to be "Full width", which means it takes up the entire width of the link list.  If it's disabled, it only takes up half of the width.
    - You can specify if a link is to be styled as a CTA button.  CTA = "Call To Action", and is a button that looks distinctly different from the others to draw the viewer's attention to it.  Any number of buttons can be styled as a "CTA" button, but it's recommended to limit this to one or two, so it doesn't lose it's impact.
    - You can also re-order the links by clicking the up or down buttons.
    - You can remove a link by pressing the red delete button (has a trash can icon).
- Separators only have the up/down/remove buttons.
- At the bottom of the Links List, there's an "Add link" and "Add separator" button.
    - Newly added links or separators appear at the bottom of the list.
- To save changes, click the "Save all changes" button in the header (the floppy disk icon).

#### Pane Management
- Yes, it's called "Pane Management".  Yes, it was accidental... at first.  But I'm keeping it.
- Clicking on the "Pane Management" buttons opens a screen that let's you change the icon for a pane, it's name, the type, as well as it's order.  You can also remove it.  At the bottom of the window, there's the option to add, save or cancel.
    - To change the icon, click the button next to the name.  On the new window that pops up, you can either specify `<svg>` or upload an image file.
        - You can either remove the icon (no icon will display), save, or cancel the operation.
    - To change the name of a pane, click in the text box to enter or change the value.
    - To re-order the panes, click the up or down arrow.  Up to down translates to left to right in the navbar.  The Links List will always be first.
    - To remove a pane, click the red remove button (has the trash can icon).  Doing this will immediately save and reload the change.  Any other unsaved changes (pane name, icon change, editied content) will be discarded.
    - To change the pane type, click the button that displays it's type, which is under the name of the pane.
    - Saving changes in the Pane Management window will immediately update the site, and reload the page.  This will discard unsaved edits if you modified the contents of a pane before saving and entering this window.

#### Modules and Panes...
- Modules are the core of panes.  They're essentially a template that is used when generating individual panes.
- In the Pane Management window, clicking "Add Pane" or changing the pane type displays another popup that shows a list of available modules, listed as "pane types".
- By default, there are two (as of v1.6.2) - Basic Text and Event List.
    - Basic Text panes allow you to enter text, utilizing both inline HTML and Markdown.
    - Event Lists let you specify events and details, listing past events if desired, and saving events to your calendar, exporting and saving an `.ics` file.
- Once the icon, name, and type is defined, and the order is taken care of, and you click Save, click on the pane in the navigation bar to switch to it.

##### Basic Text pane
Basic text panes are one large text input with a formatting toolbar at the top.  Since markdown can be a 'pain' to remember, the most common types of formatting when writing content is included here: bold, italic, heading selector, unordered lists, ordered lists, quote, code fragment, hyperlink, image insert, line break, horizontal rule.  A preview button is also provided so you don't have to save and switch to the live page to check if your edits look correct.

Markdown is fairly strict with certain things.  When Markdown doesn't cut it, inline HTML is allowed.  For example, here's a snippet from the default welcome page on a new instance of LawndingPage:

```
To access the admin panel, click [here](admin/).
<br><br>

**LawndingPage** is a simple community landing page platform that let's you easily create a central hub for your community!
<br><br>

```

Notice the `<br>` tags?  Markdown recognizes new lines, however only once, before it looks for more text to display.  Therefore, whether you press enter 1 or 100 times, there will only be a single new line before more text is drawn:
```
These two rows

don't have a blank line in between them...
```
shows as

> These two rows
> don't have a blank line in between them...


Also, manually adding `<br>` replaces the normal line break that is inserted.  Thus, if you intentionally want to double-space your text, you must add two `<br>` tags together.  Doing so will let this happen:

```
These two rows
<br><br>
have a blank line in between them!
```
shows as

> These two rows
>
> have a blank line in between them!
