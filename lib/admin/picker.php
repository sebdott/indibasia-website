<?php
declare(strict_types=1);
$search = trim((string)($_GET['q'] ?? '')); $page = max(1, (int)($_GET['page'] ?? 1)); $offset = ($page - 1) * 24;
$kind = in_array($_GET['kind'] ?? '', ['file','media'], true) ? $_GET['kind'] : 'image';
$where = match ($kind) { 'file'=>" WHERE (mime LIKE 'image/%' OR mime LIKE 'video/%' OR mime LIKE 'audio/%' OR mime = 'application/pdf')", 'media'=>" WHERE (mime LIKE 'video/%' OR mime LIKE 'audio/%')", default=>" WHERE mime LIKE 'image/%'" }; $parameters = [];
$where .= ' AND trashed_at IS NULL';
if ($search !== '') { $where .= ' AND (name LIKE ? OR title LIKE ? OR alt_text LIKE ?)'; $parameters = array_fill(0, 3, '%' . $search . '%'); }
$query = $db->prepare("SELECT id, path, COALESCE(NULLIF(title, ''), name) AS name, mime, alt_text, caption FROM indiba_cms_media" . $where . " ORDER BY uploaded DESC, id DESC LIMIT 25 OFFSET $offset"); $query->execute($parameters); $files = $query->fetchAll();
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['items' => array_slice($files, 0, 24), 'has_more' => count($files) > 24], JSON_THROW_ON_ERROR);
