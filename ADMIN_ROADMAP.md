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

## Phase 9: MVP Hardening
- [x] Update readme with installation/deployment instructions
- [x] Add example `lp-overrides.php`
- [x] Health check for required files?
- [x] Ensure the root `admin/` directory is not web-accessible (can currently access `admin/config.php` from the URL on test server - should redirect perhaps?)
- [x] Error logging hint
- [x] consider base URL override

## Post-MVP additions to consider
- [ ] Persistent sessions on reload
- [ ] Rate limiting / lockout for repeated failures
- [ ] Audit logging for logins
- [ ] Optional user management UI
- [ ] Move relevant auth logic into `admin/auth.php`
- [ ] Add explicit permission checks helper for future endpoints
- [ ] Implement session timeout and activity check, defining a fixed amount of time before a forced logout occurs?
- [ ] Re-authenticate for sensitive actions (reset/remove users)?
- [ ] Add and remove additional pages and page types
- [ ] Add a changelog to the admin panel
- [x] Bug: `website.com/admin` results in a login page with no styling applied to it, but `website.com/admin/` loads fine.
- [ ] Feature: generate a new account creation link for people.  allow you to pre-emptively set permissions for this user, based on the link
- [ ] Tweak: Allow ordering of background images.  Also toggle between random and cycling.  Add a slideshow effect for the background (updates attribution in the footer) and toggle to enable or disable this.
- [ ] Feature: Add or remove pages, configre their type
- [ ] Feature: Proper text editor controls for markdown pages with rich text preview
- [ ] Tweak: change link styling to be bold and underlined on hover, with a small link icon next to it
- [ ] Tweak: replace text labels in the admin panel UI with icons (save, help, delete buttons).  Add proper up/down arrow icons for the re-order buttons.
