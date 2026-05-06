<?php
// Module: Event List (admin)
// Renders editable event cards and exposes JSON to Save All via pane[<id>][events].

if (!isset($pane) || !is_array($pane)) {
    return;
}

// Inject admin styles/scripts and the shared delete-confirm modal once per request.
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
        lawnding_modal_open('eventDeleteConfirmModal', 'Remove Event');
        ?>
        <p class="usersHint">Are you sure you want to remove this event?</p>
        <div class="userModalActions">
            <button class="usersButton usersDanger" type="button" id="eventDeleteConfirmYes" data-modal-confirm="true" autofocus>Remove</button>
            <button class="usersButton userModalClose" type="button">Cancel</button>
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

// Resolve JSON file path through bootstrap helpers when available.
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

// Render icon HTML using the shared helper injected by admin/config.php.
$iconHtml = '';
if (isset($renderPaneIcon) && is_callable($renderPaneIcon)) {
    $iconHtml = (string) $renderPaneIcon($pane);
}
if ($iconHtml === '') {
    $iconHtml = '<span class="paneIconFallback">Icon</span>';
}

?>
<?php
    // Snapshot of the on-disk state, embedded as a data-* attribute so it
    // sidesteps script-src CSP entirely. admin.js reads + parses it on init.
    $snapshotJson = json_encode([
        'events' => $events,
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if ($snapshotJson === false) {
        $snapshotJson = '{"events":[]}';
    }
?>
<div class="pane glassConvex eventListPane" id="<?php echo htmlspecialchars($paneId); ?>" data-pane-type="eventList" data-snapshot="<?php echo htmlspecialchars($snapshotJson, ENT_QUOTES, 'UTF-8'); ?>">
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

    <div class="eventListScroll">
        <div class="eventList" data-pane-id="<?php echo htmlspecialchars($paneId); ?>">
            <?php if (empty($events)): ?>
                <div class="eventEmpty">No events yet. Click Add Event to create one.</div>
            <?php endif; ?>
            <?php foreach ($events as $index => $event): ?>
                <?php
                    if (!is_array($event)) {
                        $event = [];
                    }
                $eventId = $event['id'] ?? '';
                $eventName = $event['name'] ?? '';
                $startDate = $event['startDate'] ?? ($event['date'] ?? '');
                $startTime = $event['startTime'] ?? '';
                $endDate = $event['endDate'] ?? '';
                if ($endDate === '' && !empty($event['endTime']) && !empty($event['date'])) {
                    $endDate = $event['date'];
                }
                $endTime = $event['endTime'] ?? '';
                $timeZone = $event['timeZone'] ?? '';
                $address = $event['address'] ?? '';
                $description = $event['description'] ?? '';
                $allDay = !empty($event['allDay']);
            ?>
                <div class="eventCard" data-event-index="<?php echo (int) $index; ?>" data-event-id="<?php echo htmlspecialchars($eventId); ?>">
                    <div class="eventNameRow">
                        <label class="eventNameLabel">
                            <span class="eventFieldTitle">Event Name</span>
                            <input type="text" class="eventNameInput" value="<?php echo htmlspecialchars($eventName); ?>" placeholder="Event name">
                        </label>
                        <div class="eventCardActions">
                            <button class="deleteLink iconButton" type="button" title="Remove event" aria-label="Remove event">
                                <?php echo lawnding_icon_svg('delete'); ?>
                            </button>
                        </div>
                    </div>
                    <div class="eventSectionDivider" aria-hidden="true"></div>
                    <div class="eventAllDayRow">
                        <label class="eventAllDayLabel">
                            <input type="checkbox" class="eventAllDayInput"<?php if ($allDay): ?> checked<?php endif; ?>>
                            <span>All day</span>
                        </label>
                    </div>
                    <div class="eventTimeRow<?php if ($allDay): ?> isAllDay<?php endif; ?>">
                        <div class="eventFieldTitle eventFieldTitleRow">When</div>
                        <div class="eventTimeFields">
                            <div class="eventTimeGroup">
                                <span class="eventTimeLabel">From</span>
                                <input type="date" class="eventStartDateInput" value="<?php echo htmlspecialchars($startDate); ?>" aria-label="Start date">
                                <input type="time" class="eventStartTimeInput" value="<?php echo htmlspecialchars($startTime); ?>" aria-label="Start time">
                            </div>
                            <div class="eventTimeDash">-</div>
                            <div class="eventTimeGroup">
                                <span class="eventTimeLabel">To</span>
                                <input type="date" class="eventEndDateInput" value="<?php echo htmlspecialchars($endDate); ?>" aria-label="End date">
                                <input type="time" class="eventEndTimeInput" value="<?php echo htmlspecialchars($endTime); ?>" aria-label="End time">
                            </div>
                        </div>
                    </div>
                    <div class="eventSectionDivider" aria-hidden="true"></div>
                    <div class="eventTimeZoneRow">
                        <span class="eventFieldTitle">Time Zone</span>
                        <input type="text" class="eventTimezoneInput" value="<?php echo htmlspecialchars($timeZone); ?>" placeholder="America/New_York" aria-label="Time zone">
                    </div>
                    <div class="eventAddressRow">
                        <div class="eventFieldTitle">Address</div>
                        <input type="text" class="eventAddressInput" value="<?php echo htmlspecialchars($address); ?>" placeholder="123 Main St, City, State" aria-label="Address">
                    </div>
                    <div class="eventSectionDivider" aria-hidden="true"></div>
                    <div class="eventSectionDivider" aria-hidden="true"></div>
                    <div class="eventDescriptionLabel">
                        <span class="eventFieldTitle">Description</span>
                        <div class="markdownEditor">
                            <?php echo markdown_editor_toolbar_html(); ?>
                            <textarea class="eventDescriptionInput markdownTextarea" rows="4" placeholder="Details, host, venue, etc."><?php echo htmlspecialchars($description); ?></textarea>
                            <div class="markdownPreview" aria-live="polite" hidden></div>
                        </div>
                    </div>
                    <div class="eventValidation" aria-live="polite"></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="eventListControls">
        <button class="eventAddButton" type="button">Add Event</button>
    </div>

    <textarea class="eventListPayload" name="pane[<?php echo htmlspecialchars($paneId); ?>][events]" aria-label="<?php echo htmlspecialchars($paneName); ?> events" hidden></textarea>
</div>
