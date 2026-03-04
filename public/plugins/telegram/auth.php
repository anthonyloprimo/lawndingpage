<?php
// Telegram login auth endpoint (redirect mode).
require_once __DIR__ . '/../../../lp-bootstrap.php';
$tgAuthPath = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('lib/tg-auth.php')
    : __DIR__ . '/../../../admin/lib/tg-auth.php';
require_once $tgAuthPath;

lawnding_init_session();

$tgConfig = lawnding_load_tg_config();
$returnTo = lawnding_tg_relative_return_path($_GET['return'] ?? '/');
$redirectTarget = function_exists('lawnding_asset_url')
    ? lawnding_asset_url(ltrim($returnTo, '/'))
    : $returnTo;
if (!is_string($redirectTarget) || $redirectTarget === '') {
    $redirectTarget = '/';
}

$payload = $_GET;
// Ignore local routing params that are not part of Telegram's signed payload.
unset($payload['return']);
$hashOk = lawnding_tg_verify_login_payload($payload, (string) ($tgConfig['bot_token'] ?? ''));
$authDate = isset($payload['auth_date']) ? (int) $payload['auth_date'] : 0;
$authFresh = $authDate > 0 && (time() - $authDate) <= 86400;

if (!$hashOk || !$authFresh) {
    unset($_SESSION['tg_user'], $_SESSION['tg_user_id']);
    $_SESSION['tg_login_error'] = 'Telegram login verification failed.';
    header('Location: ' . $redirectTarget, true, 302);
    exit;
}

$tgUser = [
    'id' => isset($payload['id']) ? (int) $payload['id'] : 0,
    'username' => (string) ($payload['username'] ?? ''),
    'first_name' => (string) ($payload['first_name'] ?? ''),
    'last_name' => (string) ($payload['last_name'] ?? ''),
    'photo_url' => (string) ($payload['photo_url'] ?? ''),
    'auth_date' => $authDate,
];

if (empty($tgUser['id'])) {
    unset($_SESSION['tg_user'], $_SESSION['tg_user_id']);
    $_SESSION['tg_login_error'] = 'Telegram user data missing.';
    header('Location: ' . $redirectTarget, true, 302);
    exit;
}

$_SESSION['tg_user'] = $tgUser;
$_SESSION['tg_user_id'] = $tgUser['id'];
$_SESSION['tg_membership_force_refresh'] = true;
unset($_SESSION['tg_login_error']);

header('Location: ' . $redirectTarget, true, 302);
exit;
