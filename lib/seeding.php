<?php
declare(strict_types=1);
require_once __DIR__ . '/database.php';

function databaseSeed(PDO $db, bool $activate = true): array {
    $config = require dirname(__DIR__) . '/config.php';
    $lock = 'indiba:seed:' . hash('md5', (string)$db->query('SELECT DATABASE()')->fetchColumn());
    $acquire = $db->prepare('SELECT GET_LOCK(?, 30)');
    $acquire->execute([$lock]);
    if ((int)$acquire->fetchColumn() !== 1) throw new RuntimeException('Another seeder is still running.');
    try {
        $seed = require dirname(__DIR__) . '/database/seeders/001_cms_content.php';
        $counts = $seed($db, $config);
        if ($activate) {
            $marker = getenv('CMS_INSTALL_FILE') ?: $config['storage'] . '/cms-installed.json';
            $state = [
                'installed_at' => gmdate('c'),
                'pages' => (int)$db->query('SELECT COUNT(*) FROM indiba_cms_pages')->fetchColumn(),
                'assets' => (int)$db->query('SELECT COUNT(*) FROM indiba_cms_media')->fetchColumn(),
            ];
            if (file_put_contents($marker, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                throw new RuntimeException('The CMS installation marker could not be saved.');
            }
        }
        return $counts;
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lock]);
    }
}
