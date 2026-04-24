<?php
// Public changelog page — renders CHANGELOG.md from the project root.
$bootstrapPath = __DIR__ . '/../lp-bootstrap.php';
if (!is_readable($bootstrapPath)) {
    $bootstrapPath = __DIR__ . '/../../lp-bootstrap.php';
}
require_once $bootstrapPath;

$cacheHeadersPath = function_exists('lawnding_public_path')
    ? lawnding_public_path('res/scr/cache_headers.php')
    : __DIR__ . '/res/scr/cache_headers.php';
require_once $cacheHeadersPath;

$versionPath = function_exists('lawnding_public_path')
    ? lawnding_public_path('res/version.php')
    : __DIR__ . '/res/version.php';
require_once $versionPath;

ini_set('display_errors', '0');

header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');

$parsedownPath = function_exists('lawnding_public_path')
    ? lawnding_public_path('res/scr/Parsedown.php')
    : __DIR__ . '/res/scr/Parsedown.php';
require_once $parsedownPath;

$rootDir = function_exists('lawnding_config')
    ? lawnding_config('root_dir', dirname(__DIR__))
    : dirname(__DIR__);
$changelogPath = rtrim($rootDir, '/') . '/CHANGELOG.md';
$changelogMarkdown = is_readable($changelogPath) ? (string) file_get_contents($changelogPath) : '';

$parser = new Parsedown();
$changelog = $parser->text($changelogMarkdown);

$headerJsonPath = function_exists('lawnding_data_path')
    ? lawnding_data_path('header.json')
    : __DIR__ . '/res/data/header.json';
$siteTitle = 'LawndingPage';
if (is_readable($headerJsonPath)) {
    $decoded = json_decode((string) file_get_contents($headerJsonPath), true);
    if (is_array($decoded) && isset($decoded['title']) && $decoded['title'] !== '') {
        $siteTitle = (string) $decoded['title'];
    }
}
$siteTitleEsc = htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Changelog — <?php echo $siteTitleEsc; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(lawnding_asset_url('res/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="changelogPageBody">
    <div class="changelogPageWrap">
        <div class="changelogPageHeader">
            <a href="<?php echo htmlspecialchars(lawnding_asset_url(''), ENT_QUOTES, 'UTF-8'); ?>" class="changelogBackLink">← Back</a>
            <span class="changelogPageTitle">Changelog</span>
        </div>
        <div class="pane glassConvex changelogContent">
            <?php echo $changelog; ?>
        </div>
        <div class="footer">
            <?php echo lawnding_footer_platform_html(); ?>.
        </div>
    </div>
</body>
</html>
