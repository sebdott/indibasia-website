<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
$config = require dirname(__DIR__) . '/config.php';
$manifest = json_decode(file_get_contents($config['storage'] . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$errors = [];
foreach ($manifest['pages'] as $route => $page) {
    $file = $config['storage'] . '/' . $page['file'];
    if (!is_file($file) || filesize($file) === 0) $errors[] = "Missing page: $route";
    elseif (!str_contains(file_get_contents($file), '/mirror.js')) $errors[] = "Missing local adapter: $route";
}
foreach ($manifest['assets'] as $url => $asset) {
    $file = $config['public'] . $asset['local'];
    if (!is_file($file) || filesize($file) === 0) $errors[] = "Missing asset: $url";
    if (preg_match('/\.(?:php|phtml)$/i', $file)) $errors[] = "Executable asset: $url";
}
$base = $argv[1] ?? 'http://127.0.0.1:8082';
function request(string $url, string $method = 'GET', string $headers = ''): array {
    $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 15, 'header' => $headers]]);
    $body = file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $m);
    return [(int)($m[1] ?? 0), $body, $responseHeaders];
}
foreach (['/' => 200, '/us/' => 200, '/asia/' => 200, '/fr/' => 404, '/es/' => 404, '/it/' => 404, '/missing-local-test-1934/' => 404] as $route => $expected) {
    [$status] = request($base . $route);
    if ($status !== $expected) $errors[] = "GET $route: expected $expected, received $status";
}
[$status, $body] = request($base . '/asia/contact/', 'POST', "Content-Type: application/x-www-form-urlencoded\r\n");
if ($status !== 501 || !is_array(json_decode($body ?: '', true))) $errors[] = 'POST must return HTTP 501 JSON';
[$status, $body] = request($base . '/', 'HEAD');
if ($status !== 200 || $body !== '') $errors[] = 'HEAD must return headers without a body';
$firstAsset = array_key_first($manifest['assets']);
if ($firstAsset) {
    $url = $base . (parse_url($firstAsset, PHP_URL_PATH) ?: '/') . (parse_url($firstAsset, PHP_URL_QUERY) !== null ? '?' . parse_url($firstAsset, PHP_URL_QUERY) : '');
    [$status, $body] = request($url, 'GET', "Range: bytes=0-15\r\n");
    if ($status !== 206 || strlen($body ?: '') !== 16) $errors[] = 'Original asset alias must support byte ranges';
    [$status] = request($url, 'GET', "Range: bytes=99999999999-\r\n");
    if ($status !== 416) $errors[] = 'Invalid byte ranges must return HTTP 416';
}
echo count($manifest['pages']) . ' page routes and ' . count($manifest['assets']) . " asset records checked.\n";
foreach ($errors as $error) fwrite(STDERR, $error . "\n");
echo $errors ? count($errors) . " checks failed.\n" : "All checks passed.\n";
exit($errors ? 1 : 0);
