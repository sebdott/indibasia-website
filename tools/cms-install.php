<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/lib/migrations.php';
require dirname(__DIR__) . '/lib/seeding.php';
try {
    $db = database();
    $migrations = databaseMigrate($db, static fn(string $message) => print($message . PHP_EOL));
    $counts = databaseSeed($db, !in_array('--no-activate', $argv, true));
    echo "$migrations migrations applied; " . $counts['pages'] . ' pages and ' . $counts['media'] . " media files added.\n";
    if ($counts['administrator']) echo "Administrator created. Credentials saved privately in the configured bootstrap file.\n";
    echo "Portal ready. Existing content and accounts preserved.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Portal installation failed (' . $error->getCode() . "). Check database access, migration files, the import manifest, and storage permissions.\n");
    exit(1);
}
