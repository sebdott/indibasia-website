<?php
declare(strict_types=1);
try { $previewPage = cmsPage((int)($_GET['id'] ?? 0)); $html = cmsHtml($previewPage); }
catch (RuntimeException $e) { http_response_code(404); exit('Preview unavailable.'); }
header('X-Robots-Tag: noindex, nofollow');
$previewDoc = cmsDocument($html);
$xpath = new DOMXPath($previewDoc);
foreach (iterator_to_array($xpath->query('//script | //form | //base | //meta[translate(@http-equiv,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="refresh"]')) as $node) $node->parentNode->removeChild($node);
foreach ($xpath->query('//*') as $element) foreach (iterator_to_array($element->attributes) as $attribute) if (str_starts_with(strtolower($attribute->name), 'on')) $element->removeAttribute($attribute->name);
$html = cmsDocumentHtml($previewDoc);
$html = preg_replace('~</head>~i', '<link rel="stylesheet" href="/admin/preview.css"></head>', $html, 1);
$html = preg_replace_callback('~<body\b[^>]*>~i', fn($m) => $m[0] . '<div class="cms-preview-banner">Saved page preview · ' . cmsEscape($previewPage['status']) . ' · <a href="/admin/?view=edit&id=' . $previewPage['id'] . '">Return to editor</a></div>', $html, 1);
header("Content-Security-Policy: default-src 'self' https: data:; script-src 'none'; style-src 'self' https: 'unsafe-inline'; img-src 'self' https: data:; font-src 'self' https: data:; frame-src 'none'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'");
session_write_close(); echo $html;
