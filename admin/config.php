<?php
require __DIR__ . '/../public/res/scr/Parsedown.php';

$assetBase = '';
if (empty($_SERVER['DOCUMENT_ROOT']) || !is_dir($_SERVER['DOCUMENT_ROOT'] . '/res')) {
    $assetBase = '/public';
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

$rulesMdPath = __DIR__ . '/../public/res/data/rules.md';
$rulesMarkdown = is_readable($rulesMdPath) ? file_get_contents($rulesMdPath) : '';
$Parsedown = new Parsedown();
$rules = $Parsedown->text($rulesMarkdown);

$aboutMdPath = __DIR__ . '/../public/res/data/about.md';
$aboutMarkdown = is_readable($aboutMdPath) ? file_get_contents($aboutMdPath) : '';
$about = $Parsedown->text($aboutMarkdown);

$faqMdPath = __DIR__ . '/../public/res/data/faq.md';
$faqMarkdown = is_readable($faqMdPath) ? file_get_contents($faqMdPath) : '';
$faq = $Parsedown->text($faqMarkdown);

$linksJsonPath = __DIR__ . '/../public/res/data/links.json';
$linksData = [];
if (is_readable($linksJsonPath)) {
    $decoded = json_decode(file_get_contents($linksJsonPath), true);
    if (is_array($decoded)) {
        $linksData = $decoded;
    }
}

$headerJsonPath = __DIR__ . '/../public/res/data/header.json';
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
    <title>Lawnding Page</title>
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
            <li><a class="navLink" href="#" data-pane="links">LINKS</a></li>
            <li><a class="navLink" href="#" data-pane="users">USERS</a></li>
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
