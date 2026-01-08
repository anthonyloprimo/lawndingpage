<?php
// Module: Event List (admin)
// Renders editable event cards and exposes JSON to Save All via pane[<id>][events].

if (!isset($pane) || !is_array($pane)) {
    return;
}

// Inject admin styles once per request.
static $eventListAdminStylesInjected = false;
if (!$eventListAdminStylesInjected) {
    $eventListAdminStylesInjected = true;
    $styleUrl = function_exists('lawnding_asset_url')
        ? lawnding_asset_url('res/scr/module-style.php?module=eventList')
        : '/res/scr/module-style.php?module=eventList';
    echo '<link rel="stylesheet" href="'
        . htmlspecialchars(lawnding_versioned_url($styleUrl), ENT_QUOTES, 'UTF-8')
        . '">';
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
$showPast = !empty($decoded['showPast']);
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

// Build the "saves to ..." hint from the pane data map.
$dataFiles = [];
if (!empty($paneData) && is_array($paneData)) {
    foreach ($paneData as $file) {
        if (is_string($file) && $file !== '') {
            $dataFiles[] = $file;
        }
    }
}
$dataHint = $dataFiles ? 'saves to ' . implode(', ', $dataFiles) : '';
?>
<div class="pane glassConvex eventListPane" id="<?php echo htmlspecialchars($paneId); ?>" data-pane-type="eventList">
    <div class="paneHeader">
        <button class="paneIconButton" type="button" data-pane-id="<?php echo htmlspecialchars($paneId); ?>" aria-label="Edit pane icon">
            <span class="paneIconPreview"><?php echo $iconHtml; ?></span>
        </button>
        <div class="paneHeaderTitle">
            <span class="paneTitle"><?php echo htmlspecialchars($paneName); ?></span>
            <?php if ($dataHint !== ''): ?>
                <span class="paneDataHint"><?php echo htmlspecialchars('(' . $dataHint . ')'); ?></span>
            <?php endif; ?>
        </div>
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
                    <div class="eventTimeRow">
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
                            <div class="markdownToolbar" role="toolbar" aria-label="Markdown formatting">
                                <div class="markdownToolbarGroup">
                                    <button class="usersButton iconButton mdToolbarButton" type="button" data-md-action="bold" title="Bold" aria-label="Bold">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M13.5,15.5H10V12.5H13.5A1.5,1.5 0 0,1 15,14A1.5,1.5 0 0,1 13.5,15.5M10,6.5H13A1.5,1.5 0 0,1 14.5,8A1.5,1.5 0 0,1 13,9.5H10M15.6,10.79C16.57,10.11 17.25,9 17.25,8C17.25,5.74 15.5,4 13.25,4H7V18H14.04C16.14,18 17.75,16.3 17.75,14.21C17.75,12.69 16.89,11.39 15.6,10.79Z" /></svg>
                                    </button>
                                    <button class="usersButton iconButton mdToolbarButton" type="button" data-md-action="italic" title="Italic" aria-label="Italic">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M10,4V7H12.21L8.79,15H6V18H14V15H11.79L15.21,7H18V4H10Z" /></svg>
                                    </button>
                                    <select class="markdownHeadingSelect" aria-label="Insert heading">
                                        <option value="">Heading</option>
                                        <option value="1">Heading 1</option>
                                        <option value="2">Heading 2</option>
                                        <option value="3">Heading 3</option>
                                        <option value="4">Heading 4</option>
                                        <option value="5">Heading 5</option>
                                        <option value="6">Heading 6</option>
                                    </select>
                                    <button class="usersButton iconButton mdToolbarButton" type="button" data-md-action="ul" title="Bulleted list" aria-label="Bulleted list">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M7,5H21V7H7V5M7,13V11H21V13H7M4,4.5A1.5,1.5 0 0,1 5.5,6A1.5,1.5 0 0,1 4,7.5A1.5,1.5 0 0,1 2.5,6A1.5,1.5 0 0,1 4,4.5M4,10.5A1.5,1.5 0 0,1 5.5,12A1.5,1.5 0 0,1 4,13.5A1.5,1.5 0 0,1 2.5,12A1.5,1.5 0 0,1 4,10.5M7,19V17H21V19H7M4,16.5A1.5,1.5 0 0,1 5.5,18A1.5,1.5 0 0,1 4,19.5A1.5,1.5 0 0,1 2.5,18A1.5,1.5 0 0,1 4,16.5Z" /></svg>
                                    </button>
                                    <button class="usersButton iconButton mdToolbarButton" type="button" data-md-action="ol" title="Numbered list" aria-label="Numbered list">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M7,13V11H21V13H7M7,19V17H21V19H7M7,7V5H21V7H7M3,8V5H2V4H4V8H3M2,17V16H5V20H2V19H4V18.5H3V17.5H4V17H2M4.25,10A0.75,0.75 0 0,1 5,10.75C5,10.95 4.92,11.14 4.79,11.27L3.12,13H5V14H2V13.08L4,11H2V10H4.25Z" /></svg>
                                    </button>
                                    <button class="usersButton iconButton mdToolbarButton" type="button" data-md-action="quote" title="Quote" aria-label="Quote">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M9 22C8.4 22 8 21.6 8 21V18H4C2.9 18 2 17.1 2 16V4C2 2.9 2.9 2 4 2H20C21.1 2 22 2.9 22 4V16C22 17.1 21.1 18 20 18H13.9L10.2 21.7C10 21.9 9.8 22 9.5 22H9M10 16V19.1L13.1 16H20V4H4V16H10M16.3 6L14.9 9H17V13H13V8.8L14.3 6H16.3M10.3 6L8.9 9H11V13H7V8.8L8.3 6H10.3Z" /></svg>
                                    </button>
                                    <button class="usersButton iconButton mdToolbarButton" type="button" data-md-action="code" title="Code" aria-label="Code">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M5,3H7V5H5V10A2,2 0 0,1 3,12A2,2 0 0,1 5,14V19H7V21H5C3.93,20.73 3,20.1 3,19V15A2,2 0 0,0 1,13H0V11H1A2,2 0 0,0 3,9V5A2,2 0 0,1 5,3M19,3A2,2 0 0,1 21,5V9A2,2 0 0,0 23,11H24V13H23A2,2 0 0,0 21,15V19A2,2 0 0,1 19,21H17V19H19V14A2,2 0 0,1 21,12A2,2 0 0,1 19,10V5H17V3H19M12,15A1,1 0 0,1 13,16A1,1 0 0,1 12,17A1,1 0 0,1 11,16A1,1 0 0,1 12,15M8,15A1,1 0 0,1 9,16A1,1 0 0,1 8,17A1,1 0 0,1 7,16A1,1 0 0,1 8,15M16,15A1,1 0 0,1 17,16A1,1 0 0,1 16,17A1,1 0 0,1 15,16A1,1 0 0,1 16,15Z" /></svg>
                                    </button>
                                    <button class="usersButton iconButton mdToolbarButton" type="button" data-md-action="link" title="Link" aria-label="Link">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M19,3H5A2,2 0 0,0 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5A2,2 0 0,0 19,3M19,19H5V5H19V19M13.94,10.06C14.57,10.7 14.92,11.54 14.92,12.44C14.92,13.34 14.57,14.18 13.94,14.81L11.73,17C11.08,17.67 10.22,18 9.36,18C8.5,18 7.64,17.67 7,17C5.67,15.71 5.67,13.58 7,12.26L8.35,10.9L8.34,11.5C8.33,12 8.41,12.5 8.57,12.94L8.62,13.09L8.22,13.5C7.91,13.8 7.74,14.21 7.74,14.64C7.74,15.07 7.91,15.47 8.22,15.78C8.83,16.4 9.89,16.4 10.5,15.78L12.7,13.59C13,13.28 13.18,12.87 13.18,12.44C13.18,12 13,11.61 12.7,11.3C12.53,11.14 12.44,10.92 12.44,10.68C12.44,10.45 12.53,10.23 12.7,10.06C13.03,9.73 13.61,9.74 13.94,10.06M18,9.36C18,10.26 17.65,11.1 17,11.74L15.66,13.1V12.5C15.67,12 15.59,11.5 15.43,11.06L15.38,10.92L15.78,10.5C16.09,10.2 16.26,9.79 16.26,9.36C16.26,8.93 16.09,8.53 15.78,8.22C15.17,7.6 14.1,7.61 13.5,8.22L11.3,10.42C11,10.72 10.82,11.13 10.82,11.56C10.82,12 11,12.39 11.3,12.7C11.47,12.86 11.56,13.08 11.56,13.32C11.56,13.56 11.47,13.78 11.3,13.94C11.13,14.11 10.91,14.19 10.68,14.19C10.46,14.19 10.23,14.11 10.06,13.94C8.75,12.63 8.75,10.5 10.06,9.19L12.27,7C13.58,5.67 15.71,5.68 17,7C17.65,7.62 18,8.46 18,9.36Z" /></svg>
                                    </button>
                                    <button class="usersButton iconButton mdToolbarButton" type="button" data-md-action="image" title="Image" aria-label="Image">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M13 19C13 19.7 13.13 20.37 13.35 21H5C3.9 21 3 20.11 3 19V5C3 3.9 3.9 3 5 3H19C20.11 3 21 3.9 21 5V13.35C20.37 13.13 19.7 13 19 13V5H5V19H13M13.96 12.29L11.21 15.83L9.25 13.47L6.5 17H13.35C13.75 15.88 14.47 14.91 15.4 14.21L13.96 12.29M20 18V15H18V18H15V20H18V23H20V20H23V18H20Z" /></svg>
                                    </button>
                                    <button class="usersButton iconButton mdToolbarButton mdTextIcon" type="button" data-md-action="linebreak" title="Line break" aria-label="Line break">&lt;br&gt;</button>
                                    <button class="usersButton iconButton mdToolbarButton mdTextIcon" type="button" data-md-action="hr" title="Horizontal rule" aria-label="Horizontal rule">&lt;hr&gt;</button>
                                </div>
                                <div class="markdownToolbarPreview">
                                    <button class="usersButton iconButton mdToolbarButton mdPreviewButton" type="button" data-md-action="preview" title="Preview" aria-label="Preview" aria-pressed="false">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12,9A3,3 0 0,0 9,12A3,3 0 0,0 12,15A3,3 0 0,0 15,12A3,3 0 0,0 12,9M12,17A5,5 0 0,1 7,12A5,5 0 0,1 12,7A5,5 0 0,1 17,12A5,5 0 0,1 12,17M12,4.5C7,4.5 2.73,7.61 1,12C2.73,16.39 7,19.5 12,19.5C17,19.5 21.27,16.39 23,12C21.27,7.61 17,4.5 12,4.5Z" /></svg>
                                    </button>
                                </div>
                            </div>
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
        <label class="eventListToggle">
            <input type="checkbox" class="eventShowPast" <?php echo $showPast ? 'checked' : ''; ?>>
            Show past events on the site
        </label>
        <button class="eventAddButton" type="button">Add Event</button>
    </div>

    <textarea class="eventListPayload" name="pane[<?php echo htmlspecialchars($paneId); ?>][events]" aria-label="<?php echo htmlspecialchars($paneName); ?> events" hidden></textarea>
</div>
