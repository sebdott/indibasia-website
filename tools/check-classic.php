<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/lib/cms-classic.php';
$manifest = json_decode(file_get_contents(dirname(__DIR__) . '/storage/manifest.json'),true);
foreach (['/', '/asia/products/ct8/', '/about-us/'] as $route) {
    $document = cmsDocument(file_get_contents(dirname(__DIR__) . '/storage/' . $manifest['pages'][$route]['file']));
    $state = cmsClassicPrepare($document); if (!$state['available']) throw new RuntimeException('Classic content unavailable: ' . $route);
    $before = []; foreach (['script','style','svg','form','iframe'] as $tag) $before[$tag]=$document->getElementsByTagName($tag)->length;
    cmsClassicApply($document,$state['content'] . '<p id="classic-safety-test" onclick="alert(1)"><strong>Classic test</strong><a href="javascript:alert(1)">Unsafe link</a></p><script>alert(1)</script><iframe src="https://example.com/"></iframe>');
    $after = []; foreach (['script','style','svg','form','iframe'] as $tag) $after[$tag]=$document->getElementsByTagName($tag)->length;
    if ($before !== $after) throw new RuntimeException('Original design elements were changed: ' . $route . ' ' . json_encode([$before,$after]));
    $xpath = new DOMXPath($document); $test=$xpath->query('//*[@id="classic-safety-test"]')->item(0);
    if (!$test || $test->hasAttribute('onclick') || $test->getElementsByTagName('a')->item(0)->hasAttribute('href')) throw new RuntimeException('Classic content filtering failed.');
    echo "$route: protected elements retained and unsafe markup removed.\n";
}
echo "Classic content checks passed.\n";
