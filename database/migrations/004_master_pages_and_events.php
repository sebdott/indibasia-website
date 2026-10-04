<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/content-types.php';

return static function (PDO $db): void {
    $db->exec("ALTER TABLE indiba_cms_pages MODIFY COLUMN content_type ENUM('page','news','master','event') NOT NULL DEFAULT 'page'");
    $pageRoot = realpath(dirname(__DIR__, 2) . '/storage/pages');
    $rows = $db->query("SELECT id, route, source_file, content_type FROM indiba_cms_pages WHERE source_file <> '' AND content_type IN ('page','news')")->fetchAll();
    $update = $db->prepare('UPDATE indiba_cms_pages SET content_type = ?, updated_at = updated_at WHERE id = ?');
    $db->beginTransaction();
    try {
        foreach ($rows as $row) {
            $file = realpath(dirname(__DIR__, 2) . '/storage/' . $row['source_file']);
            $html = $pageRoot && $file && str_starts_with($file, $pageRoot . DIRECTORY_SEPARATOR) && is_file($file) ? file_get_contents($file) : '';
            $type = cmsImportedContentType($row['route'], $html === false ? '' : $html);
            if ($type !== $row['content_type']) $update->execute([$type, $row['id']]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
};
