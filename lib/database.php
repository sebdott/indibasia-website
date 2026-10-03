<?php
declare(strict_types=1);

function databaseConfig(): array {
    $values = [];
    $file = dirname(__DIR__) . '/pw.txt';
    if (is_file($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^\s*SetEnv\s+(DB_[A-Z_]+)\s+(.+?)\s*$/', $line, $m)) {
                $values[$m[1]] = trim($m[2], "\"'");
            }
        }
    }
    foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_CHARSET', 'DB_SSL_CA'] as $key) {
        $value = getenv($key);
        if ($value !== false) $values[$key] = $value;
    }
    foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
        if (!isset($values[$key])) throw new RuntimeException('Database credentials are incomplete.');
    }
    return $values;
}

function database(): PDO {
    static $connection;
    if ($connection instanceof PDO) return $connection;
    $c = databaseConfig();
    $charset = $c['DB_CHARSET'] ?? 'utf8mb4';
    if (!in_array($charset, ['utf8', 'utf8mb4'], true)) throw new RuntimeException('Unsupported database charset.');
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5];
    if (!empty($c['DB_SSL_CA'])) $options[PDO::MYSQL_ATTR_SSL_CA] = $c['DB_SSL_CA'];
    $connection = new PDO('mysql:host=' . $c['DB_HOST'] . ';port=' . ($c['DB_PORT'] ?? '3306') . ';dbname=' . $c['DB_DATABASE'] . ';charset=' . $charset,
        $c['DB_USERNAME'], $c['DB_PASSWORD'], $options);
    return $connection;
}
