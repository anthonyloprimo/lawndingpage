### Changelog


#### v1.13.1
- Fixed a bug where uploading a replacement icon for a pane left visitors seeing the old icon until they hard-refreshed.

-----

#### v1.13.0
- Added a Site Config section to the admin panel for full admins. Sets the defaults that newly created media galleries inherit. Currently includes "Allow per-item custom thumbnail uploads" and "Allow replacing media files on existing items".
- Each gallery pane now has a gear icon in the top-right corner. Click it to override the site defaults for that specific gallery — useful when one curated gallery needs custom thumbnails enabled while everything else stays locked down.
- Media galleries auto-derive a 1:1 square thumbnail on upload, intelligently cropping to keep faces and high-contrast areas in frame instead of dumb-centering.
- Click any image in the gallery's edit modal to set a focal point. The thumbnail re-derives around that point so cover-mode crops keep the right pixels visible.
- The "Add new media" button now accepts multiple files at once.
- The image edit modal now shows an Image Info section: original size, saved size, upload date, and uploader. Telegram uploaders show as their friendly handle (e.g. "@phinnay") instead of raw numeric IDs.
- Delete buttons in the gallery now ask for confirmation via a blur-backdrop overlay over the image preview.
- Caption changes in the edit modal enable the Save button immediately, instead of waiting for a focal-point change.
- Thumbnail grid images now use lazy and async loading hints — long galleries scroll smoother on slow links.

-----

#### v1.12.8
- Media galleries now accept image uploads only. Video uploads will return as an admin-controllable feature in a future release.

-----

#### v1.12.7
- Added a "Skip to main content" link that appears at the top of every page when you press Tab. Lets keyboard users jump past the header and navigation in one keystroke.
- The admin panel browser tab now shows a proper page title with your site name (was previously blank).
- The main content area of the public site and admin panel now uses the proper landmark element, so screen readers can jump straight to it.
- Event List calendar and events tabs are now properly labelled for screen readers.
- Media gallery lightbox images now get descriptive alt text (uses the item's title, falls back to the filename when no title is set).
- Login screen error and success messages now announce themselves to screen readers.
- Closing the changelog popup returns focus to the link you opened it from, instead of dropping you at the top of the page.
- Decorative icons in buttons and navigation are no longer announced redundantly by screen readers.

-----

#### v1.12.6
- Notices on the public site and admin panel now announce themselves to screen readers when they appear. Save success, login errors, and other feedback that previously only showed visually are now spoken.
- Modals now announce their title when opened, instead of just announcing "dialog".
- Telegram permission checkboxes (Edit site, Add users, Edit users, Remove users) now identify which permission they toggle for screen reader users.

-----

#### v1.12.5
- Added a Diagnostics section to the admin panel — admins with edit permission can browse recent runtime errors with severity color coding, click-to-expand details, and an optional 5-second auto-refresh. Captures errors from both the public page and the admin flow. Old entries roll off automatically once the log file gets large.
- The legacy admin/errors.txt file is no longer written — the new Diagnostics view replaces it. If you have an existing errors.txt on your server, you can delete it manually.

-----

#### v1.12.4
- Fixed a bug where red error banners on the public site stayed onscreen until manually dismissed, instead of fading out after 30 seconds like they do in the admin panel.

-----

#### v1.12.3
- Added a calendar grid view to the Event List pane. Admins can enable it per-pane and choose whether it opens by default. Includes month navigation, a day-detail popup, and a toast when a selected day has no events.
- Added Telegram allowlist and denylist fields to the bot configuration. Allowlisted user IDs gain access regardless of group membership; denylisted IDs are denied regardless.

-----

#### v1.12.2
- Added a changelog popup to the site footer — click [CHANGELOG] to read version history without leaving the page.
- Added GitHub and lawnding.page links to the site footer with a smaller font and wider spacing between groups.
- Fixed a security gap where uploaded files retained their original filename extension instead of one derived from the file's actual content type.
- Added file-write locking to all JSON and text write operations to prevent data corruption under concurrent saves.
- Blocked direct browser access to files under the media gallery data directory.
- Added execution protection to the image upload directory.

-----

#### v1.12.1
- Added a proper editor for per-group admin permissions on Telegram groups.
- Added an admin panel shortcut to the Telegram profile chip for users whose group grants admin access.
- Logging out of the admin panel now returns you to the public site if you arrived via that shortcut, instead of dropping you on the admin login form.
- Fixed a bug where, if your Telegram admin access was revoked mid-session, the first attempt to log back in with a normal admin account failed with "Security token invalid." 

-----

#### v1.12.0
- Added the ability for Telegram-authenticated visitors to earn admin permissions just by being in a configured Telegram group.  No need for a separate admin account.  Admins set per-group permissions.  Master accounts and full admin access still require a password-based admin account.
- Read-only admin accounts stay read-only, even if the same person's Telegram identity would otherwise grant extra permissions.
- Fixed a bug where a Telegram admin who lost their group membership mid-session could stay on the admin page until the next page load.  They now get logged out automatically with a yellow banner explaining why.
- Fixed a bug where the admin login form didn't show revocation banners, leaving the user without context for why they got kicked.
- Security fix: two diagnostic endpoints (for testing the bot token and validating group IDs) were reachable without logging in.  Both now require admin access.

-----

#### v1.11.0
- Enhanced Telegram login handling, additional info is collected incluiding user icon. Move logout button to the top-right of the page header.
- Telegram login now requires being a member of a configured Telegram group.  If you aren't in one, the login is rejected cleanly instead of leaving you half-authenticated.
- Fixed a bug where losing your group membership mid-session didn't log you out.
- Added top-of-screen banner notifications for login success, login rejection, and access revocation.  Same look as the admin save banner.
- Logo and background image uploads now apply global cache busting and refresh immediately after saving.
- Telegram membership cache also stores each visitor's username and first/last name on each fresh check.
- Added a small plugin hook system so plugins can extend the public page without touching the core templates. 

-----

#### v1.10.2
- Mirrored to LIFURS repo and began development specific to that usecase. 

-----

#### v1.10.1
- Tweaked link list display to be a bit larger.
- Fixed a bug where multiple mediaGallery panes being present would trigger multiple file upload prompts when trying to upload to only one gallery.
- Fixed a bug where mediaGallery uploads would immediately fail, rendering the module useless.
- Fixed an error where the web page title only showed the URL instead of the name of the website.
- Fixed a bug where navbar link icons did not correctly resize non-svg images.
- Modified some default behaviors for the site config when certain data files are missing.

-----

#### v1.10.0
- Security fixes.
- Fixed an issue where favicon wouldn't always update.
- Modified some visual elements - the NSFW tag for links as well as the logout button was fixed.
- Added content gating to mardown-supported text inputs so it behaves like gated links.  Use `[sfw][/sfw]` and `[nsfw][/nsfw]` tags to conceal content only intended for members.
- Added external link support to the main navbar.
- Modified navbar display, added text labels for public site

-----

#### v1.9.0
- Added authorized links that integrates with Telegram groups; user logs in with telegram and as long as they're in specified groups, they can view hidden links.
- Added SFW and NSFW link categories, and SFW and NSFW group ID notation.
- Tweaked how the bottom toolbar for the links list displays options on mobile.
- Updated when and how files are re-cached.  No more query string for URLs.
- Fixed a bug where enabling/disabling the auth links pane on mobile resets the active pane.
- Fixed a bug where if the active pane ever points to 'null', it points to a default pane if possible, otherwise a message displayed to notify the user to select a pane.

-----

#### v1.8.0
- FEATURE: Added media gallery module.

-----

#### v1.7.0
- FEATURE: Enabled toggling of the link list.  If disabled, only defined panes will display.
- UI TWEAK: Tweaked the display of the link list on the front-end so it's centered when no other panes are defined.
- BUGFIX: Fixed a bug where removing users or modifying their roles/permissions where upon saving the entire page becomes unresponsive, except for the notification that pops up.
- BIGFIX: Fixed a bug where the bottom toolbar for the background image list behaved as if it were inside the list, being pushed down when enough entries were in the list.
- BUGFIX: Fixed a bug where background display mode "sequential on load" was always loading the first image instead of loading the next in the list.

-----

#### v1.6.3
**Changes:**
- Tweaked user account behaviors for live demo using the "read only" mode.
- Fixed account permission behaviors.
- Changed default page to user account management for the admin panel.

-----

#### v1.6.2
**Changes:**
- Updated `README.md` to be accurate to the current version.
- Added information on the admin panel to `README.md`.

-----

#### v1.6.1
**Changes:**
- Added background display mode (random, sequential, slideshow random, slideshow sequential) with duration specification for slideshow modes.

-----

#### v1.6.0
**Changes:**
- UI: Tweaked the display of the title and subtitle fields in the admin panel on mobile devices.
- UI: Tweaked the display of the existing users list and adjusted the button display for better readability on mobile and desktop devices.
- UI: Tweaked the display and behavior of the background image pane for better readability and usability on mobile.
- UI: Tweaked the display and behavior of the links list for better readability and usability on mobile.  Simplified some of the fields.
- UI: Tweaked the display and behavior of the eventList module for better readability and usability on mobile.
- UI: Tweaked the display and layout of the Pane Management modal for better readability and usability on mobile.
- Fixed a bug where it was possible to create events that ended before their start date/times.
- Fixed a bug where re-ordering links would make them jump around in a strange order.
- Front-end has a quick fade-in once the content is loading, so we don't see abrasive popping in of content before it's ready.
- Added visual fade indicator when the navbar can be scrolled.
- General usability tweaks on desktop mode

**Known Issues:**
- Added the initial options to change background display mode, but it does not yet function.

-----

#### v1.5.0
**Changes:**
- Security fix: Session & Cookie hardening
- Security fix: Added CSRF protection in remaining functions for admin panel.
- Security fix: Clickjacking defense for admin panel.
- Security fix: Clickjacking defense for public-facing page.
- Security fix: Changed the google maps link for event addresses to use HTTPS.
- Security fix: Added `.htaccess` to the admin folder to ensure it's never web-accessible even when placed in a web-accessible directory.
- Security fix: CSP protection for scripts.
- Security fix: CSP protection for styles.
- Header textboxes expand in mobile mode for better viewing when editing.
- Text editing toolbar now implemented for all instances of markdown-capable textareas (basicText, eventPane).

**Known Issues:**
- Changing the order of links sometimes jumps more than one slot when doing so too quickly.  Workaround: Just move the link that jumped too far back up/down to where it should be.
- Event List pane does not correctly display in mobile devices on the back-end.

-----

#### v1.4.1
**Changes:**
- Added the ability to save current and future events to a calendar by generating an `.ics` file.
- Made addreesses on event cards and modals clickable links to google maps.

**Known Issues:**
- Changing the order of links sometimes jumps more than one slot when doing so too quickly.  Workaround: Just move the link that jumped too far back up/down to where it should be.
- Event List pane does not correctly display in mobile devices on the back-end.

-----

#### v1.4.0
**Changes:**
- Converted the panes to a modular system that load dynamically.
- Added the ability to add and remove panes based on modules (pane templates).
- Modules contain the code for the front-end and back-end views of a given pane.
- Panes contain references to the data that gets saved when clicking the "Save all changes" button.
- Panes can be re-ordered.
- Updated the tutorial.
- Added Event List module and associated files.
- Added module schema veersion to `version.php`.
- Added blank module template for developers.
- Added modals for adding/removing/renaming/changing pane types, icons, etc.
- Added module discovery code, ensuring first-party and third-party developers can create new modules over time.
- Added a feature to automatically migrate code to newer versions when the module schema updates.  This will fully wipe all data already saved.
- Selecting a module will allow for a preview to display next to them - if desired.
- When saving events, verifies duplicate events cannot be created.

**Known Issues:**
- Changing the order of links sometimes jumps more than one slot when doing so too quickly.  Workaround: Just move the link that jumped too far back up/down to where it should be.
- Event List pane does not correctly display in mobile on the back-end.

-----

#### v1.3.2
**Changes:**
- Fixed error with forced re-caching.

**Known Issues:**
- Changing the order of links sometimes jumps more than one slot when doing so too quickly.  Workaround: Just move the link that jumped too far back up/down to where it should be.

-----

#### v1.3.1
**Changes:**
- Hid currently unused pages.

**Known Issues:**
- Changing the order of links sometimes jumps more than one slot when doing so too quickly.  Workaround: Just move the link that jumped too far back up/down to where it should be.

-----

#### v1.3.0
**Changes:**
- Tweaked the appearance of the header information in the admin panel so the fields are a bit more usable.
- Fixed an error in LICENSE.md
- Removed test versions of `errors.log` and `users.json` from the repo.
- Cleaned up `lp-bootstrap.php` and worked in comments.
- Cleaned up `public/admin/index.php` and worked in comments.
- Cleaned up background code (`backgrounds-upload.php`, `backgrounds-list.php`, `backgrounds-delete.php`) and added helper functions to improve readability.  Worked in comments for these files too.
- Cleaned up `save-config.php` and worked in comments.
- Cleaned up `index.php` and worked in comments.
- Cleaned up the CSS and added comments.
- Cleaned up `app.js` and added comments.
- Added version identifier internally and ensures it displays correctly on the site.
- Added forced re-cache when the site version saved to a cookie doesn't exist or mis-matches the value on the site.  An initial re-cache may be required by anyone viewing the site before this happens automatically.
- Moved changelog to a dedicated file and added the ability to view the changelog in the app.

**Known Issues:**
- Changing the order of links sometimes jumps more than one slot when doing so too quickly.  Workaround: Just move the link that jumped too far back up/down to where it should be.

-----

#### v1.2.1
**Changes:**
- FINALLY fixed mobile page rendering!!

**Known Issues:**
- Changing the order of links sometimes jumps more than one slot when doing so too quickly.  Workaround: Just move the link that jumped too far back up/down to where it should be.

-----

#### v1.2.0
**Changes:**
- Fixed a bug where users could accidentally enter reserved, hardcoded IDs from the site as the link ID, causing style conflicts.
- The client and server now validate entered IDs and fails to update with a warning so the user can correct them.
- Response notifications (success, failures, general notifications) will now auto-dismiss after a set time.  "Danger" class notifications (red bars) will NOT auto-dismiss.
- Display icons for links in the link list.
- Added a field for a URL for background image attribution.

**Known Issues:**
- Changing the order of links sometimes jumps more than one slot when doing so too quickly.  Workaround: Just move the link that jumped too far back up/down to where it should be.

-----

#### v1.1.0
**Changes:**
- Fixed a bug where certain file paths were not correctly being respected due to being hardcoded, causing the site to break, primarily with the admin panel.
- Fixed a bug where on mobile devices, when the navbar exceeded the view width, it would cause unexpected display issues, with parts of the UI clipping and being inaccessible.
- Fixed a major bug where sending too much data to save would drop the POST request, and the server would update all files with empty data, effectively wiping the site contents.
- Saving changes now gives an overlay and indicator that changes are being saved to the server.
- Fixed a bug where buttons stretched to contain their text instead of fitting inside and truncating if too long.
- Inline link styling improved for readability
- Updated button appearances in the admin panel.
- POST payload is cleared on successful upload, reloading after will not ask to resubmit.
- Cleaned up link list display in the admin panel.
- Added styling for call-to-action buttons.

**Known Issues:**
- Changing the order of links sometimes jumps more than one slot when doing so too quickly.  Workaround: Just move the link that jumped too far back up/down to where it should be.

-----

#### v1.0.0
- Initial Version

