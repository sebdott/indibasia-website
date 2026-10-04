<?php
/** Recover missing local content without changing existing CMS edits or publication states. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/lib/cms.php';
require dirname(__DIR__) . '/lib/site-repairs.php';
$config = require dirname(__DIR__) . '/config.php';
$credentials = databaseConfig();
if ($credentials['DB_HOST'] !== 'db' || $credentials['DB_DATABASE'] !== 'indiba_local') throw new RuntimeException('Repairs are restricted to the local Compose database.');
$manifest = json_decode(file_get_contents($config['storage'] . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$catalog = [];
foreach ($manifest['pages'] as $route=>$page) {
    if (!preg_match('~^/(asia/|us/)?(training|hub-asset|news|events|hall-of-fame)/[^/]+/$~', $route, $match)) continue;
    $html = file_get_contents($config['storage'] . '/' . $page['file']);
    $postType = $match[2] === 'hall-of-fame' ? 'member' : $match[2];
    if (!preg_match('~<body\b[^>]*\bclass=["\'][^"\']*\bsingle-' . preg_quote($postType, '~') . '\b~i', $html)) continue;
    $doc = cmsDocument($html); $xpath = new DOMXPath($doc); $taxonomies = [];
    foreach ($xpath->query('//article[@class]') as $article) foreach (preg_split('/\s+/', $article->getAttribute('class')) as $class) {
        if (preg_match('/^(training-division|training-category|training-type|brand-news|categories-news|event-brands|event-category|event-type|member_cat|hof-type|hub-categories)-(.+)$/', $class, $taxonomy)) $taxonomies[$taxonomy[1]][] = $taxonomy[2];
    }
    $title = html_entity_decode($doc->getElementsByTagName('title')->item(0)?->textContent ?? $route, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $main = $xpath->query('//*[@id="content"] | //*[@id="main"]')->item(0);
    $excerpt = '';
    foreach ($xpath->query('.//p[not(ancestor::form)]', $main) as $paragraph) { $text = trim(preg_replace('/\s+/', ' ', $paragraph->textContent)); if (strlen($text) > 60) { preg_match('/\A.{0,180}/us', $text, $excerptMatch); $excerpt = ($excerptMatch[0] ?? '') . (strlen($text) > strlen($excerptMatch[0] ?? '') ? '…' : ''); break; } }
    $section = ['training'=>'trainings', 'hub-asset'=>'scientific-literature'][$match[2]] ?? $match[2];
    $catalog[$route] = ['section'=>$section, 'region'=>$match[1] ?: '', 'title'=>$title, 'excerpt'=>$excerpt, 'taxonomies'=>$taxonomies];
}
file_put_contents($config['storage'] . '/recovered-catalog.json.tmp', json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
rename($config['storage'] . '/recovered-catalog.json.tmp', $config['storage'] . '/recovered-catalog.json');
echo count($catalog) . " existing articles indexed for local archive filters.\n";
$db = database(); $existing = $db->query('SELECT route, source_file, html, version, status FROM indiba_cms_pages')->fetchAll(PDO::FETCH_UNIQUE);
$snapshot = fn(string $route)=>file_get_contents($config['storage'] . '/' . $manifest['pages'][$route]['file']);
$created = [];
$add = function(string $route, string $title, string $html) use ($db, $config, &$existing, &$created): void {
    $file = 'pages/recovered-' . hash('sha256', $route) . '.html';
    if (isset($existing[$route])) {
        $current = $existing[$route];
        if ($current['source_file'] === $file && $current['html'] === null && (int)$current['version'] === 1 && $current['status'] === 'published') file_put_contents($config['storage'] . '/' . $file, $html);
        return;
    }
    file_put_contents($config['storage'] . '/' . $file, $html);
    $db->prepare("INSERT INTO indiba_cms_pages (route, title, source_file, content_type, group_name) VALUES (?, ?, ?, 'page', 'Recovered pages')")->execute([$route, $title, $file]);
    cmsAudit('recover_page', $route); $existing[$route] = ['source_file'=>$file, 'html'=>null, 'version'=>1, 'status'=>'published']; $created[] = $route;
};
$db->beginTransaction();
try {
    // EQUUS content already exists in the downloaded US site; retain the Asia navigation around it.
    if (!isset($existing['/asia/products/equus/'])) {
        $equus = cmsDocument($snapshot('/us/products/equus/')); $asia = cmsDocument($snapshot('/asia/animal-health/'));
        $xpath = new DOMXPath($equus); $asiaXpath = new DOMXPath($asia);
        foreach (['header','footer'] as $tag) { $old = $equus->getElementsByTagName($tag)->item(0); $new = $asia->getElementsByTagName($tag)->item(0); if ($old && $new) $old->parentNode->replaceChild($equus->importNode($new, true), $old); }
        foreach ($asiaXpath->query('//head/link[@rel="stylesheet"]') as $style) $equus->getElementsByTagName('head')->item(0)->appendChild($equus->importNode($style, true));
        foreach ($xpath->query('//link[@rel="canonical"] | //meta[@property="og:url"]') as $node) $node->setAttribute($node->tagName === 'link' ? 'href' : 'content', '/asia/products/equus/');
        $add('/asia/products/equus/', 'EQUUS · INDIBA', cmsDocumentHtml($equus));
    }
    foreach (['', 'asia/', 'us/'] as $region) {
        $contact = '/' . $region . 'contact/';
        // This overview uses existing animal-health and accessory content/assets; unavailable specifications are omitted.
        $content = '<main class="recovered-page"><section class="recovered-product"><div><p class="recovered-eyebrow">INDIBA Animal Health</p><h1>AH-100</h1><p>Radiofrequency technology for small-animal veterinary care.</p><p>The AH-100 is INDIBA’s device for small animals. Explore the technology, compatible accessories, and veterinary training resources.</p><a class="recovered-button" href="' . $contact . '">Speak to an Expert</a></div><img src="/assets/28a5566a30f32fbd34bedcd8bddcf13a36ebf3ebd2cd191914fbdd980547bba7.png" alt="INDIBA AH-100 veterinary radiofrequency device"></section><section class="recovered-section"><h2>Technology for animal health</h2><p>INDIBA Animal Health uses radiofrequency at 448 kHz. The AH-100 includes IAS protocols developed for veterinary treatments.</p><a href="/' . $region . 'animal-health/">Explore Animal Health →</a></section><section class="recovered-section"><h2>Accessories and consumables</h2><div class="recovered-grid"><article class="recovered-card"><h2>Handles and electrodes</h2><p>Capacitive and resistive electrodes in different sizes for small animals.</p><a href="/accessories/indiba-ah-100-handles-and-electrodes/">View handles and electrodes →</a></article><article class="recovered-card"><h2>Conductive media</h2><p>Explore INDIBA veterinary conductive gel and Proionic VET lotion.</p><a href="/accessories/indiba-conductive-media/">View conductive media →</a></article><article class="recovered-card"><h2>Veterinary training</h2><p>Browse veterinary courses and training resources.</p><a href="/' . $region . 'trainings/?training-division=animal-health">View training →</a></article></div></section></main>';
        $route = '/' . $region . 'products/ah-100/';
        $add($route, 'AH-100 · INDIBA', siteDocument($snapshot('/' . $region . 'animal-health/'), $content, 'AH-100', $route));
    }
    foreach (['', 'asia/'] as $region) {
        $route = '/' . $region . 'products/aero-flow/';
        $content = '<main class="recovered-page"><section class="recovered-product"><div><p class="recovered-eyebrow">INDIBA Rehabilitation</p><h1>AEROFLOW</h1><p>Explore INDIBA’s AEROFLOW accessory and its training resources.</p><a class="recovered-button" href="/' . $region . 'contact/">Speak to an Expert</a></div><img src="/assets/2a3ff16d62dc263cfe2b4793f653050c9f1a5374ae76099832ee6c3746ff414e.png" alt="INDIBA AEROFLOW"></section><section class="recovered-section"><h2>Training and resources</h2><div class="recovered-grid"><article class="recovered-card"><h2>Initial training</h2><a href="/training/indiba-activ-aero-flow-initial-training/">Explore AEROFLOW initial training →</a></article><article class="recovered-card"><h2>Advanced training</h2><a href="/training/indiba-activ-aeroflow-advance-training/">Explore AEROFLOW advanced training →</a></article></div></section></main>';
        $add($route, 'AEROFLOW · INDIBA', siteDocument($snapshot('/' . $region . 'animal-health/'), $content, 'AEROFLOW', $route));
    }
    $content = '<main class="recovered-page"><section class="recovered-product"><div><p class="recovered-eyebrow">INDIBA Animal Health</p><h1>VET905</h1><p>INDIBA’s veterinary radiofrequency device for equine care.</p><p>Learn about INDIBA Animal Health and explore veterinary resources.</p><a class="recovered-button" href="/animal-health/">Explore Animal Health</a></div><img src="/assets/d6f3928ca0cecff3f2f526f9b4b48cf8118abf22e7d649840918bb0a3d4035f0.jpg" alt="INDIBA veterinary devices"></section><section class="recovered-section"><h2>Related resources</h2><p><a href="/news/indiba-animal-health-conquers-japan/">INDIBA Animal Health in Japan →</a></p><p><a href="/trainings/?training-division=animal-health">Veterinary training →</a></p></section></main>';
    $add('/products/vet-905/', 'VET905 · INDIBA', siteDocument($snapshot('/animal-health/'), $content, 'VET905', '/products/vet-905/'));
    $content = '<main class="recovered-page"><header class="recovered-heading"><p class="recovered-eyebrow">INDIBA Beauty</p><h1>EDNA series</h1><p>Explore the INDIBA EDNA range.</p></header><div class="recovered-grid">';
    foreach (['edna-one'=>'EDNA One', 'edna-plus'=>'EDNA Plus', 'edna-pro-max'=>'EDNA Pro Max'] as $slug=>$label) $content .= '<article class="recovered-card"><h2>' . $label . '</h2><a href="/products/' . $slug . '/">Explore ' . $label . ' →</a></article>';
    $content .= '</div></main>';
    $add('/products/edna-series/', 'EDNA series · INDIBA', siteDocument($snapshot('/animal-health/'), $content, 'EDNA series', '/products/edna-series/'));
    $db->commit();
} catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
foreach ($created as $route) echo "Recovered $route\n";
echo count($created) . " pages added; existing pages and accounts preserved.\n";
