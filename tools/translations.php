<?php
/** Extract one English dictionary; merge multiple translated files in one command. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/lib/translation.php';
$config = require dirname(__DIR__) . '/config.php';
$command = $argv[1] ?? 'extract';
$directory = dirname(__DIR__) . '/locales';
if ($command === 'extract') {
    $manifest = json_decode(file_get_contents($config['storage'] . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $dictionary = englishTranslations(); $contexts = []; $seen = [];
    foreach ($manifest['pages'] as $route => $page) {
        if (isset($seen[$page['raw']])) continue;
        $seen[$page['raw']] = true;
        $dom = new DOMDocument(); $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . file_get_contents($config['storage'] . '/' . $page['raw']), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        foreach (translatableNodes($dom) as $node) {
            $text = trim($node->nodeValue); $key = translationKey($text);
            if (!isset($dictionary[$key])) $dictionary[$key] = $text;
            if (count($contexts[$key] ?? []) < 5 && !in_array($route, $contexts[$key] ?? [], true)) $contexts[$key][] = $route;
        }
    }
    ksort($dictionary);
    file_put_contents("$directory/en.json", json_encode($dictionary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    file_put_contents($config['storage'] . '/translation-context.json', json_encode($contexts, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo count($dictionary) . " English strings saved in locales/en.json.\n";
} elseif ($command === 'merge') {
    // Supply all translated JSON files together; each filename is its language code.
    // This writes language packs for future use. The public site continues to use English.
    $files = array_slice($argv, 2);
    if (!$files) { fwrite(STDERR, "Usage: php tools/translations.php merge path/to/fr.json path/to/es.json ...\n"); exit(1); }
    $english = englishTranslations(); $prepared = [];
    foreach ($files as $file) {
        if (!is_file($file)) { fwrite(STDERR, "Missing file: $file\n"); exit(1); }
        $code = strtolower(pathinfo($file, PATHINFO_FILENAME));
        if (!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $code) || $code === 'en') { fwrite(STDERR, "Invalid target language filename: $file\n"); exit(1); }
        $translations = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($translations) || array_is_list($translations)) { fwrite(STDERR, "Expected a JSON dictionary: $file\n"); exit(1); }
        foreach ($translations as $key => $value) {
            if (!isset($english[$key]) || !is_string($value)) { fwrite(STDERR, "Invalid key or value in $file: $key\n"); exit(1); }
        }
        $prepared[$code] = array_replace($english, $translations);
    }
    foreach ($prepared as $code => $dictionary) {
        file_put_contents("$directory/$code.json", json_encode($dictionary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        echo "$code.json saved; " . count($dictionary) . " entries.\n";
    }
} else {
    fwrite(STDERR, "Use extract or merge.\n"); exit(1);
}
