<?php
// Clear Telegram site session state and return to public homepage.
require_once __DIR__ . '/../../../../lp-bootstrap.php';

lawnding_init_session();
unset($_SESSION['tg_user'], $_SESSION['tg_user_id'], $_SESSION['tg_login_error']);
unset($_SESSION['tg_membership_force_refresh']);

// If this Telegram session had been promoted to an admin identity (the
// tg:<id> sentinel in $_SESSION['auth_user']), tear that down too so the
// next request doesn't see a half-cleared state. Also clear the one-time
// regeneration flag and the CSRF token (forces a fresh CSRF on the next
// admin visit, which is what bcrypt logout effectively does too).
if (isset($_SESSION['auth_user']) && is_string($_SESSION['auth_user'])
    && str_starts_with($_SESSION['auth_user'], 'tg:')) {
    unset($_SESSION['auth_user'], $_SESSION['tg_admin_regenerated'], $_SESSION['csrf_token']);
}

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
if ($target === '') {
    $target = '/';
}
header('Location: ' . $target, true, 302);
exit;
