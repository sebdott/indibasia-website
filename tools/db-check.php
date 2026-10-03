<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/lib/database.php';
try {
    $db = database();
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo 'MySQL connection successful. Server ' . $db->getAttribute(PDO::ATTR_SERVER_VERSION) . '. Existing tables: ' . count($tables) . ".\n";
    echo 'Portal tables present: ' . count(array_filter($tables, fn($name) => str_starts_with($name, 'indiba_cms_'))) . ".\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Database connection failed (' . $e->getCode() . '). Check connection details and network access.' . "\n"); exit(1);
}
