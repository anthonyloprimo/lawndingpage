<?php
// Handles saving config changes (links, header, backgrounds, markdown, and uploads).

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$baseDir = dirname(__DIR__, 1); // res/scr -> res
$rootDir = dirname($baseDir);    // project root

$headerPath = $rootDir . '/res/data/header.json';
$linksPath = $rootDir . '/res/data/links.json';
$aboutPath = $rootDir . '/res/data/about.md';
$rulesPath = $rootDir . '/res/data/rules.md';
$faqPath = $rootDir . '/res/data/faq.md';
$imgDir = $rootDir . '/res/img/';

// Load existing header data with defaults
$headerData = [
    'logo' => 'res/img/logo.jpg',
    'title' => 'Long Island Furs',
    'subtitle' => 'A Long Island furry community encompassing Queens, Nassau County, and Suffolk County.  And Staten Island, but we do not talk about that.',
    'backgrounds' => ['res/img/bg.jpg']
];
if (is_readable($headerPath)) {
    $decoded = json_decode(file_get_contents($headerPath), true);
    if (is_array($decoded)) {
        $headerData = array_merge($headerData, $decoded);
    }
}

function respond($payload, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

// Validate and save an uploaded image; returns relative path.
function save_image($fileArray, $destName) {
    global $imgDir;
    if (!isset($fileArray['tmp_name']) || !is_uploaded_file($fileArray['tmp_name'])) {
        return null;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $fileArray['tmp_name']);
    finfo_close($finfo);
    if (strpos($mime, 'image/') !== 0) {
        return null;
    }
    $ext = strtolower(pathinfo($fileArray['name'], PATHINFO_EXTENSION));
    $safeName = $destName ? $destName . ($ext ? '.' . $ext : '') : basename($fileArray['name']);
    $targetPath = $imgDir . $safeName;
    if (!move_uploaded_file($fileArray['tmp_name'], $targetPath)) {
        return null;
    }
    return 'res/img/' . $safeName;
}

// Gather POST data
$siteTitle = $_POST['siteTitle'] ?? '';
$siteSubtitle = $_POST['siteSubtitle'] ?? '';
$linksJson = $_POST['links'] ?? '[]';
$backgroundsJson = $_POST['backgrounds'] ?? '[]';
$aboutMarkdown = $_POST['aboutMarkdown'] ?? '';
$rulesMarkdown = $_POST['rulesMarkdown'] ?? '';
$faqMarkdown = $_POST['faqMarkdown'] ?? '';

$linksData = json_decode($linksJson, true);
$backgroundsData = json_decode($backgroundsJson, true);

if (!is_array($linksData)) {
    respond(['error' => 'Invalid links payload'], 400);
}
if (!is_array($backgroundsData)) {
    respond(['error' => 'Invalid backgrounds payload'], 400);
}

// Handle logo upload
if (isset($_FILES['logoFile'])) {
    $savedLogo = save_image($_FILES['logoFile'], 'logo');
    if ($savedLogo) {
        $headerData['logo'] = $savedLogo;
    }
}

// Handle backgrounds
$newBackgrounds = [];
foreach ($backgroundsData as $bg) {
    if (!is_array($bg)) {
        continue;
    }
    $author = $bg['author'] ?? '';
    $existingUrl = $bg['url'] ?? '';
    $fileKey = $bg['fileKey'] ?? null;

    if ($fileKey && isset($_FILES[$fileKey])) {
        $saved = save_image($_FILES[$fileKey], null);
        if ($saved) {
            $newBackgrounds[] = [
                'url' => $saved,
                'author' => $author,
            ];
        }
    } elseif ($existingUrl) {
        $newBackgrounds[] = [
            'url' => $existingUrl,
            'author' => $author,
        ];
    }
}

// Handle links
$linksOut = [];
foreach ($linksData as $link) {
    if (!is_array($link)) {
        continue;
    }
    $type = $link['type'] ?? '';
    if ($type === 'separator') {
        $linksOut[] = ['type' => 'separator'];
    } elseif ($type === 'link') {
        $linksOut[] = [
            'type' => 'link',
            'id' => $link['id'] ?? '',
            'href' => $link['href'] ?? '',
            'text' => $link['text'] ?? '',
            'title' => $link['title'] ?? '',
            'fullWidth' => !empty($link['fullWidth']),
            'cta' => !empty($link['cta']),
        ];
    }
}

// Write markdown files
file_put_contents($aboutPath, $aboutMarkdown);
file_put_contents($rulesPath, $rulesMarkdown);
file_put_contents($faqPath, $faqMarkdown);

// Update header data
$headerData['title'] = $siteTitle;
$headerData['subtitle'] = $siteSubtitle;
$headerData['backgrounds'] = $newBackgrounds;

file_put_contents($headerPath, json_encode($headerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($linksPath, json_encode($linksOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

respond(['status' => 'ok']);
