<?php
declare(strict_types=1);

function cmsContentTypes(): array {
    return [
        'page' => ['view' => 'pages', 'label' => 'Pages', 'singular' => 'page', 'plural' => 'pages', 'title' => 'Page', 'prefix' => '/'],
        'master' => ['view' => 'master', 'label' => 'Master pages', 'singular' => 'master page', 'plural' => 'master pages', 'title' => 'Master page', 'prefix' => '/'],
        'news' => ['view' => 'news', 'label' => 'News', 'singular' => 'news item', 'plural' => 'news items', 'title' => 'News', 'prefix' => '/news/'],
        'event' => ['view' => 'events', 'label' => 'Events', 'singular' => 'event', 'plural' => 'events', 'title' => 'Event', 'prefix' => '/events/'],
    ];
}

function cmsContentSection(string $type): array {
    return cmsContentTypes()[$type] ?? cmsContentTypes()['page'];
}

function cmsImportedContentType(string $route, string $html): string {
    // Original archive pages render lists, filters, and pagination, rather than a single entry.
    preg_match('~<body\b[^>]*\bclass\s*=\s*(["\'])(.*?)\1~is', $html, $match);
    $classes = preg_split('/\s+/', html_entity_decode($match[2] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (in_array('archive', $classes, true)) return 'master';
    if (in_array('single-news', $classes, true)) return 'news';
    if (in_array('single-events', $classes, true)) return 'event';
    if (preg_match('~^/(?:asia/|us/)?(?:news|events)/(?:$|page/\d+/|(?:brand-news-|categories-news-|event-brands-|event-category-))~', $route)) return 'master';
    if (preg_match('~^/(?:asia/|us/)?news/~', $route)) return 'news';
    if (preg_match('~^/(?:asia/|us/)?events/~', $route)) return 'event';
    return 'page';
}
