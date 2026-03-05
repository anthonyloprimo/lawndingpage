<?php
// Clear Telegram site session state and return to public homepage.
require_once __DIR__ . '/../../../lp-bootstrap.php';

lawnding_init_session();
unset($_SESSION['tg_user'], $_SESSION['tg_user_id'], $_SESSION['tg_login_error']);
unset($_SESSION['tg_membership_force_refresh']);

$returnTo = '/';
if (isset($_GET['return']) && is_string($_GET['return'])) {
    $candidate = $_GET['return'];
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $candidate) && !str_starts_with($candidate, '//')) {
        $returnTo = str_starts_with($candidate, '/') ? $candidate : '/' . ltrim($candidate, '/');
    }
}

$target = function_exists('lawnding_asset_url')
    ? lawnding_asset_url(ltrim($returnTo, '/'))
    : $returnTo;
if (!is_string($target) || $target === '') {
    $target = '/';
}
header('Location: ' . $target, true, 302);
exit;
