# Admin Auth Roadmap Checklist

## Phase 1: Layout + Config
- [x] Confirm auth data location outside `public/` (`admin/users.json`)
- [x] Define shared auth include path (`admin/auth.php`)
- [x] Note: `admin/users.json` will be created by the account creation script

## Phase 2: Data Model
- [x] Define `users.json` schema (username, password_hash, permissions, flags)
- [x] Define session keys (`auth_user`, `csrf_token`)

## Phase 3: First-Run Setup
- [x] Detect missing/invalid users file
- [x] Show warning banner with expected users file path
- [x] Add "Create First Admin" form (username, password, confirm)
- [x] Generate CSRF token on GET
- [x] Validate CSRF on POST
- [x] Validate inputs (length, allowed chars)
- [x] Hash password (bcrypt)
- [x] Write `users.json`
- [x] Redirect to login view

## Phase 4: Login
- [x] Render login form when users exist
- [x] Include CSRF token in login form
- [x] Validate CSRF on submit
- [x] Verify username + password hash
- [x] Regenerate session ID on success
- [x] Set `auth_user` in session
- [x] Show "username/password incorrect" on failure

## Phase 5: Admin UI Gate
- [x] Require auth before loading admin UI
- [x] Include `admin/config.php` only after successful login
- [x] Add logout button in `admin/config.php`
- [x] Logout clears session and redirects to login

## Phase 6: User Management
- [x] Add USERS tab in admin nav (before BG)
- [x] Create USERS pane with split layout (create + list)
- [x] Add create user form (username + temp password)
- [x] List users with actions (reset password, remove with confirmation)
- [x] Add required-password-change flag per user
- [x] Prompt user to set new password when flagged
- [x] Add permissions modal (Full admin + granular permissions)
- [x] Enforce permissions for create/edit/remove/reset actions
- [x] Disable master remove + restrict master reset to master/full admin
- [x] Add self-action warnings (reset/remove self logs out)
- [x] Update users pane via partial refresh (no full page reload)
- [x] Fix partial refresh state when updating permissions or adding/removing users
- [x] Separate user-action notifications from the create-user hint area
- [x] Enforce edit-site permission on save endpoint (server-side)

## Phase 7: Session + Auth Hardening
- [x] Add CSRF protection for logout and any non-AJAX POSTs

## Phase 8: MVP Hardening
- [x] Validate input lengths and allowed characters
- [x] Set secure permissions on `users.json`
- [x] Fail closed on file errors

## Phase 9: Post-MVP additions to consider
- [ ] Persistent sessions on reload
- [ ] Rate limiting / lockout for repeated failures
- [ ] Audit logging for logins
- [ ] Optional user management UI
- [ ] Move relevant auth logic into `admin/auth.php`
- [ ] Add explicit permission checks helper for future endpoints
- [ ] Implement session timeout and activity check, defining a fixed amount of time before a forced logout occurs
- [ ] Re-authenticate for sensitive actions (reset/remove users)
- [ ] Text editor controls for markdown pages
