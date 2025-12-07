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
    'subtitle' => 'A Long Island furry community encompassing Queens, Nassau County, and Suffolk County.  And Staten Island, but we do not talk about that.'
];
if (is_readable($headerJsonPath)) {
    $decoded = json_decode(file_get_contents($headerJsonPath), true);
    if (is_array($decoded)) {
        $headerData = array_merge($headerData, $decoded);
    }
}
$backgrounds = [];
if (!empty($headerData['backgrounds']) && is_array($headerData['backgrounds'])) {
    $backgrounds = $headerData['backgrounds'];
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
    <link rel="stylesheet" href="res/config.css">

    <script src="res/scr/jquery-3.7.1.min.js"></script>
</head>
<body>
    <div id="noJsWarning"><noscript>This site requires JavaScript to function properly. Please enable JavaScript in your browser.</noscript></div>
    <header class="header" id="header">
        <div class="logo" id="logo">
            <button class="logoChange" type="button">Change</button>
            <input type="file" id="logoFileInput" accept="image/*" class="fileInputHidden">
        </div>
        <div class="headline">
            <input class="headlineInput" type="text" name="siteTitle" value="<?php echo htmlspecialchars($headerData['title'] ?? ''); ?>" aria-label="Site Title">
            <input class="headlineInput" type="text" name="siteSubtitle" value="<?php echo htmlspecialchars($headerData['subtitle'] ?? ''); ?>" aria-label="Site Subtitle">
        </div>
        <div class="headerActions">
            <button class="helpTutorial" type="button">Help</button>
            <button class="saveChanges" type="button">Save All Changes</button>
        </div>
    </header>
    <div class="container" id="container">
        <div class="pane glassConvex alwaysShow" id="links">
            <h3>LINKS</h3>
            <div class="linksConfig" id="linksConfig">
                <div class="linksConfigList">
                    <?php foreach ($linksData as $link): ?>
                        <?php if (($link['type'] ?? '') === 'separator'): ?>
                            <div class="linksConfigCard linksConfigSeparator">
                                <div class="linksConfigRow">
                                    <span class="linksConfigLabel">Separator</span>
                                    <span class="linksConfigSpacer"></span>
                                    <button class="moveUpLink" type="button" title="Move this entry up in the list.">↑</button>
                                    <button class="moveDownLink" type="button" title="Move this entry down in the list.">↓</button>
                                    <button class="deleteLink" type="button" title="Removes this entry from the list.">Delete</button>
                                </div>
                            </div>
                        <?php elseif (($link['type'] ?? '') === 'link'): ?>
                        <?php
                            $href = $link['href'] ?? '';
                            $text = $link['text'] ?? '';
                            $title = $link['title'] ?? '';
                            $id = $link['id'] ?? '';
                            $isFullWidth = !empty($link['fullWidth']);
                            $isCta = !empty($link['cta']);
                        ?>
                        <div class="linksConfigCard">
                            <div class="linksConfigRow">
                                <label class="linksConfigField" title="The internal HTML ID of the link.  Make it unique.">ID
                                    <input class="linksConfigInput" type="text" name="linkId[]" value="<?php echo htmlspecialchars($id); ?>" placeholder="Link ID" title="The internal HTML ID of the link.  Make it unique.">
                                </label>
                                <label class="linksConfigField" title="The full URL (https: and all) to link to.">URL
                                        <input class="linksConfigInput" type="text" name="linkUrl[]" value="<?php echo htmlspecialchars($href); ?>" placeholder="Link URL" title="The full URL (https: and all) to link to.">
                                    </label>
                                </div>
                                <div class="linksConfigRow">
                                    <label class="linksConfigField" title="The label that is displayed for each link.">Text
                                        <input class="linksConfigInput" type="text" name="linkText[]" value="<?php echo htmlspecialchars($text); ?>" placeholder="Display text" title="The label that is displayed for each link.">
                                    </label>
                                    <label class="linksConfigField" title="The text that appears when the user hovers over a link.">Title
                                        <input class="linksConfigInput" type="text" name="linkTitle[]" value="<?php echo htmlspecialchars($title); ?>" placeholder="Title attribute" title="The text that appears when the user hovers over a link.">
                                    </label>
                                </div>
                            <div class="linksConfigRow linksConfigToggles">
                                <label class="linksConfigCheckbox" title="If checked, the link takes up the full width of the links pane.  Otherwise, it'll take up half of the width.">
                                    <input type="checkbox" name="linkFullWidth[]" <?php echo $isFullWidth ? 'checked' : ''; ?> title="If checked, the link takes up the full width of the links pane.  Otherwise, it'll take up half of the width.">
                                    Full width
                                </label>
                                <label class="linksConfigCheckbox" title="AKA Call to Action.  If checked, the link appears more prominently than the others.  Ideally, you will only want to use one, but you can set multiple links as a CTA button.">
                                    <input type="checkbox" name="linkCta[]" <?php echo $isCta ? 'checked' : ''; ?> title="AKA Call to Action.  If checked, the link appears more prominently than the others.  Ideally, you will only want to use one, but you can set multiple links as a CTA button.">
                                    CTA
                                </label>
                                <span class="linksConfigSpacer"></span>
                                <button class="moveUpLink" type="button" title="Move this entry up in the list.">↑</button>
                                <button class="moveDownLink" type="button" title="Move this entry down in the list.">↓</button>
                                <button class="deleteLink" type="button" title="Removes this entry from the list.">Delete</button>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
                <div class="linksConfigActions">
                    <button class="addLink" type="button">Add link</button>
                    <button class="addSeparator" type="button">Add separator</button>
                </div>
            </div>
        </div>
        <div class="pane glassConvex" id="bg">
            <h3>BACKGROUND IMAGES</h3>
            <div class="bgConfig" id="bgConfig">
                <div class="bgConfigRow bgConfigHeader">
                    <span>Preview</span>
                    <span>Author</span>
                    <span>Actions</span>
                </div>
                <?php foreach ($backgrounds as $bg): ?>
                    <?php
                        $bgUrl = '';
                        $bgAuthor = '';
                        if (is_string($bg)) {
                            $bgUrl = $bg;
                        } elseif (is_array($bg)) {
                            $bgUrl = $bg['url'] ?? '';
                            $bgAuthor = $bg['author'] ?? '';
                        }
                        $isEmptyBg = empty($bgUrl);
                    ?>
                    <div class="bgConfigRow" data-current-url="<?php echo htmlspecialchars($bgUrl); ?>">
                        <div class="bgThumbWrap <?php echo $isEmptyBg ? 'empty' : ''; ?>">
                            <img class="bgThumb" src="<?php echo htmlspecialchars($bgUrl); ?>" alt="Background preview">
                            <button class="bgChange" type="button">Change</button>
                        </div>
                        <input class="bgAuthorInput" type="text" name="bgAuthor[]" value="<?php echo htmlspecialchars($bgAuthor); ?>" placeholder="Author">
                        <button class="deleteBackground" type="button">Delete</button>
                    </div>
                <?php endforeach; ?>
                <div class="bgConfigActions">
                    <input type="file" id="bgFileInput" accept="image/*" class="fileInputHidden">
                    <button class="addBackground" type="button">Add background image</button>
                </div>
            </div>
        </div>
        <div class="pane glassConvex" id="about">
            <h3>ABOUT</h3>
            <textarea class="paneEditor" name="aboutMarkdown" aria-label="About markdown"><?php echo htmlspecialchars($aboutMarkdown); ?></textarea>
        </div>
        <div class="pane glassConvex" id="rules">
            <h3>RULES</h3>
            <textarea class="paneEditor" name="rulesMarkdown" aria-label="Rules markdown"><?php echo htmlspecialchars($rulesMarkdown); ?></textarea>
        </div>
        <div class="pane glassConvex" id="faq">
            <h3>FAQ</h3>
            <textarea class="paneEditor" name="faqMarkdown" aria-label="FAQ markdown"><?php echo htmlspecialchars($faqMarkdown); ?></textarea>
        </div>
        <div class="pane glassConvex" id="events">Public events go here</div>
        <div class="pane glassConvex" id="donate">donate pane here maybe</div>
    </div>
    <nav>
        <ul class="navBar glassConcave" id="navBar">
            <li><a class="navLink" href="#" data-pane="links">LINKS</a></li>
            <li><a class="navLink" href="#" data-pane="bg">BG</a></li>
            <li><a class="navLink" href="#" data-pane="about">ABOUT</a></li>
            <li><a class="navLink" href="#" data-pane="rules">RULES</a></li>
            <li><a class="navLink" href="#" data-pane="faq">FAQ</a></li>
            <li><a class="navLink" href="#" data-pane="events">EVENTS</a></li>
            <li><a class="navLink" href="#" data-pane="donate">DONATE</a></li>
        </ul>
        <div class="footer">
            Powered by LawndingPage
        </div>
    </nav>
    <div id="tutorialOverlay" class="hidden">
        <div id="mask-top" class="tutorialMask"></div>
        <div id="mask-left" class="tutorialMask"></div>
        <div id="mask-right" class="tutorialMask"></div>
        <div id="mask-bottom" class="tutorialMask"></div>
        <div id="tutorialPopover" class="tutorialPopover glassConcave">
            <div class="tutorialText"></div>
            <div class="tutorialControls">
                <button type="button" class="tutorialPrev">Previous</button>
                <button type="button" class="tutorialClose">Close Tutorial</button>
                <button type="button" class="tutorialNext">Next</button>
            </div>
        </div>
    </div>
    <script>
        // Expose header data to JS for assets like the logo background.
        window.headerData = <?php echo json_encode($headerData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script src="res/scr/app.js"></script>
    <script src="res/scr/config.js"></script>
</body>
</html>
