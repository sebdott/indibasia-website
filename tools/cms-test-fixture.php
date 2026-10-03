<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
$root = dirname(__DIR__);
if (in_array('--reset', $argv, true)) {
    require $root . '/lib/database.php'; $credentials = databaseConfig();
    if ($credentials['DB_DATABASE'] !== 'indiba_cms_test' || $credentials['DB_HOST'] !== 'indiba-cms-test-db') { fwrite(STDERR, "Reset is restricted to the isolated test database.\n"); exit(1); }
    $db = database();
    foreach (['users','pages','media','audit','login_attempts','revisions','redirects'] as $table) $db->exec('TRUNCATE TABLE indiba_cms_' . $table);
}
$manifest = json_decode(file_get_contents($root . '/storage/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$pages = [];
foreach (['/', '/asia/products/ct8/'] as $route) $pages[$route] = $manifest['pages'][$route];
$assets = array_slice($manifest['assets'], 0, 3, true);
file_put_contents($root . '/storage/cms-test-manifest.json', json_encode(['pages' => $pages, 'assets' => $assets], JSON_THROW_ON_ERROR));
echo "Isolated test import manifest prepared.\n";
