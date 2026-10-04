<?php
declare(strict_types=1);
require_once __DIR__ . '/cms.php';

function cmsBannerXPathValue(string $value): string {
    if (!str_contains($value, "'")) return "'" . $value . "'";
    return 'concat(' . implode(', "\'", ', array_map(fn($part) => "'" . $part . "'", explode("'", $value))) . ')';
}

// Elementor IDs survive Classic editor normalization; paths handle ordinary HTML.
function cmsBannerLocator(DOMNode $node): string {
    for ($parent = $node; $parent instanceof DOMNode; $parent = $parent->parentNode) {
        if (!$parent instanceof DOMElement) continue;
        foreach (['data-cms-banner', 'data-id', 'id'] as $attribute) {
            $value = $parent->getAttribute($attribute);
            if ($value === '') continue;
            $anchor = '//*[@' . $attribute . '=' . cmsBannerXPathValue($value) . ']';
            if ((new DOMXPath($node->ownerDocument))->query($anchor)->length !== 1) continue;
            return $anchor . substr($node->getNodePath(), strlen($parent->getNodePath()));
        }
    }
    return $node->getNodePath();
}

function cmsBannerRoot(DOMDocument $document): ?DOMElement {
    $xpath = new DOMXPath($document);
    foreach (['//main', '//*[@data-elementor-type="wp-page"]', '//*[@id="content"]', '//*[@role="main"]', '//*[@data-elementor-type][.//h1][not(ancestor::header or ancestor::footer or ancestor::nav)]', '//*[@id="main"]'] as $query) {
        $node = $xpath->query($query)->item(0);
        if ($node instanceof DOMElement) return $node;
    }
    return null;
}

function cmsBannerHidden(DOMElement $node): bool {
    for ($parent = $node; $parent instanceof DOMElement; $parent = $parent->parentNode) {
        $classes = preg_split('/\s+/', $parent->getAttribute('class'));
        if ($parent->hasAttribute('hidden') || preg_match('/display\s*:\s*none/i', $parent->getAttribute('style'))) return true;
        if (!array_diff(['elementor-hidden-desktop', 'elementor-hidden-tablet', 'elementor-hidden-mobile'], $classes)) return true;
    }
    return false;
}

function cmsBannerTargets(DOMDocument $document): array {
    $xpath = new DOMXPath($document);
    $explicit = iterator_to_array($xpath->query('//*[@data-cms-banner]'));
    if ($explicit) return $explicit;
    $root = cmsBannerRoot($document);
    if (!$root) return [];
    $sections = $xpath->query('.//section[contains(concat(" ",normalize-space(@class)," ")," elementor-top-section ")][not(ancestor::section)] | .//*[@data-element_type="container"][not(ancestor::*[@data-element_type="container"])]', $root);
    $targets = [];
    foreach ($sections as $section) {
        if (cmsBannerHidden($section)) continue;
        // The first visible section is the hero, including image-only/video heroes.
        // Adjacent responsive copies of its heading belong to the same banner.
        if (!$targets) { $targets[] = $section; continue; }
        $firstHeading = $xpath->query('.//*[self::h1 or self::h2 or self::h3]', $targets[0])->item(0);
        $heading = $xpath->query('.//*[self::h1 or self::h2 or self::h3]', $section)->item(0);
        if ($firstHeading && $heading && trim($firstHeading->textContent) === trim($heading->textContent)) $targets[] = $section;
        else break;
    }
    if ($targets) return $targets;
    $heading = $xpath->query('.//h1', $root)->item(0);
    if (!$heading instanceof DOMElement) return [];
    for ($parent = $heading->parentNode; $parent instanceof DOMElement && $parent !== $root; $parent = $parent->parentNode) {
        if (in_array($parent->tagName, ['section', 'header'], true) || preg_match('/(?:hero|banner|page-header)/i', $parent->getAttribute('class'))) return [$parent];
    }
    return [$heading];
}

function cmsBannerCss(DOMDocument $document): string {
    $xpath = new DOMXPath($document); $css = '';
    foreach ($xpath->query('//style') as $style) $css .= $style->textContent . "\n";
    static $files = [];
    $root = realpath(dirname(__DIR__) . '/public/assets');
    foreach ($xpath->query('//link[contains(@rel,"stylesheet")][@href]') as $link) {
        $url = parse_url($link->getAttribute('href'), PHP_URL_PATH) ?: '';
        if (!str_starts_with($url, '/assets/') || !$root) continue;
        $file = realpath(dirname(__DIR__) . '/public' . $url);
        if (!$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'css') continue;
        $files[$file] ??= file_get_contents($file);
        $css .= $files[$file] . "\n";
    }
    return $css;
}

function cmsBannerBackground(DOMElement $node, string $css): string {
    if ($node->hasAttribute('data-cms-featured-image')) return $node->getAttribute('data-cms-featured-image');
    $urlPattern = '~background(?:-image)?\s*:[^;{}]*?url\(\s*(["\']?)(.*?)\1\s*\)~i';
    if (preg_match($urlPattern, $node->getAttribute('style'), $match)) return html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $id = $node->getAttribute('data-id');
    if ($id !== '') {
        // Read only rules painting this element, not unrelated descendant widgets.
        preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $rules, PREG_SET_ORDER);
        foreach ($rules as $rule) {
            foreach (explode(',', $rule[1]) as $selector) {
                if (!preg_match('~\.elementor-element-' . preg_quote($id, '~') . '(?:\s*:not\([^)]*\))?\s*$~', trim($selector))) continue;
                if (preg_match($urlPattern, $rule[2], $match)) return html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
    }
    return '';
}

function cmsBannerState(DOMDocument $document): array {
    $xpath = new DOMXPath($document); $state = []; $css = cmsBannerCss($document);
    foreach (cmsBannerTargets($document) as $target) {
        $locator = cmsBannerLocator($target); $key = hash('sha256', $locator);
        $imageNode = $target; $image = cmsBannerBackground($target, $css); $kind = 'background';
        if ($target->getAttribute('data-cms-featured-kind') === 'image' || ($image === '' && !$target->hasAttribute('data-cms-featured-image'))) {
            $img = $xpath->query('.//img[@src][not(ancestor::a)]', $target)->item(0);
            if ($img instanceof DOMElement) { $imageNode = $img; $image = $img->getAttribute('src'); $kind = 'image'; }
        }
        $fields = []; $titleSeen = false;
        $nodes = $xpath->query('.//text()[ancestor::h1 or ancestor::h2 or ancestor::h3 or ancestor::h4 or ancestor::h5 or ancestor::h6 or ancestor::p or ancestor::a or ancestor::button][not(ancestor::script or ancestor::style or ancestor::svg or ancestor::form)]', $target);
        if (preg_match('/^h[1-6]$/', $target->tagName)) $nodes = $xpath->query('.//text()', $target);
        foreach ($nodes as $node) {
            if (trim($node->nodeValue) === '') continue;
            $fieldLocator = cmsBannerLocator($node); $tag = '';
            for ($parent = $node->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
                if (in_array($parent->tagName, ['h1','h2','h3','h4','h5','h6','p','a','button'], true)) { $tag = $parent->tagName; break; }
            }
            $isHeading = preg_match('/^h[1-6]$/', $tag) === 1;
            $label = $isHeading && (!$titleSeen || $tag === 'h1') ? 'Banner title' : match($tag) {'p'=>'Banner description', 'a','button'=>'Button text', default=>'Banner subtitle'};
            if ($isHeading) $titleSeen = true;
            $fields[hash('sha256', $fieldLocator)] = ['locator'=>$fieldLocator, 'value'=>trim($node->nodeValue), 'label'=>$label];
        }
        $settings = json_decode($target->getAttribute('data-settings'), true) ?: [];
        $state[$key] = ['locator'=>$locator, 'image_locator'=>cmsBannerLocator($imageNode), 'image'=>$image, 'kind'=>$kind, 'fields'=>$fields, 'video'=>($settings['background_background'] ?? '') === 'video'];
    }
    return $state;
}

function cmsBannerStyles(DOMDocument $document): void {
    $xpath = new DOMXPath($document);
    if ($xpath->query('//link[@href="/cms-banner.css"]')->length) return;
    $head = $document->getElementsByTagName('head')->item(0);
    if ($head) { $link = $document->createElement('link'); $link->setAttribute('rel', 'stylesheet'); $link->setAttribute('href', '/cms-banner.css'); $head->appendChild($link); }
}

function cmsBannerCreate(DOMDocument $document, string $title, string $description): DOMElement {
    $root = cmsBannerRoot($document) ?: $document->getElementsByTagName('body')->item(0);
    if (!$root) throw new RuntimeException('This page needs a content area before adding a banner.');
    $banner = $document->createElement('section'); $banner->setAttribute('data-cms-banner', 'header'); $banner->setAttribute('class', 'cms-page-banner');
    $copy = $document->createElement('div'); $copy->setAttribute('class', 'cms-banner-copy'); $copy->setAttribute('data-cms-banner-copy', '1'); $banner->appendChild($copy);
    foreach (['h1'=>$title, 'p'=>$description] as $tag=>$value) {
        if ($value === '') continue;
        $node = $document->createElement($tag); $node->appendChild($document->createTextNode($value)); $copy->appendChild($node);
    }
    $root->insertBefore($banner, $root->firstChild); cmsBannerStyles($document);
    return $banner;
}

function cmsBannerApply(DOMDocument $document, array $submitted, array $original, array $new = []): bool {
    $xpath = new DOMXPath($document); $changed = false;
    if (!$original && $new) {
        $title = trim((string)($new['title'] ?? '')); $description = trim((string)($new['description'] ?? '')); $image = trim((string)($new['image'] ?? ''));
        if ($title !== '' || $description !== '' || $image !== '') {
            if (strlen($title) > 5000 || strlen($description) > 5000) throw new RuntimeException('Banner wording must be under 5,000 characters per field.');
            if ($image !== '') cmsImageUrl($image);
            cmsBannerCreate($document, $title, $description); $changed = true;
            $original = cmsBannerState($document);
            $submitted = [array_key_first($original) => ['image'=>$image]];
        }
    }
    foreach ($original as $key => $banner) {
        if (!isset($submitted[$key]) || !is_array($submitted[$key])) continue;
        $values = $submitted[$key]; $target = $xpath->query($banner['locator'])->item(0);
        $image = array_key_exists('image', $values) ? trim((string)$values['image']) : $banner['image'];
        $imageChanged = $image !== $banner['image'];
        $textChanged = false;
        foreach ($banner['fields'] as $fieldKey => $field) {
            if (!isset($values['text'][$fieldKey]) || trim((string)$values['text'][$fieldKey]) === $field['value']) continue;
            $value = trim((string)$values['text'][$fieldKey]);
            if (strlen($value) > 5000) throw new RuntimeException('Banner wording must be under 5,000 characters per field.');
            $node = $xpath->query($field['locator'])->item(0);
            if (!$node) throw new RuntimeException('The banner layout changed. Save the content, then edit the banner again.');
            preg_match('/^(\s*)(.*?)(\s*)$/s', $node->nodeValue, $spaces);
            $node->nodeValue = ($spaces[1] ?? '') . $value . ($spaces[3] ?? ''); $textChanged = true;
        }
        $newTitle = trim((string)($values['title'] ?? '')); $newDescription = trim((string)($values['description'] ?? ''));
        if (!$imageChanged && !$textChanged && $newTitle === '' && $newDescription === '') continue;
        $changed = true;
        if (!$target instanceof DOMElement) throw new RuntimeException('The banner layout changed. Save the content, then edit the banner again.');
        if ($imageChanged) {
            if ($image !== '') $image = cmsImageUrl($image);
            $imageNode = $xpath->query($banner['image_locator'])->item(0);
            if (!$imageNode instanceof DOMElement) throw new RuntimeException('The banner image changed. Reload before editing it.');
            if ($banner['kind'] === 'image') {
                if ($image === '') { $imageNode->setAttribute('src', ''); $imageNode->setAttribute('hidden', ''); }
                else { $imageNode->setAttribute('src', $image); $imageNode->removeAttribute('hidden'); }
                foreach (['srcset','data-srcset','data-src','data-lazy-src','data-lazy-srcset','sizes','width','height'] as $attribute) $imageNode->removeAttribute($attribute);
            } else {
                // Escape CSS string characters independently of HTML attribute escaping.
                $escaped = str_replace(["\\", '"', "\n", "\r", "\f", '<', '>'], ['\\\\', '\\"', '\\a ', '', '', '\\3c ', '\\3e '], $image);
                $style = preg_replace('~background-image\s*:[^;]*;?~i', '', $imageNode->getAttribute('style'));
                $imageNode->setAttribute('style', rtrim($style, "; \t\n\r") . ';background-image:' . ($image === '' ? 'none' : 'url("' . $escaped . '")') . ' !important;');
                // Replacing a video hero with a featured image must also stop its player.
                $settings = json_decode($target->getAttribute('data-settings'), true) ?: [];
                if (($settings['background_background'] ?? '') === 'video') {
                    $settings['background_background'] = 'classic'; unset($settings['background_video_link']);
                    $target->setAttribute('data-settings', json_encode($settings, JSON_UNESCAPED_SLASHES));
                    foreach (iterator_to_array($xpath->query('.//*[contains(@class,"elementor-background-video-container")]', $target)) as $video) $video->parentNode->removeChild($video);
                }
            }
            $target->setAttribute('data-cms-featured-image', $image);
            $target->setAttribute('data-cms-featured-kind', $banner['kind']);
        }
        foreach (['h1'=>$newTitle, 'p'=>$newDescription] as $tag => $value) {
            if ($value === '') continue;
            if (strlen($value) > 5000) throw new RuntimeException('Banner wording must be under 5,000 characters per field.');
            $emptyQuery = $tag === 'h1' ? './/*[self::h1 or self::h2 or self::h3][not(normalize-space())][not(*)]' : './/p[not(normalize-space())][not(*)]';
            $empty = $xpath->query($emptyQuery, $target)->item(0);
            if ($empty instanceof DOMElement) { $empty->appendChild($document->createTextNode($value)); continue; }
            $copy = $xpath->query('.//*[@data-cms-banner-copy]', $target)->item(0);
            if (!$copy) {
                $copy = $document->createElement('div'); $copy->setAttribute('data-cms-banner-copy', '1'); $copy->setAttribute('class', 'cms-banner-copy');
                $container = $xpath->query('.//*[contains(@class,"elementor-widget-wrap")]', $target)->item(0) ?: $target;
                // A bare h1 fallback keeps its parent as the content container.
                if (preg_match('/^h[1-6]$/', $target->tagName)) $container = $target->parentNode;
                $container->appendChild($copy);
            }
            $node = $document->createElement($tag); $node->appendChild($document->createTextNode($value)); $copy->appendChild($node);
            cmsBannerStyles($document);
        }
    }
    return $changed;
}
