<?php
declare(strict_types=1);

return static function (PDO $db): void {
    $columns = $db->query('SHOW COLUMNS FROM indiba_cms_pages')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('content_type', $columns, true)) {
        $db->exec("ALTER TABLE indiba_cms_pages ADD COLUMN content_type ENUM('page','news') NOT NULL DEFAULT 'page'");
    }
    // Preserve content, publication state, versions, and the original update dates.
    $db->exec("UPDATE indiba_cms_pages SET content_type = 'news', updated_at = updated_at WHERE route REGEXP '^/(asia/|us/)?news(/|$)' AND content_type = 'page'");
    $indexes = $db->query('SHOW INDEX FROM indiba_cms_pages')->fetchAll(PDO::FETCH_ASSOC);
    if (!in_array('type_status_updated', array_column($indexes, 'Key_name'), true)) {
        $db->exec('ALTER TABLE indiba_cms_pages ADD INDEX type_status_updated (content_type, status, updated_at)');
    }
};
