<?php
/** Read-only audit of published page routes and internal document links. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/lib/cms.php';
$config = require dirname(__DIR__) . '/config.php';
$base = rtrim($argv[1] ?? 'http://127.0.0.1:9000', '/');
if (!in_array(parse_url($base, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) throw new RuntimeException('Use a local website URL for this audit.');
$manifest = json_decode(file_get_contents($config['storage'] . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$managed = []; $pages = [];
foreach (database()->query('SELECT * FROM indiba_cms_pages ORDER BY id') as $page) { $managed[$page['route']] = $page; if ($page['status'] === 'published') $pages[$page['route']] = $page; }
foreach ($manifest['pages'] as $route=>$page) if (!isset($managed[$route])) $pages[$route] = ['source_file'=>$page['file'], 'html'=>null];
$targets = []; $unreadable = []; $ignored = 0;
$internalHosts = [parse_url($base, PHP_URL_HOST), 'indiba.com', 'www.indiba.com'];
foreach ($pages as $route=>$page) {
    $targets[$route] ??= ['page'=>true, 'referrers'=>[]]; $targets[$route]['page'] = true;
    try { $html = cmsHtml($page); } catch (RuntimeException $e) { $unreadable[$route] = $e->getMessage(); continue; }
    $dom = cmsDocument($html);
    foreach ($dom->getElementsByTagName('a') as $anchor) {
        $href = trim($anchor->getAttribute('href'));
        if ($href === '' || preg_match('~^(?:#|mailto:|tel:|javascript:|data:)~i', $href)) continue;
        $url = parse_url(str_starts_with($href, '//') ? 'https:' . $href : $href);
        if (!$url || isset($url['scheme']) && !in_array(strtolower($url['scheme']), ['http', 'https'], true) || isset($url['host']) && !in_array(strtolower($url['host']), $internalHosts, true)) continue;
        $path = $url['path'] ?? $route;
        if (!str_starts_with($path, '/')) $path = preg_replace('~/[^/]*$~', '/', $route) . $path;
        $segments = [];
        foreach (explode('/', $path) as $segment) { if ($segment === '..') array_pop($segments); elseif ($segment !== '.' && $segment !== '') $segments[] = $segment; }
        $path = '/' . implode('/', $segments);
        if (preg_match('~^/(?:es|fr|it|admin)(?:/|$)~', $path) || pathinfo($path, PATHINFO_EXTENSION) !== '') { $ignored++; continue; }
        foreach ($config['excluded_paths'] as $excluded) if (str_contains($path, $excluded)) { $ignored++; continue 2; }
        $path = $path === '/' ? '/' : $path . '/';
        $targets[$path] ??= ['page'=>false, 'referrers'=>[]];
        $targets[$path]['referrers'][$route] ??= trim($anchor->textContent);
    }
}
echo count($pages) . ' published/snapshot routes; ' . count($targets) . " distinct document targets. Checking HTTP responses...\n";
$queue = array_keys($targets); $active = []; $multi = curl_multi_init(); $statuses = [];
while ($queue || $active) {
    while ($queue && count($active) < 8) {
        $route = array_shift($queue); $curl = curl_init($base . $route);
        curl_setopt_array($curl, [CURLOPT_NOBODY=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>5, CURLOPT_TIMEOUT=>30, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_PROTOCOLS=>CURLPROTO_HTTP, CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP]);
        curl_multi_add_handle($multi, $curl); $active[spl_object_id($curl)] = [$route, $curl];
    }
    curl_multi_exec($multi, $running);
    while ($info = curl_multi_info_read($multi)) {
        $curl = $info['handle']; [$route] = $active[spl_object_id($curl)];
        $statuses[$route] = ['status'=>curl_getinfo($curl, CURLINFO_HTTP_CODE), 'final_url'=>curl_getinfo($curl, CURLINFO_EFFECTIVE_URL), 'error'=>curl_error($curl)];
        curl_multi_remove_handle($multi, $curl); unset($active[spl_object_id($curl)]);
    }
    if ($active) curl_multi_select($multi, 0.1);
}
$missing = []; $failedPages = [];
foreach ($targets as $route=>$target) {
    if ($statuses[$route]['status'] >= 200 && $statuses[$route]['status'] < 400) continue;
    $record = ['route'=>$route, ...$statuses[$route], 'managed_status'=>$managed[$route]['status'] ?? null, 'referrer_count'=>count($target['referrers']), 'referrers'=>array_slice($target['referrers'], 0, 10, true)];
    if ($target['page']) $failedPages[] = $record;
    if ($target['referrers']) $missing[] = $record;
}
usort($missing, fn($a,$b)=>$b['referrer_count'] <=> $a['referrer_count']);
$report = ['base'=>$base, 'checked_at'=>gmdate('c'), 'pages_checked'=>count($pages), 'document_targets_checked'=>count($targets), 'ignored_non_document_links'=>$ignored, 'unreadable_pages'=>$unreadable, 'failed_pages'=>$failedPages, 'broken_links'=>$missing];
$output = $config['storage'] . '/links-check.json';
file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
echo count($failedPages) . ' failed published routes; ' . count($missing) . " broken internal document targets.\n";
foreach (array_slice($missing, 0, 40) as $record) echo $record['status'] . ' ' . $record['route'] . ' (' . $record['referrer_count'] . " referring pages)\n";
echo "Report saved to storage/links-check.json\n";
exit($missing || $failedPages || $unreadable ? 1 : 0);
