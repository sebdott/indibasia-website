<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/lib/migrations.php';
try {
    $count = databaseMigrate(database(), static fn(string $message) => print($message . PHP_EOL));
    echo "$count migrations applied. Database schema is up to date.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Database migration failed (' . $error->getCode() . "). Check database access, schema permissions, and migration files.\n");
    exit(1);
}
