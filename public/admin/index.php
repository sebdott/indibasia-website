<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/lib/cms-management.php';
require dirname(__DIR__, 2) . '/lib/cms-classic.php';
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' https: data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self' data:; frame-src 'self' https://www.youtube.com https://www.youtube-nocookie.com https://player.vimeo.com; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
header('Cache-Control: no-store');
session_name('indiba_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/admin', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
session_start();
if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > 1800) { $_SESSION = []; session_regenerate_id(true); }
$_SESSION['last_seen'] = time();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$view = $_GET['view'] ?? 'dashboard'; $error = ''; $notice = $_SESSION['notice'] ?? ''; unset($_SESSION['notice']);
function csrfInput(): string { return '<input type="hidden" name="csrf" value="' . cmsEscape($_SESSION['csrf']) . '">'; }
function redirectAdmin(string $url = '/admin/'): never { header('Location: ' . $url, true, 303); exit; }
function adminNotice(string $message, string $url): never { $_SESSION['notice'] = $message; redirectAdmin($url); }
try {
    $db = database();
    $installed = (int)$db->query('SELECT COUNT(*) FROM indiba_cms_users')->fetchColumn() > 0;
    if (!$installed) throw new RuntimeException('Portal installation is incomplete.');
} catch (Throwable $e) {
    http_response_code(503); echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Management portal</title><h1>Management portal unavailable</h1><p>Check the database connection and run the portal installation command.</p></html>'; exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Your session expired. Reload the page and try again.'); }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'login') {
            $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $rate = $db->prepare('SELECT COUNT(*) FROM indiba_cms_login_attempts WHERE ip_hash = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'); $rate->execute([$ipHash]);
            if ((int)$rate->fetchColumn() >= 5) { http_response_code(429); throw new RuntimeException('Too many sign-in attempts. Try again in 15 minutes.'); }
            $userQuery = $db->prepare('SELECT * FROM indiba_cms_users WHERE username = ?'); $userQuery->execute([trim((string)($_POST['username'] ?? ''))]); $user = $userQuery->fetch();
            $hash = $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $valid = password_verify((string)($_POST['password'] ?? ''), $hash);
            if (!$user || !$valid) {
                $db->prepare('INSERT INTO indiba_cms_login_attempts (ip_hash) VALUES (?)')->execute([$ipHash]);
                throw new RuntimeException('The username or password is incorrect.');
            }
            $db->prepare('DELETE FROM indiba_cms_login_attempts WHERE ip_hash = ? OR attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)')->execute([$ipHash]);
            session_regenerate_id(true); $_SESSION['csrf'] = bin2hex(random_bytes(32)); $_SESSION['user_id'] = (int)$user['id']; $_SESSION['username'] = $user['username'];
            cmsAudit('login', $user['username']); redirectAdmin();
        }
        if (empty($_SESSION['user_id'])) { http_response_code(401); throw new RuntimeException('Sign in to continue.'); }
        require dirname(__DIR__, 2) . '/lib/admin/actions.php';
        cmsManagementAction((string)$action);
        if ($action === 'logout') { cmsAudit('logout', $_SESSION['username']); $_SESSION = []; session_destroy(); redirectAdmin(); }
        if ($action === 'password') {
            $q = $db->prepare('SELECT password_hash FROM indiba_cms_users WHERE id = ?'); $q->execute([$_SESSION['user_id']]);
            if (!password_verify((string)($_POST['current_password'] ?? ''), $q->fetchColumn())) throw new RuntimeException('The current password is incorrect.');
            $password = (string)($_POST['new_password'] ?? '');
            if (strlen($password) < 12 || strlen($password) > 72) throw new RuntimeException('Choose a password between 12 and 72 characters.');
            if ($password !== ($_POST['confirm_password'] ?? '')) throw new RuntimeException('The new passwords do not match.');
            $db->prepare('UPDATE indiba_cms_users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $_SESSION['user_id']]);
            session_regenerate_id(true); cmsAudit('password_changed', $_SESSION['username']);
            $bootstrap = getenv('CMS_BOOTSTRAP_FILE') ?: dirname(__DIR__, 2) . '/storage/admin-bootstrap.txt'; if (is_file($bootstrap)) unlink($bootstrap);
            adminNotice('Your password has been changed.', '/admin/?view=account');
        }
        if ($action === 'save_page' || $action === 'reset_page') {
            $page = cmsPage((int)($_POST['id'] ?? 0));
            if ($page['status'] === 'trashed') throw new RuntimeException('Restore this page from Trash before editing it.');
            $status = ($_POST['status'] ?? '') === 'draft' ? 'draft' : 'published';
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '' || strlen($title) > 490) throw new RuntimeException('Enter a page title under 490 characters.');
            $html = null;
            if ($action === 'save_page') {
                if (($_POST['mode'] ?? 'content') === 'html') {
                    $html = (string)($_POST['html'] ?? '');
                    if (!str_contains(strtolower($html), '<html') || !str_contains(strtolower($html), '<body')) throw new RuntimeException('The source must contain a complete HTML document.');
                } else {
                    $document = cmsDocument(cmsHtml($page)); [$text, $images] = cmsEditable($document);
                    if (($_POST['mode'] ?? '') === 'classic' && ($_POST['classic_changed'] ?? '') === '1') {
                        cmsClassicApply($document, (string)($_POST['classic_content'] ?? ''));
                    }
                    foreach ($text as $key => $node) if (isset($_POST['text'][$key])) {
                        preg_match('/^(\s*)(.*?)(\s*)$/s', $node->nodeValue, $spaces);
                        $node->nodeValue = ($spaces[1] ?? '') . (string)$_POST['text'][$key] . ($spaces[3] ?? '');
                    }
                    if (($_POST['mode'] ?? '') === 'visual') {
                        cmsApplyRichText($document, (array)($_POST['rich'] ?? []));
                        if ($page['source_file'] === '') {
                            $main = $document->getElementsByTagName('main')->item(0);
                            foreach (array_slice((array)($_POST['new_blocks'] ?? []), 0, 50) as $block) {
                                if (!$main || !in_array($block['type'] ?? '', ['p','h2','blockquote'], true)) continue;
                                $element = $document->createElement($block['type']); $main->appendChild($element);
                                $element->nodeValue = trim((string)($block['text'] ?? ''));
                            }
                        }
                    }
                    foreach ($images as $key => $node) if (isset($_POST['images'][$key]['src'])) {
                        $previousSource = $node->getAttribute('src');
                        $newSource = (string)$_POST['images'][$key]['src'];
                        if ($newSource !== $previousSource) $node->setAttribute('src', cmsImageUrl($newSource));
                        $node->setAttribute('alt', (string)($_POST['images'][$key]['alt'] ?? ''));
                        if ($node->getAttribute('src') !== $previousSource) {
                            foreach (['srcset', 'data-srcset', 'data-src', 'data-lazy-src', 'data-lazy-srcset', 'sizes', 'width', 'height'] as $attribute) $node->removeAttribute($attribute);
                        }
                    }
                    $titles = $document->getElementsByTagName('title'); if ($titles->length) $titles->item(0)->textContent = $title;
                    $html = cmsDocumentHtml($document);
                }
                if (strlen($html) > 12 * 1024 * 1024) throw new RuntimeException('The page exceeds the 12 MB limit.');
            } elseif ($page['source_file'] === '') throw new RuntimeException('New pages have no original snapshot to restore.');
            $route = cmsRoute((string)($_POST['route'] ?? $page['route']));
            $group = trim((string)($_POST['group_name'] ?? $page['group_name'])) ?: 'General';
            $seoTitle = trim((string)($_POST['seo_title'] ?? $page['seo_title'] ?? ''));
            $description = array_key_exists('meta_description', $_POST) ? trim((string)$_POST['meta_description']) : $page['meta_description'];
            if (strlen($group) > 100 || strlen($seoTitle) > 490 || strlen($description ?? '') > 2000) throw new RuntimeException('The page group or search settings are too long.');
            if ($html !== null) $html = cmsSeo($html, $seoTitle ?: $title, $description);
            cmsSave($page, ['title' => $title, 'route' => $route, 'html' => $html, 'status' => $status, 'group_name' => $group, 'seo_title' => $seoTitle ?: null, 'meta_description' => $description], (int)($_POST['version'] ?? 0), (string)$action);
            adminNotice($action === 'reset_page' ? 'Original page restored.' : ($status === 'published' ? 'Page published. Your changes are visible on the website.' : 'Draft saved. Preview it before publishing.'), '/admin/?view=edit&id=' . $page['id'] . '&mode=' . urlencode((string)($_POST['mode'] ?? 'visual')));
        }
        if ($action === 'create_page') {
            $route = cmsRoute((string)($_POST['route'] ?? '')); $title = trim((string)($_POST['title'] ?? ''));
            cmsRouteAvailable($route);
            if ($title === '' || strlen($title) > 490) throw new RuntimeException('Enter a page title under 490 characters.');
            $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . cmsEscape($title) . '</title><link rel="stylesheet" href="/admin-page.css"></head><body><header><a href="/">INDIBA</a></header><main><h1>' . cmsEscape($title) . '</h1><p>Add your English page content here.</p></main><script src="/mirror.js"></script></body></html>';
            $db->prepare('INSERT INTO indiba_cms_pages (route, title, source_file, html, status) VALUES (?, ?, ?, ?, ?)')->execute([$route, $title, '', $html, 'draft']);
            $id = $db->lastInsertId(); cmsAudit('create_page', $route); adminNotice('Draft created. Add content and publish when ready.', '/admin/?view=edit&id=' . $id);
        }
        if ($action === 'upload' || $action === 'editor_upload') {
            $file = $_FILES['file'] ?? [];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) throw new RuntimeException('Choose a file up to 20 MB and try again.');
            if (($file['size'] ?? 0) > 20 * 1024 * 1024) throw new RuntimeException('The upload exceeds 20 MB.');
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf'];
            if (!isset($extensions[$mime])) throw new RuntimeException('Upload a JPG, PNG, WebP, GIF, or PDF file.');
            $directory = dirname(__DIR__) . '/uploads'; if (!is_dir($directory)) mkdir($directory, 0755, true);
            $path = '/uploads/' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
            $name = substr(basename((string)$file['name']), 0, 490);
            if (!move_uploaded_file($file['tmp_name'], dirname(__DIR__) . $path)) throw new RuntimeException('The file could not be saved.');
            try { $db->prepare('INSERT INTO indiba_cms_media (path, name, mime, uploaded) VALUES (?, ?, ?, 1)')->execute([$path, $name, $mime]); }
            catch (Throwable $e) { unlink(dirname(__DIR__) . $path); throw $e; }
            cmsAudit('upload', $path);
            if ($action === 'editor_upload') { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['location'=>$path,'name'=>$name]); exit; }
            adminNotice('File uploaded. Copy its URL to use it in a page.', '/admin/?view=media&filter=uploads');
        }
    } catch (PDOException $e) {
        $error = $e->getCode() === '23000' ? 'That page path already exists. Choose another path.' : 'The database could not save this change. Please try again.';
    } catch (RuntimeException $e) { $error = $e->getMessage(); }
    if ($action === 'editor_upload' && $error !== '') { http_response_code(422); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error'=>$error]); exit; }
}
$signedIn = !empty($_SESSION['user_id']);
if (!$signedIn) $view = 'login';
if ($signedIn && $view === 'preview') { require dirname(__DIR__, 2) . '/lib/admin/preview.php'; exit; }
if ($signedIn && $view === 'picker') { require dirname(__DIR__, 2) . '/lib/admin/picker.php'; exit; }
if (!in_array($view, ['login', 'dashboard', 'pages', 'edit', 'new', 'media', 'account', 'revisions'], true)) $view = 'dashboard';
$labels = ['login' => 'Sign in', 'dashboard' => 'Overview', 'pages' => 'Pages', 'edit' => 'Edit page', 'new' => 'New page', 'media' => 'Media library', 'account' => 'Your account', 'revisions' => 'Revision history'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= cmsEscape($labels[$view]) ?> · INDIBA management</title><link rel="stylesheet" href="/admin/admin.css"><?php if ($view === 'edit'): ?><script defer src="/vendor/tinymce/tinymce.min.js"></script><?php endif ?><script defer src="/admin/admin.js"></script><?php if ($view === 'edit'): ?><script defer src="/admin/classic.js"></script><?php endif ?></head><body>
<?php if ($signedIn): ?><aside class="sidebar"><a class="brand" href="/admin/">INDIBA<span>Management</span></a><nav><?php foreach (['dashboard' => 'Overview', 'pages' => 'Pages', 'media' => 'Media library', 'account' => 'Your account'] as $key => $label): ?><a class="<?= $view === $key || $key === 'pages' && in_array($view, ['edit','new']) ? 'active' : '' ?>" href="/admin/?view=<?= $key ?>"><?= $label ?></a><?php endforeach ?></nav><div class="sidebar-bottom"><a href="/" target="_blank" rel="noopener">Open website ↗</a><form method="post"><?= csrfInput() ?><input type="hidden" name="action" value="logout"><button class="signout">Sign out</button></form></div></aside><?php endif ?>
<main class="<?= $signedIn ? 'workspace' : 'login-wrap' ?>">
<?php if ($signedIn): ?><header class="page-heading"><div><p class="eyebrow">English website</p><h1><?= $labels[$view] ?></h1></div><span class="user-badge"><?= cmsEscape($_SESSION['username']) ?></span></header><?php endif ?>
<?php if ($error): ?><div class="alert error" role="alert"><?= cmsEscape($error) ?></div><?php endif ?><?php if ($notice): ?><div class="alert success" role="status"><?= cmsEscape($notice) ?></div><?php endif ?>
<?php if ($view === 'login'): ?><section class="login-card"><p class="eyebrow">INDIBA management</p><h1>Welcome back</h1><p class="muted">Sign in to manage your English website.</p><form method="post"><?= csrfInput() ?><input type="hidden" name="action" value="login"><label>Username<input name="username" required autocomplete="username" maxlength="100"></label><label>Password<input type="password" name="password" required autocomplete="current-password" maxlength="72"></label><button>Sign in</button></form><a href="/" class="back-link">← Back to website</a></section>
<?php elseif ($view === 'dashboard'):
    $stats = $db->query("SELECT COUNT(*) AS total, SUM(status = 'published') AS published, SUM(html IS NOT NULL) AS edited FROM indiba_cms_pages WHERE status <> 'trashed'")->fetch();
    $mediaCount = $db->query('SELECT COUNT(*) FROM indiba_cms_media')->fetchColumn();
    $activity = $db->query('SELECT a.*, u.username FROM indiba_cms_audit a LEFT JOIN indiba_cms_users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 12')->fetchAll(); ?>
<section class="welcome"><div><h2>Your website, in one place</h2><p>Edit page content, publish updates, and manage your images and files.</p></div><a class="button light" href="/admin/?view=pages">Manage pages →</a></section>
<div class="stats"><?php foreach (['Total pages' => $stats['total'], 'Published' => $stats['published'], 'Edited or new' => $stats['edited'], 'Media files' => $mediaCount] as $label => $number): ?><div class="stat"><span><?= $label ?></span><strong><?= number_format((int)$number) ?></strong></div><?php endforeach ?></div>
<section class="panel"><div class="panel-heading"><h2>Recent activity</h2><a href="/admin/?view=new">Create a page</a></div><?php if (!$activity): ?><p class="muted">Your changes will appear here.</p><?php endif ?><?php foreach ($activity as $entry): ?><div class="activity"><span><strong><?= cmsEscape(str_replace('_',' ', $entry['action'])) ?></strong><small><?= cmsEscape($entry['subject']) ?></small></span><span class="muted"><?= cmsEscape($entry['username'] ?? 'System') ?> · <?= cmsEscape($entry['created_at']) ?></span></div><?php endforeach ?></section>
<?php elseif (in_array($view, ['pages', 'edit', 'new', 'revisions'], true)): require dirname(__DIR__, 2) . '/lib/admin/' . $view . '.php'; ?>
<?php elseif ($view === 'media'):
    $search = trim((string)($_GET['q'] ?? '')); $filter = ($_GET['filter'] ?? '') === 'uploads' ? 'uploads' : 'all'; $number = max(1, (int)($_GET['page'] ?? 1)); $offset = ($number-1)*24;
    $conditions = []; $params = []; if ($filter === 'uploads') $conditions[] = 'uploaded = 1';
    if ($search !== '') { $conditions[] = '(name LIKE ? OR mime LIKE ? OR path LIKE ?)'; $params = array_fill(0,3,'%' . $search . '%'); }
    $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
    $q = $db->prepare('SELECT COUNT(*) FROM indiba_cms_media' . $where); $q->execute($params); $total = (int)$q->fetchColumn();
    $q = $db->prepare('SELECT * FROM indiba_cms_media' . $where . " ORDER BY uploaded DESC, id DESC LIMIT 24 OFFSET $offset"); $q->execute($params); $media = $q->fetchAll(); ?>
<section class="panel upload-panel"><div><h2>Upload a file</h2><p class="muted">JPG, PNG, WebP, GIF, or PDF · Up to 20 MB</p></div><form method="post" enctype="multipart/form-data"><?= csrfInput() ?><input type="hidden" name="action" value="upload"><input type="file" name="file" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf" required aria-label="Choose file"><button>Upload file</button></form></section>
<div class="toolbar"><form method="get" class="search-form"><input type="hidden" name="view" value="media"><input name="q" placeholder="Search filenames or file types" value="<?= cmsEscape($search) ?>" aria-label="Search media"><select name="filter" aria-label="Media filter"><option value="all">All files</option><option value="uploads" <?= $filter === 'uploads' ? 'selected' : '' ?>>Your uploads</option></select><button>Search</button></form><span class="muted"><?= number_format($total) ?> files</span></div>
<div class="media-grid"><?php foreach ($media as $file): ?><article class="media-card"><?php if (str_starts_with($file['mime'],'image/')): ?><img src="<?= cmsEscape($file['path']) ?>" alt="" loading="lazy"><?php else: ?><div class="file-icon"><?= cmsEscape(strtoupper(pathinfo($file['path'], PATHINFO_EXTENSION))) ?></div><?php endif ?><div class="media-info"><strong title="<?= cmsEscape($file['name']) ?>"><?= cmsEscape($file['name']) ?></strong><small><?= cmsEscape($file['mime']) ?></small><input readonly value="<?= cmsEscape($file['path']) ?>" aria-label="File URL"><div><button type="button" class="secondary copy-url" data-url="<?= cmsEscape($file['path']) ?>">Copy URL</button><a href="<?= cmsEscape($file['path']) ?>" target="_blank" rel="noopener">Open ↗</a></div></div></article><?php endforeach ?></div><?php if (!$media): ?><p class="empty">No files match your search.</p><?php endif ?>
<div class="pagination"><span>Page <?= $number ?> of <?= max(1,(int)ceil($total/24)) ?></span><div><?php if ($number>1): ?><a href="?view=media&filter=<?= $filter ?>&q=<?= urlencode($search) ?>&page=<?= $number-1 ?>">← Previous</a><?php endif ?><?php if ($offset+24<$total): ?><a href="?view=media&filter=<?= $filter ?>&q=<?= urlencode($search) ?>&page=<?= $number+1 ?>">Next →</a><?php endif ?></div></div>
<?php elseif ($view === 'account'): ?><section class="panel narrow"><h2>Change your password</h2><p class="muted">Signed in as <?= cmsEscape($_SESSION['username']) ?>.</p><form method="post"><?= csrfInput() ?><input type="hidden" name="action" value="password"><label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label><label>New password<input type="password" name="new_password" required minlength="12" maxlength="72" autocomplete="new-password"></label><label>Confirm new password<input type="password" name="confirm_password" required minlength="12" maxlength="72" autocomplete="new-password"></label><button>Change password</button></form></section><?php endif ?>
</main></body></html>
