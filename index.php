<?php
require __DIR__ . '/res/scr/Parsedown.php';

$rulesMdPath = __DIR__ . '/res/data/rules.md';
$rulesMarkdown = is_readable($rulesMdPath) ? file_get_contents($rulesMdPath) : '';
$Parsedown = new Parsedown();
$rules = $Parsedown->text($rulesMarkdown);

$aboutMdPath = __DIR__ . '/res/data/about.md';
$aboutMarkdown = is_readable($aboutMdPath) ? file_get_contents($aboutMdPath) : '';
$about = $Parsedown->text($aboutMarkdown);

$faqMdPath = __DIR__ . '/res/data/faq.md';
$faqMarkdown = is_readable($faqMdPath) ? file_get_contents($faqMdPath) : '';
$faq = $Parsedown->text($faqMarkdown);

$linksJsonPath = __DIR__ . '/res/data/links.json';
$linksData = [];
if (is_readable($linksJsonPath)) {
    $decoded = json_decode(file_get_contents($linksJsonPath), true);
    if (is_array($decoded)) {
        $linksData = $decoded;
    }
}

$headerJsonPath = __DIR__ . '/res/data/header.json';
$headerData = [
    'logo' => 'res/img/logo.jpg',
    'title' => 'Long Island Furs',
    'subtitle' => 'A Long Island furry community encompassing Queens, Nassau County, and Suffolk County.  And Staten Island, but we do not talk about that.',
    'backgrounds' => ['res/img/bg.jpg']
];
if (is_readable($headerJsonPath)) {
    $decoded = json_decode(file_get_contents($headerJsonPath), true);
    if (is_array($decoded)) {
        $headerData = array_merge($headerData, $decoded);
    }
}
?> 

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lawnding Page</title>
    <link rel="icon" type="image/jpg" href="res/img/logo.jpg"/>
    <link rel="stylesheet" href="res/style.css">

    <script src="res/scr/jquery-3.7.1.min.js"></script>
</head>
<body>
    <div id="noJsWarning"><noscript>This site requires JavaScript to function properly. Please enable JavaScript in your browser.</noscript></div>
    <header class="header" id="header">
        <div class="logo" id="logo"></div>
        <div class="headline">
            <h1><?php echo htmlspecialchars($headerData['title'] ?? ''); ?></h1>
            <h2><?php echo htmlspecialchars($headerData['subtitle'] ?? ''); ?></h2>
        </div>
    </header>
    <div class="container" id="container">
        <div class="pane glassConvex alwaysShow" id="links">
            <h3>LINKS</h3>
            <ul class="linkList" id="linkList">
                <?php foreach ($linksData as $link): ?>
                    <?php if (($link['type'] ?? '') === 'separator'): ?>
                        <li class="separator" aria-hidden="true"><hr></li>
                    <?php elseif (($link['type'] ?? '') === 'link'): ?>
                        <?php
                            $href = $link['href'] ?? '#';
                            $title = $link['title'] ?? '';
                            $text = $link['text'] ?? '';
                            $id = $link['id'] ?? '';
                            $isFullWidth = !empty($link['fullWidth']);
                            $isCta = !empty($link['cta']);
                            $liClasses = trim(($isFullWidth ? 'fullWidth ' : '') . 'linkItem');
                            $aClasses = trim('link linkTelegram ' . ($isCta ? 'cta ' : ''));
                        ?>
                        <li class="<?php echo $liClasses; ?>" id="<?php echo htmlspecialchars($id); ?>">
                            <a class="<?php echo $aClasses; ?>" href="<?php echo htmlspecialchars($href); ?>" title="<?php echo htmlspecialchars($title); ?>">
                                <?php echo htmlspecialchars($text); ?>
                            </a>
                        </li>
                    <?php endif; ?>
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
        <div class="pane glassConvex" id="events">Public events go here</div>
        <div class="pane glassConvex" id="donate">donate pane here maybe</div>
    </div>
    <nav>
        <ul class="navBar glassConcave" id="navBar">
            <li><a class="navLink" href="#" data-pane="links">LINKS</a></li>
            <li><a class="navLink" href="#" data-pane="about">ABOUT</a></li>
            <li><a class="navLink" href="#" data-pane="rules">RULES</a></li>
            <li><a class="navLink" href="#" data-pane="faq">FAQ</a></li>
            <li><a class="navLink" href="#" data-pane="events">EVENTS</a></li>
            <li><a class="navLink" href="#" data-pane="donate">DONATE</a></li>
        </ul>
        <div class="footer">
            Powered by LawndingPage.  Background image by <span class="authorName"></span>.
        </div>
    </nav>
    <script>
        // Expose header data to JS for assets like the logo background.
        window.headerData = <?php echo json_encode($headerData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script src="res/scr/app.js"></script>
</body>
</html>
