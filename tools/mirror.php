<?php
/** Resumable CLI downloader. Never run this file as a web endpoint. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!extension_loaded('curl') || !extension_loaded('dom')) {
    fwrite(STDERR, "Enable PHP curl and DOM extensions before running this command.\n"); exit(1);
}
$config = require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/lib/translation.php';
$english = array_filter(englishTranslations(), fn(mixed $value, string $key): bool => is_string($value) && str_starts_with($key, 't_') && translationKey($value) !== $key, ARRAY_FILTER_USE_BOTH);
$options = getopt('', ['workers:', 'delay:', 'max-pages:', 'retry-failed', 'seed:', 'skip-sitemaps', 'assets-only', 'rebuild', 'prune-languages']);
$workers = max(1, min(16, (int)($options['workers'] ?? 4)));
$delay = max(0.0, (float)($options['delay'] ?? 1));
$maxPages = max(0, (int)($options['max-pages'] ?? 0));
$storage = $config['storage'];
foreach ([$storage, "$storage/pages", "$storage/raw", "$storage/tmp", $config['public'] . '/assets'] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0775, true);
}
$lock = fopen("$storage/crawl.lock", 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "Another download is running.\n"); exit(1); }
$stateFile = "$storage/state.json";
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR) :
    ['pending' => [], 'done' => [], 'pages' => [], 'assets' => [], 'failures' => [], 'external' => [], 'started' => gmdate('c')];
$active = [];
$stopping = false;
$processed = 0;
$lastSave = 0;
$scheduled = 0;

function atomicJson(string $file, array $data): void {
    file_put_contents($file . '.tmp', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT));
    rename($file . '.tmp', $file);
}
function atomicText(string $file, string $data): void {
    file_put_contents($file . '.tmp', $data);
    rename($file . '.tmp', $file);
}
function saveState(): void {
    global $state, $stateFile, $storage, $active, $lastSave;
    $snapshot = $state;
    foreach ($active as $task) $snapshot['pending'][$task['url']] = $task['type'];
    atomicJson($stateFile, $snapshot);
    atomicJson("$storage/manifest.json", ['pages' => $state['pages'], 'assets' => $state['assets'], 'home' => '/asia/', 'updated' => gmdate('c')]);
    $bytes = array_sum(array_column($state['assets'], 'bytes'));
    atomicJson("$storage/report.json", ['started' => $state['started'], 'updated' => gmdate('c'),
        'pages' => count($state['pages']), 'assets' => count($state['assets']), 'asset_bytes' => $bytes,
        'pending' => count($snapshot['pending']), 'failures' => $state['failures'], 'external' => array_keys($state['external']),
        'complete' => count($snapshot['pending']) === 0]);
    $lastSave = time();
}
register_shutdown_function(function (): void { saveState(); });
if (function_exists('sapi_windows_set_ctrl_handler')) {
    sapi_windows_set_ctrl_handler(function () use (&$stopping): bool { $stopping = true; return true; });
}
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true); pcntl_signal(SIGINT, function () use (&$stopping): void { $stopping = true; });
}
function absoluteUrl(string $value, string $base): ?string {
    $value = html_entity_decode(trim(str_replace('\\/', '/', $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($value === '' || preg_match('~^(?:data:|blob:|javascript:|mailto:|tel:|#)~i', $value)) return null;
    if (str_starts_with($value, '//')) $value = 'https:' . $value;
    if (!preg_match('~^https?://~i', $value)) {
        $b = parse_url($base);
        if (str_starts_with($value, '?')) $value = ($b['path'] ?? '/') . $value;
        elseif (!str_starts_with($value, '/')) $value = preg_replace('~/[^/]*$~', '/', $b['path'] ?? '/') . $value;
        $value = ($b['scheme'] ?? 'https') . '://' . $b['host'] . $value;
    }
    $parts = parse_url($value);
    if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return null;
    $segments = [];
    $path = $parts['path'] ?? '/';
    foreach (explode('/', $path) as $segment) {
        if ($segment === '..') array_pop($segments);
        elseif ($segment !== '.' && $segment !== '') $segments[] = $segment;
    }
    $path = '/' . implode('/', $segments) . (str_ends_with($path, '/') && $segments ? '/' : '');
    $host = strtolower($parts['host']);
    if ($host === 'www.indiba.com') $host = 'indiba.com';
    return 'https://' . $host . str_replace(' ', '%20', $path) . (isset($parts['query']) ? '?' . $parts['query'] : '');
}
function internal(string $url): bool { return in_array(parse_url($url, PHP_URL_HOST), ['indiba.com', 'www.indiba.com'], true); }
function excluded(string $url): bool {
    global $config;
    $path = rawurldecode(parse_url($url, PHP_URL_PATH) ?? '/');
    foreach ($config['excluded_paths'] as $part) if (str_contains($path, $part)) return true;
    return str_contains($path, '-or-') || (bool)preg_match('~/(?:hall-of-fame/member_cat-|scientific-literature/hub-categories-|trainings/training-division-)~', $path);
}
function routeKey(string $url): string {
    $path = parse_url($url, PHP_URL_PATH) ?: '/';
    return $path === '/' ? '/' : rtrim($path, '/') . '/';
}
function enqueue(string $url, string $type): void {
    global $state, $active, $config;
    $url = absoluteUrl($url, $config['origin'] . '/');
    if (!$url || excluded($url)) return;
    if ($type === 'page' || $type === 'sitemap') {
        if (!internal($url)) return;
        if (preg_match('~^/(?:es|fr|it)(?:/|$)~', parse_url($url, PHP_URL_PATH) ?: '/')) return;
        if ($type === 'page') {
            // Only finite document paths. Query-driven searches, calendars and filters are not new pages.
            if (parse_url($url, PHP_URL_QUERY) !== null) return;
            if (preg_match('~/(?:feed|embed|trackback)/?$~', parse_url($url, PHP_URL_PATH) ?? '')) return;
            if (pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) === '') $url = rtrim($url, '/') . '/';
        }
    } elseif (!in_array(parse_url($url, PHP_URL_HOST), $config['asset_hosts'], true)) {
        $state['external'][$url] = true; return;
    } elseif (str_ends_with(parse_url($url, PHP_URL_PATH) ?? '', '/')) {
        // Plugin configuration often contains a directory URL, rather than an asset.
        return;
    } elseif (internal($url) && pathinfo(parse_url($url, PHP_URL_PATH) ?: '/', PATHINFO_EXTENSION) === '') {
        return;
    }
    if (isset($state['done'][$url]) || isset($state['pending'][$url]) || isset($state['failures'][$url])) return;
    foreach ($active as $task) if ($task['url'] === $url) return;
    $state['pending'][$url] = $type;
}
function looksLikeAsset(string $url): bool {
    $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
    return (bool)preg_match('~\.(?:css|js|mjs|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|eot|otf|pdf|mp4|webm|mov|mp3|wav|ogg|zip)(?:$)~i', $path)
        || str_contains($path, '/wp-content/') || str_contains($path, '/wp-includes/') || parse_url($url, PHP_URL_HOST) === 'fonts.googleapis.com';
}
function reference(string $value, string $base, bool $asset = false): void {
    global $state;
    $url = absoluteUrl($value, $base);
    if (!$url) return;
    if ($asset || looksLikeAsset($url)) {
        enqueue($url, 'asset');
        if (in_array(routeKey($base), ['/', '/asia/', '/us/'], true)) $state['hot'][$url] = true;
    }
    elseif (internal($url)) enqueue($url, 'page');
    else $state['external'][$url] = true;
}
function discoverText(string $text, string $base, bool $css = false): void {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(['\\/', '\\u002F', '\\u002f'], '/', $text);
    preg_match_all('~https?://[^\s<>"\'`\\\\\)\(]+~i', $text, $matches);
    foreach ($matches[0] as $value) {
        $value = rtrim($value, ',;');
        if (looksLikeAsset($value)) reference($value, $base, true);
    }
    if ($css) {
        preg_match_all('~url\(\s*["\']?([^"\'\)]+)["\']?\s*\)|@import\s+["\']([^"\']+)["\']~i', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) reference(trim($m[1] ?: ($m[2] ?? '')), $base, true);
    } elseif (preg_match('~\.m?js(?:\?|$)~', $base)) {
        // Webpack runtimes list the filenames of lazy widget bundles.
        preg_match_all('~["\']([^"\'\s]+\.bundle\.min\.js)["\']~', $text, $matches);
        foreach ($matches[1] as $filename) reference($filename, $base, true);
    }
}
function htmlDocument(string $html): DOMDocument {
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    return $dom;
}
function discoverHtml(string $html, string $base): void {
    $dom = htmlDocument($html);
    foreach ($dom->getElementsByTagName('*') as $node) {
        $tag = strtolower($node->tagName);
        foreach (['src', 'poster', 'data-src', 'data-lazy-src', 'data-original', 'data-bg', 'data-background-image'] as $attr) {
            if ($node->hasAttribute($attr) && $tag !== 'iframe') reference($node->getAttribute($attr), $base, true);
        }
        foreach (['srcset', 'data-srcset', 'data-lazy-srcset', 'imagesrcset'] as $attr) {
            if ($node->hasAttribute($attr)) foreach (explode(',', $node->getAttribute($attr)) as $part) {
                reference(preg_split('/\s+/', trim($part))[0], $base, true);
            }
        }
        if ($node->hasAttribute('href')) {
            $rel = strtolower($node->getAttribute('rel'));
            if ($tag === 'a') reference($node->getAttribute('href'), $base);
            elseif ($tag === 'link' && preg_match('~stylesheet|icon|preload~', $rel)) reference($node->getAttribute('href'), $base, true);
        }
        if ($node->hasAttribute('style')) discoverText($node->getAttribute('style'), $base, true);
    }
    discoverText($html, $base);
    foreach ($dom->getElementsByTagName('style') as $style) discoverText($style->textContent, $base, true);
}
function extension(string $url, string $mime): string {
    $map = ['text/css' => 'css', 'application/javascript' => 'js', 'text/javascript' => 'js', 'application/pdf' => 'pdf',
        'image/svg+xml' => 'svg', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif',
        'font/woff2' => 'woff2', 'font/woff' => 'woff', 'video/mp4' => 'mp4', 'video/webm' => 'webm'];
    if (isset($map[$mime])) return $map[$mime];
    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
    return in_array($ext, ['css','js','mjs','png','jpg','jpeg','gif','webp','avif','svg','ico','woff','woff2','ttf','eot','otf','pdf','mp4','webm','mov','mp3','wav','ogg','zip']) ? $ext : 'bin';
}
function localReference(string $value, string $base): string {
    global $state;
    $url = absoluteUrl($value, $base);
    if ($url && isset($state['assets'][$url])) {
        $fragment = parse_url(html_entity_decode($value, ENT_QUOTES | ENT_HTML5), PHP_URL_FRAGMENT);
        return $state['assets'][$url]['local'] . ($fragment !== null ? '#' . $fragment : '');
    }
    if ($url && internal($url)) {
        $fragment = parse_url(html_entity_decode($value, ENT_QUOTES | ENT_HTML5), PHP_URL_FRAGMENT);
        return (parse_url($url, PHP_URL_PATH) ?: '/') . (parse_url($url, PHP_URL_QUERY) !== null ? '?' . parse_url($url, PHP_URL_QUERY) : '') . ($fragment !== null ? '#' . $fragment : '');
    }
    return $value;
}
function rewriteText(string $text, string $base, bool $css = false): string {
    global $state;
    // Match URL strings without reserializing HTML or changing inline JS syntax.
    $text = preg_replace_callback('~https?:(?:(?:\\\\)?/){2}(?:(?!&quot;|&#(?:34|39);|&apos;)[^\s<>"\'`\)\(])+~i', function (array $m) use ($base): string {
        $escaped = str_contains($m[0], '\\/');
        $value = str_replace('\\/', '/', $m[0]);
        $suffix = '';
        while ($value !== '' && str_contains(',;', substr($value, -1))) { $suffix = substr($value, -1) . $suffix; $value = substr($value, 0, -1); }
        $new = localReference($value, $base);
        if ($escaped) $new = str_replace('/', '\\/', $new);
        return $new . $suffix;
    }, $text);
    if ($css) {
        $text = preg_replace_callback('~url\(\s*(["\']?)([^"\'\)]+)\1\s*\)~i', fn(array $m): string => 'url(' . $m[1] . localReference(trim($m[2]), $base) . $m[1] . ')', $text);
        $text = preg_replace_callback('~(@import\s+["\'])([^"\']+)(["\'])~i', fn(array $m): string => $m[1] . localReference($m[2], $base) . $m[3], $text);
    } elseif (preg_match('~\.m?js(?:\?|$)~', $base) && str_contains($text, '__webpack_require__.p=')) {
        $directory = preg_replace('~/[^/]*$~', '/', parse_url($base, PHP_URL_PATH) ?: '/');
        $text = preg_replace('~__webpack_require__\.p=[a-zA-Z_$][a-zA-Z0-9_$]*~', '__webpack_require__.p=' . json_encode($directory), $text);
    }
    return $text;
}
function renderPage(string $html, string $url): string {
        global $english;
        $decodeEmail = function (string $hex): string {
            if (strlen($hex) < 4 || strlen($hex) % 2 !== 0) return '';
            $mask = hexdec(substr($hex, 0, 2)); $email = '';
            for ($i = 2; $i < strlen($hex); $i += 2) $email .= chr(hexdec(substr($hex, $i, 2)) ^ $mask);
            return htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $html = preg_replace_callback('~href=(["\'])(?:https?://(?:www\.)?indiba\.com)?/cdn-cgi/l/email-protection#([a-f0-9]+)\1~i', fn(array $m): string => 'href=' . $m[1] . 'mailto:' . $decodeEmail($m[2]) . $m[1], $html);
        $html = preg_replace_callback('~<(a|span)\b([^>]*\bdata-cfemail=["\']([a-f0-9]+)["\'][^>]*)>(.*?)</\1>~is', fn(array $m): string => '<' . $m[1] . preg_replace('~\s*data-cfemail=["\'][a-f0-9]+["\']~i', '', $m[2]) . '>' . $decodeEmail($m[3]) . '</' . $m[1] . '>', $html);
        $html = preg_replace('~<script\b[^>]*src=["\'][^"\']*(?:modules/recaptcha/|truncate-text\.js|contact-form-7/includes/(?:swv/)?js/index\.js)[^"\']*["\'][^>]*>\s*</script>~i', '', $html);
        $html = rewriteText($html, $url);
        // CSS in style attributes and inline style blocks can use relative URLs.
        $html = preg_replace_callback('~(<style\b[^>]*>)(.*?)(</style>)~is', fn(array $m): string => $m[1] . rewriteText($m[2], $url, true) . $m[3], $html);
        $rewriteAttributes = fn(string $tag): string => preg_replace_callback('~\b((?:src|href|poster|data-src|data-lazy-src|data-original|data-bg|action)\s*=\s*)(["\'])(.*?)\2~is', fn(array $m): string => $m[1] . $m[2] . localReference($m[3], $url) . $m[2], $tag);
        $html = preg_replace_callback('~<(script|style)\b[^>]*>.*?</\1>|<[^>]+>~is', function (array $m) use ($rewriteAttributes): string {
            // Attribute-like selectors inside JavaScript are code, not HTML attributes.
            return !empty($m[1]) ? preg_replace_callback('~^<[^>]+>~', fn(array $tag): string => $rewriteAttributes($tag[0]), $m[0]) : $rewriteAttributes($m[0]);
        }, $html);
        $html = preg_replace('~<script\b[^>]*src=["\'][^"\']*(?:analytics\.eu\.umami\.is|js\.hs-scripts\.com|stats\.wp\.com|google\.com/recaptcha|maps\.googleapis\.com|consent\.cookiebot\.com|modules/recaptcha/)[^"\']*["\'][^>]*>\s*</script>~i', '', $html);
        $html = preg_replace('~<a\b[^>]*href=["\']/(?:es|fr|it)/?["\'][^>]*>.*?</a>~is', '', $html);
        $html = preg_replace('~<li\b[^>]*>\s*</li>~is', '', $html);
        $html = preg_replace('~<link\b[^>]*hreflang=["\'](?:es|fr|it)(?:-[^"\']*)?["\'][^>]*>~i', '', $html);
        // Cloudflare's live-domain challenge code does not apply to a local snapshot.
        $html = preg_replace_callback('~<script\b[^>]*>.*?</script>~is', fn(array $m): string => str_contains($m[0], 'cdn-cgi/challenge-platform') || str_contains($m[0], 'googletagmanager.com') ? '' : $m[0], $html);
        $injection = '<meta name="robots" content="noindex,nofollow"><script src="/mirror.js"></script><link rel="stylesheet" href="/mirror.css">';
        if (preg_match('~<head\b~i', $html)) $html = preg_replace('~(<head\b[^>]*>)~i', '$1' . $injection, $html, 1);
        else $html = $injection . $html;
        return applyEnglishTranslations($html, $english);
}
function rebuild(): void {
    global $state, $storage, $config;
    foreach ($state['pages'] as $page) {
        if (!is_file("$storage/" . $page['raw'])) continue;
        atomicText("$storage/" . $page['file'], renderPage(file_get_contents("$storage/" . $page['raw']), $page['url']));
    }
    foreach ($state['assets'] as $asset) {
        if (!in_array($asset['ext'], ['css','js','mjs'], true) || !is_file("$storage/" . $asset['raw'])) continue;
        $content = rewriteText(file_get_contents("$storage/" . $asset['raw']), $asset['url'], $asset['ext'] === 'css');
        atomicText($config['public'] . $asset['local'], $content);
    }
    saveState();
}
if (isset($options['prune-languages'])) {
    foreach (['pending', 'done', 'failures'] as $section) foreach ($state[$section] as $url => $value) {
        if (preg_match('~^/(?:es|fr|it)(?:/|$)~', parse_url($url, PHP_URL_PATH) ?: '/')) unset($state[$section][$url]);
    }
    foreach ($state['pages'] as $route => $page) if (preg_match('~^/(?:es|fr|it)(?:/|$)~', $route)) {
        foreach (['raw', 'file'] as $key) {
            $target = realpath("$storage/" . $page[$key]);
            if ($target && str_starts_with($target, realpath($storage) . DIRECTORY_SEPARATOR)) unlink($target);
        }
        unset($state['pages'][$route]);
    }
    foreach ($state['assets'] as $url => $asset) if (preg_match('~^/(?:es|fr|it)(?:/|$)~', parse_url($url, PHP_URL_PATH) ?: '/')) {
        $target = realpath($config['public'] . $asset['local']);
        if ($target && str_starts_with($target, realpath($config['public'] . '/assets') . DIRECTORY_SEPARATOR)) unlink($target);
        if (isset($asset['raw'])) {
            $target = realpath("$storage/" . $asset['raw']);
            if ($target && str_starts_with($target, realpath("$storage/raw") . DIRECTORY_SEPARATOR)) unlink($target);
        }
        unset($state['assets'][$url]);
    }
    foreach ($state['pages'] as $page) if (is_file("$storage/" . $page['raw'])) discoverHtml(file_get_contents("$storage/" . $page['raw']), $page['url']);
    foreach ($state['assets'] as $asset) if (in_array($asset['ext'], ['js','mjs'], true) && is_file("$storage/" . ($asset['raw'] ?? ''))) discoverText(file_get_contents("$storage/" . $asset['raw']), $asset['url']);
    rebuild(); echo "English-only queue and snapshots saved.\n";
    if (isset($options['rebuild'])) exit;
}
if (isset($options['rebuild'])) { rebuild(); echo "Local pages and styles rebuilt.\n"; exit; }
if (is_file("$storage/browser-check.json")) {
    foreach (json_decode(file_get_contents("$storage/browser-check.json"), true) ?? [] as $check) foreach ($check['missing'] ?? [] as $missing) {
        $path = parse_url($missing['url'], PHP_URL_PATH) ?: '';
        if (str_contains($path, '/wp-content/') || str_contains($path, '/wp-includes/')) enqueue($config['origin'] . $path, 'asset');
    }
}
if (is_file("$storage/interactions-check.json")) {
    $checks = json_decode(file_get_contents("$storage/interactions-check.json"), true);
    foreach ($checks['pages'] ?? [] as $check) foreach ($check['missing'] ?? [] as $url) {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        if (str_contains($path, '/wp-content/') || str_contains($path, '/wp-includes/')) enqueue($config['origin'] . $path, 'asset');
    }
}
foreach ($state['pages'] as $page) if (!is_file("$storage/" . $page['file'])) {
    unset($state['done'][$page['source']]); enqueue($page['source'], 'page');
}
foreach (['/', '/asia/', '/us/'] as $route) if (isset($state['pages'][$route])) {
    $page = $state['pages'][$route];
    discoverHtml(file_get_contents("$storage/" . $page['raw']), $page['url']);
}
if (isset($options['retry-failed'])) {
    $failures = $state['failures']; $state['failures'] = [];
    foreach ($failures as $url => $failure) { unset($state['done'][$url]); enqueue($url, $failure['type']); }
}
if (!isset($options['assets-only'])) {
    $seeds = isset($options['seed']) ? (array)$options['seed'] : array_map(fn(string $locale): string => $config['origin'] . $locale . '/', $config['locales']);
    foreach ($seeds as $seed) enqueue($seed, 'page');
    if (!isset($options['skip-sitemaps'])) foreach ($config['locales'] as $locale) enqueue($config['origin'] . $locale . '/sitemap_index.xml', 'sitemap');
}
$multi = curl_multi_init();
$nextRequest = 0.0;
$pageCount = count($state['pages']);
$startPages = $pageCount;
echo "Download started: " . count($state['pending']) . " queued; $pageCount pages already available.\n";
while ((!empty($state['pending']) || !empty($active)) && !$stopping && !is_file("$storage/stop")) {
    while (count($active) < $workers && microtime(true) >= $nextRequest && !$stopping) {
        $url = null;
        // Fetch region homepages first, then share capacity between assets, pages and sitemaps.
        $priorities = $scheduled % 8 < 5 ? ['asset','page','sitemap'] : ($scheduled % 8 < 7 ? ['page','asset','sitemap'] : ['sitemap','asset','page']);
        if (!isset($options['assets-only'])) foreach ($state['pending'] as $candidate => $type) {
            if ($type === 'page' && in_array(rtrim(parse_url($candidate, PHP_URL_PATH) ?? '', '/'), $config['locales'], true)) { $url = $candidate; break; }
        }
        foreach ($url === null ? $priorities : [] as $priority) {
            if ($priority !== 'asset' && isset($options['assets-only'])) continue;
            if ($priority === 'asset') foreach ($state['hot'] ?? [] as $candidate => $_) {
                if (($state['pending'][$candidate] ?? '') === 'asset') { $url = $candidate; break; }
            }
            if ($url !== null) break;
            if ($priority === 'asset') foreach ($state['pending'] as $candidate => $type) {
                if ($type === 'asset' && preg_match('~\.(?:css|js|mjs|woff2?|ttf|eot|otf)(?:\?|$)|fonts\.googleapis\.com~i', $candidate)) { $url = $candidate; break; }
            }
            if ($url !== null) break;
            foreach ($state['pending'] as $candidate => $type) {
                if ($type !== $priority || ($type === 'page' && $maxPages && $pageCount - $startPages >= $maxPages)) continue;
                if ($type === 'page' && count(array_filter($active, fn(array $task): bool => $task['type'] === 'page')) >= 3) continue;
                $url = $candidate; break 2;
            }
        }
        if ($url === null) break;
        $type = $state['pending'][$url]; unset($state['pending'][$url]);
        $scheduled++;
        $tmp = "$storage/tmp/" . hash('sha256', $url) . '.part';
        $file = fopen($tmp, 'wb');
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_FILE => $file, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 90, CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; LocalSiteMirror/1.0)',
            CURLOPT_HTTPHEADER => ['Accept: */*', 'Accept-Language: en-US,en;q=0.9'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_COOKIEFILE => '', CURLOPT_REFERER => $config['origin'] . '/']);
        curl_multi_add_handle($multi, $curl);
        $active[spl_object_id($curl)] = ['curl' => $curl, 'url' => $url, 'type' => $type, 'tmp' => $tmp, 'file' => $file];
        $nextRequest = microtime(true) + $delay;
    }
    curl_multi_exec($multi, $running);
    while ($info = curl_multi_info_read($multi)) {
        $curl = $info['handle']; $id = spl_object_id($curl); $task = $active[$id];
        fclose($task['file']);
        $url = $task['url']; $type = $task['type']; $tmp = $task['tmp'];
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $effective = curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
        $mime = strtolower(explode(';', curl_getinfo($curl, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream')[0]);
        $error = curl_error($curl);
        curl_multi_remove_handle($multi, $curl); unset($active[$id]);
        if ($info['result'] !== CURLE_OK || $status < 200 || $status >= 300 || !is_file($tmp) || filesize($tmp) === 0 || ($type === 'asset' && $mime === 'text/html')) {
            $state['failures'][$url] = ['type' => $type, 'status' => $status, 'error' => $error ?: 'Unavailable response', 'at' => gmdate('c')];
            if (is_file($tmp)) unlink($tmp);
            echo "FAILED $status $url $error\n";
        } else {
            $state['done'][$url] = true;
            if ($type === 'sitemap') {
                $xml = new DOMDocument();
                $previous = libxml_use_internal_errors(true);
                $ok = $xml->loadXML(file_get_contents($tmp), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                libxml_clear_errors(); libxml_use_internal_errors($previous);
                if ($ok) foreach ($xml->getElementsByTagName('loc') as $loc) {
                    $parent = $loc->parentNode->localName;
                    // Image sitemap locations are assets, not additional HTML pages.
                    enqueue($loc->textContent, $parent === 'sitemap' ? 'sitemap' : ($parent === 'image' ? 'asset' : 'page'));
                }
                if (!$ok) $state['failures'][$url] = ['type' => $type, 'status' => $status, 'error' => 'Not a valid sitemap', 'at' => gmdate('c')];
                unlink($tmp);
            } elseif ($type === 'page') {
                if ($mime !== 'text/html' && $mime !== 'application/xhtml+xml') { unlink($tmp); continue; }
                $hash = hash('sha256', $url);
                $raw = "raw/$hash.html"; $file = "pages/$hash.html";
                rename($tmp, "$storage/$raw");
                $record = ['url' => $effective, 'source' => $url, 'file' => $file, 'raw' => $raw];
                $state['pages'][routeKey($url)] = $record;
                if (!isset($state['pages'][routeKey($effective)])) $state['pages'][routeKey($effective)] = $record;
                $html = file_get_contents("$storage/$raw"); discoverHtml($html, $effective);
                atomicText("$storage/$file", renderPage($html, $effective));
                $pageCount++; echo "PAGE $pageCount $url\n";
            } else {
                $ext = extension($url, $mime); $hash = hash('sha256', $url);
                $local = "/assets/$hash.$ext";
                $record = ['url' => $effective, 'local' => $local, 'ext' => $ext, 'mime' => $mime, 'bytes' => filesize($tmp)];
                if (in_array($ext, ['css','js','mjs'], true)) {
                    $record['raw'] = "raw/$hash.$ext";
                    rename($tmp, "$storage/" . $record['raw']);
                    $content = file_get_contents("$storage/" . $record['raw']);
                    discoverText($content, $effective, $ext === 'css');
                    atomicText($config['public'] . $local, rewriteText($content, $effective, $ext === 'css'));
                } else rename($tmp, $config['public'] . $local);
                $state['assets'][$url] = $record;
                if ($effective !== $url) $state['assets'][$effective] = $record;
            }
        }
        $processed++;
        if ($processed % 200 === 0) {
            echo 'PROGRESS ' . count($state['pages']) . ' pages, ' . count($state['assets']) . ' assets, ' . count($state['pending']) . ' queued, ' . count($state['failures']) . " failures\n";
            saveState();
        } elseif (time() - $lastSave >= 10) saveState();
    }
    if (!$active) {
        $eligible = false;
        foreach ($state['pending'] as $type) {
            if ($type === 'asset' || (!isset($options['assets-only']) && ($type === 'sitemap' || !$maxPages || $pageCount - $startPages < $maxPages))) { $eligible = true; break; }
        }
        if (!$eligible) break;
    }
    if ($active) curl_multi_select($multi, 0.04);
    usleep(10000);
}
rebuild();
echo 'Saved ' . count($state['pages']) . ' page routes and ' . count($state['assets']) . ' assets. ' . count($state['pending']) . " pending.\n";
echo "Details: storage/report.json\n";
