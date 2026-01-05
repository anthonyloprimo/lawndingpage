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
    echo '<style>
    .eventListPane {
        display: flex;
        flex-direction: column;
        height: 100%;
    }
    .eventListScroll {
        flex: 1 1 auto;
        overflow: auto;
        display: flex;
        flex-direction: column;
        gap: 12px;
        padding-right: 4px;
    }
    .eventListControls {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-top: 12px;
    }
    .eventListToggle {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .eventList {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    .eventEmpty {
        opacity: 0.75;
        font-size: 14px;
    }
    .eventCard {
        border-radius: 8px;
        border: 1px solid #FFFFFF22;
        background: #00000033;
        padding: 12px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .eventCardActions {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .eventNameRow,
    .eventTimeRow,
    .eventAddressRow {
        display: grid;
        gap: 10px;
        align-items: center;
    }
    .eventNameRow {
        grid-template-columns: 1fr auto;
    }
    .eventTimeRow {
        grid-template-columns: 60px minmax(0, 1fr) 50px minmax(0, 1fr) minmax(160px, 1fr);
    }
    .eventTimeGroup {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 8px;
        align-items: center;
    }
    .eventFieldTitle {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        opacity: 0.8;
    }
    .eventNameLabel,
    .eventDescriptionLabel {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .eventTimeZone {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .eventCard input,
    .eventCard textarea {
        padding: 6px 8px;
        border-radius: 4px;
        border: 1px solid #FFFFFF33;
        background: #00000033;
        color: inherit;
        font-family: inherit;
    }

    .eventCard input[type="date"]::-webkit-calendar-picker-indicator,
    .eventCard input[type="time"]::-webkit-calendar-picker-indicator {
        filter: invert(1);
        opacity: 0.85;
    }
    .eventCard textarea {
        resize: vertical;
        min-height: 80px;
    }
    .eventValidation {
        font-size: 12px;
        color: #ffb3b3;
        min-height: 16px;
    }
    .eventAddButton {
        padding: 6px 10px;
        border-radius: 6px;
        border: 1px solid #FFFFFF44;
        background: #00000055;
        color: inherit;
        cursor: pointer;
        text-decoration: none;
    }
    .eventAddButton:hover {
        background: #77777733;
        border: 1px solid #FFFFFFBB;
    }
    .eventAddButton:active {
        background: #00000077;
        border: 1px solid #FFFFFF55;
    }
    </style>';
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
                    <div class="eventTimeRow">
                        <div class="eventFieldTitle">Start</div>
                        <div class="eventTimeGroup">
                            <input type="date" class="eventStartDateInput" value="<?php echo htmlspecialchars($startDate); ?>" aria-label="Start date">
                            <input type="time" class="eventStartTimeInput" value="<?php echo htmlspecialchars($startTime); ?>" aria-label="Start time">
                        </div>
                        <div class="eventFieldTitle">End</div>
                        <div class="eventTimeGroup">
                            <input type="date" class="eventEndDateInput" value="<?php echo htmlspecialchars($endDate); ?>" aria-label="End date">
                            <input type="time" class="eventEndTimeInput" value="<?php echo htmlspecialchars($endTime); ?>" aria-label="End time">
                        </div>
                        <div class="eventTimeZone">
                            <span class="eventFieldTitle">Time Zone</span>
                            <input type="text" class="eventTimezoneInput" value="<?php echo htmlspecialchars($timeZone); ?>" placeholder="America/New_York" aria-label="Time zone">
                        </div>
                    </div>
                    <div class="eventAddressRow">
                        <div class="eventFieldTitle">Address</div>
                        <input type="text" class="eventAddressInput" value="<?php echo htmlspecialchars($address); ?>" placeholder="123 Main St, City, State" aria-label="Address">
                    </div>
                    <label class="eventDescriptionLabel">
                        <span class="eventFieldTitle">Description</span>
                        <textarea class="eventDescriptionInput" rows="4" placeholder="Details, host, venue, etc."><?php echo htmlspecialchars($description); ?></textarea>
                    </label>
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
