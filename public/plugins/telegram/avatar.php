<?php
// Session-gated proxy for the current Telegram user's profile photo.
//
// Fetches the Telegram-hosted photo once, caches the bytes under avatars/,
// serves from cache on subsequent hits. Falls back to default-avatar.svg on
// any error so the page never shows a broken image.
//
// Keeps avatars on our own origin so the public page's CSP (img-src 'self')
// stays unchanged, and blocks direct-URL avatar harvesting via the session
// check plus the avatars/.htaccess deny-all.

require_once __DIR__ . '/../../../lp-bootstrap.php';
lawnding_init_session();

function lawnding_tgprofile_serve_fallback(): void {
    $path = __DIR__ . '/default-avatar.svg';
    if (is_readable($path)) {
        header('Content-Type: image/svg+xml');
        header('Cache-Control: private, max-age=300');
        readfile($path);
    } else {
        http_response_code(404);
    }
    exit;
}

$tgUser = $_SESSION['tg_user'] ?? null;
if (!is_array($tgUser) || empty($tgUser['photo_url']) || !is_string($tgUser['photo_url'])) {
    lawnding_tgprofile_serve_fallback();
}

$photoUrl = (string) $tgUser['photo_url'];
$host = strtolower((string) (parse_url($photoUrl, PHP_URL_HOST) ?? ''));

// Telegram serves avatar URLs from a handful of hosts; log rejections so drift
// is visible in errors.txt rather than silently broken.
$allowedHosts = ['t.me', 'telegram.org', 'cdn.telegram.org', 'telesco.pe'];
$hostAllowed = false;
foreach ($allowedHosts as $allowed) {
    if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
        $hostAllowed = true;
        break;
    }
}
if (!$hostAllowed) {
    error_log('plugins/telegram/avatar.php: rejected photo_url host: ' . $host);
    lawnding_tgprofile_serve_fallback();
}

$cacheDir = __DIR__ . '/avatars';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

$cachePath = $cacheDir . '/' . hash('sha256', $photoUrl) . '.jpg';
$maxAgeSeconds = 7 * 86400;

if (!is_file($cachePath) || (time() - (int) @filemtime($cachePath)) > $maxAgeSeconds) {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 5,
            'user_agent' => 'LawndingPage-TelegramProfile/1.0',
            'follow_location' => 1,
            'max_redirects' => 3,
        ],
    ]);
    $bytes = @file_get_contents($photoUrl, false, $ctx);
    if (!is_string($bytes) || strlen($bytes) < 64) {
        error_log('plugins/telegram/avatar.php: fetch from ' . $host . ' failed (got ' . (is_string($bytes) ? strlen($bytes) . ' bytes' : gettype($bytes)) . ')');
        lawnding_tgprofile_serve_fallback();
    }
    $tmp = $cachePath . '.tmp';
    if (file_put_contents($tmp, $bytes, LOCK_EX) === false) {
        error_log('plugins/telegram/avatar.php: cache write failed at ' . $tmp . ' — check directory permissions for the web-server user');
        lawnding_tgprofile_serve_fallback();
    }
    rename($tmp, $cachePath);
}

// Telegram avatars are uniformly JPEG — no need to sniff.
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=3600');
readfile($cachePath);
