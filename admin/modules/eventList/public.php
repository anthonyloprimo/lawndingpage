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
        . htmlspecialchars(lawnding_versioned_url($styleUrl), ENT_QUOTES, 'UTF-8')
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
