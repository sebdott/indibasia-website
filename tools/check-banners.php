<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/lib/cms-classic.php';
function bannerCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root . '/storage/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$image = '/uploads/banner-test.png';
foreach (['/', '/about-us/', '/rehabilitation/', '/asia/products/ct8/', '/news/', '/us/events/', '/events/how-indiba-works-at-a-cellular-level/', '/us/events/indiba-compact-a-tool-for-treatment-and-business/', '/asia/news/physiotherapy-for-cats-enhancing-feline-wellbeing-with-indibas-radiofrequency/'] as $route) {
    $document = cmsDocument(file_get_contents($root . '/storage/' . $manifest['pages'][$route]['file']));
    $state = cmsBannerState($document); bannerCheck((bool)$state, 'Missing banner: ' . $route);
    if (str_contains($route, '/events/indiba-compact-') || str_contains($route, '/events/how-indiba-') || str_contains($route, '/news/physiotherapy-for-cats-')) {
        $featured = (new DOMXPath($document))->query('//meta[@property="og:image"]')->item(0)->getAttribute('content');
        foreach ($state as $banner) bannerCheck($banner['image'] === $featured, 'News/event previews must select the declared featured attachment: ' . $route);
    }
    if ($route !== '/') bannerCheck(in_array('Banner title', array_column(reset($state)['fields'], 'label'), true), 'Visible banner heading must be labeled as its title.');
    $before = cmsDocumentHtml($document); $submitted = [];
    foreach ($state as $key=>$banner) $submitted[$key] = ['image'=>$banner['image'], 'text'=>array_map(fn($field)=>$field['value'], $banner['fields'])];
    bannerCheck(!cmsBannerApply($document, $submitted, $state), 'Unchanged fields should be a no-op.');
    bannerCheck(cmsDocumentHtml($document) === $before, 'No-op altered the banner: ' . $route);
    $key = array_key_first($state); $banner = $state[$key]; $fieldKey = array_key_first($banner['fields']);
    $submitted[$key]['image'] = $image;
    if ($fieldKey !== null) $submitted[$key]['text'][$fieldKey] = 'Updated banner <safe> & English wording';
    else $submitted[$key]['title'] = 'Updated banner <safe> & English wording';
    // Simulate TinyMCE normalization before applying independently edited banner fields.
    $classic = cmsClassicPrepare($document);
    if ($classic['available']) cmsClassicApply($document, $classic['content'] . '<p>Updated body content.</p>');
    bannerCheck(cmsBannerApply($document, $submitted, $state), 'Banner mutation not applied.');
    $saved = cmsDocument(cmsDocumentHtml($document)); $next = cmsBannerState($saved);
    bannerCheck($next[$key]['image'] === $image, 'Featured image did not survive saving: ' . $route);
    bannerCheck(in_array('Updated banner <safe> & English wording', array_column($next[$key]['fields'], 'value'), true), 'Banner wording did not survive saving: ' . $route);
    bannerCheck(!(new DOMXPath($saved))->query('//safe')->length, 'Banner text must be escaped.');
    if ($banner['video']) bannerCheck(!$next[$key]['video'], 'Featured image must replace video playback.');
    $submitted = [$key=>['image'=>'']]; cmsBannerApply($saved, $submitted, $next);
    bannerCheck(cmsBannerState($saved)[$key]['image'] === '', 'Removed image was not retained.');
    echo "PASS $route: discovery, no-op, combined content edits, image/wording save, and removal\n";
}
$document = cmsDocument('<!doctype html><html><head><title>Test</title></head><body><header>Navigation</header><main><p>Body content.</p></main><footer>Footer</footer></body></html>');
bannerCheck(cmsBannerApply($document, [], [], ['image'=>$image, 'title'=>'New banner', 'description'=>'New description']), 'Unable to add a banner.');
$state = cmsBannerState($document); bannerCheck(count($state) === 1 && reset($state)['image'] === $image, 'New banner not editable.');
bannerCheck($document->getElementsByTagName('header')->item(0)->textContent === 'Navigation' && $document->getElementsByTagName('footer')->item(0)->textContent === 'Footer', 'Adding a banner altered navigation/footer.');
echo "PASS Creating a banner preserves the page content and navigation\n";
$document = cmsDocument('<html><head></head><body><main><section data-cms-banner="header"><h1>Heading</h1><img src="/assets/old.png" srcset="/assets/other.png 2x" data-src="/assets/old.png"></section></main></body></html>');
foreach ([$image, '', '/uploads/second.png'] as $url) {
    $state = cmsBannerState($document); $key = array_key_first($state); cmsBannerApply($document, [$key=>['image'=>$url]], $state);
    $document = cmsDocument(cmsDocumentHtml($document)); $next = cmsBannerState($document);
    bannerCheck($next[$key]['kind'] === 'image' && $next[$key]['image'] === $url, 'Image element mode not retained.');
    bannerCheck(!$document->getElementsByTagName('img')->item(0)->hasAttribute('srcset'), 'Old responsive image source retained.');
}
echo "PASS Image elements support replacement, removal, and reselection\n";
$document = cmsDocument(file_get_contents($root . '/storage/' . $manifest['pages']['/us/test22/']['file']));
$state = cmsBannerState($document); $key = array_key_first($state); $banner = $state[$key];
$xpath = new DOMXPath($document); $target = $xpath->query($banner['locator'])->item(0);
$background = cmsBannerBackground($target, cmsBannerCss($document));
$product = $xpath->query('.//img[@alt="reverso-device-final"]', $target)->item(0);
bannerCheck($product instanceof DOMElement && $background !== '', 'Reverso fixture must include both a product image and a decorative background.');
bannerCheck($banner['kind'] === 'image' && $banner['image'] === $product->getAttribute('src') && $banner['image'] !== $background, 'Featured image must select the Reverso product instead of its decorative background.');
foreach ([$image, '', '/uploads/reselected-product.png'] as $url) {
    cmsBannerApply($document, [$key=>['image'=>$url]], $state);
    $document = cmsDocument(cmsDocumentHtml($document)); $state = cmsBannerState($document);
    $target = (new DOMXPath($document))->query($state[$key]['locator'])->item(0);
    bannerCheck($state[$key]['kind'] === 'image' && $state[$key]['image'] === $url, 'Reverso product image selection must survive replacement, removal, and reselection.');
    $backgroundNode = $target->cloneNode(true); $backgroundNode->removeAttribute('data-cms-featured-image');
    bannerCheck(cmsBannerBackground($backgroundNode, cmsBannerCss($document)) === $background, 'Product edits must preserve the decorative banner background.');
}
echo "PASS Reverso product image is selected, editable, and separate from its decorative background\n";
$document = cmsDocument('<html><head></head><body><main><section data-cms-banner="header" data-cms-featured-kind="background" data-cms-featured-image="/uploads/saved-background.png"><h1>Heading</h1><img src="/assets/product.png"></section></main></body></html>');
$state = cmsBannerState($document);
bannerCheck(reset($state)['kind'] === 'background' && reset($state)['image'] === '/uploads/saved-background.png', 'Explicit saved background selections must remain selected.');
$document = cmsDocument('<html><head></head><body><main><section data-cms-banner="header"><h1>Heading</h1><div hidden><img src="/assets/hidden-product.png"></div><img src="/assets/visible-product.png"></section></main></body></html>');
$state = cmsBannerState($document);
bannerCheck(reset($state)['image'] === '/assets/visible-product.png', 'Hidden images must not be selected ahead of visible images.');
foreach ([$image, '', '/uploads/visible-product.png'] as $url) {
    cmsBannerApply($document, [array_key_first($state)=>['image'=>$url]], $state);
    $document = cmsDocument(cmsDocumentHtml($document)); $state = cmsBannerState($document);
    bannerCheck(reset($state)['image'] === $url, 'The selected foreground image must remain selected when other hidden images precede it.');
}
echo "PASS Explicit background choices and hidden foreground images are handled correctly\n";
$document = cmsDocument('<html><head><meta property="og:image" content="javascript:alert(1)"><meta name="twitter:image" content="/assets/featured.png"></head><body class="single-news"><main><section data-cms-banner="header" style="background-image:url(/assets/pattern.png)"><h1>News title</h1></section></main></body></html>');
$state = cmsBannerState($document);
bannerCheck(reset($state)['image'] === '/assets/featured.png', 'Unsafe metadata images must be skipped for a valid featured attachment.');
$pageState = cmsBannerState($document, 'page');
bannerCheck(reset($pageState)['image'] === '/assets/pattern.png', 'Metadata fallback must respect the current content section.');
$state = cmsBannerState($document); $key = array_key_first($state);
foreach ([$image, '', '/uploads/reselected-featured.png'] as $url) {
    cmsBannerApply($document, [$key=>['image'=>$url]], $state);
    $document = cmsDocument(cmsDocumentHtml($document)); $state = cmsBannerState($document);
    bannerCheck($state[$key]['image'] === $url, 'Saved and removed featured images must take precedence over metadata.');
}
echo "PASS News/event metadata fallback, section selection, replacement, and removal\n";
foreach (['javascript:alert(1)', '/assets/../private.png', 'http://example.com/image.png'] as $url) {
    $state = cmsBannerState($document); $rejected = false;
    try { cmsBannerApply($document, [array_key_first($state)=>['image'=>$url]], $state); } catch (RuntimeException $e) { $rejected = true; }
    bannerCheck($rejected, 'Unsafe featured image URL accepted.');
}
echo "PASS Invalid featured image URLs are rejected\n";
$document = cmsDocument('<html><head></head><body><main><section data-cms-banner="header"><h1>Hello <strong>world</strong></h1><p>Introduction</p></section></main></body></html>');
$state = cmsBannerState($document); $key = array_key_first($state); $fieldKey = array_key_first($state[$key]['fields']);
cmsBannerApply($document, [$key=>['text'=>[$fieldKey=>'Welcome']]], $state);
bannerCheck($document->getElementsByTagName('h1')->item(0)->textContent === 'Welcome world', 'Editing wording removed spacing around inline formatting.');
$document = cmsDocument('<html><head></head><body><main><section data-cms-banner="header"><h1></h1><p></p></section></main></body></html>');
$state = cmsBannerState($document); cmsBannerApply($document, [array_key_first($state)=>['title'=>'Replacement title', 'description'=>'Replacement description']], $state);
bannerCheck($document->getElementsByTagName('h1')->length === 1 && $document->getElementsByTagName('p')->length === 1, 'Restoring empty wording should reuse existing elements.');
echo "PASS Inline wording spacing and empty heading restoration\n";
