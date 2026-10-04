<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/content-types.php';

return static function (PDO $db, array $config): array {
    $manifestFile = getenv('CMS_IMPORT_MANIFEST') ?: $config['storage'] . '/manifest.json';
    $manifest = json_decode((string)file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest['pages'] ?? null) || !is_array($manifest['assets'] ?? null)) {
        throw new RuntimeException('The import manifest must contain pages and assets.');
    }
    $existingPages = array_fill_keys($db->query('SELECT route FROM indiba_cms_pages')->fetchAll(PDO::FETCH_COLUMN), true);
    $existingMedia = array_fill_keys($db->query('SELECT path FROM indiba_cms_media')->fetchAll(PDO::FETCH_COLUMN), true);
    $pageRoot = realpath($config['storage'] . '/pages');
    $limit = static function (string $text): string {
        if (!preg_match('/\A.{0,490}/us', $text, $match)) throw new RuntimeException('Invalid UTF-8 in the import manifest.');
        return $match[0];
    };
    $insertPage = $db->prepare('INSERT INTO indiba_cms_pages (route, title, source_file, content_type) VALUES (?, ?, ?, ?)');
    $insertMedia = $db->prepare('INSERT INTO indiba_cms_media (path, name, mime, source_url) VALUES (?, ?, ?, ?)');
    $counts = ['pages' => 0, 'media' => 0, 'administrator' => false];
    $db->beginTransaction();
    try {
        foreach ($manifest['pages'] as $route => $page) {
            if (isset($existingPages[$route])) continue;
            $file = realpath($config['storage'] . '/' . $page['file']);
            if (!$pageRoot || !$file || !str_starts_with($file, $pageRoot . DIRECTORY_SEPARATOR) || !is_file($file)) {
                throw new RuntimeException('An original page file is missing or outside storage/pages.');
            }
            $html = file_get_contents($file);
            if ($html === false || $html === '') throw new RuntimeException('An original page file is empty or unreadable.');
            preg_match('~<title\b[^>]*>(.*?)</title>~is', $html, $match);
            $title = html_entity_decode(strip_tags($match[1] ?? $route), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $type = cmsImportedContentType($route, $html);
            $insertPage->execute([$route, $limit($title), $page['file'], $type]);
            $existingPages[$route] = true;
            $counts['pages']++;
        }
        foreach ($manifest['assets'] as $url => $asset) {
            if (isset($existingMedia[$asset['local']])) continue;
            $name = rawurldecode(basename(parse_url($url, PHP_URL_PATH) ?: $asset['local']));
            $insertMedia->execute([$asset['local'], $limit($name ?: 'Asset'), $asset['mime'] ?? 'application/octet-stream', $url]);
            $existingMedia[$asset['local']] = true;
            $counts['media']++;
        }
        if ((int)$db->query('SELECT COUNT(*) FROM indiba_cms_users')->fetchColumn() === 0) {
            $password = bin2hex(random_bytes(12));
            $bootstrap = getenv('CMS_BOOTSTRAP_FILE') ?: $config['storage'] . '/admin-bootstrap.txt';
            $text = "Username: admin\nPassword: $password\n\nChange this password in Your account after signing in.\n";
            if (file_put_contents($bootstrap, $text, LOCK_EX) === false) throw new RuntimeException('Administrator credentials could not be saved.');
            @chmod($bootstrap, 0600);
            $db->prepare('INSERT INTO indiba_cms_users (username, password_hash) VALUES (?, ?)')->execute(['admin', password_hash($password, PASSWORD_DEFAULT)]);
            $counts['administrator'] = true;
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    return $counts;
};
