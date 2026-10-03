<?php
declare(strict_types=1);
require_once __DIR__ . '/database.php';

function cmsEscape(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function cmsAudit(string $action, string $subject): void {
    database()->prepare('INSERT INTO indiba_cms_audit (user_id, action, subject) VALUES (?, ?, ?)')->execute([$_SESSION['user_id'] ?? null, $action, $subject]);
}
function cmsPage(int $id): array {
    $statement = database()->prepare('SELECT * FROM indiba_cms_pages WHERE id = ?'); $statement->execute([$id]);
    $page = $statement->fetch();
    if (!$page) throw new RuntimeException('Page not found.');
    return $page;
}
function cmsHtml(array $page): string {
    if ($page['html'] !== null) return $page['html'];
    $root = realpath(dirname(__DIR__) . '/storage/pages');
    $file = realpath(dirname(__DIR__) . '/storage/' . $page['source_file']);
    if (!$root || !$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) throw new RuntimeException('Original page file is unavailable.');
    return file_get_contents($file);
}
function cmsDocument(string $html): DOMDocument {
    $document = new DOMDocument(); $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    return $document;
}
function cmsDocumentHtml(DOMDocument $document): string {
    return preg_replace('/<\?xml encoding="UTF-8"\s*\?>/', '', $document->saveHTML()) ?? $document->saveHTML();
}
function cmsEditable(DOMDocument $document): array {
    $xpath = new DOMXPath($document); $text = []; $images = [];
    $query = '//body//text()[not(ancestor::script or ancestor::style or ancestor::noscript or ancestor::svg or ancestor::code or ancestor::pre)]';
    foreach ($xpath->query($query) as $node) {
        if (trim($node->nodeValue) === '' || !preg_match('/[a-zA-Z0-9]/', $node->nodeValue)) continue;
        $text[hash('sha256', $node->getNodePath())] = $node;
    }
    foreach ($xpath->query('//body//img[@src]') as $node) $images[hash('sha256', $node->getNodePath())] = $node;
    return [$text, $images];
}
function cmsRoute(string $route): string {
    $route = trim($route);
    if (!preg_match('~^/(?:[a-zA-Z0-9_-]+/)*$~', $route) || preg_match('~^/(?:admin|assets|uploads|wp-admin|wp-json|es|fr|it)(?:/|$)~', $route)) {
        throw new RuntimeException('Use an English page path such as /about-our-team/.');
    }
    return $route;
}
function cmsImageUrl(string $url): string {
    $url = trim($url);
    if (preg_match('~^/(?:assets|uploads)/[a-zA-Z0-9_./%-]+$~', $url) && !str_contains($url, '..')) return $url;
    if (filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https') return $url;
    throw new RuntimeException('Choose a local media URL or an HTTPS image URL.');
}
function cmsServePage(string $route): bool {
    if (getenv('CMS_ENABLED') !== '1' && !is_file(dirname(__DIR__) . '/storage/cms-installed.json')) return false;
    try {
        $query = database()->prepare('SELECT * FROM indiba_cms_pages WHERE route = ?');
        $query->execute([$route]); $page = $query->fetch();
        if (!$page) {
            $alias = database()->prepare('SELECT p.* FROM indiba_cms_redirects r JOIN indiba_cms_pages p ON p.id = r.page_id WHERE r.route = ?'); $alias->execute([$route]); $page = $alias->fetch();
            if ($page && $page['status'] === 'published') { header('Location: ' . $page['route'], true, 301); return true; }
        }
    } catch (Throwable $e) {
        error_log('CMS database unavailable.'); http_response_code(503); header('Retry-After: 60'); header('Content-Type: text/html; charset=utf-8');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo '<!doctype html><html lang="en"><title>Temporarily unavailable</title><h1>The website is temporarily unavailable</h1><p>Please try again shortly.</p></html>';
        return true;
    }
    if (!$page) return false;
    if ($page['status'] !== 'published') { http_response_code(404); header('Content-Type: text/html; charset=utf-8'); if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo '<!doctype html><html lang="en"><title>Page unavailable</title><h1>Page unavailable</h1><a href="/">Return to the homepage</a></html>'; return true; }
    header('Content-Type: text/html; charset=utf-8');
    if ($page['html'] !== null) {
        header('Content-Length: ' . strlen($page['html']));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $page['html'];
        return true;
    }
    try { $html = cmsHtml($page); } catch (Throwable $e) { return false; }
    if (($page['seo_title'] ?? null) !== null || ($page['meta_description'] ?? null) !== null) {
        require_once __DIR__ . '/cms-management.php'; $html = cmsSeo($html, $page['seo_title'] ?: $page['title'], $page['meta_description']);
    }
    header('Content-Length: ' . strlen($html));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $html;
    return true;
}
