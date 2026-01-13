### Changelog

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

