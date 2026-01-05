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
    echo '<style>
    .eventListPublic {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }
    .eventSection {
        border-top: 1px solid #FFFFFF22;
        padding-top: 12px;
    }
    .eventSplit {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }
    .eventSplit.eventSplitSingle {
        grid-template-columns: 1fr;
    }
    .eventColumn.hidden {
        display: none;
    }
    .eventSectionBody {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .eventItem {
        padding: 10px;
        border-radius: 6px;
        border: 1px solid #FFFFFF22;
        background: #00000033;
        display: flex;
        flex-direction: column;
        gap: 6px;
        cursor: pointer;
    }
    .eventItemTitle {
        font-weight: 600;
    }
    .eventItemMeta {
        font-size: 12px;
        opacity: 0.8;
    }
    .eventCalendarButton {
        align-self: flex-start;
        padding: 6px 10px;
        border-radius: 6px;
        border: 1px solid #FFFFFF44;
        background: #00000055;
        color: inherit;
        cursor: pointer;
        text-decoration: none;
    }
    .eventCalendarButton[disabled] {
        opacity: 0.6;
        cursor: not-allowed;
    }
    @media (max-width: 860px) {
        .eventSplit {
            grid-template-columns: 1fr;
        }
    }

    .eventModalOverlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.6);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
    }

    .eventModal {
        max-width: 640px;
        width: 92%;
        border-radius: 12px;
        border: 1px solid #FFFFFF22;
        background: #0f0f0f;
        padding: 18px;
        color: inherit;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .eventModalHeader {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    .eventModalTitle {
        font-size: 18px;
        font-weight: 600;
    }

    .eventModalMeta {
        font-size: 13px;
        opacity: 0.8;
    }

    .eventModalDescription {
        font-size: 14px;
        line-height: 1.4;
    }

    .eventModalActions {
        display: flex;
        align-items: center;
        gap: 8px;
        justify-content: flex-end;
    }

    .eventModalClose {
        padding: 6px 10px;
        border-radius: 6px;
        border: 1px solid #FFFFFF44;
        background: #00000055;
        color: inherit;
        cursor: pointer;
    }
    </style>';
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
    $parser = new Parsedown();
    foreach ($events as &$event) {
        if (!is_array($event)) {
            continue;
        }
        $desc = $event['description'] ?? '';
        if (is_string($desc) && $desc !== '') {
            $event['descriptionHtml'] = $parser->text($desc);
        }
    }
    unset($event);
}

// Output data for front-end rendering/sorting.
$eventsJson = json_encode([
    'showPast' => $showPast,
    'events' => $events,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<div class="pane glassConvex" id="<?php echo htmlspecialchars($paneId); ?>" data-pane-type="eventList">
    <div class="eventListPublic" data-pane-id="<?php echo htmlspecialchars($paneId); ?>">
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
            <button class="eventCalendarButton" type="button" id="eventModalCalendar" disabled>Save to Calendar</button>
        </div>
    </div>
</div>
