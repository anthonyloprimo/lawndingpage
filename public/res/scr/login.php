<?php
// JSON login endpoint for the public-site sign-in dialog.
//
// Mirrors the bcrypt login flow in public/admin/index.php but returns
// JSON instead of redirecting, so a fetch() submission from the modal can
// stay on the public page and surface errors inline.
//
// Contract:
//   POST application/x-www-form-urlencoded
//     csrf_token = $_SESSION['csrf_token']  (required)
//     username   = string
//     password   = string
//     remember   = '1' (optional — issues a 30-day remember-me cookie)
//   200 → {ok: true}
//   400 → {error: '...'} for malformed requests
//   401 → {error: 'Username or password incorrect.'}
//   403 → {error: 'Security token invalid. Refresh and try again.'}
//   405 → {error: 'Method not allowed.'}
require_once __DIR__ . '/../../../lp-bootstrap.php';
require_once __DIR__ . '/../../../admin/auth.php';
require_once __DIR__ . '/../../../admin/lib/remember-me.php';
require_once __DIR__ . '/../../../admin/lib/rate-limit.php';

lawnding_init_session();

function lp_login_respond(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    lp_login_respond(['error' => 'Method not allowed.'], 405);
}

$csrfPosted = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
$csrfSession = is_string($_SESSION['csrf_token'] ?? null) ? $_SESSION['csrf_token'] : '';
if ($csrfSession === '' || !hash_equals($csrfSession, $csrfPosted)) {
    lp_login_respond(['error' => 'Security token invalid. Refresh and try again.'], 403);
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$remember = isset($_POST['remember']) && (string) $_POST['remember'] === '1';

if ($username === '' || $password === '') {
    lp_login_respond(['error' => 'Username and password are required.'], 400);
}

$clientIp = lawnding_client_ip();
$rateLimitPath = lawnding_rate_limit_path();
$lock = lawnding_rate_limit_check($clientIp, $username, $rateLimitPath);
if ($lock !== null) {
    $minutes = max(1, (int) ceil($lock['seconds_remaining'] / 60));
    $suffix = $minutes === 1 ? '' : 's';
    lp_login_respond([
        'error' => "Too many failed attempts. Try again in {$minutes} minute{$suffix}.",
    ], 429);
}

$usersPath = (string) lawnding_config('users_path', '');
$users = lawnding_load_users_file($usersPath);

$matched = null;
if (is_array($users)) {
    foreach ($users as $candidate) {
        if (is_array($candidate) && (($candidate['username'] ?? '') === $username)) {
            $matched = $candidate;
            break;
        }
    }
}

if ($matched === null || !password_verify($password, $matched['password_hash'] ?? '')) {
    lawnding_log_event('info', 'login_failed', [
        'attempted_username' => $username,
        'user_exists' => $matched !== null,
        'source' => 'public_modal',
    ]);
    lawnding_rate_limit_record_failure($clientIp, $username, $rateLimitPath);
    lp_login_respond(['error' => 'Username or password incorrect.'], 401);
}

session_regenerate_id(true);
$_SESSION['auth_user'] = $matched['username'];
// Coming through the public-side modal: a later logout should return the
// user to the public site, not drop them on the admin login form.
$_SESSION['logout_return_public'] = true;
lawnding_rate_limit_record_success($clientIp, $matched['username'], $rateLimitPath);

if ($remember) {
    lawnding_remember_token_issue($matched['username'], lawnding_remember_tokens_path());
}

lp_login_respond(['ok' => true], 200);
