<?php
declare(strict_types=1);
require_once __DIR__ . '/cms.php';

function siteRepairAliases(): array {
    $aliases = [
        '/aia/products/k-laser-speciale-live-vet-series/' => '/asia/products/k-laser-speciale-live-vet-series/',
        '/technology/scientific-literature/' => '/scientific-literature/',
        '/technology/' => '/us/indiba-tecar-radiofrequency-therapy/',
        '/speak-to-an-expert/' => '/contact/',
        '/ia/' => '/rehabilitation/', '/ia/academy/' => '/trainings/', '/ia/academy/events/' => '/events/',
        '/ia/news/' => '/news/', '/ia/hall-of-fame/' => '/hall-of-fame/', '/ia/technology/' => '/us/indiba-tecar-radiofrequency-therapy/',
        '/iah/products/' => '/animal-health/', '/iah/treatments/' => '/animal-health/', '/iah/news/' => '/news/', '/iah/technology/' => '/us/indiba-tecar-radiofrequency-therapy/',
        '/iah/treatments/rehabilitation-and-recovery/' => '/rehabilitation-and-recovery/',
        '/iah/treatments/general-well-being/' => '/animal-well-being/', '/iah/treatments/pain-management/' => '/pain-management/',
        '/iah/treatments/injury-prevention/' => '/performance-and-injury-prevention/', '/iah/treatments/performance/' => '/performance-and-injury-prevention/',
        '/ia/treatments/sports-physio/' => '/sports/', '/ia/treatments/rehabilitation/' => '/rehabilitation/',
        '/ia/treatments/pelvic-health/' => '/pelvic-health/',
        '/idc/treatments/gynecoaesthetics/' => '/gynecoaesthetics/',
        '/news/animal-health/animal-health-ias-protocols/' => '/news/animal-health-ias-protocols/',
        '/products/equus/' => '/us/products/equus/',
        '/products/hairwave/' => '/us/products/hairwave/', '/products/hairwave-beauty/' => '/us/products/hairwave-beauty/',
        '/asia/products/hairwave/' => '/us/products/hairwave/', '/asia/products/hairwave-beauty/' => '/us/products/hairwave-beauty/',
        '/us/products/at7/' => '/products/at7/', '/us/products/at7-podiatry/' => '/products/at7-podiatry/',
        '/products/intracavitary-handle/' => '/accessories/pluma-intracavitary-handle/',
        '/events/fisioexpo-2023-movement-is-life/' => '/asia/events/fisioexpo-2023-movement-is-life/',
        '/asset/12978/' => '/hub-asset/combined-use-of-resistive-capacitive-monopolar-radio-frequency-rfmcr-at-448-khz-and-underwater-treadmill-in-the-conservative-treatment-of-grade-iii-medial-patellar-dislocation-4-clinical-cases/',
        '/asset/5540/' => '/hub-asset/treatment-of-neck-contusion-signs-in-a-dog-with-monopolar-radiofrequency-at-448-khz/',
        '/asset/5536/' => '/hub-asset/radiofrequency-at-448-khz-for-the-treatment-of-muscle-spasticity-in-a-dog-with-c2-c3-spinal-cord-injury/',
        '/asset/5470/' => '/hub-asset/treatment-using-448khz-capacitive-resistive-monopolar-radiofrequency-improves-pain-and-function-in-patients-with-osteoarthritis-of-the-knee-joint-a-randomised-controlled-trial/',
        '/k-laser-class-iv-laser-therapy/' => '/us/k-laser-class-iv-laser-therapy/',
        '/k-laser-class-iv-laser-therapy-for-veterinary-care/' => '/us/k-laser-class-iv-laser-therapy-for-veterinary-care/',
        '/asia/facial-rejuvenation/' => '/facial-rejuvenation/', '/asia/skin-tightening/' => '/skin-tightening/',
        '/asia/wellness-treatment/' => '/spa-wellness/', '/us/general-well-being/' => '/us/animal-well-being/',
        '/us/gynecoaesthetics/' => '/gynecoaesthetics/',
    ];
    return $aliases;
}

function siteCatalog(): array {
    $file = dirname(__DIR__) . '/storage/recovered-catalog.json';
    return is_file($file) ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
}
function siteTerm(string $term): string { return ['sport'=>'sports', 'athletic'=>'athletics', 'motor-sport'=>'motor-sports', 'soccer'=>'football', 'tenis'=>'tennis', 'piloto-de-rallies'=>'rally-driver', 'belleza'=>'beauty'][$term] ?? $term; }
function siteKnownTerms(): array {
    // These filter options occur in captured archives, including categories with no captured entries.
    return ['event-brands'=>['ceuticals'], 'event-category'=>['webinar','event','therapy','vacancy'], 'training-division'=>['animal-health','rehabilitation','beauty','medical-aesthetics','intimate-health','sports']];
}
function siteArchive(string $route, array $catalog): ?array {
    if (!preg_match('~^/(asia/|us/)?(trainings|scientific-literature|news|events|hall-of-fame|training-division|event-brands|event-category|event-type|hof-type|hof-category|brand-news|categories-news)/(.*)$~', $route, $match)) return null;
    $region = $match[1] ?: ''; $root = $match[2]; $tail = trim($match[3], '/');
    $section = ['training-division'=>'trainings', 'event-brands'=>'events', 'event-category'=>'events', 'event-type'=>'events', 'brand-news'=>'news', 'categories-news'=>'news', 'hof-type'=>'hall-of-fame', 'hof-category'=>'hall-of-fame'][$root] ?? $root;
    $groups = ['trainings'=>['training-division','training-category','training-type'], 'scientific-literature'=>['hub-categories'], 'news'=>['brand-news','categories-news'], 'events'=>['event-brands','event-category','event-type'], 'hall-of-fame'=>['member_cat','hof-type']][$section];
    $terms = array_fill_keys($groups, []);
    foreach (siteKnownTerms() as $group=>$values) if (isset($terms[$group])) foreach ($values as $value) $terms[$group][$value] = true;
    foreach ($catalog as $item) if ($item['section'] === $section) foreach ($item['taxonomies'] as $key=>$values) if (isset($terms[$key])) foreach ($values as $value) $terms[$key][siteTerm($value)] = true;
    $parts = $tail === '' ? [] : explode('/', $tail); $filters = [];
    if ($root !== $section) {
        $group = $root === 'hof-category' ? 'member_cat' : $root;
        $term = array_shift($parts);
        if (!$term || !isset($terms[$group][siteTerm($term)])) return null;
        $filters[$group] = [siteTerm($term)];
    }
    foreach ($parts as $part) {
        $matched = false;
        foreach ($groups as $group) if (str_starts_with($part, $group . '-')) {
            $values = array_map('siteTerm', explode('-or-', substr($part, strlen($group) + 1)));
            foreach ($values as $value) if (!isset($terms[$group][$value])) return null;
            $filters[$group] = $values; $matched = true; break;
        }
        if (!$matched) return null;
    }
    return compact('region', 'section', 'groups', 'terms', 'filters');
}

// Reuse the regional website header, footer, fonts, and navigation for recovered content.
function siteDocument(string $template, string $content, string $title, string $route): string {
    $doc = cmsDocument($template); $xpath = new DOMXPath($doc);
    $body = $doc->getElementsByTagName('body')->item(0); $body->setAttribute('class', $body->getAttribute('class') . ' recovered-layout');
    $logos = [
        '/assets/2c6a057e32f8aaa8de32ad5ddce6624908c97669a5da2e8fd70f05310df04872.svg'=>'/assets/e7a7b8269c05a8877f85d861e39dfc0abaa4b463d30a048209ac8faf5087f141.svg',
        '/assets/de1a3ccc0cc24848ec1931dc5c7c6c842f4a6e469ce20ebc4d37c5d652a7ae8a.svg'=>'/assets/a0b667491349395cf2a7c38b2395f8cd40ee1eb0ee7a0dd57d2b9c21347870db.svg',
        '/assets/b96f8684c775a68e54479e4976226f4d45fe9aea1b805c2870cbab7e4c4a2123.svg'=>'/assets/dcac6e61d47e45eef46d5f4e69fd717c38651b455c6ddebfedf7783610aee5bb.svg',
    ];
    foreach ($xpath->query('//header//img') as $image) foreach (['src','data-src','data-lazy-src'] as $attribute) if (isset($logos[$image->getAttribute($attribute)])) { $image->setAttribute($attribute, $logos[$image->getAttribute($attribute)]); $image->removeAttribute('srcset'); }
    $main = $xpath->query('//*[@id="main"]')->item(0);
    if (!$main) throw new RuntimeException('The regional website layout is unavailable.');
    while ($main->firstChild) $main->removeChild($main->firstChild);
    $fragment = cmsDocument('<html><body><div id="recovered-root">' . $content . '</div></body></html>');
    $root = (new DOMXPath($fragment))->query('//*[@id="recovered-root"]')->item(0);
    foreach (iterator_to_array($root->childNodes) as $child) $main->appendChild($doc->importNode($child, true));
    $link = $doc->createElement('link'); $link->setAttribute('rel', 'stylesheet'); $link->setAttribute('href', '/recovered-pages.css'); $doc->getElementsByTagName('head')->item(0)->appendChild($link);
    foreach ($xpath->query('//link[@rel="canonical"] | //meta[@property="og:url"]') as $node) $node->setAttribute($node->tagName === 'link' ? 'href' : 'content', $route);
    foreach ($xpath->query('//meta[@property="og:title"] | //meta[@name="twitter:title"]') as $node) $node->setAttribute('content', $title);
    foreach ($xpath->query('//meta[@name="description"] | //meta[@property="og:description"] | //script[@type="application/ld+json"]') as $node) $node->parentNode->removeChild($node);
    $doc->getElementsByTagName('title')->item(0)->textContent = $title . ' · INDIBA';
    return cmsDocumentHtml($doc);
}

function siteServeRepair(string $route): bool {
    $installedFile = getenv('CMS_INSTALL_FILE') ?: dirname(__DIR__) . '/storage/cms-installed.json';
    if (getenv('CMS_ENABLED') !== '1' && !is_file($installedFile)) return false;
    $aliases = siteRepairAliases();
    if (isset($aliases[$route])) {
        $target = $aliases[$route];
        $query = database()->prepare('SELECT status FROM indiba_cms_pages WHERE route = ?'); $query->execute([$target]); $status = $query->fetchColumn();
        if ($status === 'published' || !$status && siteArchive($target, siteCatalog())) { header('Location: ' . $target, true, 301); return true; }
        return false;
    }
    $catalog = siteCatalog(); $archive = siteArchive($route, $catalog);
    if (!$catalog || !$archive) return false;
    header('Content-Type: text/html; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') return true;
    require __DIR__ . '/site-archive.php';
    return true;
}
