<?php
// Admin entrypoint: routing, auth, and dispatch to the full admin UI or login flow.

// Locate the bootstrap file (supports alternate directory layouts).
$bootstrapPath = __DIR__ . '/../../lp-bootstrap.php';
if (!is_readable($bootstrapPath)) {
    $bootstrapPath = __DIR__ . '/../../../lp-bootstrap.php';
}
require_once $bootstrapPath;
// Prevent stale HTML/PHP responses from being cached.
$cacheHeadersPath = function_exists('lawnding_public_path')
    ? lawnding_public_path('res/scr/cache_headers.php')
    : __DIR__ . '/../res/scr/cache_headers.php';
require_once $cacheHeadersPath;
// Load the authoritative site version and set client cookie if needed.
$versionPath = function_exists('lawnding_public_path')
    ? lawnding_public_path('res/version.php')
    : __DIR__ . '/../res/version.php';
require_once $versionPath;
if (!isset($_COOKIE['site_version']) || $_COOKIE['site_version'] !== SITE_VERSION) {
    setcookie('site_version', SITE_VERSION, [
        'expires' => time() + 31536000,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => false,
        'samesite' => 'Lax'
    ]);
}

// Normalize /admin to /admin/ for clean relative URL behavior.
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($requestPath !== null && $requestPath !== '') {
    $trimmedPath = rtrim($requestPath, '/');
    if ($trimmedPath !== '' && $trimmedPath === $requestPath && str_ends_with($trimmedPath, '/admin')) {
        $query = $_SERVER['QUERY_STRING'] ?? '';
        $location = $requestPath . '/' . ($query !== '' ? '?' . $query : '');
        header('Location: ' . $location, true, 301);
        exit;
    }
}

// Resolve admin root and configure error logging to admin/errors.txt.
$adminRoot = function_exists('lawnding_config')
    ? lawnding_config('admin_dir', dirname(__DIR__, 2) . '/admin')
    : dirname(__DIR__, 2) . '/admin';
$errorLogPath = $adminRoot . '/errors.txt';
ini_set('log_errors', '1');
ini_set('error_log', $errorLogPath);

session_start(); // Initialize PHP session storage and load existing session data.

// Users file location (stored outside public web root).
$usersPath = function_exists('lawnding_config')
    ? lawnding_config('users_path', dirname(__DIR__, 2) . '/admin/users.json')
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

$hasUsers = is_array($users) && count($users) > 0;
if (!$hasUsers && $usersFileIssue === null) {
    $usersFileIssue = 'empty';
}

// CSRF token stored in the session and embedded in forms.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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
function normalize_permissions($permissions, $allowedPermissions) {
    if (!is_array($permissions)) {
        return [];
    }
    return array_values(array_intersect($permissions, $allowedPermissions));
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

// Append a warning with a pointer to errors.txt for detail.
function add_health_warning(&$warnings, $message) {
    $warnings[] = $message . ' Check errors.txt for more information.';
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
    if ($encoded === false || file_put_contents($usersPath, $encoded, LOCK_EX) === false) {
        $errors[] = $message;
        return false;
    }
    return true;
}

// Build the admin base path used for redirects after logout.
function admin_redirect_path() {
    $redirectPath = rtrim(dirname($_SERVER['PHP_SELF']), '/');
    if ($redirectPath === '') {
        $redirectPath = '/';
    }
    return $redirectPath . '/';
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
function build_permission_context($authRecord, $allowedPermissions) {
    $currentPermissions = $authRecord ? normalize_permissions($authRecord['permissions'] ?? [], $allowedPermissions) : [];
    $isMasterUser = $authRecord && !empty($authRecord['master']);
    $isFullAdmin = $isMasterUser || in_array('full_admin', $currentPermissions, true);
    if ($isFullAdmin) {
        $currentPermissions = $allowedPermissions;
    }
    return [
        'currentPermissions' => $currentPermissions,
        'isMasterUser' => $isMasterUser,
        'isFullAdmin' => $isFullAdmin,
        'canAddUsers' => $isFullAdmin || in_array('add_users', $currentPermissions, true),
        'canEditUsers' => $isFullAdmin || in_array('edit_users', $currentPermissions, true),
        'canRemoveUsers' => $isFullAdmin || in_array('remove_users', $currentPermissions, true),
        'canEditSite' => $isFullAdmin || in_array('edit_site', $currentPermissions, true),
    ];
}

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
            $user = find_user($users, $username);
            if (!$user || !password_verify($password, $user['password_hash'] ?? '')) {
                $errors[] = 'Username/password incorrect.';
            } else {
                session_regenerate_id(true); // Prevent session fixation by rotating the ID on login.
                $_SESSION['auth_user'] = $user['username']; // Store the authenticated user in the session.
            }
        }
    }
}

// Resolve the current authenticated user and permission flags.
$authUser = $_SESSION['auth_user'] ?? '';
$authRecord = $authUser !== '' ? find_user($users, $authUser) : null;
$forcePasswordChange = $authRecord && !empty($authRecord['must_change_password']);
$permissionContext = build_permission_context($authRecord, $allowedPermissions);
$currentPermissions = $permissionContext['currentPermissions'];
$isMasterUser = $permissionContext['isMasterUser'];
$isFullAdmin = $permissionContext['isFullAdmin'];
$canAddUsers = $permissionContext['canAddUsers'];
$canEditUsers = $permissionContext['canEditUsers'];
$canRemoveUsers = $permissionContext['canRemoveUsers'];
$canEditSite = $permissionContext['canEditSite'];

// Health checks for files and directories needed by the admin app.
if (!file_exists($errorLogPath)) {
    if (is_dir($adminRoot) && is_writable($adminRoot)) {
        @touch($errorLogPath);
    }
    if (!file_exists($errorLogPath)) {
        add_health_warning($usersWarnings, 'Health check: Unable to create errors.txt in the admin directory.');
    }
}
if (file_exists($errorLogPath) && !is_writable($errorLogPath)) {
    add_health_warning($usersWarnings, 'Health check: errors.txt is not writable.');
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
                }
            }
        }
    }
}

// Admin action: create a new user with a temporary password.
if ($action === 'create_user') {
    if ($blockUserActions) {
        $usersErrors[] = 'User actions unavailable: `users.json` is damaged.';
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
                $normalized = normalize_permissions($submitted, $allowedPermissions);
                if (!$isMasterUser) {
                    $normalized = array_values(array_diff($normalized, ['full_admin']));
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
                        $user['permissions'] = $normalized;
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
    } elseif (!$authRecord || $forcePasswordChange) {
        $usersErrors[] = 'You must be logged in to reset passwords.';
    } else {
        if (require_csrf_token($usersErrors)) {
            $targetUsername = trim($_POST['target_username'] ?? '');
            if (!$canEditUsers && $targetUsername !== $authUser) {
                $usersErrors[] = 'You do not have permission to edit users.';
            }
            if (count($usersErrors) === 0) {
                $newTempPassword = bin2hex(random_bytes(6));
                $updated = false;
                $resetLogoutAfter = false;
                foreach ($users as &$user) {
                    if (($user['username'] ?? '') === $targetUsername) {
                        if (!empty($user['master']) && !$isFullAdmin) {
                            $usersErrors[] = 'Only master or full admin accounts can reset the master password.';
                            break;
                        }
                        $user['password_hash'] = password_hash($newTempPassword, PASSWORD_DEFAULT);
                        $user['must_change_password'] = true;
                        $user['temp_password'] = $newTempPassword;
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
    } elseif (!$authRecord || $forcePasswordChange) {
        $usersErrors[] = 'You must be logged in to remove users.';
    } else {
        if (require_csrf_token($usersErrors)) {
            $targetUsername = trim($_POST['target_username'] ?? '');
            if (!$canRemoveUsers && $targetUsername !== $authUser) {
                $usersErrors[] = 'You do not have permission to remove users.';
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
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        header('Location: ' . admin_redirect_path());
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
    $redirectPath = $_SERVER['REQUEST_URI'] ?? '';
    if ($redirectPath === '') {
        $redirectPath = '/';
    }
    header('Location: ' . $redirectPath);
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
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Admin Panel</title>
    <script>
        (function () {
            var serverVersion = "<?php echo htmlspecialchars(SITE_VERSION, ENT_QUOTES, 'UTF-8'); ?>";
            var match = document.cookie.match(/(?:^|;)\s*site_version=([^;]*)/);
            var cookieVersion = match ? decodeURIComponent(match[1]) : "";
            var encodedVersion = encodeURIComponent(serverVersion);
            if (cookieVersion === serverVersion) {
                return;
            }
            if (window.location.search.indexOf("__v=" + encodedVersion) !== -1) {
                document.cookie = "site_version=" + encodedVersion + "; path=/; SameSite=Lax";
                return;
            }
            document.cookie = "site_version=" + encodedVersion + "; path=/; SameSite=Lax";
            var sep = window.location.search ? "&" : "?";
            var target = window.location.pathname + window.location.search + sep
                + "__v=" + encodedVersion + "&__t=" + Date.now() + window.location.hash;
            window.location.replace(target);
        })();
    </script>
    <?php $assetBase = function_exists('lawnding_config') ? rtrim(lawnding_config('base_url', ''), '/') : ''; ?>
    <link rel="icon" type="image/jpg" href="<?php echo htmlspecialchars($assetBase); ?>/res/img/logo.jpg">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(lawnding_versioned_url($assetBase . '/res/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <style>
        body {
            background: #111;
        }

        .loginWrap {
            flex: 1 1 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .loginPane {
            width: min(520px, 92vw);
            flex: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.75rem;
        }

        .loginPane .pane {
            width: min(320px, 88vw);
            flex: none;
        }

        .loginForm {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }

        .loginField {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            font-size: 0.9rem;
            color: #FFFFFFCC;
        }

        .loginInput {
            border: 1px solid #FFFFFF55;
            border-radius: 12px;
            padding: 0.45rem 0.65rem;
            background: #00000033;
        }

        .loginButton {
            border: 1px solid #FFFFFF77;
            border-radius: 999px;
            padding: 0.5rem 0.9rem;
            background: #00000055;
            font-weight: 600;
        }

        .notice {
            border: 1px solid #FFCC6677;
            background: #33220066;
            border-radius: 12px;
            padding: 0.6rem 0.75rem;
            font-size: 0.85rem;
            line-height: 1.3;
            width: min(520px, 92vw);
            margin: 0 auto;
        }

        .message {
            border-radius: 12px;
            padding: 0.6rem 0.75rem;
            font-size: 0.85rem;
            line-height: 1.3;
            margin-top: 0.75rem;
        }

        .message.error {
            border: 1px solid #FF777777;
            background: #33000066;
        }

        .message.success {
            border: 1px solid #77FFAA77;
            background: #00331A66;
        }

        .adminFooter {
            text-align: center;
            padding: 0.75rem 1rem 1rem;
        }

        .loginButton:hover {
            background-color: #77777733;
            border: 1px solid #FFFFFFBB;
        }

        .loginButton:active {
            background-color: #00000055;
            border: 1px solid #FFFFFF55;
        }
    </style>
</head>
<body>
    <!-- Login / first-run / password reset screen. -->
    <div class="loginWrap">
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

            <div class="pane glassConvex">
                <h3>ADMIN PANEL</h3>
            <!-- Status messages for the login screen. -->
            <?php if (count($errors) > 0): ?>
                <div class="message error">
                    <?php echo htmlspecialchars(implode(' ', $errors)); ?>
                </div>
            <?php elseif ($success): ?>
                <div class="message success">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php elseif ($passwordChangeSuccess): ?>
                <div class="message success">
                    <?php echo htmlspecialchars($passwordChangeSuccess); ?>
                </div>
            <?php elseif (!empty($usersWarnings)): ?>
                <div class="message error">
                    <?php echo htmlspecialchars(implode(' ', $usersWarnings)); ?>
                </div>
            <?php endif; ?>

            <!-- The form changes based on first-run, forced reset, or normal login. -->
            <?php if ($forcePasswordChange): ?>
                <form class="loginForm" method="post" action="">
                    <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <label class="loginField">
                        New Password
                        <input class="loginInput" type="password" name="new_password" autocomplete="new-password" required>
                    </label>
                    <label class="loginField">
                        Confirm Password
                        <input class="loginInput" type="password" name="confirm_password" autocomplete="new-password" required>
                    </label>
                    <button class="loginButton" type="submit">Update Password</button>
                </form>
                <?php elseif (!$hasUsers): ?>
                <form class="loginForm" method="post" action="">
                    <input type="hidden" name="action" value="create_admin">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <label class="loginField">
                            Username
                            <input class="loginInput" type="text" name="username" autocomplete="username" required>
                        </label>
                        <label class="loginField">
                            Password
                            <input class="loginInput" type="password" name="password" autocomplete="new-password" required>
                        </label>
                        <label class="loginField">
                            Confirm Password
                            <input class="loginInput" type="password" name="confirm_password" autocomplete="new-password" required>
                        </label>
                        <button class="loginButton" type="submit">Create Admin</button>
                    </form>
                <?php else: ?>
                    <form class="loginForm" method="post" action="">
                        <input type="hidden" name="action" value="login">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <label class="loginField">
                            Username
                            <input class="loginInput" type="text" name="username" autocomplete="username" required>
                        </label>
                        <label class="loginField">
                            Password
                            <input class="loginInput" type="password" name="password" autocomplete="current-password" required>
                        </label>
                        <button class="loginButton" type="submit">Login</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="footer adminFooter">LawndingPage Admin Panel</div>
</body>
</html>
