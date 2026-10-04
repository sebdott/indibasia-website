<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/lib/cms-classic.php';
$manifest = json_decode(file_get_contents(dirname(__DIR__) . '/storage/manifest.json'),true);
foreach (['/', '/asia/products/ct8/', '/about-us/', '/asia/news/physiotherapy-for-cats-enhancing-feline-wellbeing-with-indibas-radiofrequency/'] as $route) {
    $document = cmsDocument(file_get_contents(dirname(__DIR__) . '/storage/' . $manifest['pages'][$route]['file']));
    $state = cmsClassicPrepare($document); if (!$state['available']) throw new RuntimeException('Classic content unavailable: ' . $route);
    $banners = [];
    foreach (cmsBannerTargets($document) as $banner) $banners[cmsBannerLocator($banner)] = $document->saveHTML($banner);
    if (!$state['banner_locks']) throw new RuntimeException('Banner not protected in Classic: ' . $route);
    $editorDocument = cmsDocument('<html><body>' . $state['content'] . '</body></html>');
    $editorXPath = new DOMXPath($editorDocument);
    foreach ($banners as $locator=>$bannerHtml) if ($editorXPath->query($locator)->length) throw new RuntimeException('Banner leaked into the body editor: ' . $route);
    $before = []; foreach (['script','style','svg','form','iframe'] as $tag) $before[$tag]=$document->getElementsByTagName($tag)->length;
    cmsClassicApply($document,$state['content'] . '<p id="classic-safety-test" onclick="alert(1)"><strong>Classic test</strong><a href="javascript:alert(1)">Unsafe link</a></p><script>alert(1)</script><iframe src="https://example.com/"></iframe>');
    $after = []; foreach (['script','style','svg','form','iframe'] as $tag) $after[$tag]=$document->getElementsByTagName($tag)->length;
    if ($before !== $after) throw new RuntimeException('Original design elements were changed: ' . $route . ' ' . json_encode([$before,$after]));
    $xpath = new DOMXPath($document); $test=$xpath->query('//*[@id="classic-safety-test"]')->item(0);
    if (!$test || $test->hasAttribute('onclick') || $test->getElementsByTagName('a')->item(0)->hasAttribute('href')) throw new RuntimeException('Classic content filtering failed.');
    foreach ($banners as $locator=>$bannerHtml) {
        $banner = $xpath->query($locator)->item(0);
        if (!$banner || $document->saveHTML($banner) !== $bannerHtml) throw new RuntimeException('Saving body content changed the banner: ' . $route);
    }
    echo "$route: banner hidden from editor and preserved on save; unsafe markup removed.\n";
}
$document = cmsDocument('<html><head></head><body><main><section data-cms-banner="header"><h1>Original banner</h1><p>Original description</p><svg><path></path></svg></section><p>Original body</p></main></body></html>');
$bannerHtml = $document->saveHTML(cmsBannerTargets($document)[0]);
cmsClassicApply($document, '<p id="replacement-body">Entirely new body</p>');
$xpath = new DOMXPath($document);
$banner = $xpath->query('//main/section[@data-cms-banner="header"]')->item(0);
if (!$banner || $document->saveHTML($banner) !== $bannerHtml || !$banner->nextSibling?->isSameNode($xpath->query('//*[@id="replacement-body"]')->item(0))) throw new RuntimeException('Replacing body HTML must retain the banner before the body.');
if ($xpath->query('//*[@data-cms-lock]')->length) throw new RuntimeException('Editor placeholders leaked into the saved page.');
echo "Replacing body HTML retains the original banner above the body.\n";
echo "Classic content checks passed.\n";
