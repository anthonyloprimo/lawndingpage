<?php
require_once __DIR__ . '/../lp-bootstrap.php';
$parsedownPath = function_exists('lawnding_public_path')
    ? lawnding_public_path('res/scr/Parsedown.php')
    : __DIR__ . '/../public/res/scr/Parsedown.php';
require $parsedownPath;

$assetBase = '';
if (function_exists('lawnding_config')) {
    $assetBase = (string) lawnding_config('base_url', '');
}
if ($assetBase === '') {
    if (empty($_SERVER['DOCUMENT_ROOT']) || !is_dir($_SERVER['DOCUMENT_ROOT'] . '/res')) {
        $assetBase = '/public';
    }
}
$assetBase = rtrim($assetBase, '/');

$makeAssetUrl = function ($path) use ($assetBase) {
    if (!is_string($path) || $path === '') {
        return $path;
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    if (str_starts_with($path, $assetBase . '/')) {
        return $path;
    }
    if (str_starts_with($path, '/res/')) {
        return $assetBase . $path;
    }
    if (str_starts_with($path, 'res/')) {
        return $assetBase !== '' ? $assetBase . '/' . $path : '/' . $path;
    }
    return $path;
};

$rulesMdPath = function_exists('lawnding_data_path')
    ? lawnding_data_path('rules.md')
    : __DIR__ . '/../public/res/data/rules.md';
$rulesMarkdown = is_readable($rulesMdPath) ? file_get_contents($rulesMdPath) : '';
$Parsedown = new Parsedown();
$rules = $Parsedown->text($rulesMarkdown);

$aboutMdPath = function_exists('lawnding_data_path')
    ? lawnding_data_path('about.md')
    : __DIR__ . '/../public/res/data/about.md';
$aboutMarkdown = is_readable($aboutMdPath) ? file_get_contents($aboutMdPath) : '';
$about = $Parsedown->text($aboutMarkdown);

$faqMdPath = function_exists('lawnding_data_path')
    ? lawnding_data_path('faq.md')
    : __DIR__ . '/../public/res/data/faq.md';
$faqMarkdown = is_readable($faqMdPath) ? file_get_contents($faqMdPath) : '';
$faq = $Parsedown->text($faqMarkdown);

$linksJsonPath = function_exists('lawnding_data_path')
    ? lawnding_data_path('links.json')
    : __DIR__ . '/../public/res/data/links.json';
$linksData = [];
if (is_readable($linksJsonPath)) {
    $decoded = json_decode(file_get_contents($linksJsonPath), true);
    if (is_array($decoded)) {
        $linksData = $decoded;
    }
}

$headerJsonPath = function_exists('lawnding_data_path')
    ? lawnding_data_path('header.json')
    : __DIR__ . '/../public/res/data/header.json';
$headerDefaults = [
    'logo' => 'res/img/logo.jpg',
    'title' => 'Long Island Furs',
    'subtitle' => 'A Long Island furry community encompassing Queens, Nassau County, and Suffolk County.  And Staten Island, but we do not talk about that.',
    'backgrounds' => ['res/img/bg.jpg']
];
$headerData = $headerDefaults;
if (is_readable($headerJsonPath)) {
    $decoded = json_decode(file_get_contents($headerJsonPath), true);
    if (is_array($decoded)) {
        $headerData = array_merge($headerData, $decoded);
    }
}
$headerDataDisplay = $headerData;
$headerDataDisplay['logo'] = $makeAssetUrl($headerDataDisplay['logo'] ?? '');
if (!empty($headerDataDisplay['backgrounds']) && is_array($headerDataDisplay['backgrounds'])) {
    $headerDataDisplay['backgrounds'] = array_map(function ($bg) use ($makeAssetUrl) {
        if (is_string($bg)) {
            return $makeAssetUrl($bg);
        }
        if (is_array($bg)) {
            $bg['url'] = $makeAssetUrl($bg['url'] ?? '');
            return $bg;
        }
        return $bg;
    }, $headerDataDisplay['backgrounds']);
}
$backgrounds = [];
if (!empty($headerData['backgrounds']) && is_array($headerData['backgrounds'])) {
    $backgrounds = $headerData['backgrounds'];
}

$currentUserName = $_SESSION['auth_user'] ?? '';
$canAddUsers = $canAddUsers ?? true;
$canEditUsers = $canEditUsers ?? true;
$canRemoveUsers = $canRemoveUsers ?? true;
$canEditSite = $canEditSite ?? true;
$isFullAdmin = $isFullAdmin ?? false;
$isMasterUser = $isMasterUser ?? false;
$adminNotices = [];
if (!empty($usersErrors)) {
    $adminNotices[] = ['type' => 'danger', 'text' => implode(' ', $usersErrors)];
}
if (!empty($usersSuccess)) {
    $adminNotices[] = ['type' => 'ok', 'text' => $usersSuccess];
}
if (!empty($usersWarnings)) {
    $adminNotices[] = ['type' => 'danger', 'text' => implode(' ', $usersWarnings)];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <link rel="icon" type="image/jpg" href="<?php echo htmlspecialchars($assetBase); ?>/res/img/logo.jpg">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase); ?>/res/style.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase); ?>/res/config.css">

    <script src="<?php echo htmlspecialchars($assetBase); ?>/res/scr/jquery-3.7.1.min.js"></script>
</head>
<body>
    <div id="noJsWarning"><noscript>This site requires JavaScript to function properly. Please enable JavaScript in your browser.</noscript></div>
    <div class="adminNotices" id="adminNotices">
        <?php foreach ($adminNotices as $notice): ?>
            <div class="adminNotice adminNotice--<?php echo htmlspecialchars($notice['type']); ?>">
                <span class="adminNoticeText"><?php echo htmlspecialchars($notice['text']); ?></span>
                <button type="button" class="adminNoticeClose" aria-label="Dismiss notification">×</button>
            </div>
        <?php endforeach; ?>
        <?php if (($usersPermissionsFixResult ?? '') === 'ok'): ?>
            <div class="adminNotice adminNotice--ok">
                <span class="adminNoticeText">Updated `users.json` permissions to 0640.</span>
                <button type="button" class="adminNoticeClose" aria-label="Dismiss notification">×</button>
            </div>
        <?php elseif (($usersPermissionsFixResult ?? '') === 'fail'): ?>
            <div class="adminNotice adminNotice--danger">
                <span class="adminNoticeText">Unable to update `users.json` permissions. Please set 0640 manually.</span>
                <button type="button" class="adminNoticeClose" aria-label="Dismiss notification">×</button>
            </div>
        <?php endif; ?>
        <?php if (!empty($usersPermissionsNeedsFix)): ?>
            <div class="adminNotice adminNotice--danger">
                <span class="adminNoticeText">WARNING: `users.json` permissions appear too open. Recommended 0640.</span>
                <?php if (!empty($isFullAdmin)): ?>
                    <form method="post" action="">
                        <input type="hidden" name="action" value="fix_users_permissions">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                        <button type="submit">Fix</button>
                    </form>
                <?php endif; ?>
                <button type="button" class="adminNoticeClose" aria-label="Dismiss notification">×</button>
            </div>
        <?php endif; ?>
    </div>
    <header class="header" id="header">
        <div class="logo" id="logo">
            <button class="logoChange" type="button">Change</button>
            <input type="file" id="logoFileInput" accept="image/*" class="fileInputHidden">
        </div>
        <div class="headline">
            <input class="headlineInput" type="text" name="siteTitle" value="<?php echo htmlspecialchars($headerData['title'] ?? ''); ?>" aria-label="Site Title">
            <input class="headlineInput" type="text" name="siteSubtitle" value="<?php echo htmlspecialchars($headerData['subtitle'] ?? ''); ?>" aria-label="Site Subtitle">
        </div>
        <div class="headerActionStack">
            <div class="signedInAs"><?php echo htmlspecialchars($_SESSION['auth_user'] ?? ''); ?></div>
            <form method="post" action="">
                <input type="hidden" name="action" value="logout">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <button class="logoutButton" type="submit">Log Out</button>
            </form>
            <div class="headerActions">
                <button class="helpTutorial" type="button">Help</button>
                <button class="saveChanges" type="button">Save All Changes</button>
            </div>
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
        <div class="pane glassConvex" id="users">
            <h3>USERS</h3>
            <p class="usersNotice">Changes made here take effect immediately.</p>
            <div class="usersGrid">
                <div class="usersColumn">
                    <h4>Create User</h4>
                    <form class="usersBlock<?php echo $canAddUsers ? '' : ' isDisabled'; ?> usersCreateForm" method="post" action="">
                        <input type="hidden" name="action" value="create_user">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                        <label class="usersField">
                            Username
                            <input class="usersInput" type="text" name="new_username" placeholder="New username" required <?php echo $canAddUsers ? '' : 'disabled'; ?>>
                        </label>
                        <label class="usersField">
                            Temporary Password
                            <input class="usersInput" type="text" name="temp_password" placeholder="Temp password" required <?php echo $canAddUsers ? '' : 'disabled'; ?>>
                        </label>
                        <button class="usersButton" type="submit" <?php echo $canAddUsers ? '' : 'disabled'; ?>>Create User</button>
                        <?php if (!$canAddUsers): ?>
                            <p class="usersHint">You do not have permission to add users.</p>
                        <?php else: ?>
                            <p class="usersHint">New users must change their password on first login.</p>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="usersColumn">
                    <h4>Existing Users</h4>
                    <div class="usersBlock usersList">
                        <div class="usersRow usersHeader">
                            <span>User</span>
                            <span>Actions</span>
                        </div>
                        <?php if (!empty($users) && is_array($users)): ?>
                            <?php foreach ($users as $user): ?>
                                <?php
                                    $username = $user['username'] ?? '';
                                    $isMaster = !empty($user['master']);
                                    $isSelf = $username !== '' && $username === $currentUserName;
                                    $permissionsDisabled = $isMaster || (!$canEditUsers && !$isSelf);
                                    $resetDisabled = (!$canEditUsers && !$isSelf) || ($isMaster && !($isMasterUser || $isFullAdmin));
                                    $removeDisabled = $isMaster || (!$canRemoveUsers && !$isSelf);
                                    $tempPassword = $user['temp_password'] ?? '';
                                    $label = $username . ($isMaster ? ' (Master Account)' : '');
                                    if (!$isMaster && !empty($tempPassword)) {
                                        $label .= ' (Temporary password: ' . $tempPassword . ')';
                                    }
                                    $permissions = $user['permissions'] ?? [];
                                    if (!is_array($permissions)) {
                                        $permissions = [];
                                    }
                                    if (!empty($allowedPermissions) && is_array($allowedPermissions)) {
                                        $permissions = array_values(array_intersect($permissions, $allowedPermissions));
                                    }
                                    $permissionsAttr = htmlspecialchars(implode(',', $permissions));
                                ?>
                                <div class="usersRow" data-username="<?php echo htmlspecialchars($username); ?>" data-permissions="<?php echo $permissionsAttr; ?>">
                                    <span><?php echo htmlspecialchars($label); ?></span>
                                    <div class="usersActions">
                                        <button class="usersButton usersPermissionsButton" type="button" <?php echo $permissionsDisabled ? 'disabled' : ''; ?>>Permissions</button>
                                        <form method="post" action="" class="usersResetForm" data-username="<?php echo htmlspecialchars($username); ?>">
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                            <input type="hidden" name="target_username" value="<?php echo htmlspecialchars($username); ?>">
                                            <button class="usersButton" type="submit" <?php echo $resetDisabled ? 'disabled' : ''; ?>>Reset Password</button>
                                        </form>
                                        <button class="usersButton usersDanger usersRemoveButton" type="button" <?php echo $removeDisabled ? 'disabled' : ''; ?>>Remove</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="usersRow">
                                <span>No users found.</span>
                            </div>
                        <?php endif; ?>
                        <p class="usersHint">Reset issues a temporary password and forces a change on next login. Master accounts cannot be removed.</p>
                    </div>
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
                        $bgDisplayUrl = $makeAssetUrl($bgUrl);
                        $isEmptyBg = empty($bgUrl);
                    ?>
                    <div class="bgConfigRow" data-current-url="<?php echo htmlspecialchars($bgUrl); ?>">
                        <div class="bgThumbWrap <?php echo $isEmptyBg ? 'empty' : ''; ?>">
                            <img class="bgThumb" src="<?php echo htmlspecialchars($bgDisplayUrl); ?>" alt="Background preview">
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
            <li><a class="navLink" href="#" data-pane="links" aria-label="Links" title="Links"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M19,3H5A2,2 0 0,0 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5A2,2 0 0,0 19,3M13.94,14.81L11.73,17C11.08,17.67 10.22,18 9.36,18C8.5,18 7.64,17.67 7,17C5.67,15.71 5.67,13.58 7,12.26L8.35,10.9L8.34,11.5C8.33,12 8.41,12.5 8.57,12.94L8.62,13.09L8.22,13.5C7.91,13.8 7.74,14.21 7.74,14.64C7.74,15.07 7.91,15.47 8.22,15.78C8.83,16.4 9.89,16.4 10.5,15.78L12.7,13.59C13,13.28 13.18,12.87 13.18,12.44C13.18,12 13,11.61 12.7,11.3C12.53,11.14 12.44,10.92 12.44,10.68C12.44,10.45 12.53,10.23 12.7,10.06C13.03,9.73 13.61,9.74 13.94,10.06C14.57,10.7 14.92,11.54 14.92,12.44C14.92,13.34 14.57,14.18 13.94,14.81M17,11.74L15.66,13.1V12.5C15.67,12 15.59,11.5 15.43,11.06L15.38,10.92L15.78,10.5C16.09,10.2 16.26,9.79 16.26,9.36C16.26,8.93 16.09,8.53 15.78,8.22C15.17,7.6 14.1,7.61 13.5,8.22L11.3,10.42C11,10.72 10.82,11.13 10.82,11.56C10.82,12 11,12.39 11.3,12.7C11.47,12.86 11.56,13.08 11.56,13.32C11.56,13.56 11.47,13.78 11.3,13.94C11.13,14.11 10.91,14.19 10.68,14.19C10.46,14.19 10.23,14.11 10.06,13.94C8.75,12.63 8.75,10.5 10.06,9.19L12.27,7C13.58,5.67 15.71,5.68 17,7C17.65,7.62 18,8.46 18,9.36C18,10.26 17.65,11.1 17,11.74Z" /></svg></a></li>
            <li><a class="navLink" href="#" data-pane="users" aria-label="Users" title="User Management"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12,5.5A3.5,3.5 0 0,1 15.5,9A3.5,3.5 0 0,1 12,12.5A3.5,3.5 0 0,1 8.5,9A3.5,3.5 0 0,1 12,5.5M5,8C5.56,8 6.08,8.15 6.53,8.42C6.38,9.85 6.8,11.27 7.66,12.38C7.16,13.34 6.16,14 5,14A3,3 0 0,1 2,11A3,3 0 0,1 5,8M19,8A3,3 0 0,1 22,11A3,3 0 0,1 19,14C17.84,14 16.84,13.34 16.34,12.38C17.2,11.27 17.62,9.85 17.47,8.42C17.92,8.15 18.44,8 19,8M5.5,18.25C5.5,16.18 8.41,14.5 12,14.5C15.59,14.5 18.5,16.18 18.5,18.25V20H5.5V18.25M0,20V18.5C0,17.11 1.89,15.94 4.45,15.6C3.86,16.28 3.5,17.22 3.5,18.25V20H0M24,20H20.5V18.25C20.5,17.22 20.14,16.28 19.55,15.6C22.11,15.94 24,17.11 24,18.5V20Z" /></svg></a></li>
            <li><a class="navLink" href="#" data-pane="bg" aria-label="Backgrounds" title="Edit Random Background Images"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M22.7 14.3L21.7 15.3L19.7 13.3L20.7 12.3C20.8 12.2 20.9 12.1 21.1 12.1C21.2 12.1 21.4 12.2 21.5 12.3L22.8 13.6C22.9 13.8 22.9 14.1 22.7 14.3M13 19.9V22H15.1L21.2 15.9L19.2 13.9L13 19.9M21 5C21 3.9 20.1 3 19 3H5C3.9 3 3 3.9 3 5V19C3 20.1 3.9 21 5 21H11V19.1L12.1 18H5L8.5 13.5L11 16.5L14.5 12L16.1 14.1L21 9.1V5Z" /></svg></a></li>
            <li><a class="navLink" href="#" data-pane="about" aria-label="About" title="Edit About Page"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M13,9H11V7H13M13,17H11V11H13M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2Z" /></svg></a></li>
            <li><a class="navLink" href="#" data-pane="rules" aria-label="Rules" title="Edit Rules"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M7,13V11H21V13H7M7,19V17H21V19H7M7,7V5H21V7H7M3,8V5H2V4H4V8H3M2,17V16H5V20H2V19H4V18.5H3V17.5H4V17H2M4.25,10A0.75,0.75 0 0,1 5,10.75C5,10.95 4.92,11.14 4.79,11.27L3.12,13H5V14H2V13.08L4,11H2V10H4.25Z" /></svg></a></li>
            <li><a class="navLink" href="#" data-pane="faq" aria-label="FAQ" title="Edit FAQ"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M18,15H6L2,19V3A1,1 0 0,1 3,2H18A1,1 0 0,1 19,3V14A1,1 0 0,1 18,15M23,9V23L19,19H8A1,1 0 0,1 7,18V17H21V8H22A1,1 0 0,1 23,9M8.19,4C7.32,4 6.62,4.2 6.08,4.59C5.56,5 5.3,5.57 5.31,6.36L5.32,6.39H7.25C7.26,6.09 7.35,5.86 7.53,5.7C7.71,5.55 7.93,5.47 8.19,5.47C8.5,5.47 8.76,5.57 8.94,5.75C9.12,5.94 9.2,6.2 9.2,6.5C9.2,6.82 9.13,7.09 8.97,7.32C8.83,7.55 8.62,7.75 8.36,7.91C7.85,8.25 7.5,8.55 7.31,8.82C7.11,9.08 7,9.5 7,10H9C9,9.69 9.04,9.44 9.13,9.26C9.22,9.08 9.39,8.9 9.64,8.74C10.09,8.5 10.46,8.21 10.75,7.81C11.04,7.41 11.19,7 11.19,6.5C11.19,5.74 10.92,5.13 10.38,4.68C9.85,4.23 9.12,4 8.19,4M7,11V13H9V11H7M13,13H15V11H13V13M13,4V10H15V4H13Z" /></svg></a></li>
            <li><a class="navLink" href="#" data-pane="events" aria-label="Events" title="Events"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M19,19V8H5V19H19M16,1H18V3H19A2,2 0 0,1 21,5V19A2,2 0 0,1 19,21H5C3.89,21 3,20.1 3,19V5C3,3.89 3.89,3 5,3H6V1H8V3H16V1M7,10H9V12H7V10M15,10H17V12H15V10M11,14H13V16H11V14M15,14H17V16H15V14Z" /></svg></a></li>
            <li><a class="navLink" href="#" data-pane="donate" aria-label="Donate" title="Donate"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M7,15H9C9,16.08 10.37,17 12,17C13.63,17 15,16.08 15,15C15,13.9 13.96,13.5 11.76,12.97C9.64,12.44 7,11.78 7,9C7,7.21 8.47,5.69 10.5,5.18V3H13.5V5.18C15.53,5.69 17,7.21 17,9H15C15,7.92 13.63,7 12,7C10.37,7 9,7.92 9,9C9,10.1 10.04,10.5 12.24,11.03C14.36,11.56 17,12.22 17,15C17,16.79 15.53,18.31 13.5,18.82V21H10.5V18.82C8.47,18.31 7,16.79 7,15Z" /></svg></a></li>
        </ul>
        <div class="footer">
            Powered by LawndingPage
        </div>
    </nav>
    <div class="userModalOverlay<?php echo !empty($resetPassword) ? ' isOpen' : ''; ?>" id="resetPasswordModal" role="dialog" aria-modal="true" aria-hidden="<?php echo !empty($resetPassword) ? 'false' : 'true'; ?>">
        <div class="userModal glassConcave">
            <h4>Temporary Password</h4>
            <p class="usersHint">Copy this temporary password and send it to the person creating the account. They will be prompted to enter a new password the next time they log in.</p>
            <label class="usersField">
                Password for <?php echo htmlspecialchars($resetUsername ?? ''); ?>
                <input class="usersInput" type="text" readonly value="<?php echo htmlspecialchars($resetPassword ?? ''); ?>">
            </label>
            <div class="userModalActions">
                <?php if (!empty($resetLogoutAfterReset)): ?>
                    <form method="post" action="">
                        <input type="hidden" name="action" value="logout">
                        <button class="usersButton" type="submit">OK</button>
                    </form>
                <?php else: ?>
                    <button class="usersButton userModalClose" type="button">OK</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="userModalOverlay" id="permissionsModal" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="userModal glassConcave">
            <h4>User Permissions</h4>
            <form method="post" action="" id="permissionsForm">
                <input type="hidden" name="action" value="save_permissions">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="target_username" id="permissionsUsername" value="">
                <div class="permissionsList">
                    <?php
                        $permissionLabels = [
                            'full_admin' => 'Full admin (all permissions, can reset master account password)',
                            'add_users' => 'Add users',
                            'edit_users' => 'Edit users (includes password reset & permissions)',
                            'remove_users' => 'Remove users',
                            'edit_site' => 'Edit site',
                        ];
                    ?>
                    <?php foreach ($allowedPermissions ?? [] as $permission): ?>
                        <?php $isFullAdminOption = $permission === 'full_admin'; ?>
                        <label class="permissionsItem">
                            <input type="checkbox" name="permissions[]" value="<?php echo htmlspecialchars($permission); ?>" <?php echo (!$isMasterUser && $isFullAdminOption) ? 'disabled' : ''; ?>>
                            <?php echo htmlspecialchars($permissionLabels[$permission] ?? $permission); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="userModalActions">
                    <button class="usersButton" type="submit">Save</button>
                    <button class="usersButton userModalClose" type="button">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <div class="userModalOverlay" id="removeUserModal" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="userModal glassConcave">
            <h4>Remove Account</h4>
            <p class="usersHint" id="removeUserWarning">WARNING: Clicking Delete will permanently remove this account. This cannot be reversed!</p>
            <form method="post" action="" id="removeUserForm">
                <input type="hidden" name="action" value="remove_user">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="target_username" id="removeUsername" value="">
                <div class="userModalActions">
                    <button class="usersButton usersDanger" type="submit">Delete</button>
                    <button class="usersButton userModalClose" type="button">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <div class="userModalOverlay" id="permissionsSelfConfirmModal" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="userModal glassConcave">
            <h4>Remove Your Permissions</h4>
            <p class="usersHint">You are removing your own permissions. Another admin will need to re-enable them. Continue?</p>
            <div class="userModalActions">
                <button class="usersButton" type="button" id="permissionsSelfConfirmYes">Yes</button>
                <button class="usersButton userModalClose" type="button">Cancel</button>
            </div>
        </div>
    </div>
    <div class="userModalOverlay" id="resetConfirmModal" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="userModal glassConcave">
            <h4>Reset Password</h4>
            <p class="usersHint" id="resetConfirmMessage">Are you sure you want to reset this password?</p>
            <div class="userModalActions">
                <button class="usersButton" type="button" id="resetConfirmYes">Yes</button>
                <button class="usersButton userModalClose" type="button">No</button>
            </div>
        </div>
    </div>
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
        window.headerData = <?php echo json_encode($headerDataDisplay, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        window.appConfig = {
            basePath: <?php echo json_encode($assetBase); ?>,
            currentUser: <?php echo json_encode($currentUserName); ?>,
            canEditSite: <?php echo json_encode($canEditSite); ?>
        };
    </script>
    <script src="<?php echo htmlspecialchars($assetBase); ?>/res/scr/app.js"></script>
    <script src="<?php echo htmlspecialchars($assetBase); ?>/res/scr/config.js"></script>
</body>
</html>
