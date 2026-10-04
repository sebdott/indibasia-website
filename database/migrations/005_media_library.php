<?php
declare(strict_types=1);

return static function (PDO $db): void {
    $columns = array_column($db->query('SHOW COLUMNS FROM indiba_cms_media')->fetchAll(), 'Field');
    foreach ([
        'title' => "VARCHAR(500) NOT NULL DEFAULT ''",
        'alt_text' => "VARCHAR(1000) NOT NULL DEFAULT ''",
        'caption' => 'TEXT NULL',
        'description' => 'TEXT NULL',
        'user_id' => 'BIGINT UNSIGNED NULL',
        'trashed_at' => 'TIMESTAMP NULL DEFAULT NULL',
    ] as $name => $definition) {
        if (!in_array($name, $columns, true)) $db->exec("ALTER TABLE indiba_cms_media ADD COLUMN $name $definition");
    }
    $indexes = array_column($db->query('SHOW INDEX FROM indiba_cms_media')->fetchAll(), 'Key_name');
    if (!in_array('media_library', $indexes, true)) $db->exec('ALTER TABLE indiba_cms_media ADD INDEX media_library (trashed_at, uploaded, id)');
};
