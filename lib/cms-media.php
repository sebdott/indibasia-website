<?php
declare(strict_types=1);
require_once __DIR__ . '/cms.php';

function cmsMedia(int $id): array {
    $query = database()->prepare('SELECT m.*, u.username AS author FROM indiba_cms_media m LEFT JOIN indiba_cms_users u ON u.id = m.user_id WHERE m.id = ?');
    $query->execute([$id]); $file = $query->fetch();
    if (!$file) throw new RuntimeException('Media file not found.');
    return $file;
}

function cmsMediaLocalPath(string $url): ?string {
    if (!preg_match('~^/(assets|uploads)/~', $url, $match)) return null;
    $root = realpath(dirname(__DIR__) . '/public/' . $match[1]);
    $path = realpath(dirname(__DIR__) . '/public' . $url);
    return $root && $path && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path) ? $path : null;
}

function cmsMediaDetails(array $file): array {
    $path = cmsMediaLocalPath($file['path']);
    $file['title'] = $file['title'] !== '' ? $file['title'] : pathinfo($file['name'], PATHINFO_FILENAME);
    $file['author'] = $file['author'] ?: ($file['uploaded'] ? 'Administrator' : 'Imported');
    $file['available'] = $path !== null;
    $file['size'] = $path ? filesize($path) : null;
    $size = $path && str_starts_with($file['mime'], 'image/') ? @getimagesize($path) : false;
    $file['width'] = $size ? $size[0] : null; $file['height'] = $size ? $size[1] : null;
    return $file;
}

function cmsMediaJson(array $data, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE); exit;
}

function cmsMediaAction(string $action): void {
    $db = database();
    if ($action === 'media_save') {
        $file = cmsMedia((int)($_POST['id'] ?? 0));
        if ($file['trashed_at']) throw new RuntimeException('Restore this file before editing its details.');
        $values = [];
        foreach (['title'=>490, 'alt_text'=>990, 'caption'=>10000, 'description'=>20000] as $key=>$limit) {
            if (!is_string($_POST[$key] ?? null)) throw new RuntimeException('Enter valid attachment details.');
            $values[$key] = trim($_POST[$key]);
            if (strlen($values[$key]) > $limit) throw new RuntimeException('The attachment ' . str_replace('_', ' ', $key) . ' is too long.');
        }
        if ($values['title'] === '') throw new RuntimeException('Enter an attachment title.');
        $db->prepare('UPDATE indiba_cms_media SET title = ?, alt_text = ?, caption = ?, description = ? WHERE id = ? AND trashed_at IS NULL')
            ->execute([...array_values($values), $file['id']]);
        cmsAudit('media_details', $file['path']);
        cmsMediaJson(['file'=>cmsMediaDetails(cmsMedia((int)$file['id'])), 'message'=>'Attachment details saved.']);
    }
    if ($action === 'media_status' || $action === 'bulk_media') {
        $target = $_POST['target_status'] ?? '';
        if (!in_array($target, ['trash', 'restore'], true)) throw new RuntimeException('Choose a valid media action.');
        $ids = $action === 'media_status' ? [(int)($_POST['id'] ?? 0)] : array_values(array_unique(array_map('intval', (array)($_POST['ids'] ?? []))));
        if (!$ids || count($ids) > 100 || min($ids) < 1) throw new RuntimeException('Select between 1 and 100 media files.');
        $db->beginTransaction();
        try {
            foreach ($ids as $id) {
                $query = $db->prepare('SELECT id, path FROM indiba_cms_media WHERE id = ? FOR UPDATE'); $query->execute([$id]); $file = $query->fetch();
                if (!$file) throw new RuntimeException('A selected file no longer exists. Refresh the library.');
                $db->prepare('UPDATE indiba_cms_media SET trashed_at = ' . ($target === 'trash' ? 'CURRENT_TIMESTAMP' : 'NULL') . ' WHERE id = ?')->execute([$id]);
                cmsAudit('media_' . $target, $file['path']);
            }
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        $message = count($ids) . (count($ids) === 1 ? ' file ' : ' files ') . ($target === 'trash' ? 'moved to Trash.' : 'restored to the library.');
        if ($action === 'media_status') cmsMediaJson(['message'=>$message]);
        adminNotice($message, '/admin/?view=media&filter=' . ($target === 'trash' ? 'trash' : 'all'));
    }
}
