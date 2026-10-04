<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/lib/seeding.php';
try {
    $counts = databaseSeed(database(), !in_array('--no-activate', $argv, true));
    echo $counts['pages'] . ' pages and ' . $counts['media'] . " media files added. Existing content and accounts preserved.\n";
    if ($counts['administrator']) echo "Administrator created. Credentials saved privately in the configured bootstrap file.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Database seeding failed (' . $error->getCode() . "). Run migrations first and check the import manifest and storage permissions.\n");
    exit(1);
}
