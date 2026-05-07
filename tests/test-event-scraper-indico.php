<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../admin/plugins/eventScraper/extractors/indico.php';

// Indico's adapter doesn't carry a uidFromUrlPattern (UID comes from
// top-level `id`), so the second arg is unused but the contract requires it.
$adapter = ['id' => 'nyfurs'];

// ---- Empty / malformed inputs ----

test_assert(
    event_scraper_extract_indico('', $adapter) === [],
    'empty body returns empty array'
);

test_assert(
    event_scraper_extract_indico('not json at all', $adapter) === [],
    'invalid JSON returns empty array'
);

test_assert(
    event_scraper_extract_indico('{"count":0}', $adapter) === [],
    'JSON without results[] returns empty array'
);

test_assert(
    event_scraper_extract_indico('{"results":[]}', $adapter) === [],
    'JSON with empty results[] returns empty array'
);

test_assert(
    event_scraper_extract_indico('{"results":"not-an-array"}', $adapter) === [],
    'results that is not an array returns empty array'
);

// ---- Full valid event ----

$fullEvent = [
    'count'   => 1,
    'results' => [[
        '_type'       => 'Conference',
        'id'          => '89',
        'title'       => 'NYFurs TF2 Night',
        'description' => '<p>Join us for <strong>fragging</strong>!</p>',
        'startDate'   => ['date' => '2026-03-30', 'time' => '19:30:00', 'tz' => 'America/New_York'],
        'endDate'     => ['date' => '2026-03-30', 'time' => '23:59:00', 'tz' => 'America/New_York'],
        'location'    => 'Discord Server',
        'address'     => '',
        'url'         => 'https://events.nyfurs.org/event/89/',
        'material'    => [],
    ]],
];
$result = event_scraper_extract_indico(json_encode($fullEvent), $adapter);
test_assert(count($result) === 1, 'full Conference produces one record');
test_assert($result[0]['sourceUid'] === '89', 'sourceUid taken from top-level id');
test_assert($result[0]['name'] === 'NYFurs TF2 Night', 'name taken from title');
test_assert($result[0]['startDate'] === '2026-03-30', 'startDate.date extracted');
test_assert($result[0]['endDate'] === '2026-03-30', 'endDate.date extracted');
test_assert($result[0]['location'] === 'Discord Server', 'location-only fills location');
test_assert($result[0]['description'] === 'Join us for fragging!', 'description HTML stripped to plaintext');
test_assert($result[0]['url'] === 'https://events.nyfurs.org/event/89/', 'url passed through');
test_assert($result[0]['registrationUrl'] === '', 'registrationUrl always empty (Indico has no field)');
test_assert($result[0]['image'] === '', 'no material -> empty image');

// ---- Required-field drops ----

$mkResults = function (array $event): string {
    return json_encode(['results' => [$event]]);
};

$baseEvent = [
    'id' => '1', 'title' => 'X',
    'startDate' => ['date' => '2026-01-01'],
    'url' => 'https://events.nyfurs.org/event/1/',
];

$missingId = $baseEvent; unset($missingId['id']);
test_assert(event_scraper_extract_indico($mkResults($missingId), $adapter) === [], 'event without id dropped');

$emptyId = $baseEvent; $emptyId['id'] = '';
test_assert(event_scraper_extract_indico($mkResults($emptyId), $adapter) === [], 'event with empty id dropped');

$missingTitle = $baseEvent; unset($missingTitle['title']);
test_assert(event_scraper_extract_indico($mkResults($missingTitle), $adapter) === [], 'event without title dropped');

$missingStart = $baseEvent; unset($missingStart['startDate']);
test_assert(event_scraper_extract_indico($mkResults($missingStart), $adapter) === [], 'event without startDate dropped');

$emptyStartDate = $baseEvent; $emptyStartDate['startDate'] = ['date' => '', 'time' => ''];
test_assert(event_scraper_extract_indico($mkResults($emptyStartDate), $adapter) === [], 'event with empty startDate.date dropped');

$missingUrl = $baseEvent; unset($missingUrl['url']);
test_assert(event_scraper_extract_indico($mkResults($missingUrl), $adapter) === [], 'event without url dropped');

// ---- Defensive id handling: future int id ----

$intId = $baseEvent; $intId['id'] = 89;
$result = event_scraper_extract_indico($mkResults($intId), $adapter);
test_assert(count($result) === 1, 'numeric id accepted (Indico might switch to int someday)');
test_assert($result[0]['sourceUid'] === '89', 'numeric id cast to string');

// ---- Non-array entries inside results[] are skipped ----

$mixed = json_encode(['results' => [
    'a string slipped in',
    null,
    $baseEvent,
]]);
$result = event_scraper_extract_indico($mixed, $adapter);
test_assert(count($result) === 1, 'non-array entries in results[] silently skipped');

// ---- Date variations ----

test_assert(event_scraper_indico_pick_date(['date' => '2026-05-07', 'time' => 't']) === '2026-05-07', 'array shape extracts date');
test_assert(event_scraper_indico_pick_date('2026-05-07') === '2026-05-07', 'string fallback accepted');
test_assert(event_scraper_indico_pick_date(null) === '', 'null date returns empty string');
test_assert(event_scraper_indico_pick_date(['time' => '12:00']) === '', 'array without date key returns empty string');
test_assert(event_scraper_indico_pick_date(['date' => '  2026-05-07  ']) === '2026-05-07', 'date trimmed');

// ---- Optional endDate ----

$noEnd = ['results' => [$baseEvent]];
$result = event_scraper_extract_indico(json_encode($noEnd), $adapter);
test_assert($result[0]['endDate'] === '', 'missing endDate normalizes to empty string');

// ---- Location formatter shapes ----

test_assert(event_scraper_indico_format_location('Hotel', 'NY') === 'Hotel, NY', 'both filled -> "<location>, <address>"');
test_assert(event_scraper_indico_format_location('Discord', '') === 'Discord', 'empty address -> just location');
test_assert(event_scraper_indico_format_location('', '123 Main St') === '123 Main St', 'empty location -> just address');
test_assert(event_scraper_indico_format_location('', '') === '', 'both empty -> empty string');
test_assert(event_scraper_indico_format_location(null, null) === '', 'non-string inputs return empty');
test_assert(event_scraper_indico_format_location('  Hotel  ', '  ') === 'Hotel', 'whitespace-only address dropped');

// ---- HTML-to-text conversion ----

test_assert(event_scraper_indico_html_to_text('') === '', 'empty input returns empty string');
test_assert(event_scraper_indico_html_to_text('plain text') === 'plain text', 'no HTML passes through');
test_assert(event_scraper_indico_html_to_text('<p>One.</p><p>Two.</p>') === "One.\nTwo.", '<p> blocks become newline-separated');
test_assert(event_scraper_indico_html_to_text('Line 1<br>Line 2') === "Line 1\nLine 2", '<br> becomes newline');
test_assert(event_scraper_indico_html_to_text('Line 1<br />Line 2') === "Line 1\nLine 2", 'self-closing <br/> becomes newline');
test_assert(event_scraper_indico_html_to_text('<strong>bold</strong>') === 'bold', 'inline tags stripped, content kept');
test_assert(event_scraper_indico_html_to_text('Caf&eacute;') === 'Café', 'named entities decoded');
test_assert(event_scraper_indico_html_to_text('A &amp; B') === 'A & B', 'amp entity decoded');
test_assert(event_scraper_indico_html_to_text('&nbsp;hello&nbsp;') === 'hello', 'nbsp decoded and trimmed');
test_assert(event_scraper_indico_html_to_text('<p>Foo</p><p></p><p></p><p>Bar</p>') === "Foo\n\nBar", 'multiple empty paragraphs collapse to one blank line');
test_assert(event_scraper_indico_html_to_text('  hello   world  ') === 'hello world', 'horizontal whitespace runs collapsed');
test_assert(event_scraper_indico_html_to_text('<p><img src="/poster.png">Hello</p>') === 'Hello', '<img> tag stripped, surrounding text kept');
test_assert(event_scraper_indico_html_to_text('<h2>Heading</h2>Body') === "Heading\nBody", '<h*> closes as paragraph break');
test_assert(event_scraper_indico_html_to_text('A<br><br>B') === "A\n\nB", 'two <br>s become blank-line separator');

// ---- Image-pick variations ----

test_assert(event_scraper_indico_pick_image(null) === '', 'null material returns empty string');
test_assert(event_scraper_indico_pick_image([]) === '', 'empty material returns empty string');

$onePoster = [[
    'resources' => [['name' => 'poster.png', 'url' => 'https://x/poster.png']],
]];
test_assert(event_scraper_indico_pick_image($onePoster) === 'https://x/poster.png', 'single image in single folder picked');

$pdfFirst = [[
    'resources' => [
        ['name' => 'agenda.pdf', 'url' => 'https://x/agenda.pdf'],
        ['name' => 'poster.jpg', 'url' => 'https://x/poster.jpg'],
    ],
]];
test_assert(event_scraper_indico_pick_image($pdfFirst) === 'https://x/poster.jpg', 'non-image resources skipped, image found');

$multiFolder = [
    ['resources' => [['name' => 'doc.txt', 'url' => 'https://x/doc.txt']]],
    ['resources' => [['name' => 'p.webp', 'url' => 'https://x/p.webp']]],
];
test_assert(event_scraper_indico_pick_image($multiFolder) === 'https://x/p.webp', 'walks across multiple folders');

$queryString = [['resources' => [['name' => 'p.png?v=1', 'url' => 'https://x/p.png?v=1']]]];
test_assert(event_scraper_indico_pick_image($queryString) === 'https://x/p.png?v=1', 'image URL with query string accepted');

$caseInsensitive = [['resources' => [['name' => 'P.JPG', 'url' => 'https://x/P.JPG']]]];
test_assert(event_scraper_indico_pick_image($caseInsensitive) === 'https://x/P.JPG', 'image extension match is case-insensitive');

$noImages = [['resources' => [
    ['name' => 'a.txt', 'url' => 'https://x/a.txt'],
    ['name' => 'b.pdf', 'url' => 'https://x/b.pdf'],
]]];
test_assert(event_scraper_indico_pick_image($noImages) === '', 'no image-extension resource -> empty string');

$malformed = [['resources' => 'not an array']];
test_assert(event_scraper_indico_pick_image($malformed) === '', 'malformed resources field tolerated');

// ---- Multi-event integration ----

$twoEvents = ['results' => [
    ['id' => '1', 'title' => 'A', 'startDate' => ['date' => '2026-01-01'],
     'url' => 'https://events.nyfurs.org/event/1/'],
    ['id' => '2', 'title' => 'B', 'startDate' => ['date' => '2026-02-02'],
     'url' => 'https://events.nyfurs.org/event/2/'],
]];
$result = event_scraper_extract_indico(json_encode($twoEvents), $adapter);
test_assert(count($result) === 2, 'two valid events both extracted');
test_assert($result[0]['sourceUid'] === '1' && $result[1]['sourceUid'] === '2', 'order preserved');

// ---- Mixed valid + invalid in same response ----

$mixed = ['results' => [
    ['id' => '1', 'title' => 'Valid', 'startDate' => ['date' => '2026-01-01'],
     'url' => 'https://events.nyfurs.org/event/1/'],
    ['id' => '', 'title' => 'No id', 'startDate' => ['date' => '2026-01-01'],
     'url' => 'https://events.nyfurs.org/event/2/'],
    ['id' => '3', 'title' => 'Also valid', 'startDate' => ['date' => '2026-01-01'],
     'url' => 'https://events.nyfurs.org/event/3/'],
]];
$result = event_scraper_extract_indico(json_encode($mixed), $adapter);
test_assert(count($result) === 2, 'valid events kept, invalid dropped');
test_assert($result[0]['sourceUid'] === '1' && $result[1]['sourceUid'] === '3', 'invalid skipped without disrupting array order');

