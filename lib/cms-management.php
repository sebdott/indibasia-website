<?php
declare(strict_types=1);
require_once __DIR__ . '/cms.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/content-types.php';
require_once __DIR__ . '/cms-banner.php';

function cmsUpgrade(): void {
    databaseMigrate(database());
}

function cmsRevision(array $page): void {
    database()->prepare('INSERT INTO indiba_cms_revisions (page_id, version, data, user_id) VALUES (?, ?, ?, ?)')->execute([
        $page['id'], $page['version'], json_encode($page, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $_SESSION['user_id'] ?? null,
    ]);
}

function cmsRouteAvailable(string $route, int $id = 0): void {
    $query = database()->prepare('SELECT id FROM indiba_cms_pages WHERE route = ? AND id <> ?'); $query->execute([$route, $id]);
    $alias = database()->prepare('SELECT page_id FROM indiba_cms_redirects WHERE route = ? AND page_id <> ?'); $alias->execute([$route, $id]);
    if ($query->fetchColumn() || $alias->fetchColumn()) throw new RuntimeException('That page path is already in use.');
}

function cmsSave(array $page, array $changes, int $version, string $action): void {
    $db = database(); $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT * FROM indiba_cms_pages WHERE id = ? FOR UPDATE'); $query->execute([$page['id']]); $current = $query->fetch();
        if (!$current || (int)$current['version'] !== $version) throw new RuntimeException('Someone else changed this page. Reload before saving.');
        $next = array_replace($current, $changes); cmsRouteAvailable($next['route'], (int)$current['id']);
        if (!array_key_exists($next['content_type'], cmsContentTypes())) throw new RuntimeException('Choose a valid content section.');
        if ($next['route'] !== $current['route']) {
            if (in_array($current['route'], ['/', '/asia/', '/us/'], true)) throw new RuntimeException('The regional homepage path must stay unchanged.');
            $db->prepare('DELETE FROM indiba_cms_redirects WHERE route = ? AND page_id = ?')->execute([$next['route'], $current['id']]);
            $db->prepare('INSERT INTO indiba_cms_redirects (route, page_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE page_id = VALUES(page_id)')->execute([$current['route'], $current['id']]);
        }
        cmsRevision($current);
        $db->prepare('UPDATE indiba_cms_pages SET title = ?, route = ?, html = ?, status = ?, seo_title = ?, meta_description = ?, group_name = ?, content_type = ?, version = version + 1 WHERE id = ?')->execute([
            $next['title'], $next['route'], $next['html'], $next['status'], $next['seo_title'], $next['meta_description'], $next['group_name'], $next['content_type'], $current['id'],
        ]);
        cmsAudit($action, $next['route']); $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function cmsSeo(string $html, string $title, ?string $description): string {
    $escaped = cmsEscape($title);
    if (preg_match('~<title\b[^>]*>.*?</title>~is', $html)) $html = preg_replace_callback('~<title\b[^>]*>.*?</title>~is', fn() => '<title>' . $escaped . '</title>', $html, 1);
    else $html = preg_replace_callback('~</head>~i', fn() => '<title>' . $escaped . '</title></head>', $html, 1);
    if ($description !== null) {
        $html = preg_replace('~<meta\b(?=[^>]*\bname\s*=\s*["\']description["\'])[^>]*>~i', '', $html);
        $html = preg_replace_callback('~</head>~i', fn() => '<meta name="description" content="' . cmsEscape($description) . '"></head>', $html, 1);
    }
    return $html;
}

function cmsRichBlocks(DOMDocument $document): array {
    $xpath = new DOMXPath($document); $blocks = [];
    foreach ($xpath->query('//body//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6 or self::p or self::li or self::blockquote][not(ancestor::header or ancestor::footer or ancestor::nav or ancestor::script or ancestor::style or ancestor::li or ancestor::blockquote)]') as $node) {
        if (trim($node->textContent) === '' || $node->getElementsByTagName('img')->length || $node->getElementsByTagName('svg')->length || $node->getElementsByTagName('button')->length) continue;
        $blocks[hash('sha256', $node->getNodePath())] = $node;
    }
    return $blocks;
}

function cmsInnerHtml(DOMElement $element): string {
    $html = ''; foreach ($element->childNodes as $child) $html .= $element->ownerDocument->saveHTML($child); return $html;
}

function cmsSafeRichText(string $html): string {
    $document = cmsDocument('<!doctype html><html><body><div id="rich-root">' . $html . '</div></body></html>');
    $root = (new DOMXPath($document))->query('//*[@id="rich-root"]')->item(0);
    $clean = function (DOMNode $parent) use (&$clean): void {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof DOMComment) { $parent->removeChild($node); continue; }
            if (!$node instanceof DOMElement) continue;
            if (in_array(strtolower($node->tagName), ['script','style','iframe','object','embed','svg','math','form','input','button'], true)) { $parent->removeChild($node); continue; }
            $clean($node);
            if (!in_array(strtolower($node->tagName), ['b','strong','i','em','u','s','br','a','span','code','sup','sub'], true)) {
                if (in_array(strtolower($node->tagName), ['p','div'], true)) $node->appendChild($node->ownerDocument->createElement('br'));
                while ($node->firstChild) $parent->insertBefore($node->firstChild, $node); $parent->removeChild($node); continue;
            }
            foreach (iterator_to_array($node->attributes) as $attribute) if (!($node->tagName === 'a' && in_array($attribute->name, ['href', 'title'], true))) $node->removeAttribute($attribute->name);
            if ($node->tagName === 'a' && !preg_match('~^(?:https?://|mailto:|tel:|/(?!/)|#)~i', $node->getAttribute('href'))) $node->removeAttribute('href');
        }
    };
    $clean($root); return cmsInnerHtml($root);
}

function cmsApplyRichText(DOMDocument $document, array $submitted): void {
    foreach (cmsRichBlocks($document) as $key => $node) {
        if (!isset($submitted[$key]) || $submitted[$key] === cmsInnerHtml($node)) continue;
        $clean = cmsSafeRichText((string)$submitted[$key]);
        $fragmentDoc = cmsDocument('<!doctype html><html><body><div id="rich-root">' . $clean . '</div></body></html>');
        $root = (new DOMXPath($fragmentDoc))->query('//*[@id="rich-root"]')->item(0);
        while ($node->firstChild) $node->removeChild($node->firstChild);
        foreach (iterator_to_array($root->childNodes) as $child) $node->appendChild($document->importNode($child, true));
    }
}
