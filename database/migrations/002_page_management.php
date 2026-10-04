<?php
declare(strict_types=1);

return static function (PDO $db): void {
    // Also adopts installations made before migrations were introduced.
    $columns = $db->query('SHOW COLUMNS FROM indiba_cms_pages')->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'seo_title' => 'VARCHAR(500) NULL',
        'meta_description' => 'TEXT NULL',
        'group_name' => "VARCHAR(100) NOT NULL DEFAULT 'General'",
    ] as $name => $definition) {
        if (!in_array($name, $columns, true)) {
            $db->exec("ALTER TABLE indiba_cms_pages ADD COLUMN $name $definition");
        }
    }
    $db->exec('CREATE TABLE IF NOT EXISTS indiba_cms_revisions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        page_id BIGINT UNSIGNED NOT NULL,
        version INT NOT NULL,
        data MEDIUMTEXT NOT NULL,
        user_id BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY page_history (page_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->exec('CREATE TABLE IF NOT EXISTS indiba_cms_redirects (
        route VARCHAR(700) NOT NULL,
        page_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (route)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
};
