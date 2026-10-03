<?php
declare(strict_types=1);

function translationKey(string $text): string {
    return 't_' . substr(hash('sha256', preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text)), 0, 20);
}
function englishTranslations(): array {
    $file = dirname(__DIR__) . '/locales/en.json';
    if (!is_file($file)) return [];
    return json_decode(file_get_contents($file), true) ?: [];
}
function translatableNodes(DOMDocument $dom): array {
    $xpath = new DOMXPath($dom);
    $result = [];
    foreach ($xpath->query('//text()[not(ancestor::script or ancestor::style or ancestor::noscript or ancestor::svg or ancestor::code or ancestor::pre)] | //@alt | //@title | //@placeholder | //@aria-label') as $node) {
        $text = trim($node->nodeValue);
        if ($text === '' || !preg_match('/[a-zA-Z]/', $text) || preg_match('~^(?:https?://|data:|[\w.+-]+@[\w.-]+\.[a-z]+$)~i', $text)) continue;
        $parent = $node instanceof DOMAttr ? $node->ownerElement : $node->parentNode;
        $foreignLink = false;
        while ($parent instanceof DOMElement) {
            if ($parent->tagName === 'a' && preg_match('~^(?:https?://(?:www\.)?indiba\.com)?/(?:es|fr|it)(?:/|$)~', $parent->getAttribute('href'))) { $foreignLink = true; break; }
            $parent = $parent->parentNode;
        }
        if ($foreignLink) continue;
        $result[] = $node;
    }
    return $result;
}
function applyEnglishTranslations(string $html, array $dictionary): string {
    if (!$dictionary) return $html;
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    $changed = false;
    foreach (translatableNodes($dom) as $node) {
        $original = trim($node->nodeValue);
        $key = translationKey($original);
        if (!isset($dictionary[$key]) || !is_string($dictionary[$key]) || translationKey($dictionary[$key]) === $key) continue;
        preg_match('/^(\s*)(.*?)(\s*)$/s', $node->nodeValue, $parts);
        $node->nodeValue = ($parts[1] ?? '') . $dictionary[$key] . ($parts[3] ?? '');
        $changed = true;
    }
    if (!$changed) return $html;
    $output = $dom->saveHTML();
    return preg_replace('/<\?xml encoding="UTF-8"\s*\?>/', '', $output) ?? $html;
}
