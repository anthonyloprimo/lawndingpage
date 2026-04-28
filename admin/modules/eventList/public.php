<?php
// Module: Event List (public)
// Renders happening now, upcoming, and past events from the pane JSON file.

if (!isset($pane) || !is_array($pane)) {
    return;
}

// Inject public styles once per request.
static $eventListPublicStylesInjected = false;
if (!$eventListPublicStylesInjected) {
    $eventListPublicStylesInjected = true;
    $styleUrl = function_exists('lawnding_asset_url')
        ? lawnding_asset_url('res/scr/module-style.php?module=eventList')
        : '/res/scr/module-style.php?module=eventList';
    echo '<link rel="stylesheet" href="'
        . htmlspecialchars($styleUrl, ENT_QUOTES, 'UTF-8')
        . '">';
}

// Pane metadata used for IDs and data file resolution.
$paneId = isset($pane['id']) ? (string) $pane['id'] : '';
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
$showPast = !empty($decoded['showPast']);
$showCalendar = !empty($decoded['showCalendar']);
$calendarDefault = !array_key_exists('calendarDefault', $decoded) || !empty($decoded['calendarDefault']);
$events = $decoded['events'] ?? [];
if (!is_array($events)) {
    $events = [];
}

// Add parsed HTML descriptions for markdown-safe rendering.
if (!empty($events)) {
    if (!class_exists('Parsedown')) {
        $parsedownPath = function_exists('lawnding_public_path')
            ? lawnding_public_path('res/scr/Parsedown.php')
            : __DIR__ . '/../../public/res/scr/Parsedown.php';
        require_once $parsedownPath;
    }
    if (!function_exists('lawnding_markdown_gate_apply')) {
        $gatingPath = function_exists('lawnding_public_path')
            ? lawnding_public_path('res/scr/markdown-gating.php')
            : __DIR__ . '/../../../public/res/scr/markdown-gating.php';
        require_once $gatingPath;
    }
    $clearance = isset($markdownGateClearance) && is_string($markdownGateClearance)
        ? $markdownGateClearance
        : 'none';
    $parser = new Parsedown();
    foreach ($events as &$event) {
        if (!is_array($event)) {
            continue;
        }
        $desc = $event['description'] ?? '';
        if (is_string($desc) && $desc !== '') {
            $gated = lawnding_markdown_gate_apply($desc, $clearance, false);
            if (empty($gated['ok'])) {
                $eventId = isset($event['id']) ? (string) $event['id'] : '(unknown)';
                error_log('eventList/public.php: invalid content-gating syntax in event ' . $eventId);
            }
            $event['descriptionHtml'] = $parser->text((string) ($gated['markdown'] ?? ''));
        }
    }
    unset($event);
}

// Output data for front-end rendering/sorting.
$eventsJson = json_encode([
    'showPast' => $showPast,
    'showCalendar' => $showCalendar,
    'calendarDefault' => $calendarDefault,
    'events' => $events,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<div class="pane glassConvex<?php echo $showCalendar ? ' eventListCalendarEnabled' : ''; ?>" id="<?php echo htmlspecialchars($paneId); ?>" data-pane-type="eventList">
    <div class="eventListPublic" data-pane-id="<?php echo htmlspecialchars($paneId); ?>">
        <?php
            // Tab/tabpanel ids must be unique per pane on the page.
            $eventTabIdCalendar  = 'eventTab-calendar-' . $paneId;
            $eventTabIdEvents    = 'eventTab-events-' . $paneId;
            $eventPanelIdCalendar = 'eventPanel-calendar-' . $paneId;
            $eventPanelIdEvents   = 'eventPanel-events-' . $paneId;
        ?>
        <?php if ($showCalendar): ?>
            <div class="eventViewTabs" role="tablist" aria-label="Event views">
                <button class="eventViewTab" type="button" id="<?php echo htmlspecialchars($eventTabIdCalendar); ?>" data-event-view="calendar" role="tab" aria-selected="false" aria-controls="<?php echo htmlspecialchars($eventPanelIdCalendar); ?>">CALENDAR</button>
                <button class="eventViewTab" type="button" id="<?php echo htmlspecialchars($eventTabIdEvents); ?>" data-event-view="events" role="tab" aria-selected="false" aria-controls="<?php echo htmlspecialchars($eventPanelIdEvents); ?>">EVENTS</button>
            </div>
        <?php endif; ?>
        <div class="eventViewShell<?php echo $showCalendar ? ' hasCalendarTabs' : ''; ?>">
            <?php if ($showCalendar): ?>
                <div class="eventCalendarView" id="<?php echo htmlspecialchars($eventPanelIdCalendar); ?>" data-event-view-panel="calendar" role="tabpanel" aria-labelledby="<?php echo htmlspecialchars($eventTabIdCalendar); ?>" tabindex="0">
                    <table class="eventCalendarTable" aria-label="Event calendar">
                        <thead>
                            <tr class="eventCalendarNavRow">
                                <th colspan="1">
                                    <button class="eventCalendarNavButton eventCalendarPrev" type="button" aria-label="Previous month">&lt;</button>
                                </th>
                                <th colspan="5" class="eventCalendarMonthLabel">
                                    <span class="eventCalendarMonthText"></span>
                                    <button class="eventCalendarTodayButton hidden" type="button">Today</button>
                                </th>
                                <th colspan="1">
                                    <button class="eventCalendarNavButton eventCalendarNext" type="button" aria-label="Next month">&gt;</button>
                                </th>
                            </tr>
                            <tr class="eventCalendarDayLabels">
                                <th>SUN</th>
                                <th>MON</th>
                                <th>TUE</th>
                                <th>WED</th>
                                <th>THU</th>
                                <th>FRI</th>
                                <th>SAT</th>
                            </tr>
                        </thead>
                        <tbody class="eventCalendarBody"></tbody>
                    </table>
                </div>
            <?php endif; ?>
            <div class="eventEventsView" <?php if ($showCalendar): ?>id="<?php echo htmlspecialchars($eventPanelIdEvents); ?>" aria-labelledby="<?php echo htmlspecialchars($eventTabIdEvents); ?>" tabindex="0" <?php endif; ?>data-event-view-panel="events" role="tabpanel">
                <div class="eventSection">
                    <h3>HAPPENING NOW</h3>
                    <div class="eventSectionBody eventHappening"></div>
                </div>
                <div class="eventSection eventSplit">
                    <div class="eventColumn eventUpcoming">
                        <h3>UPCOMING EVENTS</h3>
                        <div class="eventSectionBody"></div>
                    </div>
                    <div class="eventColumn eventPast">
                        <h3>PAST EVENTS</h3>
                        <div class="eventSectionBody"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script type="application/json" class="eventListData"><?php echo $eventsJson; ?></script>
</div>

<div class="eventModalOverlay hidden" id="eventModalOverlay">
    <div class="eventModal" role="dialog" aria-modal="true" aria-labelledby="eventModalTitle">
        <div class="eventModalHeader">
            <div class="eventModalTitle" id="eventModalTitle"></div>
            <button class="eventModalClose" type="button" id="eventModalClose">Close</button>
        </div>
        <div class="eventModalMeta" id="eventModalMeta"></div>
        <div class="eventModalMeta" id="eventModalAddress"></div>
        <div class="eventModalDescription" id="eventModalDescription"></div>
        <div class="eventModalActions">
            <div class="eventCalendarMenu hidden" id="eventModalCalendarMenu">
                <button
                    class="eventCalendarButton eventCalendarToggle"
                    type="button"
                    id="eventModalCalendarToggle"
                    aria-haspopup="true"
                    aria-expanded="false"
                    disabled
                >
                    Save to Calendar
                </button>
                <div class="eventCalendarDropdown hidden" id="eventModalCalendarDropdown" role="menu" aria-label="Calendar options">
                    <button class="eventCalendarOption" type="button" data-calendar-provider="ics" role="menuitem">Download ICS File</button>
                    <button class="eventCalendarOption" type="button" data-calendar-provider="google" role="menuitem">Add to Google Calendar</button>
                    <button class="eventCalendarOption" type="button" data-calendar-provider="outlook" role="menuitem">Add to Outlook Calendar</button>
                    <button class="eventCalendarOption" type="button" data-calendar-provider="m365" role="menuitem">Add to Microsoft 365 Calendar</button>
                    <button class="eventCalendarOption" type="button" data-calendar-provider="yahoo" role="menuitem">Add to Yahoo! Calendar</button>
                    <button class="eventCalendarOption" type="button" data-calendar-provider="aol" role="menuitem">Add to AOL Calendar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="eventModalOverlay hidden" id="eventCalendarDayModalOverlay">
    <div class="eventModal eventCalendarDayModal" role="dialog" aria-modal="true" aria-labelledby="eventCalendarDayModalTitle">
        <div class="eventModalHeader">
            <div class="eventModalTitle" id="eventCalendarDayModalTitle"></div>
            <button class="eventModalClose" type="button" id="eventCalendarDayModalClose">Close</button>
        </div>
        <div class="eventCalendarDayModalBody" id="eventCalendarDayModalBody"></div>
    </div>
</div>

<div class="eventCalendarToast hidden" id="eventCalendarToast" role="status" aria-live="polite">
    No events scheduled for this day!
</div>
