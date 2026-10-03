<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/lib/database.php';
$config = require dirname(__DIR__) . '/config.php';
try {
    $db = database();
    $schemas = [
        'users' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(100) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'pages' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, route VARCHAR(700) NOT NULL UNIQUE, title VARCHAR(500) NOT NULL, source_file VARCHAR(255) NOT NULL, html MEDIUMTEXT NULL, status VARCHAR(20) NOT NULL DEFAULT \'published\', version INT NOT NULL DEFAULT 1, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        'media' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, path VARCHAR(700) NOT NULL UNIQUE, name VARCHAR(500) NOT NULL, mime VARCHAR(150) NOT NULL, source_url TEXT NULL, uploaded TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'audit' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NULL, action VARCHAR(80) NOT NULL, subject VARCHAR(700) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'login_attempts' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ip_hash CHAR(64) NOT NULL, attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX login_window (ip_hash, attempted_at)',
    ];
    foreach ($schemas as $name => $columns) $db->exec("CREATE TABLE IF NOT EXISTS indiba_cms_$name ($columns) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    require_once dirname(__DIR__) . '/lib/cms-management.php';
    cmsUpgrade();
    $manifestFile = getenv('CMS_IMPORT_MANIFEST') ?: $config['storage'] . '/manifest.json';
    $manifest = json_decode(file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
    $insertPage = $db->prepare('INSERT IGNORE INTO indiba_cms_pages (route, title, source_file) VALUES (?, ?, ?)');
    $insertMedia = $db->prepare('INSERT IGNORE INTO indiba_cms_media (path, name, mime, source_url) VALUES (?, ?, ?, ?)');
    $db->beginTransaction(); $count = 0;
    foreach ($manifest['pages'] as $route => $page) {
        $html = file_get_contents($config['storage'] . '/' . $page['file']);
        preg_match('~<title\b[^>]*>(.*?)</title>~is', $html, $match);
        $title = html_entity_decode(strip_tags($match[1] ?? $route), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $insertPage->execute([$route, $title, $page['file']]);
        if (++$count % 100 === 0) { $db->commit(); echo "$count pages indexed.\n"; $db->beginTransaction(); }
    }
    $db->commit(); $db->beginTransaction(); $count = 0;
    foreach ($manifest['assets'] as $url => $asset) {
        $name = rawurldecode(basename(parse_url($url, PHP_URL_PATH) ?: $asset['local']));
        $insertMedia->execute([$asset['local'], substr($name ?: 'Asset', 0, 490), $asset['mime'] ?? 'application/octet-stream', $url]);
        if (++$count % 200 === 0) { $db->commit(); echo "$count assets indexed.\n"; $db->beginTransaction(); }
    }
    $db->commit();
    if ((int)$db->query('SELECT COUNT(*) FROM indiba_cms_users')->fetchColumn() === 0) {
        $password = bin2hex(random_bytes(12));
        $statement = $db->prepare('INSERT INTO indiba_cms_users (username, password_hash) VALUES (?, ?)');
        $statement->execute(['admin', password_hash($password, PASSWORD_DEFAULT)]);
        $bootstrap = getenv('CMS_BOOTSTRAP_FILE') ?: $config['storage'] . '/admin-bootstrap.txt';
        file_put_contents($bootstrap, "Portal: http://127.0.0.1:9000/admin/\nUsername: admin\nPassword: $password\n\nChange the password in the portal after signing in.\n", LOCK_EX);
        @chmod($bootstrap, 0600);
        echo "Administrator created. Initial login saved privately to storage/admin-bootstrap.txt.\n";
    }
    if (!in_array('--no-activate', $argv, true)) file_put_contents($config['storage'] . '/cms-installed.json', json_encode(['installed_at' => gmdate('c'), 'pages' => count($manifest['pages']), 'assets' => count($manifest['assets'])], JSON_PRETTY_PRINT), LOCK_EX);
    echo "Portal installed. Original website files and existing database tables preserved.\n";
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'Portal installation failed (' . $e->getCode() . '). Check database access, schema permissions, and local file permissions.' . "\n"); exit(1);
}
