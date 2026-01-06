<?php
require_once __DIR__ . '/../../../lp-bootstrap.php';

// Generate a single-event iCal file from the Event List module data.

function respond_status(int $code): void {
    http_response_code($code);
    exit;
}

function sanitize_id($value): string {
    if (!is_string($value)) {
        return '';
    }
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }
    if (preg_match('/[^a-zA-Z0-9_-]/', $trimmed)) {
        return '';
    }
    return $trimmed;
}

function ics_escape(string $value): string {
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace(["\r\n", "\n", "\r"], '\\n', $value);
    $value = str_replace(',', '\\,', $value);
    $value = str_replace(';', '\\;', $value);
    return $value;
}

function ics_fold(string $line): string {
    $limit = 75;
    $result = '';
    $length = strlen($line);
    while ($length > $limit) {
        $result .= substr($line, 0, $limit) . "\r\n ";
        $line = substr($line, $limit);
        $length = strlen($line);
    }
    return $result . $line;
}

function build_datetime(string $date, string $time, ?DateTimeZone $tz): ?DateTimeImmutable {
    if ($date === '' || $time === '') {
        return null;
    }
    try {
        return new DateTimeImmutable($date . 'T' . $time . ':00', $tz ?: null);
    } catch (Exception $e) {
        return null;
    }
}

$paneId = sanitize_id($_GET['pane'] ?? '');
$eventId = sanitize_id($_GET['event'] ?? '');
if ($paneId === '' || $eventId === '') {
    respond_status(400);
}

$panesPath = function_exists('lawnding_data_path')
    ? lawnding_data_path('panes.json')
    : dirname(__DIR__, 2) . '/data/panes.json';
$panesRaw = is_readable($panesPath) ? file_get_contents($panesPath) : '';
$panesJson = $panesRaw !== '' ? json_decode($panesRaw, true) : null;
if (!is_array($panesJson)) {
    respond_status(404);
}

$panes = $panesJson['panes'] ?? [];
$pane = null;
if (is_array($panes)) {
    foreach ($panes as $entry) {
        if (is_array($entry) && ($entry['id'] ?? '') === $paneId) {
            $pane = $entry;
            break;
        }
    }
}
if (!$pane || ($pane['module'] ?? '') !== 'eventList') {
    respond_status(404);
}

$paneData = $pane['data'] ?? [];
$jsonFile = is_array($paneData) ? ($paneData['json'] ?? '') : '';
if (!is_string($jsonFile) || $jsonFile === '') {
    respond_status(404);
}

$eventsPath = function_exists('lawnding_data_path')
    ? lawnding_data_path($jsonFile)
    : dirname(__DIR__, 2) . '/data/' . $jsonFile;
$eventsRaw = is_readable($eventsPath) ? file_get_contents($eventsPath) : '';
$eventsJson = $eventsRaw !== '' ? json_decode($eventsRaw, true) : null;
if (!is_array($eventsJson)) {
    respond_status(404);
}

$events = $eventsJson['events'] ?? [];
if (!is_array($events)) {
    respond_status(404);
}

$event = null;
foreach ($events as $entry) {
    if (is_array($entry) && ($entry['id'] ?? '') === $eventId) {
        $event = $entry;
        break;
    }
}
if (!$event) {
    respond_status(404);
}

$name = is_string($event['name'] ?? null) ? trim($event['name']) : '';
$address = is_string($event['address'] ?? null) ? trim($event['address']) : '';
$description = is_string($event['description'] ?? null) ? trim($event['description']) : '';
$startDate = is_string($event['startDate'] ?? null) ? $event['startDate'] : (is_string($event['date'] ?? null) ? $event['date'] : '');
$startTime = is_string($event['startTime'] ?? null) ? $event['startTime'] : '';
$endDate = is_string($event['endDate'] ?? null) ? $event['endDate'] : '';
$endTime = is_string($event['endTime'] ?? null) ? $event['endTime'] : '';
$timeZoneName = is_string($event['timeZone'] ?? null) ? trim($event['timeZone']) : '';

if ($startDate === '' || $startTime === '') {
    respond_status(404);
}

if ($endTime !== '' && $endDate === '') {
    $endDate = $startDate;
}

$tz = null;
if ($timeZoneName !== '') {
    try {
        $tz = new DateTimeZone($timeZoneName);
    } catch (Exception $e) {
        $tz = null;
    }
}

$startDt = build_datetime($startDate, $startTime, $tz);
if (!$startDt) {
    respond_status(404);
}

$endDt = null;
if ($endDate !== '' && $endTime !== '') {
    $endDt = build_datetime($endDate, $endTime, $tz);
}
if (!$endDt) {
    $endDt = $startDt->modify('+1 hour');
}

$headerPath = function_exists('lawnding_data_path')
    ? lawnding_data_path('header.json')
    : dirname(__DIR__, 2) . '/data/header.json';
$headerRaw = is_readable($headerPath) ? file_get_contents($headerPath) : '';
$headerJson = $headerRaw !== '' ? json_decode($headerRaw, true) : [];
$communityName = is_array($headerJson) && !empty($headerJson['title']) ? $headerJson['title'] : 'LawndingPage';
$orgName = preg_replace('/[^A-Za-z0-9]/', '', (string) $communityName);
if ($orgName === '') {
    $orgName = 'LawndingPage';
}
$prodId = '-//' . $orgName . '//LawndingPage//EN';

$uidDate = $startDt->format('Ymd');
$uidName = preg_replace('/[^A-Za-z0-9]/', '', $name);
if ($uidName === '') {
    $uidName = $eventId;
}
$uid = $uidName . '@' . $orgName . '-' . $uidDate . '.lawndingpage';

$dtstamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Ymd\THis\Z');
$tzPrefix = $tz ? 'TZID=' . $timeZoneName . ':' : '';
$dtstart = $startDt->format('Ymd\THis');
$dtend = $endDt->format('Ymd\THis');

$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:' . $prodId,
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    ics_fold('UID:' . ics_escape($uid)),
    'DTSTAMP:' . $dtstamp,
    'DTSTART' . ($tzPrefix ? ';' . $tzPrefix : ':') . $dtstart,
    'DTEND' . ($tzPrefix ? ';' . $tzPrefix : ':') . $dtend,
    ics_fold('SUMMARY:' . ics_escape($name !== '' ? $name : 'Event')),
    $address !== '' ? ics_fold('LOCATION:' . ics_escape($address)) : null,
    $description !== '' ? ics_fold('DESCRIPTION:' . ics_escape($description)) : null,
    'END:VEVENT',
    'END:VCALENDAR',
];

$lines = array_values(array_filter($lines, function($line) {
    return $line !== null;
}));

$filenameBase = $eventId !== '' ? $eventId : $uidName;
$filenameBase = preg_replace('/[^A-Za-z0-9_-]/', '', $filenameBase);
if ($filenameBase === '') {
    $filenameBase = 'event';
}
$filename = $filenameBase . '.ics';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo implode("\r\n", $lines) . "\r\n";
