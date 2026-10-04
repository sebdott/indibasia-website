<?php
declare(strict_types=1);
$section = $archive['section']; $region = $archive['region']; $filters = $archive['filters'];
foreach ($archive['groups'] as $group) if (isset($_GET[$group]) && is_string($_GET[$group])) {
    $values = array_map('siteTerm', explode('-or-', $_GET[$group]));
    if ($_GET[$group] === '') unset($filters[$group]);
    elseif (!array_diff($values, array_keys($archive['terms'][$group]))) $filters[$group] = $values;
}
$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$managed = database()->query('SELECT route, title, status FROM indiba_cms_pages')->fetchAll(PDO::FETCH_UNIQUE);
$regionalSlugs = [];
foreach ($catalog as $path=>$item) if ($item['section'] === $section && $item['region'] === $region) $regionalSlugs[basename(rtrim($path, '/'))] = true;
$items = [];
foreach ($catalog as $path=>$item) {
    $regional = $item['region'] === $region;
    $globalFallback = $region !== '' && $item['region'] === '' && !isset($regionalSlugs[basename(rtrim($path, '/'))]);
    if ($item['section'] !== $section || !$regional && !$globalFallback || isset($managed[$path]) && $managed[$path]['status'] !== 'published') continue;
    if (isset($managed[$path])) $item['title'] = $managed[$path]['title'];
    if ($search !== '' && stripos($item['title'] . ' ' . $item['excerpt'], $search) === false) continue;
    foreach ($filters as $group=>$values) if (!array_intersect($values, array_map('siteTerm', $item['taxonomies'][$group] ?? []))) continue 2;
    $items[$path] = $item;
}
uasort($items, fn($a,$b)=>strcasecmp($a['title'], $b['title']));
$total = count($items); $number = min(max(1, (int)($_GET['page'] ?? 1)), max(1, (int)ceil($total / 24)));
$items = array_slice($items, ($number - 1) * 24, 24, true);
$titles = ['trainings'=>'Trainings', 'scientific-literature'=>'Scientific Literature', 'events'=>'Events', 'news'=>'News', 'hall-of-fame'=>'Hall of Fame'];
$title = $titles[$section]; $rootRoute = '/' . $region . $section . '/';
$queryValues = ['q'=>$search]; foreach ($filters as $group=>$values) $queryValues[$group] = implode('-or-', $values);
$pageUrl = fn(int $page)=>$route . '?' . http_build_query([...$queryValues, 'page'=>$page]);
ob_start();
?>
<main class="recovered-page"><header class="recovered-heading"><p class="recovered-eyebrow">INDIBA resources</p><h1><?= cmsEscape($title) ?></h1><p>Explore <?= cmsEscape(strtolower($title)) ?> from INDIBA.</p></header>
<form method="get" action="<?= cmsEscape($route) ?>" class="recovered-filters"><label>Search<input type="search" name="q" value="<?= cmsEscape($search) ?>" placeholder="Search <?= cmsEscape(strtolower($title)) ?>"></label>
<?php foreach ($archive['groups'] as $group): if (!$archive['terms'][$group]) continue; $options = array_keys($archive['terms'][$group]); sort($options); ?><label><?= cmsEscape(ucwords(str_replace(['training-','event-','brand-news','categories-news','hub-categories','member_cat','hof-type','-'], ['', '', 'Division', 'Category', 'Category', 'Sport', 'Type', ' '], $group))) ?><select name="<?= $group ?>"><option value="">All</option><?php if (count($filters[$group] ?? []) > 1): ?><option value="<?= cmsEscape(implode('-or-', $filters[$group])) ?>" selected><?= cmsEscape(ucwords(str_replace('-', ' ', implode(' or ', $filters[$group])))) ?></option><?php endif ?><?php foreach ($options as $term): ?><option value="<?= cmsEscape($term) ?>" <?= ($filters[$group] ?? []) === [$term] ? 'selected' : '' ?>><?= cmsEscape(ucwords(str_replace('-', ' ', $term))) ?></option><?php endforeach ?></select></label><?php endforeach ?>
<button type="submit">Apply filters</button><a href="<?= cmsEscape($rootRoute) ?>">Clear filters</a></form>
<p class="recovered-count"><?= number_format($total) ?> results<?php if ($filters): ?> · <?= cmsEscape(implode(' · ', array_map(fn($values)=>ucwords(str_replace('-', ' ', implode(' or ', $values))), $filters))) ?><?php endif ?></p>
<div class="recovered-grid"><?php foreach ($items as $path=>$item): ?><article class="recovered-card"><h2><a href="<?= cmsEscape($path) ?>"><?= cmsEscape(preg_replace('/\s*[–|]\s*INDIBA.*$/u', '', $item['title'])) ?></a></h2><?php if ($item['excerpt']): ?><p><?= cmsEscape($item['excerpt']) ?></p><?php endif ?><a href="<?= cmsEscape($path) ?>">Read more →</a></article><?php endforeach ?></div>
<?php if (!$items): ?><p>No results match your search. <a href="<?= cmsEscape($rootRoute) ?>">View all <?= cmsEscape(strtolower($title)) ?></a>.</p><?php endif ?>
<nav class="recovered-pagination" aria-label="Result pages"><?php if ($number > 1): ?><a href="<?= cmsEscape($pageUrl($number - 1)) ?>">← Previous</a><?php endif ?><span>Page <?= $number ?> of <?= max(1, (int)ceil($total / 24)) ?></span><?php if ($number * 24 < $total): ?><a href="<?= cmsEscape($pageUrl($number + 1)) ?>">Next →</a><?php endif ?></nav></main>
<?php
$content = ob_get_clean();
$templateRoute = '/' . $region . 'animal-health/';
$templateQuery = database()->prepare('SELECT * FROM indiba_cms_pages WHERE route = ? AND status = ?'); $templateQuery->execute([$templateRoute, 'published']); $template = $templateQuery->fetch();
if (!$template) { http_response_code(503); echo '<h1>Resources temporarily unavailable</h1>'; return; }
echo siteDocument(cmsHtml($template), $content, $title, $rootRoute);
