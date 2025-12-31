### Changelog
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

