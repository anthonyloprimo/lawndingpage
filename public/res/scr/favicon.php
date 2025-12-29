<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$domains = collect_domains();
if (!$domains) {
    echo json_encode(['error' => 'missing_domains']);
    exit;
}

$cachePath = dirname(__DIR__) . '/data/favicon-cache.json';
$cache = read_cache($cachePath);
$results = [];

foreach ($domains as $domain) {
    if (isset($cache[$domain]) && is_array($cache[$domain])) {
        $cached = $cache[$domain];
        $icon = is_string($cached['icon'] ?? null) ? $cached['icon'] : '';
        $source = is_string($cached['source'] ?? null) ? $cached['source'] : 'google';
        $url = is_string($cached['url'] ?? null) ? $cached['url'] : build_base_url($domain);
        if ($icon) {
            $results[$domain] = [
                'icon' => $icon,
                'source' => $source,
                'url' => $url,
            ];
            continue;
        }
    }

    $iconUrl = build_google_favicon_url($domain);
    $results[$domain] = [
        'icon' => $iconUrl,
        'source' => 'google',
        'url' => build_base_url($domain),
    ];

    $cache[$domain] = [
        'icon' => $iconUrl,
        'source' => 'google',
        'url' => build_base_url($domain),
        'ts' => time(),
    ];
}

write_cache($cachePath, $cache);

echo json_encode(['icons' => $results]);

function collect_domains(): array
{
    $payload = null;
    $domains = [];

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $payload = json_decode($raw, true);
        }
    }

    if (is_array($payload)) {
        if (isset($payload['domains']) && is_array($payload['domains'])) {
            $domains = $payload['domains'];
        } elseif (isset($payload['url'])) {
            $domains = [$payload['url']];
        }
    }

    if (!$domains && isset($_GET['domains'])) {
        $domains = array_map('trim', explode(',', (string) $_GET['domains']));
    }

    if (!$domains && isset($_GET['url'])) {
        $domains = [(string) $_GET['url']];
    }

    $normalized = [];
    foreach ($domains as $value) {
        $domain = normalize_domain((string) $value);
        if ($domain) {
            $normalized[$domain] = true;
        }
    }

    return array_keys($normalized);
}

function normalize_domain(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (strpos($value, '//') === 0) {
        $value = 'https:' . $value;
    }

    $parsed = parse_url($value);
    if (!$parsed || empty($parsed['host'])) {
        if (!preg_match('/^https?:\/\//i', $value)) {
            $value = 'https://' . $value;
            $parsed = parse_url($value);
        }
    }

    $host = $parsed['host'] ?? '';
    if ($host === '') {
        return null;
    }

    $host = strtolower($host);
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }

    $host = rtrim($host, '.');
    if (!preg_match('/^(?:[a-z0-9-]+\.)+[a-z0-9-]+$/', $host)) {
        return null;
    }

    return $host;
}

function build_google_favicon_url(string $domain): string
{
    return 'https://www.google.com/s2/favicons?sz=64&domain=' . urlencode($domain);
}

function build_base_url(string $domain): string
{
    return 'https://' . $domain . '/';
}

function read_cache(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function write_cache(string $path, array $cache): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    file_put_contents($path, json_encode($cache));
}
