<?php
declare(strict_types=1);
require_once __DIR__ . '/database.php';

function databaseMigrate(PDO $db, ?callable $report = null): int {
    $lock = 'indiba:migrate:' . hash('md5', (string)$db->query('SELECT DATABASE()')->fetchColumn());
    $acquire = $db->prepare('SELECT GET_LOCK(?, 30)');
    $acquire->execute([$lock]);
    if ((int)$acquire->fetchColumn() !== 1) throw new RuntimeException('Another migration is still running.');
    try {
        $db->exec('CREATE TABLE IF NOT EXISTS indiba_cms_migrations (
            migration VARCHAR(190) NOT NULL PRIMARY KEY,
            checksum CHAR(64) NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $applied = $db->query('SELECT migration, checksum FROM indiba_cms_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
        $files = glob(dirname(__DIR__) . '/database/migrations/*.php');
        if ($files === false) throw new RuntimeException('Migration files could not be read.');
        sort($files, SORT_STRING);
        $record = $db->prepare('INSERT INTO indiba_cms_migrations (migration, checksum) VALUES (?, ?)');
        $count = 0;
        foreach ($files as $file) {
            $name = basename($file, '.php');
            $source = file_get_contents($file);
            if ($source === false) throw new RuntimeException('A migration file could not be read.');
            $checksum = hash('sha256', str_replace(["\r\n", "\r"], "\n", $source));
            if (isset($applied[$name])) {
                if (!hash_equals($applied[$name], $checksum)) throw new RuntimeException('An applied migration was modified: ' . $name);
                continue;
            }
            if ($report) $report('Applying ' . $name . '...');
            $migration = require $file;
            if (!is_callable($migration)) throw new RuntimeException('Invalid migration: ' . $name);
            // MySQL DDL commits implicitly; each migration must tolerate a retry.
            $migration($db);
            $record->execute([$name, $checksum]);
            $count++;
        }
        return $count;
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lock]);
    }
}
