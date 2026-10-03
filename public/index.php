<?php
declare(strict_types=1);
$config = require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/lib/translation.php';
$english = englishTranslations();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (in_array($path, ['/admin', '/admin/', '/admin/index.php'], true)) {
    if ($path === '/admin' && $method === 'GET') { header('Location: /admin/', true, 302); exit; }
    require __DIR__ . '/admin/index.php'; exit;
}
header('X-Content-Type-Options: nosniff');
if (!in_array($method, ['GET', 'HEAD'], true) || preg_match('~/(?:wp-json|wp-admin|wp-login\.php|xmlrpc\.php)(?:/|$)~', $path)) {
    http_response_code(501); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 'local_copy', 'message' => $english['ui_feature_unavailable'] ?? 'This feature is unavailable in the local copy.', 'status' => 'mail_failed']); exit;
}
$manifestFile = $config['storage'] . '/manifest.json';
$manifest = is_file($manifestFile) ? json_decode(file_get_contents($manifestFile), true) : ['pages' => [], 'assets' => []];
if ($path === '/translations.json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_filter($english, fn(string $key): bool => str_starts_with($key, 'ui_'), ARRAY_FILTER_USE_KEY), JSON_UNESCAPED_UNICODE); exit;
}
if ($path === '/mirror-map.json') {
    header('Content-Type: application/json; charset=utf-8');
    $map = [];
    foreach ($manifest['assets'] as $url => $asset) $map[$url] = $asset['local'];
    echo json_encode($map, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
function sendFile(string $file, string $mime): never {
    header('Content-Type: ' . $mime);
    $size = filesize($file); $start = 0; $end = $size - 1;
    header('Accept-Ranges: bytes');
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] === '') $start = max(0, $size - (int)$m[2]);
        else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
        if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
        http_response_code(206); header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') exit;
    $handle = fopen($file, 'rb'); fseek($handle, $start); $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($handle)) { $data = fread($handle, min(65536, $remaining)); echo $data; $remaining -= strlen($data); }
    fclose($handle); exit;
}
// Support plugin scripts which construct original WordPress asset URLs dynamically.
$requested = $config['origin'] . $path . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
$asset = $manifest['assets'][$requested] ?? $manifest['assets'][$config['origin'] . $path] ?? null;
if (!$asset && str_starts_with($path, '/assets/')) {
    foreach ($manifest['assets'] as $candidate) if ($candidate['local'] === $path) { $asset = $candidate; break; }
}
if (!$asset && (str_contains($path, '/wp-content/') || str_contains($path, '/wp-includes/'))) {
    foreach ($manifest['assets'] as $url => $candidate) if (parse_url($url, PHP_URL_PATH) === $path) { $asset = $candidate; break; }
}
if ($asset && is_file($config['public'] . $asset['local'])) sendFile($config['public'] . $asset['local'], $asset['mime']);
$key = $path === '/' ? '/' : rtrim($path, '/') . '/';
require_once dirname(__DIR__) . '/lib/cms.php';
if (cmsServePage($key)) exit;
$page = $manifest['pages'][$key] ?? ($key === '/' ? ($manifest['pages'][$config['home']] ?? null) : null);
if ($page && is_file($config['storage'] . '/' . $page['file'])) sendFile($config['storage'] . '/' . $page['file'], 'text/html; charset=utf-8');
http_response_code(404); header('Content-Type: text/html; charset=utf-8');
$title = htmlspecialchars($english['ui_page_unavailable'] ?? 'Page unavailable', ENT_QUOTES, 'UTF-8');
$description = htmlspecialchars($english['ui_page_unavailable_description'] ?? 'This page is not available in the local copy.', ENT_QUOTES, 'UTF-8');
$homeLink = htmlspecialchars($english['ui_home_link'] ?? 'Return to the homepage', ENT_QUOTES, 'UTF-8');
echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . $title . '</title><style>body{font:18px system-ui;max-width:680px;margin:12vh auto;padding:24px;color:#172c35}a{color:#07677e}</style><h1>' . $title . '</h1><p>' . $description . '</p><a href="/">' . $homeLink . '</a></html>';
