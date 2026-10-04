<?php
declare(strict_types=1);

// Matches the existing remote CMS schema inspected with SHOW CREATE TABLE.
return static function (PDO $db): void {
    $tables = [
        'users' => "
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(100) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY username (username)",
        'pages' => "
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            route VARCHAR(700) NOT NULL,
            title VARCHAR(500) NOT NULL,
            source_file VARCHAR(255) NOT NULL,
            html MEDIUMTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'published',
            version INT NOT NULL DEFAULT 1,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY route (route)",
        'media' => "
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            path VARCHAR(700) NOT NULL,
            name VARCHAR(500) NOT NULL,
            mime VARCHAR(150) NOT NULL,
            source_url TEXT NULL,
            uploaded TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY path (path)",
        'audit' => "
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            action VARCHAR(80) NOT NULL,
            subject VARCHAR(700) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)",
        'login_attempts' => "
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip_hash CHAR(64) NOT NULL,
            attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY login_window (ip_hash, attempted_at)",
    ];
    foreach ($tables as $name => $columns) {
        $db->exec("CREATE TABLE IF NOT EXISTS indiba_cms_$name ($columns)
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
