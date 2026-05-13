<?php
// Admin entrypoint: routing, auth, and dispatch to the full admin UI or login flow.

// Locate the bootstrap file (supports alternate directory layouts).
$bootstrapPath = __DIR__ . '/../../lp-bootstrap.php';
if (!is_readable($bootstrapPath)) {
    $bootstrapPath = __DIR__ . '/../../../lp-bootstrap.php';
}
require_once $bootstrapPath;
if (function_exists('lawnding_initialize_instance_if_needed')) {
    lawnding_initialize_instance_if_needed();
}
// Suppress error display in the response. Errors are captured to
// admin/errors.jsonl via the bootstrap-registered handler and surfaced in
// the Diagnostics admin section, never echoed into HTML.
ini_set('display_errors', '0');
// Prevent stale HTML/PHP responses from being cached.
$cacheHeadersPath = function_exists('lawnding_public_path')
    ? lawnding_core_public_path('res/scr/cache_headers.php')
    : __DIR__ . '/../res/scr/cache_headers.php';
require_once $cacheHeadersPath;
// Load the authoritative site version for display and shared constants.
$versionPath = function_exists('lawnding_public_path')
    ? lawnding_core_public_path('res/version.php')
    : __DIR__ . '/../res/version.php';
require_once $versionPath;

// Seed missing data files from the split seed-instance defaults on first request after deploy.
lawnding_ensure_data_files();

// Load plugins so admin-side hook callbacks are registered before the
// head emits and fires head_assets.
lawnding_load_plugins();

// Content Security Policy for the admin entrypoint + clickjacking protection.
// img-src includes blob: so URL.createObjectURL output (used by
// smartcrop.js client-side analysis in the gallery upload flow) can
// be loaded as Image() src. blob: URLs are same-origin-only by design
// -- this doesn't open a remote-load vector, only lets the page
// display its own in-memory image data.
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');

// Build the canonical public admin URL so redirects never expose rewritten upstream paths.
function admin_public_url(string $path = '', bool $includeQuery = false): string {
    $base = function_exists('lawnding_asset_url')
        ? lawnding_asset_url('admin/')
        : '/admin/';
    $base = rtrim($base, '/') . '/';

    $path = ltrim($path, '/');
    $url = $path === '' ? $base : $base . $path;

    if ($includeQuery) {
        $query = $_SERVER['QUERY_STRING'] ?? '';
        if (is_string($query) && $query !== '') {
            $url .= '?' . $query;
        }
    }

    return $url;
}

// Normalize /admin to /admin/ for clean relative URL behavior.
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($requestPath !== null && $requestPath !== '') {
    $trimmedPath = rtrim($requestPath, '/');
    if ($trimmedPath !== '' && $trimmedPath === $requestPath && str_ends_with($trimmedPath, '/admin')) {
        header('Location: ' . admin_public_url('', true), true, 301);
        exit;
    }
}

// Admin root used by the health checks below.
$adminRoot = function_exists('lawnding_config')
    ? lawnding_config('admin_dir', dirname(__DIR__, 2) . '/admin')
    : dirname(__DIR__, 2) . '/admin';

lawnding_init_session(); // Initialize PHP session storage and load existing session data.

// Pick up the "arrived via public-site shortcut" marker. When the visitor
// lands here via the Admin-panel button on the public header, we stash a
// session flag so their eventual logout redirects them back to the public
// site root rather than the admin login form. The flag survives admin-page
// navigation until session destruction on logout.
if (($_GET['from'] ?? '') === 'public') {
    $_SESSION['logout_return_public'] = true;
}

// Load centralized auth/identity helpers (shared with mutation endpoints).
// admin/auth.php defines lawnding_resolve_admin_identity(), build_permission_context(),
// lawnding_load_users_file(), etc.
$adminAuthPath = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('auth.php')
    : dirname(__DIR__, 2) . '/admin/auth.php';
require_once $adminAuthPath;
$rememberLibPath = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('lib/remember-me.php')
    : dirname(__DIR__, 2) . '/admin/lib/remember-me.php';
require_once $rememberLibPath;
$rateLimitLibPath = function_exists('lawnding_admin_path')
    ? lawnding_admin_path('lib/rate-limit.php')
    : dirname(__DIR__, 2) . '/admin/lib/rate-limit.php';
require_once $rateLimitLibPath;
lawnding_consume_remember_cookie();

// Users file location (stored outside public web root).
$usersPath = function_exists('lawnding_config')
    ? (function_exists('lawnding_runtime_file_path')
        ? lawnding_runtime_file_path('users_path')
        : lawnding_config('users_path', dirname(__DIR__, 2) . '/admin/users.json'))
    : dirname(__DIR__, 2) . '/admin/users.json';
$users = [];
$usersFileIssue = null;
$allowedPermissions = ['full_admin', 'add_users', 'edit_users', 'remove_users', 'edit_site'];

// Load users.json if present and well-formed.
if (is_readable($usersPath)) {
    $decoded = json_decode(file_get_contents($usersPath), true);
    if (is_array($decoded)) {
        $users = $decoded;
    } else {
        // File exists but doesn't decode cleanly as an array.
        $usersFileIssue = 'invalid';
    }
} else {
    // File missing (first run or broken install).
    $usersFileIssue = 'missing';
}

$hasUsers = count($users) > 0;
if (!$hasUsers && $usersFileIssue === null) {
    $usersFileIssue = 'empty';
}

// Normalize action once for all POST handlers.
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? '') : '';

// General response state for admin/login flows.
$errors = [];
$success = '';
$usersErrors = [];
$usersSuccess = '';
$usersWarnings = [];
$usersPermissionsNeedsFix = false;
$usersPermissionsFixResult = null;
$passwordChangeSuccess = '';
$resetPassword = null;
$resetUsername = null;
$resetLogoutAfterReset = false;
$logoutAfterAction = false;
$blockUserActions = false;
$flash = null;
// One-time messages are stored in session and replayed after redirects.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_SESSION['flash_admin'])) {
    $flash = $_SESSION['flash_admin'];
    unset($_SESSION['flash_admin']);
}

if ($usersFileIssue === 'invalid') {
    $errors[] = 'WARNING: `users.json` is missing or damaged. If this is not a new setup, stop and verify the file.';
    // Prevent any mutation operations when user data is corrupted.
    $blockUserActions = true;
}

// Find a user record by username in the decoded users array.
function find_user($users, $username) {
    if (!is_array($users)) {
        return null;
    }
    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }
        if (($user['username'] ?? '') === $username) {
            return $user;
        }
    }
    return null;
}

// Keep only known permissions and normalize array order.
if (!function_exists('normalize_permissions')) {
    function normalize_permissions($permissions, $allowedPermissions) {
        if (!is_array($permissions)) {
            return [];
        }
        return array_values(array_intersect($permissions, $allowedPermissions));
    }
}

// Check if users.json permissions are too open or missing group read.
function users_permissions_needs_fix($usersPath) {
    if (!is_readable($usersPath)) {
        return false;
    }
    $perms = fileperms($usersPath);
    if ($perms === false) {
        return false;
    }
    $mode = $perms & 0777;
    return ($mode & 0037) !== 0 || (($mode & 0070) === 0);
}

// Append a warning with a pointer to the Diagnostics view for detail.
function add_health_warning(&$warnings, $message) {
    $warnings[] = $message . ' Check the Diagnostics view for more information.';
}

// Validate CSRF token and append to the provided errors array on failure.
function require_csrf_token(&$errors) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $errors[] = 'Security token invalid. Refresh and try again.';
        return false;
    }
    return true;
}

// Persist user data to disk with the standard JSON format.
function write_users_file($usersPath, $users, &$errors, $message) {
    $encoded = json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (function_exists('lawnding_ensure_parent_dir')) {
        lawnding_ensure_parent_dir($usersPath);
    }
    if ($encoded === false || file_put_contents($usersPath, $encoded, LOCK_EX) === false) {
        $errors[] = $message;
        return false;
    }
    return true;
}

// Build the admin base path used for redirects after logout.
function admin_redirect_path() {
    return admin_public_url();
}

// Attempt to lock down users.json permissions after writes.
function enforce_users_permissions($usersPath, &$warnings) {
    if (!file_exists($usersPath)) {
        return;
    }
    if (!@chmod($usersPath, 0640)) {
        $warnings[] = 'WARNING: Unable to set secure permissions on `users.json`. Please set 0640.';
    }
}

// Compute effective permissions for the current user.
// build_permission_context() now lives in admin/auth.php so mutation
// endpoints can share it. Kept here as a no-op marker; the require_once
// above defines the canonical version.

// First-run account creation flow.
if ($action === 'create_admin') {
    if ($usersFileIssue === 'invalid') {
        $errors[] = 'Cannot create admin while `users.json` is damaged. Fix or delete the file first.';
    } elseif ($hasUsers) {
        $errors[] = 'An admin account already exists.';
    } else {
        require_csrf_token($errors);

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($username === '' || strlen($username) < 3 || strlen($username) > 32) {
            $errors[] = 'Username must be 3-32 characters.';
        }
        if (str_starts_with(strtolower($username), 'tg:')) {
            // The 'tg:' prefix is reserved for synthetic Telegram-derived
            // sessions (admin/auth.php sentinel). Bcrypt usernames must not
            // collide with that namespace.
            $errors[] = 'Usernames cannot start with "tg:" (reserved).';
        }
        if (strlen($password) < 8 || strlen($password) > 128) {
            $errors[] = 'Password must be 8-128 characters.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        if (count($errors) === 0) {
            // Create the master admin record with full permissions.
            $record = [
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'master' => true,
                'must_change_password' => false,
                'created_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
                'permissions' => $allowedPermissions,
            ];
            if (write_users_file($usersPath, [$record], $errors, 'Unable to write users file.')) {
                // Reset CSRF token after new account creation.
                $success = 'Admin account created. Please log in.';
                $users = [$record];
                $hasUsers = true;
                $usersFileIssue = null;
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                enforce_users_permissions($usersPath, $usersWarnings);
            }
        }
    }
}

// Login flow: validate CSRF, verify password hash, then establish session.
if ($action === 'login') {
    if ($blockUserActions) {
        $errors[] = 'Login unavailable: `users.json` is damaged.';
    } else {
        if (require_csrf_token($errors)) {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            // Brute-force gate: refuse the attempt before reaching
            // password_verify if this IP or username is currently locked.
            $clientIp = lawnding_client_ip();
            $rateLimitPath = lawnding_rate_limit_path();
            $lock = lawnding_rate_limit_check($clientIp, $username, $rateLimitPath);
            if ($lock !== null) {
                $minutes = max(1, (int) ceil($lock['seconds_remaining'] / 60));
                $suffix = $minutes === 1 ? '' : 's';
                $errors[] = "Too many failed attempts. Try again in {$minutes} minute{$suffix}.";
            } else {
                $user = find_user($users, $username);
                if (!$user || !password_verify($password, $user['password_hash'] ?? '')) {
                    $errors[] = 'Username/password incorrect.';
                    lawnding_log_event('info', 'login_failed', [
                        'attempted_username' => $username,
                        'user_exists' => $user !== null,
                    ]);
                    lawnding_rate_limit_record_failure($clientIp, $username, $rateLimitPath);
                } else {
                    session_regenerate_id(true); // Prevent session fixation by rotating the ID on login.
                    $_SESSION['auth_user'] = $user['username']; // Store the authenticated user in the session.
                    lawnding_rate_limit_record_success($clientIp, $user['username'], $rateLimitPath);
                    if (isset($_POST['remember']) && (string) $_POST['remember'] === '1') {
                        lawnding_remember_token_issue($user['username'], lawnding_remember_tokens_path());
                    }
                }
            }
        }
    }
}

// Resolve the current authenticated user and permission flags.
// The resolver accepts either a bcrypt-logged-in user (from users.json),
// a Telegram-logged-in user with admin perms from their group memberships,
// or both (union of perms, bcrypt canonical). See admin/auth.php.
$tgConfig = lawnding_load_tg_config();
$identity = lawnding_resolve_admin_identity($tgConfig, $users, $allowedPermissions);
$authUser = $identity['authUser'];
$authRecord = $identity['authRecord'];
$displayName = $identity['displayName'];
$permissionContext = $identity['context'];
$currentPermissions = $permissionContext['currentPermissions'];
$isReadOnlyUser = $permissionContext['isReadOnlyUser'];
$isMasterUser = $permissionContext['isMasterUser'];
$isFullAdmin = $permissionContext['isFullAdmin'];
$canAddUsers = $permissionContext['canAddUsers'];
$canEditUsers = $permissionContext['canEditUsers'];
$canRemoveUsers = $permissionContext['canRemoveUsers'];
$canEditSite = $permissionContext['canEditSite'];
$forcePasswordChange = $authRecord && !empty($authRecord['must_change_password']);
if ($isReadOnlyUser) {
    $forcePasswordChange = false;
}

// Login surface needs the Telegram widget origins; admin UI proper stays strict.
$tgBotId = lawnding_tg_bot_id_from_token($tgConfig['bot_token']);
$tgAdminAuthEndpoint = function_exists('lawnding_asset_url')
    ? lawnding_asset_url('res/scr/plugin-endpoint.php?plugin=telegram&endpoint=auth')
    : '/res/scr/plugin-endpoint.php?plugin=telegram&endpoint=auth';
if (!$identity['isAuthenticated'] && $tgBotId !== '') {
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://telegram.org; style-src 'self'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-src https://telegram.org https://oauth.telegram.org; frame-ancestors 'none'");
}

// Promote a Telegram-derived session to a "logged-in admin" identity by
// persisting the tg:<id> sentinel into $_SESSION['auth_user']. Mirrors the
// session-fixation defense bcrypt login does at line ~273 below — fires once
// on the first such promotion (gated on $_SESSION['tg_admin_regenerated']).
$identityIsTgOnly = $identity['isAuthenticated']
    && in_array('telegram', $identity['sources'], true)
    && !in_array('bcrypt', $identity['sources'], true);
if ($identityIsTgOnly) {
    if (($_SESSION['auth_user'] ?? '') !== $authUser) {
        $_SESSION['auth_user'] = $authUser;
    }
    if (empty($_SESSION['tg_admin_regenerated'])) {
        session_regenerate_id(true);
        $_SESSION['tg_admin_regenerated'] = true;
    }
}

// Health checks for files and directories needed by the admin app.
$errorsLogPath = lawnding_logs_path();
if (!is_dir($adminRoot) || !is_writable($adminRoot)) {
    add_health_warning($usersWarnings, 'Health check: admin directory is not writable; runtime errors cannot be captured.');
} elseif (file_exists($errorsLogPath) && !is_writable($errorsLogPath)) {
    add_health_warning($usersWarnings, 'Health check: errors.jsonl is not writable.');
}

if (PHP_VERSION_ID < 80000) {
    add_health_warning($usersWarnings, 'Health check: PHP 8.0+ is required.');
}
if (!extension_loaded('json')) {
    add_health_warning($usersWarnings, 'Health check: PHP json extension is missing.');
}
if (!extension_loaded('fileinfo')) {
    add_health_warning($usersWarnings, 'Health check: PHP fileinfo extension is missing.');
}

// Resolve asset directories for write access checks.
$dataDir = function_exists('lawnding_config')
    ? lawnding_config('data_dir', dirname(__DIR__) . '/res/data')
    : dirname(__DIR__) . '/res/data';
$imgDir = function_exists('lawnding_config')
    ? lawnding_config('img_dir', dirname(__DIR__) . '/res/img')
    : dirname(__DIR__) . '/res/img';

if (is_dir($dataDir) && !is_writable($dataDir)) {
    add_health_warning($usersWarnings, 'Health check: res/data is not writable.');
}
if (is_dir($imgDir) && !is_writable($imgDir)) {
    add_health_warning($usersWarnings, 'Health check: res/img is not writable.');
}
if (file_exists($usersPath)) {
    if (!is_writable($usersPath)) {
        add_health_warning($usersWarnings, 'Health check: users.json is not writable.');
    }
} elseif (!is_writable(dirname($usersPath))) {
    add_health_warning($usersWarnings, 'Health check: admin directory is not writable for users.json.');
}

// Admin action: fix users.json permissions to 0640.
if ($action === 'fix_users_permissions') {
    if (!$authRecord) {
        $usersErrors[] = 'Login required to update file permissions.';
    } elseif (!$isFullAdmin) {
        $usersErrors[] = 'Permission update requires full admin access.';
    } else {
        if (!require_csrf_token($usersErrors)) {
        } elseif (!file_exists($usersPath)) {
            $usersErrors[] = 'Unable to find `users.json`.';
        } elseif (@chmod($usersPath, 0640)) {
            $usersPermissionsFixResult = 'ok';
        } else {
            $usersPermissionsFixResult = 'fail';
        }
    }
}

// Admin action: change own password (forced or voluntary).
if ($action === 'change_password') {
    if ($blockUserActions) {
        $errors[] = 'Password changes unavailable: `users.json` is damaged.';
    } elseif ($isReadOnlyUser) {
        $errors[] = 'Read-only accounts cannot change passwords.';
    } elseif (!$authRecord) {
        $errors[] = 'You must be logged in to change your password.';
    } else {
        if (require_csrf_token($errors)) {
            $newPassword = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if (strlen($newPassword) < 8 || strlen($newPassword) > 128) {
                $errors[] = 'Password must be 8-128 characters.';
            }
            if ($newPassword !== $confirm) {
                $errors[] = 'Passwords do not match.';
            }
            if (password_verify($newPassword, $authRecord['password_hash'] ?? '')) {
                $errors[] = 'New password must be different from the temporary password.';
            }

            if (count($errors) === 0) {
                foreach ($users as &$user) {
                    if (($user['username'] ?? '') === $authRecord['username']) {
                        $user['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
                        $user['must_change_password'] = false;
                        $user['temp_password'] = '';
                        $user['updated_at'] = gmdate('c');
                        break;
                    }
                }
                unset($user);
                if (write_users_file($usersPath, $users, $errors, 'Unable to update password.')) {
                    // Clear the forced-change flag after success.
                    $passwordChangeSuccess = 'Password updated. You can now use the admin panel.';
                    $authRecord['must_change_password'] = false;
                    $forcePasswordChange = false;
                    // Invalidate all "Stay signed in" cookies for this user
                    // across every device. Password change is the canonical
                    // "I think someone got in" signal — leaving long-lived
                    // cookies valid would let the attacker back in.
                    lawnding_remember_token_revoke_all_for_user(
                        $authRecord['username'],
                        lawnding_remember_tokens_path()
                    );
                }
            }
        }
    }
}

// Admin action: create a new user with a temporary password.
if ($action === 'create_user') {
    if ($blockUserActions) {
        $usersErrors[] = 'User actions unavailable: `users.json` is damaged.';
    } elseif ($isReadOnlyUser) {
        $usersErrors[] = 'Read-only accounts cannot create users.';
    } elseif (!$authRecord || $forcePasswordChange) {
        $usersErrors[] = 'You must be logged in to create users.';
    } elseif (!$canAddUsers) {
        $usersErrors[] = 'You do not have permission to add users.';
    } else {
        if (require_csrf_token($usersErrors)) {
            $newUsername = trim($_POST['new_username'] ?? '');
            $tempPassword = $_POST['temp_password'] ?? '';

            if ($newUsername === '' || strlen($newUsername) < 3 || strlen($newUsername) > 32) {
                $usersErrors[] = 'Username must be 3-32 characters.';
            }
            if (str_starts_with(strtolower($newUsername), 'tg:')) {
                $usersErrors[] = 'Usernames cannot start with "tg:" (reserved).';
            }
            if (strlen($tempPassword) < 8 || strlen($tempPassword) > 128) {
                $usersErrors[] = 'Temporary password must be 8-128 characters.';
            }
            if (find_user($users, $newUsername)) {
                $usersErrors[] = 'Username already exists.';
            }

            if (count($usersErrors) === 0) {
                $users[] = [
                    'username' => $newUsername,
                    'password_hash' => password_hash($tempPassword, PASSWORD_DEFAULT),
                    'master' => false,
                    'must_change_password' => true,
                    'temp_password' => $tempPassword,
                    'created_at' => gmdate('c'),
                    'updated_at' => gmdate('c'),
                    'permissions' => [],
                ];
                if (write_users_file($usersPath, $users, $usersErrors, 'Unable to write users file.')) {
                    $usersSuccess = 'User created. Share the temporary password securely.';
                    enforce_users_permissions($usersPath, $usersWarnings);
                }
            }
        }
    }
}

// Admin action: update user permissions.
if ($action === 'save_permissions') {
    if ($blockUserActions) {
        $usersErrors[] = 'User actions unavailable: `users.json` is damaged.';
    } elseif ($isReadOnlyUser) {
        $usersErrors[] = 'Read-only accounts cannot edit permissions.';
    } elseif (!$authRecord || $forcePasswordChange) {
        $usersErrors[] = 'You must be logged in to edit permissions.';
    } else {
        if (require_csrf_token($usersErrors)) {
            $targetUsername = trim($_POST['target_username'] ?? '');
            if (!$canEditUsers && $targetUsername !== $authUser) {
                $usersErrors[] = 'You do not have permission to edit users.';
            }
            if (count($usersErrors) === 0) {
                $submitted = $_POST['permissions'] ?? [];
                $targetRecord = find_user($users, $targetUsername);
                $isTargetMaster = $targetRecord && !empty($targetRecord['master']);
                $targetPermissions = normalize_permissions($targetRecord ? ($targetRecord['permissions'] ?? []) : [], $allowedPermissions);
                $isTargetFullAdmin = $isTargetMaster || in_array('full_admin', $targetPermissions, true);
                $isTargetReadOnly = $targetRecord && !empty($targetRecord['read_only']);
                if ($targetRecord && $isTargetMaster && !$isMasterUser) {
                    $usersErrors[] = 'Only master accounts can edit master accounts.';
                } elseif ($targetRecord && $isTargetFullAdmin && !$isMasterUser && !$isFullAdmin) {
                    $usersErrors[] = 'Only full admin or master accounts can edit full admin accounts.';
                } elseif ($targetRecord && $isTargetReadOnly && !$isMasterUser && !$isFullAdmin) {
                    $usersErrors[] = 'Only full admin or master accounts can edit read-only accounts.';
                }
            }
            if (count($usersErrors) === 0) {
                $readOnlyRequested = $isMasterUser && !$isTargetMaster && !empty($_POST['read_only']);
                $normalized = normalize_permissions($submitted, $allowedPermissions);
                if (!$isMasterUser) {
                    $normalized = array_values(array_diff($normalized, ['full_admin']));
                }
                if ($readOnlyRequested) {
                    $normalized = [];
                }
                if (in_array('full_admin', $normalized, true)) {
                    $normalized = $allowedPermissions;
                }
                if (!$canEditUsers && $targetUsername === $authUser) {
                    $currentPerms = normalize_permissions($authRecord['permissions'] ?? [], $allowedPermissions);
                    $normalized = array_values(array_intersect($normalized, $currentPerms));
                }
                $updated = false;
                foreach ($users as &$user) {
                    if (($user['username'] ?? '') === $targetUsername) {
                        $isTargetMaster = !empty($user['master']);
                        $existingReadOnly = !empty($user['read_only']);
                        $readOnlyValue = $isMasterUser ? ($readOnlyRequested && !$isTargetMaster) : $existingReadOnly;
                        $user['permissions'] = $normalized;
                        if ($readOnlyValue) {
                            $user['permissions'] = [];
                            $user['must_change_password'] = false;
                            $user['temp_password'] = '';
                        }
                        if ($isMasterUser) {
                            $user['read_only'] = $readOnlyValue;
                        }
                        $user['updated_at'] = gmdate('c');
                        $updated = true;
                        break;
                    }
                }
                unset($user);
                if (!$updated) {
                    $usersErrors[] = 'User not found.';
                } else {
                    if (write_users_file($usersPath, $users, $usersErrors, 'Unable to update permissions.')) {
                        $usersSuccess = 'Permissions updated.';
                        enforce_users_permissions($usersPath, $usersWarnings);
                        if ($targetUsername === $authUser) {
                            // Refresh permission context for the current session user.
                            $authRecord = find_user($users, $authUser);
                            $permissionContext = build_permission_context($authRecord, $allowedPermissions);
                            $currentPermissions = $permissionContext['currentPermissions'];
                            $isReadOnlyUser = $permissionContext['isReadOnlyUser'];
                            $isMasterUser = $permissionContext['isMasterUser'];
                            $isFullAdmin = $permissionContext['isFullAdmin'];
                            $canAddUsers = $permissionContext['canAddUsers'];
                            $canEditUsers = $permissionContext['canEditUsers'];
                            $canRemoveUsers = $permissionContext['canRemoveUsers'];
                            $canEditSite = $permissionContext['canEditSite'];
                        }
                    }
                }
            }
        }
    }
}

// Admin action: reset a user's password to a temporary value.
if ($action === 'reset_password') {
    if ($blockUserActions) {
        $usersErrors[] = 'User actions unavailable: `users.json` is damaged.';
    } elseif ($isReadOnlyUser) {
        $usersErrors[] = 'Read-only accounts cannot reset passwords.';
    } elseif (!$authRecord || $forcePasswordChange) {
        $usersErrors[] = 'You must be logged in to reset passwords.';
    } else {
        if (require_csrf_token($usersErrors)) {
            $targetUsername = trim($_POST['target_username'] ?? '');
            if (!$canEditUsers && $targetUsername !== $authUser) {
                $usersErrors[] = 'You do not have permission to edit users.';
            }
            if (count($usersErrors) === 0) {
                $targetRecord = find_user($users, $targetUsername);
                $targetPermissions = normalize_permissions($targetRecord ? ($targetRecord['permissions'] ?? []) : [], $allowedPermissions);
                $isTargetMaster = $targetRecord && !empty($targetRecord['master']);
                $isTargetFullAdmin = $isTargetMaster || in_array('full_admin', $targetPermissions, true);
                $isTargetReadOnly = $targetRecord && !empty($targetRecord['read_only']);
                if ($targetRecord && $isTargetMaster && !$isMasterUser && !$isFullAdmin) {
                    $usersErrors[] = 'Only master or full admin accounts can reset the master password.';
                } elseif ($targetRecord && $isTargetFullAdmin && !$isMasterUser && !$isFullAdmin) {
                    $usersErrors[] = 'Only full admin or master accounts can reset full admin passwords.';
                } elseif ($targetRecord && $isTargetReadOnly && !$isMasterUser && !$isFullAdmin) {
                    $usersErrors[] = 'Only full admin or master accounts can reset read-only passwords.';
                }
            }
            if (count($usersErrors) === 0) {
                $newTempPassword = bin2hex(random_bytes(6));
                $updated = false;
                $resetLogoutAfter = false;
                foreach ($users as &$user) {
                    if (($user['username'] ?? '') === $targetUsername) {
                        $user['password_hash'] = password_hash($newTempPassword, PASSWORD_DEFAULT);
                        $user['must_change_password'] = empty($user['read_only']);
                        $user['temp_password'] = empty($user['read_only']) ? $newTempPassword : '';
                        $user['updated_at'] = gmdate('c');
                        $updated = true;
                        $resetLogoutAfter = !empty($user['master']) || ($targetUsername === $authUser);
                        break;
                    }
                }
                unset($user);
                if ($updated) {
                    if (write_users_file($usersPath, $users, $usersErrors, 'Unable to reset password.')) {
                        $resetPassword = $newTempPassword;
                        $resetUsername = $targetUsername;
                        $resetLogoutAfterReset = $resetLogoutAfter;
                        enforce_users_permissions($usersPath, $usersWarnings);
                        // Invalidate every "Stay signed in" cookie for the
                        // target user — they're getting a new temp password,
                        // so any device that auto-logs-in via remember-me
                        // could otherwise dodge the forced password change.
                        lawnding_remember_token_revoke_all_for_user(
                            $targetUsername,
                            lawnding_remember_tokens_path()
                        );
                    }
                } elseif (count($usersErrors) === 0) {
                    $usersErrors[] = 'User not found.';
                }
            }
        }
    }
}

// Admin action: remove a user (cannot remove master).
if ($action === 'remove_user') {
    if ($blockUserActions) {
        $usersErrors[] = 'User actions unavailable: `users.json` is damaged.';
    } elseif ($isReadOnlyUser) {
        $usersErrors[] = 'Read-only accounts cannot remove users.';
    } elseif (!$authRecord || $forcePasswordChange) {
        $usersErrors[] = 'You must be logged in to remove users.';
    } else {
        if (require_csrf_token($usersErrors)) {
            $targetUsername = trim($_POST['target_username'] ?? '');
            if (!$canRemoveUsers && $targetUsername !== $authUser) {
                $usersErrors[] = 'You do not have permission to remove users.';
            }
            if (count($usersErrors) === 0) {
                $targetRecord = find_user($users, $targetUsername);
                $targetPermissions = normalize_permissions($targetRecord ? ($targetRecord['permissions'] ?? []) : [], $allowedPermissions);
                $isTargetMaster = $targetRecord && !empty($targetRecord['master']);
                $isTargetFullAdmin = $isTargetMaster || in_array('full_admin', $targetPermissions, true);
                $isTargetReadOnly = $targetRecord && !empty($targetRecord['read_only']);
                if ($targetRecord && $isTargetMaster && !$isMasterUser) {
                    $usersErrors[] = 'Only master accounts can remove master accounts.';
                } elseif ($targetRecord && $isTargetFullAdmin && !$isMasterUser && !$isFullAdmin) {
                    $usersErrors[] = 'Only full admin or master accounts can remove full admin accounts.';
                } elseif ($targetRecord && $isTargetReadOnly && !$isMasterUser && !$isFullAdmin) {
                    $usersErrors[] = 'Only full admin or master accounts can remove read-only accounts.';
                }
            }
            if (count($usersErrors) === 0) {
                $newUsers = [];
                $removed = false;
                foreach ($users as $user) {
                    if (($user['username'] ?? '') === $targetUsername) {
                        if (!empty($user['master'])) {
                            $usersErrors[] = 'Master accounts cannot be removed.';
                            $newUsers[] = $user;
                        } else {
                            $removed = true;
                        }
                    } else {
                        $newUsers[] = $user;
                    }
                }
                if ($removed) {
                    if (write_users_file($usersPath, $newUsers, $usersErrors, 'Unable to remove user.')) {
                        $users = $newUsers;
                        $usersSuccess = 'User removed.';
                        enforce_users_permissions($usersPath, $usersWarnings);
                        if ($targetUsername === $authUser) {
                            // If you removed yourself, force a logout.
                            $_SESSION = [];
                            if (ini_get('session.use_cookies')) {
                                $params = session_get_cookie_params();
                                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
                            }
                            session_destroy();
                            $logoutAfterAction = true;
                        }
                    }
                } elseif (count($usersErrors) === 0) {
                    $usersErrors[] = 'User not found.';
                }
            }
        }
    }
}

// Admin action: log out and clear session.
if ($action === 'logout') {
    if (!require_csrf_token($errors)) {
    } else {
        // Capture the "came from public site" intent before session wipe,
        // since $_SESSION = [] below will erase the flag along with everything
        // else. If set, route the browser back to the site's public root;
        // otherwise keep the traditional admin-login landing.
        $returnToPublic = !empty($_SESSION['logout_return_public']);
        $logoutTarget = $returnToPublic
            ? (rtrim((string) lawnding_config('base_url', ''), '/') . '/')
            : admin_redirect_path();

        // Revoke this device's remember-me token (if any) — clears the
        // cookie and removes the matching entry from the token store.
        // Other devices' tokens are untouched.
        $rememberRaw = isset($_COOKIE[LP_REMEMBER_COOKIE_NAME]) && is_string($_COOKIE[LP_REMEMBER_COOKIE_NAME])
            ? $_COOKIE[LP_REMEMBER_COOKIE_NAME] : '';
        lawnding_remember_token_revoke($rememberRaw, lawnding_remember_tokens_path());

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        header('Location: ' . $logoutTarget);
        exit;
    }
}

// Check if users.json permissions look unsafe for the admin UI.
$usersPermissionsNeedsFix = users_permissions_needs_fix($usersPath);

// Restore flash messages stored from the last POST redirect.
if ($flash) {
    if (!empty($flash['errors']) && is_array($flash['errors'])) {
        $errors = $flash['errors'];
    }
    if (!empty($flash['success']) && is_string($flash['success'])) {
        $success = $flash['success'];
    }
    if (!empty($flash['passwordChangeSuccess']) && is_string($flash['passwordChangeSuccess'])) {
        $passwordChangeSuccess = $flash['passwordChangeSuccess'];
    }
    if (!empty($flash['usersErrors']) && is_array($flash['usersErrors'])) {
        $usersErrors = $flash['usersErrors'];
    }
    if (!empty($flash['usersSuccess']) && is_string($flash['usersSuccess'])) {
        $usersSuccess = $flash['usersSuccess'];
    }
    if (!empty($flash['usersWarnings']) && is_array($flash['usersWarnings'])) {
        $usersWarnings = array_merge($usersWarnings, $flash['usersWarnings']);
    }
    if (array_key_exists('usersPermissionsNeedsFix', $flash)) {
        $usersPermissionsNeedsFix = (bool) $flash['usersPermissionsNeedsFix'];
    }
    if (!empty($flash['usersPermissionsFixResult']) && is_string($flash['usersPermissionsFixResult'])) {
        $usersPermissionsFixResult = $flash['usersPermissionsFixResult'];
    }
    if (!empty($flash['resetPassword']) && is_string($flash['resetPassword'])) {
        $resetPassword = $flash['resetPassword'];
    }
    if (!empty($flash['resetUsername']) && is_string($flash['resetUsername'])) {
        $resetUsername = $flash['resetUsername'];
    }
    if (!empty($flash['resetLogoutAfterReset'])) {
        $resetLogoutAfterReset = (bool) $flash['resetLogoutAfterReset'];
    }
}

// If we just logged out or removed ourselves, redirect to the login screen.
if ($logoutAfterAction) {
    header('Location: ' . admin_redirect_path());
    exit;
}

// Post/redirect/get: persist status messages and avoid resubmission on refresh.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'logout') {
    $_SESSION['flash_admin'] = [
        'errors' => $errors,
        'success' => $success,
        'passwordChangeSuccess' => $passwordChangeSuccess,
        'usersErrors' => $usersErrors,
        'usersSuccess' => $usersSuccess,
        'usersWarnings' => $usersWarnings,
        'usersPermissionsNeedsFix' => $usersPermissionsNeedsFix,
        'usersPermissionsFixResult' => $usersPermissionsFixResult,
        'resetPassword' => $resetPassword,
        'resetUsername' => $resetUsername,
        'resetLogoutAfterReset' => $resetLogoutAfterReset,
    ];
    header('Location: ' . admin_public_url('', true));
    exit;
}

// If authenticated and not forced to change password, render the admin app.
if ($authRecord && !$forcePasswordChange) {
    $adminConfigPath = function_exists('lawnding_admin_path')
        ? lawnding_admin_path('config.php')
        : dirname(__DIR__, 2) . '/admin/config.php';
    require $adminConfigPath;
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <title>Admin Panel</title>
    <?php
        $assetBase = function_exists('lawnding_config') ? rtrim(lawnding_config('base_url', ''), '/') : '';
        if ($assetBase === '') {
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            if (is_string($scriptName) && str_starts_with($scriptName, '/public/')) {
                $assetBase = '/public';
            }
        }
        $headerData = lawnding_read_json(
            function_exists('lawnding_data_path')
                ? lawnding_data_path('header.json')
                : __DIR__ . '/../res/data/header.json'
        );
        $faviconRaw = is_string($headerData['logo'] ?? null) && $headerData['logo'] !== ''
            ? $headerData['logo']
            : 'res/img/logo.jpg';
        $faviconUrl = lawnding_versioned_local_asset_url(
            $faviconRaw,
            defined('SITE_VERSION') ? (string) SITE_VERSION : ''
        );
    ?>
    <?php // Deprecated: site-version.js cache-busting is no longer loaded. ?>
    <script src="<?php echo htmlspecialchars($assetBase . '/res/scr/no-zoom.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
    <link rel="icon" href="<?php echo htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase . '/res/style.css', ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase . '/res/admin.css', ENT_QUOTES, 'UTF-8'); ?>">
    <?php lawnding_run_hook('head_assets'); ?>
</head>
<body>
    <?php
    // Drain any session flash so revocation banners (e.g. from
    // lawnding_admin_handle_tg_revocation) surface here instead of being
    // stranded for the next public-page visit. No JS on this surface, so
    // the close button is intentionally omitted — the banner clears on the
    // next navigation.
    $loginFlash = function_exists('lawnding_flash_consume') ? lawnding_flash_consume() : null;
    ?>
    <?php if ($loginFlash !== null): ?>
        <div class="adminNotices" id="adminNotices" aria-live="polite" aria-atomic="true">
            <div class="adminNotice adminNotice--<?php echo htmlspecialchars($loginFlash['type'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo $loginFlash['type'] === 'danger' ? ' role="alert"' : ''; ?>>
                <span class="adminNoticeText"><?php echo htmlspecialchars($loginFlash['text'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        </div>
    <?php endif; ?>
    <!-- Keyboard skip target — reveals on focus, jumps past notices to the form. -->
    <a class="skipLink" href="#loginWrap">Skip to login form</a>
    <!-- Login / first-run / password reset screen. -->
    <div class="loginWrap" id="loginWrap">
        <div class="loginPane">
            <?php if ($forcePasswordChange): ?>
                <div class="notice">
                    Please create a new permanent password before continuing.
                </div>
            <?php elseif (!$hasUsers): ?>
                <div class="notice">
                    WARNING! A `users.json` file is missing or damaged. If this is a first-time setup, create an account
                    now. If this is NOT a new installation, STOP IMMEDIATELY and verify the website data on your server
                    is correct. There should be a `users.json` file in
                    "<?php echo htmlspecialchars($usersPath); ?>".
                </div>
            <?php endif; ?>

            <?php
                // Title + submit text vary per form; everything else (shell,
                // field/input chrome, error block) is shared with the public
                // login modal via the .userModal + .lpLoginModal__* classes.
                if ($forcePasswordChange) {
                    $loginPanelTitle = 'Update password';
                } elseif (!$hasUsers) {
                    $loginPanelTitle = 'Create admin account';
                } else {
                    $loginPanelTitle = 'Sign in';
                }
            ?>
            <div class="userModal glassConcave lpModalNoDrag adminLoginPanel">
                <h4><?php echo htmlspecialchars($loginPanelTitle); ?></h4>
                <!-- role="alert" / role="status" so screen readers announce the
                     message even though it appears on a fresh page render. -->
                <?php if (count($errors) > 0): ?>
                    <div class="message error" role="alert">
                        <?php echo htmlspecialchars(implode(' ', $errors)); ?>
                    </div>
                <?php elseif ($success): ?>
                    <div class="message success" role="status">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php elseif ($passwordChangeSuccess): ?>
                    <div class="message success" role="status">
                        <?php echo htmlspecialchars($passwordChangeSuccess); ?>
                    </div>
                <?php elseif (!empty($usersWarnings)): ?>
                    <div class="message error" role="alert">
                        <?php echo htmlspecialchars(implode(' ', $usersWarnings)); ?>
                    </div>
                <?php endif; ?>

                <?php if ($forcePasswordChange): ?>
                    <form class="lpLoginModal__form" method="post" action="">
                        <input type="hidden" name="action" value="change_password">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <label class="lpLoginModal__field">
                            <span class="lpLoginModal__fieldLabel">New password</span>
                            <input class="lpLoginModal__input" type="password" name="new_password" autocomplete="new-password" required>
                        </label>
                        <label class="lpLoginModal__field">
                            <span class="lpLoginModal__fieldLabel">Confirm password</span>
                            <input class="lpLoginModal__input" type="password" name="confirm_password" autocomplete="new-password" required>
                        </label>
                        <button class="lpLoginModal__submit" type="submit">Update password</button>
                    </form>
                <?php elseif (!$hasUsers): ?>
                    <form class="lpLoginModal__form" method="post" action="">
                        <input type="hidden" name="action" value="create_admin">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <label class="lpLoginModal__field">
                            <span class="lpLoginModal__fieldLabel">Username</span>
                            <input class="lpLoginModal__input" type="text" name="username" autocomplete="username" required>
                        </label>
                        <label class="lpLoginModal__field">
                            <span class="lpLoginModal__fieldLabel">Password</span>
                            <input class="lpLoginModal__input" type="password" name="password" autocomplete="new-password" required>
                        </label>
                        <label class="lpLoginModal__field">
                            <span class="lpLoginModal__fieldLabel">Confirm password</span>
                            <input class="lpLoginModal__input" type="password" name="confirm_password" autocomplete="new-password" required>
                        </label>
                        <button class="lpLoginModal__submit" type="submit">Create admin account</button>
                    </form>
                <?php else: ?>
                    <form class="lpLoginModal__form" method="post" action="">
                        <input type="hidden" name="action" value="login">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <label class="lpLoginModal__field">
                            <span class="lpLoginModal__fieldLabel">Username</span>
                            <input class="lpLoginModal__input" type="text" name="username" autocomplete="username" required>
                        </label>
                        <label class="lpLoginModal__field">
                            <span class="lpLoginModal__fieldLabel">Password</span>
                            <input class="lpLoginModal__input" type="password" name="password" autocomplete="current-password" required>
                        </label>
                        <label class="lpLoginModal__remember">
                            <input type="checkbox" name="remember" value="1">
                            <span>Stay signed in</span>
                        </label>
                        <button class="lpLoginModal__submit" type="submit">Sign in</button>
                    </form>
                    <output role="alert" aria-live="polite"
                            class="lpLoginModal__error" data-lp-login-error hidden></output>
                    <div class="lpLoginModal__divider" aria-hidden="true"><span>or</span></div>
                    <button type="button" class="lpLoginModal__telegram"
                            data-lp-login-telegram
                            data-tg-bot-id="<?php echo htmlspecialchars($tgBotId, ENT_QUOTES, 'UTF-8'); ?>"
                            data-tg-auth-endpoint="<?php echo htmlspecialchars($tgAdminAuthEndpoint, ENT_QUOTES, 'UTF-8'); ?>"
                            <?php echo $tgBotId === '' ? 'disabled aria-disabled="true"' : ''; ?>>
                        <svg aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 24 24">
                            <path fill="currentColor" d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71l-4.14-3.06-1.99 1.93c-.23.23-.42.42-.83.42z"/>
                        </svg>
                        <span><?php echo $tgBotId === '' ? 'Telegram login unavailable' : 'Login with Telegram'; ?></span>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="footer adminFooter">LawndingPage Admin Panel</div>
    <?php if (!$identity['isAuthenticated'] && $tgBotId !== ''): ?>
    <script src="<?php echo htmlspecialchars(lawnding_versioned_local_asset_url('res/scr/tg-login.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <?php endif; ?>
</body>
</html>
