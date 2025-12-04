<?php
require __DIR__ . '/res/scr/Parsedown.php';

$rulesMdPath = __DIR__ . '/res/data/rules.md';
$rulesMarkdown = is_readable($rulesMdPath) ? file_get_contents($rulesMdPath) : '';
$Parsedown = new Parsedown();
$rules = $Parsedown->text($rulesMarkdown);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lawnding Page</title>
    <link rel="icon" type="image/jpg" href="res/img/logo.jpg"/>
    <link rel="stylesheet" href="res/style.css">
</head>
<body>
    <header class="header" id="header">
        <div class="logo" id="logo"></div>
        <div class="headline">
            <h1>Long Island Furs</h1>
            <h2>A Long Island furry community encompassing Queens, Nassau County, and Suffolk County.  And Staten Island, but we don't talk about that.</h2>
        </div>
    </header>
    <div class="container" id="container">
        <div class="pane glassConvex alwaysShow" id="links">
            <h3>LINKS</h3>
            <ul class="linkList" id="linkList">
                <a class="fullWidth" href="https://t.me/+WQneCwkE_0L9j14u" title="Welcome Lobby for the LI Furs Telegram Main Chats"><li class="cta link linkTelegram" id="linkWelcomeLobby">New to LI Furs?  Start Here! (Telegram Welcome Lobby)</li></a>
                <a class="fullWidth" href="https://t.me/+gkC_JjJCd4AxYTk5" title="Telegram group chat for the yearly OMGWTFBBQ, a weekend-long get-together with cool vibes and awesome food!"><li class="link linkTelegram" id="linkBbq">OMGWTFBBQ Group</li></a>
                <hr>
                <a href="https://t.me/+7xLxW6RIz-gwYzEx" title="Telegram group chat for the gamers in the LI Furs!  Video games, tabletop games, and more!"><li class="link linkTelegram" id="linkGaming">LI Furs Gaming Telegram Group</li></a>
                <a href="https://t.me/+7xLxW6RIz-gwYzEx" title="Also the tg gaming group chat."><li class="link linkTelegram" id="linkGaming">LI Furs Gaming Telegram Group</li></a>
            </ul>
        </div>
        <div class="pane glassConvex" id="rules">
            <?php echo $rules ?>
        </div>
        <!-- <div class="pane glassConvex" id="faq">FAQs go here</div>
        <div class="pane glassConvex" id="about">About info goes here</div>
        <div class="pane glassConvex" id="events">Public events go here</div>
        <div class="pane glassConvex" id="donate">donate pane here maybe</div> -->
    </div>
    <nav>
        <ul class="navBar glassConcave" id="navBar">
            <a href="#"><li>HOME</li></a>
            <a href="#"><li>RULES</li></a>
            <a href="#"><li>FAQ</li></a>
            <a href="#"><li>ABOUT</li></a>
            <a href="#"><li>EVENTS</li></a>
            <a href="#"><li>DONATE</li></a>
        </ul>
    </nav>
</body>
</html>
