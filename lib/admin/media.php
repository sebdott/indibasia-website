<?php
declare(strict_types=1);
$search = trim((string)($_GET['q'] ?? ''));
$filter = in_array($_GET['filter'] ?? '', ['uploads','imported','trash'], true) ? $_GET['filter'] : 'all';
$kind = in_array($_GET['kind'] ?? '', ['image','video','audio','document'], true) ? $_GET['kind'] : 'all';
$layout = ($_GET['layout'] ?? '') === 'list' ? 'list' : 'grid';
$month = is_string($_GET['month'] ?? null) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['month']) ? $_GET['month'] : '';
$mediaTypes = "(m.mime LIKE 'image/%' OR m.mime LIKE 'video/%' OR m.mime LIKE 'audio/%' OR m.mime = 'application/pdf')";
$conditions = [$mediaTypes, $filter === 'trash' ? 'm.trashed_at IS NOT NULL' : 'm.trashed_at IS NULL']; $params = [];
if ($filter === 'uploads') $conditions[] = 'm.uploaded = 1';
if ($filter === 'imported') $conditions[] = 'm.uploaded = 0';
if ($kind !== 'all') {
    $conditions[] = $kind === 'document' ? "m.mime = 'application/pdf'" : 'm.mime LIKE ?';
    if ($kind !== 'document') $params[] = $kind . '/%';
}
if ($month !== '') {
    $start = $month . '-01'; $end = date('Y-m-d', strtotime($start . ' +1 month'));
    $conditions[] = 'm.created_at >= ? AND m.created_at < ?'; array_push($params, $start, $end);
}
if ($search !== '') {
    $conditions[] = '(m.name LIKE ? OR m.title LIKE ? OR m.mime LIKE ? OR m.path LIKE ? OR m.alt_text LIKE ?)';
    array_push($params, ...array_fill(0, 5, '%' . $search . '%'));
}
$where = ' WHERE ' . implode(' AND ', $conditions);
$query = $db->prepare('SELECT COUNT(*) FROM indiba_cms_media m' . $where); $query->execute($params); $total = (int)$query->fetchColumn();
$number = min(max(1, (int)($_GET['page'] ?? 1)), max(1, (int)ceil($total / 40))); $offset = ($number - 1) * 40;
$query = $db->prepare('SELECT m.*, u.username AS author FROM indiba_cms_media m LEFT JOIN indiba_cms_users u ON u.id = m.user_id' . $where . " ORDER BY m.uploaded DESC, m.id DESC LIMIT 40 OFFSET $offset");
$query->execute($params); $media = $query->fetchAll();
foreach ($media as &$file) {
    $file['title'] = $file['title'] !== '' ? $file['title'] : pathinfo($file['name'], PATHINFO_FILENAME);
    $file['author'] = $file['author'] ?: ($file['uploaded'] ? 'Administrator' : 'Imported');
} unset($file);
$months = $db->query("SELECT DISTINCT DATE_FORMAT(m.created_at, '%Y-%m') AS month FROM indiba_cms_media m WHERE $mediaTypes ORDER BY month DESC")->fetchAll(PDO::FETCH_COLUMN);
$trashCount = (int)$db->query("SELECT COUNT(*) FROM indiba_cms_media m WHERE $mediaTypes AND m.trashed_at IS NOT NULL")->fetchColumn();
$mediaLink = fn(array $changes = []) => '/admin/?' . http_build_query(array_replace(['view'=>'media','filter'=>$filter,'kind'=>$kind,'month'=>$month,'q'=>$search,'layout'=>$layout], $changes));
$uploadOpen = ($_GET['upload'] ?? '') === '1' || $action === 'upload';
?>
<div class="media-library-intro"><p class="muted">Your images and files, ready to use across the website.</p><button type="button" class="secondary" id="media-add" aria-controls="media-upload" aria-expanded="<?= $uploadOpen ? 'true' : 'false' ?>">+ Add new media file</button></div>
<section class="panel media-upload" id="media-upload" <?= !$uploadOpen ? 'hidden' : '' ?>>
    <div><h2>Upload a file</h2><p class="muted">JPG, PNG, WebP, GIF, or PDF · Up to 20 MB</p></div>
    <form method="post" enctype="multipart/form-data"><?= csrfInput() ?><input type="hidden" name="action" value="upload"><input type="file" name="file" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf" required aria-label="Choose file"><button>Upload file</button></form>
</section>
<form method="get" class="media-library-toolbar">
    <input type="hidden" name="view" value="media"><input type="hidden" name="layout" value="<?= $layout ?>">
    <div class="media-view-switch" role="group" aria-label="Library view">
        <a href="<?= cmsEscape($mediaLink(['layout'=>'list'])) ?>" class="<?= $layout==='list' ? 'active' : '' ?>" aria-label="List view" <?= $layout==='list' ? 'aria-current="page"' : '' ?>><svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13M3 6h1M3 12h1M3 18h1"/></svg></a>
        <a href="<?= cmsEscape($mediaLink(['layout'=>'grid'])) ?>" class="<?= $layout==='grid' ? 'active' : '' ?>" aria-label="Grid view" <?= $layout==='grid' ? 'aria-current="page"' : '' ?>><?= adminIcon('dashboard') ?></a>
    </div>
    <select name="kind" aria-label="Media type"><?php foreach (['all'=>'All media items','image'=>'Images','video'=>'Videos','audio'=>'Audio','document'=>'Documents'] as $key=>$label): ?><option value="<?= $key ?>" <?= $kind===$key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select>
    <select name="month" aria-label="Upload date"><option value="">All dates</option><?php foreach ($months as $value): ?><option value="<?= $value ?>" <?= $month===$value ? 'selected' : '' ?>><?= date('F Y', strtotime($value . '-01')) ?></option><?php endforeach ?></select>
    <select name="filter" aria-label="Media source"><?php foreach (['all'=>'All files','uploads'=>'Your uploads','imported'=>'Imported files','trash'=>'Trash (' . $trashCount . ')'] as $key=>$label): ?><option value="<?= $key ?>" <?= $filter===$key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select>
    <button class="secondary">Filter</button><button type="button" class="secondary" id="media-bulk-toggle" aria-pressed="false">Bulk select</button>
    <div class="media-search"><input name="q" type="search" placeholder="Search media" value="<?= cmsEscape($search) ?>" aria-label="Search media"><button class="secondary">Search</button></div>
</form>
<div class="media-library-summary"><span><?= number_format($total) ?> <?= $total===1 ? 'item' : 'items' ?></span><span>Click a file to view its attachment details.</span></div>
<form method="post" id="media-bulk" class="media-bulk" hidden><?= csrfInput() ?><input type="hidden" name="action" value="bulk_media"><input type="hidden" name="target_status" value="<?= $filter==='trash' ? 'restore' : 'trash' ?>"><label><input type="checkbox" id="media-select-all"> Select all on this page</label><span id="media-selected-count">0 selected</span><button <?= $filter==='trash' ? 'class="secondary"' : 'class="media-trash-button"' ?> disabled><?= $filter==='trash' ? 'Restore selected' : 'Move to Trash' ?></button><button type="button" class="text-button" id="media-bulk-cancel">Cancel</button></form>
<div id="media-library" class="media-library <?= $layout==='list' ? 'media-library-list' : 'media-library-grid' ?>">
<?php if ($layout==='grid'): ?>
    <?php foreach ($media as $file): ?><article class="media-card library-card" data-media-id="<?= $file['id'] ?>">
        <input readonly value="<?= cmsEscape($file['path']) ?>" aria-label="File URL" hidden>
        <button type="button" class="media-thumbnail" data-media-open="<?= $file['id'] ?>" aria-label="View attachment: <?= cmsEscape($file['title']) ?>"><?php if (str_starts_with($file['mime'],'image/')): ?><img src="<?= cmsEscape($file['path']) ?>" alt="<?= cmsEscape($file['alt_text']) ?>" loading="lazy"><?php else: ?><span class="media-file-icon"><?= cmsEscape(strtoupper(pathinfo($file['name'], PATHINFO_EXTENSION))) ?></span><?php endif ?></button>
        <label class="media-choice"><input type="checkbox" name="ids[]" value="<?= $file['id'] ?>" form="media-bulk" class="media-selection" aria-label="Select <?= cmsEscape($file['title']) ?>"></label>
        <div class="library-card-info"><button type="button" class="media-item-title" data-media-open="<?= $file['id'] ?>" title="<?= cmsEscape($file['title']) ?>"><?= cmsEscape($file['title']) ?></button><span><?= cmsEscape(strtoupper(pathinfo($file['name'], PATHINFO_EXTENSION))) ?><span><?= $file['uploaded'] ? 'Uploaded' : 'Imported' ?></span></span></div>
    </article><?php endforeach ?>
<?php else: ?>
    <div class="panel table-wrap media-list-table"><table><thead><tr><th class="media-choice-column"></th><th>File</th><th>Uploaded by</th><th>Type</th><th>Date</th></tr></thead><tbody>
    <?php foreach ($media as $file): ?><tr data-media-id="<?= $file['id'] ?>"><td class="media-choice-column"><input type="checkbox" name="ids[]" value="<?= $file['id'] ?>" form="media-bulk" class="media-selection" aria-label="Select <?= cmsEscape($file['title']) ?>"></td><td><div class="media-list-file"><button type="button" class="media-list-thumbnail" data-media-open="<?= $file['id'] ?>" aria-label="View attachment: <?= cmsEscape($file['title']) ?>"><?php if (str_starts_with($file['mime'],'image/')): ?><img src="<?= cmsEscape($file['path']) ?>" alt="<?= cmsEscape($file['alt_text']) ?>" loading="lazy"><?php else: ?><span><?= cmsEscape(strtoupper(pathinfo($file['name'], PATHINFO_EXTENSION))) ?></span><?php endif ?></button><div><button type="button" class="media-item-title" data-media-open="<?= $file['id'] ?>"><?= cmsEscape($file['title']) ?></button><small><?= cmsEscape($file['name']) ?></small><button type="button" class="text-button" data-media-open="<?= $file['id'] ?>">View details</button></div></div></td><td><?= cmsEscape($file['author']) ?><small><?= $file['uploaded'] ? 'Uploaded' : 'Imported' ?></small></td><td><?= cmsEscape($file['mime']) ?></td><td><?= cmsEscape(date('M j, Y', strtotime($file['created_at']))) ?></td></tr><?php endforeach ?>
    </tbody></table></div>
<?php endif ?>
</div>
<?php if (!$media): ?><div class="panel empty"><h2><?= $filter==='trash' ? 'Trash is empty' : 'No media files found' ?></h2><p>Try changing your filters or add a new file.</p></div><?php endif ?>
<div class="pagination"><span>Page <?= $number ?> of <?= max(1,(int)ceil($total/40)) ?></span><div><?php if ($number>1): ?><a href="<?= cmsEscape($mediaLink(['page'=>$number-1])) ?>">← Previous</a><?php endif ?><?php if ($offset+40<$total): ?><a href="<?= cmsEscape($mediaLink(['page'=>$number+1])) ?>">Next →</a><?php endif ?></div></div>
<textarea id="media-records" hidden><?= cmsEscape(json_encode($media, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) ?></textarea>
<dialog id="attachment-details" aria-labelledby="attachment-heading">
    <header class="attachment-heading"><h2 id="attachment-heading">Attachment details</h2><div><span id="attachment-position"></span><button type="button" class="secondary" id="attachment-prev" aria-label="Previous attachment">←</button><button type="button" class="secondary" id="attachment-next" aria-label="Next attachment">→</button><button type="button" class="secondary" id="attachment-close" aria-label="Close attachment details">✕</button></div></header>
    <div class="attachment-body"><div class="attachment-visual"><div id="attachment-preview"></div><a id="attachment-open" target="_blank" rel="noopener">Open original file ↗</a></div>
        <aside class="attachment-sidebar"><p id="attachment-status" role="status"></p><dl id="attachment-facts"></dl><form id="attachment-form"><?= csrfInput() ?><input type="hidden" name="action" value="media_save"><input type="hidden" name="id" id="attachment-id"><fieldset id="attachment-fields"><label id="attachment-alt-label">Alternative text<textarea name="alt_text" id="attachment-alt" rows="2" maxlength="990"></textarea><small>Describe the image for people who cannot see it. Leave empty for a decorative image.</small></label><label>Title<input name="title" id="attachment-title" maxlength="490" required></label><label>Caption<textarea name="caption" id="attachment-caption" rows="2" maxlength="10000"></textarea></label><label>Description<textarea name="description" id="attachment-description" rows="3" maxlength="20000"></textarea></label></fieldset><label>File URL<input id="attachment-url" readonly></label><div class="attachment-save-row"><button type="button" class="secondary" id="attachment-copy">Copy URL</button><button id="attachment-save">Save details</button></div></form><div class="attachment-links"><a id="attachment-download" download>Download file</a><button type="button" class="text-button danger-text" id="attachment-trash">Move to Trash</button></div></aside>
    </div>
</dialog>
