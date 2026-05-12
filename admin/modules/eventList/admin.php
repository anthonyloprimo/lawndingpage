<?php
// Module: Event List (admin)
// Calendar-grid event entry: click "+" on a day to add, click an event
// chip to edit. Persistence contract is unchanged from the form-card era:
// pane[<id>][events] textarea carries the {create,update,delete} changeset
// to save-config.php. admin.js is the source-of-truth for in-flight edits.

if (!isset($pane) || !is_array($pane)) {
    return;
}

// Inject admin styles/scripts and the shared modals once per request, even
// if multiple eventList panes are on the page.
static $eventListAdminAssetsInjected = false;
if (!$eventListAdminAssetsInjected) {
    $eventListAdminAssetsInjected = true;
    $styleUrl = function_exists('lawnding_asset_url')
        ? lawnding_asset_url('res/scr/module-style.php?module=eventList')
        : '/res/scr/module-style.php?module=eventList';
    echo '<link rel="stylesheet" href="'
        . htmlspecialchars($styleUrl, ENT_QUOTES, 'UTF-8')
        . '">';

    $scriptUrl = function_exists('lawnding_asset_url')
        ? lawnding_asset_url('res/scr/module-script.php?module=eventList&file=admin.js')
        : '/res/scr/module-script.php?module=eventList&file=admin.js';
    echo '<script src="'
        . htmlspecialchars($scriptUrl, ENT_QUOTES, 'UTF-8')
        . '" defer></script>';

    if (function_exists('lawnding_modal_open') && function_exists('lawnding_modal_close')) {
        // Half-hour increments. value="HH:MM" (24h) for round-trip, label is
        // 12h AM/PM for display. Off-grid times get rounded on modal open
        // (admin.js roundToHalfHour). The hidden blank is a target for
        // .val('') when all-day is checked; users never see it in the list.
        $timeOptionsHtml = '<option value="" disabled hidden></option>';
        for ($h = 0; $h < 24; $h++) {
            foreach ([0, 30] as $m) {
                $value = sprintf('%02d:%02d', $h, $m);
                $h12 = ($h % 12) ?: 12;
                $period = $h < 12 ? 'AM' : 'PM';
                $display = sprintf('%d:%02d %s', $h12, $m, $period);
                $timeOptionsHtml .= '<option value="' . $value . '">' . $display . '</option>';
            }
        }

        // Event editor modal. Single shared instance reparented to <body>
        // by admin.js init so position:fixed isn't anchored to a transformed
        // ancestor. Title is set dynamically (Add Event / Edit Event).
        lawnding_modal_open('eventEditorModal', 'Add Event', ['extra_class' => 'eventEditorModalOverlay']);
        ?>
        <div class="eventEditorScrapedBanner" hidden>
            <span>Event synced from: <a class="eventEditorScrapedFeed" target="_blank" rel="noopener noreferrer"></a> &mdash; <span class="eventEditorScrapedHint">click to unlock and edit</span></span>
            <button type="button" class="eventEditorScrapedLockToggle iconButton" aria-label="Unlock to edit" title="Unlock to edit">
                <span class="eventEditorScrapedLockIcon"><?php echo lawnding_icon_svg('lock'); ?></span>
                <span class="eventEditorScrapedUnlockIcon" hidden><?php echo lawnding_icon_svg('lock_open'); ?></span>
            </button>
        </div>
        <div class="eventEditorLockedBanner" hidden>
            <span>Local override &mdash; no longer syncing from <a class="eventEditorLockedFeed" target="_blank" rel="noopener noreferrer"></a>.</span>
            <button class="usersButton" type="button" id="eventEditorResumeSync">Resume sync</button>
        </div>

        <form class="eventEditorForm" novalidate>
            <label class="eventEditorField">
                <span class="eventFieldTitle isRequired">Event Name</span>
                <input type="text" class="eventNameInput" placeholder="Event name" autocomplete="off">
            </label>

            <label class="eventEditorField">
                <span class="eventFieldTitle">Category</span>
                <select class="eventCategoryInput" aria-label="Category">
                    <option value="">(none)</option>
                </select>
            </label>

            <div class="eventSectionDivider" aria-hidden="true"></div>

            <div class="eventEditorRow eventEditorToggles">
                <label class="eventAllDayLabel">
                    <input type="checkbox" class="eventAllDayInput">
                    <span>All day</span>
                </label>
            </div>

            <div class="eventEditorWhen">
                <div class="eventTimeGroup eventTimeGroupStart">
                    <span class="eventTimeLabel isRequired">Start</span>
                    <input type="date" class="eventStartDateInput" aria-label="Start date">
                    <select class="eventStartTimeInput" aria-label="Start time"><?php echo $timeOptionsHtml; ?></select>
                    <span class="eventTimezoneIndicator" aria-label="Time zone"></span>
                </div>
                <div class="eventTimeGroup eventTimeGroupEnd">
                    <span class="eventTimeLabel">End</span>
                    <input type="date" class="eventEndDateInput" aria-label="End date">
                    <select class="eventEndTimeInput" aria-label="End time"><?php echo $timeOptionsHtml; ?></select>
                    <span class="eventTimezoneIndicator" aria-label="Time zone"></span>
                </div>
            </div>

            <div class="eventSectionDivider" aria-hidden="true"></div>

            <label class="eventEditorField">
                <span class="eventFieldTitle isRequired">Address</span>
                <input type="text" class="eventAddressInput" placeholder="123 Main St, City, State" autocomplete="off">
            </label>

            <div class="eventEditorField">
                <span class="eventFieldTitle isRequired">Description</span>
                <div class="markdownEditor">
                    <?php echo markdown_editor_toolbar_html(); ?>
                    <textarea class="eventDescriptionInput markdownTextarea" rows="6" placeholder="Details, host, venue, etc."></textarea>
                    <div class="markdownPreview" aria-live="polite" hidden></div>
                </div>
            </div>

            <div class="eventValidation" aria-live="polite"></div>
        </form>
        <div class="userModalActions eventEditorActions">
            <button class="usersButton usersConfirm" type="button" id="eventEditorSave" data-modal-confirm="true">Save</button>
            <button class="usersButton userModalClose" type="button" id="eventEditorCancel">Cancel</button>
            <button class="usersButton usersDanger" type="button" id="eventEditorDelete" hidden>Delete</button>
        </div>
        <div class="eventListConfirmDelete" role="alertdialog" aria-hidden="true" aria-labelledby="eventListConfirmDeletePrompt">
            <p id="eventListConfirmDeletePrompt" class="eventListConfirmDeletePrompt">Are you sure?</p>
            <div class="eventListConfirmDeleteActions">
                <button class="eventListConfirmDeleteYes usersButton usersDanger" type="button">Yes, delete</button>
                <button class="eventListConfirmDeleteNo usersButton" type="button">No</button>
            </div>
        </div>
        <?php
        lawnding_modal_close();
    }
}

// Pane metadata used for IDs, labels, and data file resolution.
$paneId = isset($pane['id']) ? (string) $pane['id'] : '';
$paneName = isset($pane['name']) ? (string) $pane['name'] : '';
$paneData = isset($pane['data']) && is_array($pane['data']) ? $pane['data'] : [];
$jsonFile = isset($paneData['json']) ? (string) $paneData['json'] : '';

if ($paneId === '' || $jsonFile === '') {
    return;
}

$jsonPath = function_exists('lawnding_data_path')
    ? lawnding_data_path($jsonFile)
    : __DIR__ . '/../../public/res/data/' . $jsonFile;

$raw = is_readable($jsonPath) ? file_get_contents($jsonPath) : '';
$decoded = $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($decoded)) {
    $decoded = [];
}
$events = $decoded['events'] ?? [];
if (!is_array($events)) {
    $events = [];
}

require_once __DIR__ . '/helpers.php';
$categories = event_list_load_categories();

// Feed friendly-names for scraped-event banner. Soft-coupled via function_exists;
// eventList still renders if the eventScraper plugin isn't installed.
$feedLabels = [];
if (function_exists('event_scraper_load_config')) {
    $scraperConfig = event_scraper_load_config();
    foreach (($scraperConfig['feeds'] ?? []) as $feedId => $feed) {
        if (is_array($feed) && isset($feed['label'])) {
            $feedLabels[(string) $feedId] = (string) $feed['label'];
        }
    }
}

$iconHtml = '';
if (isset($renderPaneIcon) && is_callable($renderPaneIcon)) {
    $iconHtml = (string) $renderPaneIcon($pane);
}
if ($iconHtml === '') {
    $iconHtml = '<span class="paneIconFallback">Icon</span>';
}

// data-snapshot is the on-disk state, embedded for admin.js bootstrap.
$snapshotJson = json_encode([
    'events' => $events,
    'categories' => $categories,
    'feedLabels' => $feedLabels,
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if ($snapshotJson === false) {
    $snapshotJson = '{"events":[],"categories":[],"feedLabels":{}}';
}
?>
<div class="pane glassConvex eventListPane eventListAdminCalendarPane" id="<?php echo htmlspecialchars($paneId); ?>" data-pane-type="eventList" data-snapshot="<?php echo htmlspecialchars($snapshotJson, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="paneHeader eventListPaneHeader">
        <div class="eventListPaneIdentity">
            <span class="paneIconDisplay" aria-hidden="true">
                <span class="paneIconPreview"><?php echo $iconHtml; ?></span>
            </span>
            <div class="paneHeaderTitle">
                <span class="paneTitle"><?php echo htmlspecialchars($paneName); ?></span>
            </div>
        </div>
        <button class="paneSettingsButton iconButton" type="button" data-pane-id="<?php echo htmlspecialchars($paneId); ?>" aria-label="Pane settings" title="Pane settings"><?php echo lawnding_icon_svg('settings'); ?></button>
    </div>

    <div class="eventListAdminCalendar" data-pane-id="<?php echo htmlspecialchars($paneId); ?>">
        <div class="eventListAdminCalendarNav">
            <button class="eventListAdminCalendarNavButton eventCalendarPrev" type="button" aria-label="Previous month">&lsaquo;</button>
            <span class="eventListAdminCalendarMonthText" aria-live="polite"></span>
            <button class="eventListAdminCalendarTodayButton eventCalendarTodayButton hidden" type="button">Today</button>
            <button class="eventListAdminCalendarNavButton eventCalendarNext" type="button" aria-label="Next month">&rsaquo;</button>
        </div>
        <table class="eventCalendarTable" aria-label="Event calendar (admin)">
            <thead>
                <tr class="eventCalendarDayLabels">
                    <th scope="col">SUN</th>
                    <th scope="col">MON</th>
                    <th scope="col">TUE</th>
                    <th scope="col">WED</th>
                    <th scope="col">THU</th>
                    <th scope="col">FRI</th>
                    <th scope="col">SAT</th>
                </tr>
            </thead>
            <tbody class="eventCalendarBody"></tbody>
        </table>
        <div class="eventListAdminNarrow" hidden></div>
    </div>

    <textarea class="eventListPayload" name="pane[<?php echo htmlspecialchars($paneId); ?>][events]" aria-label="<?php echo htmlspecialchars($paneName); ?> events" hidden></textarea>
</div>
