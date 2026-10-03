<?php
declare(strict_types=1);

function cmsManagementAction(string $action): void {
    $db = database();
    if ($action === 'duplicate_page') {
        $page = cmsPage((int)($_POST['id'] ?? 0));
        $base = $page['route'] === '/' ? '/home-copy' : rtrim($page['route'], '/') . '-copy'; $route = $base . '/'; $number = 2;
        while (true) { try { cmsRouteAvailable($route); break; } catch (RuntimeException $e) { $route = $base . '-' . $number++ . '/'; } }
        $title = 'Copy of ' . $page['title'];
        $html = cmsSeo(cmsHtml($page), $title, $page['meta_description']);
        $db->prepare('INSERT INTO indiba_cms_pages (route, title, source_file, html, status, group_name, meta_description) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$route, substr($title,0,490), '', $html, 'draft', $page['group_name'], $page['meta_description']]);
        $id = $db->lastInsertId(); cmsAudit('duplicate_page', $route); adminNotice('Page duplicated as a draft.', '/admin/?view=edit&id=' . $id);
    }
    if ($action === 'page_status') {
        $page = cmsPage((int)($_POST['id'] ?? 0)); $status = (string)($_POST['target_status'] ?? '');
        if (!in_array($status, ['published','draft','trashed'], true)) throw new RuntimeException('Choose a valid page status.');
        if ($page['status'] === 'trashed' && $status === 'published') throw new RuntimeException('Restore the page as a draft before publishing it.');
        if ($status === 'trashed' && in_array($page['route'], ['/', '/asia/', '/us/'], true)) throw new RuntimeException('Regional homepages cannot be moved to Trash.');
        cmsSave($page, ['status' => $status], (int)($_POST['version'] ?? 0), $status === 'trashed' ? 'trash_page' : 'change_status');
        adminNotice($status === 'trashed' ? 'Page moved to Trash. You can restore it later.' : 'Page status updated.', '/admin/?view=pages&status=' . ($status === 'trashed' ? 'trashed' : $status));
    }
    if ($action === 'bulk_pages') {
        $target = (string)($_POST['target_status'] ?? ''); if (!in_array($target, ['published','draft','trashed'], true)) throw new RuntimeException('Choose a bulk action.');
        $ids = array_values(array_unique(array_map('intval', (array)($_POST['ids'] ?? []))));
        if (!$ids || count($ids) > 100) throw new RuntimeException('Select between 1 and 100 pages.');
        $db->beginTransaction();
        try {
            foreach ($ids as $id) {
                $query = $db->prepare('SELECT * FROM indiba_cms_pages WHERE id = ? FOR UPDATE'); $query->execute([$id]); $page = $query->fetch();
                if (!$page || (int)$page['version'] !== (int)($_POST['versions'][$id] ?? 0)) throw new RuntimeException('A selected page changed. Refresh the list before applying a bulk action.');
                if ($target === 'trashed' && in_array($page['route'], ['/', '/asia/', '/us/'], true)) throw new RuntimeException('Regional homepages cannot be moved to Trash.');
                if ($page['status'] === 'trashed' && $target === 'published') throw new RuntimeException('Restore trashed pages as drafts first.');
                cmsRevision($page); $db->prepare('UPDATE indiba_cms_pages SET status = ?, version = version + 1 WHERE id = ?')->execute([$target, $id]); cmsAudit('bulk_' . $target, $page['route']);
            }
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        adminNotice(count($ids) . ' pages updated.', '/admin/?view=pages&status=' . $target);
    }
    if ($action === 'restore_revision') {
        $query = $db->prepare('SELECT * FROM indiba_cms_revisions WHERE id = ? AND page_id = ?'); $query->execute([(int)($_POST['revision_id'] ?? 0), (int)($_POST['id'] ?? 0)]); $revision = $query->fetch();
        if (!$revision) throw new RuntimeException('Revision not found.');
        $page = cmsPage((int)$revision['page_id']); $previous = json_decode($revision['data'], true, 512, JSON_THROW_ON_ERROR);
        $changes = array_intersect_key($previous, array_flip(['title','html','seo_title','meta_description','group_name'])); $changes['status'] = 'draft';
        cmsSave($page, $changes, (int)($_POST['version'] ?? 0), 'restore_revision');
        adminNotice('Revision restored as a draft. Preview it before publishing.', '/admin/?view=edit&id=' . $page['id']);
    }
}
