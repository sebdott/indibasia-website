<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/lib/migrations.php';
require dirname(__DIR__) . '/lib/seeding.php';

$credentials = databaseConfig();
if ($credentials['DB_DATABASE'] !== 'indiba_cms_test' || $credentials['DB_HOST'] !== 'indiba-cms-test-db') {
    fwrite(STDERR, "Database tests are restricted to the isolated test database.\n");
    exit(1);
}
function databaseCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
try {
    $db = database();
    databaseCheck($db->query('SHOW TABLES')->fetchAll() === [], 'Start these checks with an empty isolated test database.');
    databaseCheck(databaseMigrate($db) === 6, 'Fresh database did not apply all migrations.');
    databaseCheck(count($db->query('SHOW TABLES')->fetchAll()) === 8, 'Expected seven CMS tables plus migration history.');
    databaseCheck((int)$db->query('SELECT COUNT(*) FROM indiba_cms_migrations')->fetchColumn() === 6, 'Migration history is incomplete.');
    databaseCheck(databaseMigrate($db) === 0, 'A migration ran more than once.');
    echo "PASS fresh schema and migration tracking\n";

    $manifestFile = getenv('CMS_IMPORT_MANIFEST');
    databaseCheck(is_string($manifestFile) && is_file($manifestFile), 'Configure an isolated import manifest.');
    $manifest = json_decode(file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
    $counts = databaseSeed($db, false);
    databaseCheck($counts['pages'] === count($manifest['pages']), 'Page seeding is incomplete.');
    databaseCheck($counts['media'] === count(array_unique(array_column($manifest['assets'], 'local'))), 'Media seeding is incomplete.');
    databaseCheck($counts['administrator'] === true, 'The initial administrator was not created.');
    $expectedTypes = [
        '/' => 'page', '/asia/products/ct8/' => 'page',
        '/asia/news/physiotherapy-for-cats-enhancing-feline-wellbeing-with-indibas-radiofrequency/' => 'news',
        '/us/events/' => 'master', '/news/' => 'master', '/us/event-brands/rehabilitation/' => 'master',
        '/events/how-indiba-works-at-a-cellular-level/' => 'event',
    ];
    foreach ($db->query('SELECT route, content_type FROM indiba_cms_pages') as $item) {
        databaseCheck(isset($expectedTypes[$item['route']]) && $item['content_type'] === $expectedTypes[$item['route']], 'Seeded content is in the wrong section.');
    }
    $bootstrap = getenv('CMS_BOOTSTRAP_FILE');
    databaseCheck(is_string($bootstrap) && is_file($bootstrap), 'Private administrator credentials were not written.');
    $bootstrapHash = hash_file('sha256', $bootstrap);
    $passwordHash = $db->query('SELECT password_hash FROM indiba_cms_users LIMIT 1')->fetchColumn();
    $initialUser = $db->query('SELECT * FROM indiba_cms_users LIMIT 1')->fetch();
    databaseCheck($initialUser['role'] === 'admin' && $initialUser['status'] === 'active' && (int)$initialUser['session_version'] === 1, 'Initial administrator access is incorrect.');
    $id = $db->query('SELECT id FROM indiba_cms_pages ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('UPDATE indiba_cms_pages SET title = ?, html = ?, status = ?, version = 9 WHERE id = ?')->execute([
        'Existing edited draft', '<!doctype html><html><body>Preserve this edit.</body></html>', 'draft', $id,
    ]);
    $pageQuery = $db->prepare('SELECT id, route, title, source_file, html, status, version, updated_at, content_type FROM indiba_cms_pages WHERE id = ?');
    $pageQuery->execute([$id]);
    $pageBefore = $pageQuery->fetch();
    $counts = databaseSeed($db, false);
    databaseCheck($counts === ['pages' => 0, 'media' => 0, 'administrator' => false], 'Seeding duplicated existing records.');
    $pageQuery->execute([$id]);
    databaseCheck($pageQuery->fetch() === $pageBefore, 'Seeding overwrote a page edit, status, or version.');
    databaseCheck($db->query('SELECT password_hash FROM indiba_cms_users LIMIT 1')->fetchColumn() === $passwordHash, 'Seeding reset an administrator password.');
    databaseCheck(hash_file('sha256', $bootstrap) === $bootstrapHash, 'Seeding overwrote private credentials.');
    echo "PASS seed reruns preserve page edits, drafts, versions, and passwords\n";

    // Emulate the original five-table installation before page management.
    $db->exec("UPDATE indiba_cms_media SET title = 'Preserved attachment title', alt_text = 'Preserved alternative text', caption = 'Preserved caption', description = 'Preserved description', trashed_at = CURRENT_TIMESTAMP ORDER BY id LIMIT 1");
    $mediaBefore = $db->query('SELECT * FROM indiba_cms_media ORDER BY id')->fetchAll();
    $allContentBefore = $db->query('SELECT id, route, title, html, status, version, updated_at, content_type FROM indiba_cms_pages ORDER BY id')->fetchAll();
    $db->exec('ALTER TABLE indiba_cms_users DROP COLUMN display_name, DROP COLUMN email, DROP COLUMN role, DROP COLUMN status, DROP COLUMN version, DROP COLUMN session_version, DROP COLUMN last_login_at');
    foreach (['migrations', 'revisions', 'redirects'] as $table) $db->exec('DROP TABLE indiba_cms_' . $table);
    $db->exec('ALTER TABLE indiba_cms_pages DROP INDEX type_status_updated, DROP COLUMN content_type, DROP COLUMN seo_title, DROP COLUMN meta_description, DROP COLUMN group_name');
    databaseCheck(databaseMigrate($db) === 6, 'An existing installation could not be adopted.');
    $columns = $db->query('SHOW COLUMNS FROM indiba_cms_pages')->fetchAll(PDO::FETCH_COLUMN);
    databaseCheck(count(array_intersect(['seo_title', 'meta_description', 'group_name'], $columns)) === 3, 'Legacy page management columns were not upgraded.');
    $pageQuery->execute([$id]);
    databaseCheck($pageQuery->fetch() === $pageBefore, 'Migration changed existing page content.');
    databaseCheck($db->query('SELECT id, route, title, html, status, version, updated_at, content_type FROM indiba_cms_pages ORDER BY id')->fetchAll() === $allContentBefore, 'News migration changed content, publication state, versions, or update dates.');
    databaseCheck($db->query('SELECT password_hash FROM indiba_cms_users LIMIT 1')->fetchColumn() === $passwordHash, 'Migration changed existing credentials.');
    databaseCheck($db->query('SELECT * FROM indiba_cms_users LIMIT 1')->fetch() === $initialUser, 'Migration changed existing account details or administrator access.');
    databaseCheck($db->query('SELECT * FROM indiba_cms_media ORDER BY id')->fetchAll() === $mediaBefore, 'Migration changed attachment metadata or Trash state.');
    echo "PASS legacy schema adoption preserves existing data\n";

    $record = $db->query('SELECT migration, checksum FROM indiba_cms_migrations ORDER BY migration LIMIT 1')->fetch();
    $db->prepare('UPDATE indiba_cms_migrations SET checksum = ? WHERE migration = ?')->execute([str_repeat('0', 64), $record['migration']]);
    $rejected = false;
    try { databaseMigrate($db); } catch (RuntimeException $error) { $rejected = str_contains($error->getMessage(), 'modified'); }
    databaseCheck($rejected, 'A modified applied migration was accepted.');
    $db->prepare('UPDATE indiba_cms_migrations SET checksum = ? WHERE migration = ?')->execute([$record['checksum'], $record['migration']]);
    echo "PASS changed migration checksum is rejected\n";

    $beforeCount = (int)$db->query('SELECT COUNT(*) FROM indiba_cms_pages')->fetchColumn();
    $broken = $manifest;
    $broken['pages']['/partial-seed-test/'] = reset($manifest['pages']);
    $broken['pages']['/missing-seed-file/'] = ['file' => 'pages/missing-seed-test-file.html'];
    $temporary = tempnam(dirname(__DIR__) . '/storage', 'seed-check-');
    databaseCheck($temporary !== false, 'A test manifest could not be created.');
    try {
        file_put_contents($temporary, json_encode($broken, JSON_THROW_ON_ERROR));
        putenv('CMS_IMPORT_MANIFEST=' . $temporary);
        $failed = false;
        try { databaseSeed($db, false); } catch (RuntimeException $error) { $failed = true; }
        databaseCheck($failed, 'A missing page file was accepted.');
        databaseCheck((int)$db->query('SELECT COUNT(*) FROM indiba_cms_pages')->fetchColumn() === $beforeCount, 'Failed seeding left partially imported pages.');
    } finally {
        putenv('CMS_IMPORT_MANIFEST=' . $manifestFile);
        unlink($temporary);
    }
    echo "PASS failed seed transaction rolls back\n";
    echo "All database checks passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Database check failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
