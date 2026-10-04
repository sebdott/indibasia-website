<?php
declare(strict_types=1);

return static function (PDO $db): void {
    $columns = array_column($db->query('SHOW COLUMNS FROM indiba_cms_users')->fetchAll(), 'Field');
    foreach ([
        'display_name' => "VARCHAR(100) NOT NULL DEFAULT ''",
        'email' => 'VARCHAR(254) NULL',
        'role' => "VARCHAR(20) NOT NULL DEFAULT 'admin'",
        'status' => "VARCHAR(20) NOT NULL DEFAULT 'active'",
        'version' => 'INT UNSIGNED NOT NULL DEFAULT 1',
        'session_version' => 'INT UNSIGNED NOT NULL DEFAULT 1',
        'last_login_at' => 'TIMESTAMP NULL DEFAULT NULL',
    ] as $name => $definition) {
        if (!in_array($name, $columns, true)) $db->exec("ALTER TABLE indiba_cms_users ADD COLUMN $name $definition");
    }
};
