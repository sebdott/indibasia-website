<?php
declare(strict_types=1);
require_once __DIR__ . '/cms-management.php';

function cmsClassicTarget(DOMDocument $document): ?DOMElement {
    $xpath = new DOMXPath($document);
    foreach (['//main', '//*[@data-elementor-type="wp-page"]', '//*[@id="content"]', '//*[@role="main"]', '//*[@id="main"]'] as $query) {
        $node = $xpath->query($query)->item(0); if ($node instanceof DOMElement) return $node;
    }
    return null;
}

function cmsClassicPrepare(DOMDocument $document): array {
    $copy = clone $document; $target = cmsClassicTarget($copy);
    if (!$target) return ['available'=>false, 'content'=>'', 'locks'=>[], 'banner_locks'=>[], 'styles'=>'', 'css'=>[], 'body_class'=>''];
    $xpath = new DOMXPath($copy); $styles = ''; $css = []; $locks = []; $bannerLocks = [];
    foreach ($xpath->query('//style') as $node) $styles .= $node->textContent . "\n";
    foreach ($xpath->query('//link[contains(@rel,"stylesheet")][@href]') as $node) {
        $url = $node->getAttribute('href'); if (str_starts_with($url, '/assets/') && !str_contains($url, '..')) $css[] = $url;
    }
    // Keep banners in their original positions while exposing only body content.
    // Lock the entire banner before its scripts/widgets to avoid overlapping locks.
    foreach (cmsBannerTargets($copy) as $banner) {
        $inside = false;
        for ($parent = $banner->parentNode; $parent; $parent = $parent->parentNode) {
            if ($parent->isSameNode($target)) { $inside = true; break; }
        }
        if (!$inside) continue;
        $key = hash('sha256', 'banner:' . $banner->getNodePath());
        $locks[$key] = $copy->saveHTML($banner); $bannerLocks[$key] = true;
        $placeholder = $copy->createElement('span');
        $placeholder->setAttribute('data-cms-lock', $key); $placeholder->setAttribute('class', 'cms-protected mceNonEditable');
        $placeholder->setAttribute('contenteditable', 'false'); $placeholder->setAttribute('data-cms-hidden', '1');
        $placeholder->appendChild($copy->createTextNode('Banner header'));
        $banner->parentNode->replaceChild($placeholder, $banner);
    }
    $query = './/*[self::script or self::style or self::svg or self::form or self::iframe or self::object or self::embed][not(ancestor::form or ancestor::svg or ancestor::iframe or ancestor::object)]';
    foreach (iterator_to_array($xpath->query($query, $target)) as $node) {
        $key = hash('sha256', $node->getNodePath()); $locks[$key] = $copy->saveHTML($node);
        $placeholder = $copy->createElement('span'); $placeholder->setAttribute('data-cms-lock', $key); $placeholder->setAttribute('class','cms-protected mceNonEditable'); $placeholder->setAttribute('contenteditable','false');
        $label = in_array($node->nodeName, ['form','iframe','object','embed'],true) ? ucfirst($node->nodeName) . ' content' : 'Design element';
        $placeholder->appendChild($copy->createTextNode($label));
        if (!in_array($node->nodeName,['form','iframe','object','embed'],true)) $placeholder->setAttribute('data-cms-hidden','1');
        $node->parentNode->replaceChild($placeholder,$node);
    }
    $body = $copy->getElementsByTagName('body')->item(0);
    return ['available'=>true,'content'=>cmsInnerHtml($target),'locks'=>$locks,'banner_locks'=>$bannerLocks,'styles'=>$styles,'css'=>array_values(array_unique($css)),'body_class'=>($body?->getAttribute('class') ?? '') . ' ' . $target->getAttribute('class')];
}

function cmsClassicSafeUrl(string $url, bool $link = false): bool {
    $url = trim($url);
    return $url === '' || preg_match($link ? '~^(?:https?://|mailto:|tel:|/(?!/)|#)~i' : '~^(?:https?://|/(?!/))~i', $url) === 1;
}

function cmsClassicApply(DOMDocument $document, string $html): void {
    if (strlen($html) > 12*1024*1024) throw new RuntimeException('The page content exceeds the 12 MB limit.');
    $state = cmsClassicPrepare($document); $target = cmsClassicTarget($document);
    if (!$target) throw new RuntimeException('Use the visual or HTML editor for this page layout.');
    $fragment = cmsDocument('<!doctype html><html><body><div id="classic-root">' . $html . '</div></body></html>');
    $xpath = new DOMXPath($fragment); $root = $xpath->query('//*[@id="classic-root"]')->item(0); $used = [];
    $clean = function(DOMNode $parent) use (&$clean, $state, &$used): void {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof DOMComment) { $parent->removeChild($node); continue; }
            if (!$node instanceof DOMElement) continue;
            $tag = strtolower($node->tagName); $lock = $node->getAttribute('data-cms-lock');
            if ($lock !== '' && isset($state['locks'][$lock]) && !isset($used[$lock])) { $used[$lock]=true; continue; }
            if (in_array($tag,['script','style','svg','math','object','embed','form','input','button','textarea','select','link','meta','base'],true)) { $parent->removeChild($node); continue; }
            if ($tag === 'iframe') {
                $url = $node->getAttribute('src'); $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
                if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !in_array($host,['www.youtube.com','www.youtube-nocookie.com','player.vimeo.com'],true)) { $parent->removeChild($node); continue; }
                $node->setAttribute('sandbox','allow-scripts allow-same-origin allow-presentation');
            }
            foreach (iterator_to_array($node->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                if (str_starts_with($name,'on') || str_starts_with($name,'data-mce-') || in_array($name,['srcdoc','formaction','data-cms-lock','data-cms-hidden','contenteditable'],true)) { $node->removeAttribute($attribute->name); continue; }
                if (in_array($name,['href','src','poster','xlink:href'],true) && !cmsClassicSafeUrl($attribute->value, $name === 'href')) $node->removeAttribute($attribute->name);
                if ($name === 'style' && preg_match('~(?:expression\s*\(|javascript\s*:|behavior\s*:|-moz-binding)~i',$attribute->value)) $node->removeAttribute('style');
            }
            if ($tag === 'a' && $node->getAttribute('target') === '_blank') $node->setAttribute('rel','noopener noreferrer');
            $clean($node);
        }
    };
    $clean($root);
    // Restore trusted original design and embedded widgets from the server-side snapshot.
    foreach (iterator_to_array($xpath->query('.//*[@data-cms-lock]',$root)) as $placeholder) {
        $key = $placeholder->getAttribute('data-cms-lock'); if (!isset($state['locks'][$key])) { $placeholder->parentNode->removeChild($placeholder); continue; }
        $lockDocument = cmsDocument('<!doctype html><html><body><div id="lock-root">' . $state['locks'][$key] . '</div></body></html>');
        $lockRoot = (new DOMXPath($lockDocument))->query('//*[@id="lock-root"]')->item(0);
        foreach (iterator_to_array($lockRoot->childNodes) as $child) $placeholder->parentNode->insertBefore($fragment->importNode($child,true),$placeholder);
        $placeholder->parentNode->removeChild($placeholder);
    }
    $missingBanners = $fragment->createDocumentFragment();
    foreach ($state['locks'] as $key=>$lockedHtml) if (!isset($used[$key])) {
        $lockDocument = cmsDocument('<!doctype html><html><body><div id="lock-root">' . $lockedHtml . '</div></body></html>');
        $lockRoot = (new DOMXPath($lockDocument))->query('//*[@id="lock-root"]')->item(0);
        $destination = isset($state['banner_locks'][$key]) ? $missingBanners : $root;
        foreach (iterator_to_array($lockRoot->childNodes) as $child) $destination->appendChild($fragment->importNode($child,true));
    }
    // Replacing all body HTML in Code view must retain banners above the new body.
    if ($missingBanners->hasChildNodes()) $root->insertBefore($missingBanners, $root->firstChild);
    while ($target->firstChild) $target->removeChild($target->firstChild);
    foreach (iterator_to_array($root->childNodes) as $child) $target->appendChild($document->importNode($child,true));
    $xpath = new DOMXPath($document);
    if (!$xpath->query('//link[@href="/cms-content.css"]')->length) {
        $head = $document->getElementsByTagName('head')->item(0); if ($head) { $link = $document->createElement('link'); $link->setAttribute('rel','stylesheet'); $link->setAttribute('href','/cms-content.css'); $head->appendChild($link); }
    }
}
