<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/lib/cms-management.php';
require dirname(__DIR__, 2) . '/lib/cms-classic.php';
require dirname(__DIR__, 2) . '/lib/cms-media.php';
require dirname(__DIR__, 2) . '/lib/cms-users.php';
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' https: data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self' data:; frame-src 'self' https://www.youtube.com https://www.youtube-nocookie.com https://player.vimeo.com; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
header('Cache-Control: no-store');
$secureSession = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
// Cookies share a host across ports; each portal needs its own session cookie.
$sessionPort = preg_match('/:(\d{1,5})$/', $_SERVER['HTTP_HOST'] ?? '', $portMatch) ? (int)$portMatch[1] : ($secureSession ? 443 : 80);
session_name('indiba_admin_' . $sessionPort);
session_set_cookie_params(['lifetime' => 0, 'path' => '/admin', 'secure' => $secureSession, 'httponly' => true, 'samesite' => 'Lax']);
session_start();
if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > 1800) { $_SESSION = []; session_regenerate_id(true); }
$_SESSION['last_seen'] = time();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$view = $_GET['view'] ?? 'dashboard'; $error = ''; $notice = $_SESSION['notice'] ?? ''; unset($_SESSION['notice']);
function csrfInput(): string { return '<input type="hidden" name="csrf" value="' . cmsEscape($_SESSION['csrf']) . '">'; }
function adminIcon(string $name): string {
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'pages' => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/>',
        'news' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h4v4H7zM14 8h3M14 12h3M7 16h10"/>',
        'master' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 9v12"/>',
        'events' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 11h18M7 15h3M14 15h3"/>',
        'media' => '<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
        'account' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
        'users' => '<circle cx="9" cy="7" r="4"/><path d="M2 21v-2a7 7 0 0 1 14 0v2M16 3a4 4 0 0 1 0 8M22 21v-2a7 7 0 0 0-4-6"/>',
        'external' => '<path d="M15 3h6v6M10 14 21 3M10 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-5"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'published' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
        'edited' => '<path d="m16 3 5 5-12 12-6 1 1-6zM13 6l5 5"/>',
    ];
    return '<svg class="admin-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? $paths['pages']) . '</svg>';
}
function redirectAdmin(string $url = '/admin/'): never { header('Location: ' . $url, true, 303); exit; }
function adminNotice(string $message, string $url): never { $_SESSION['notice'] = $message; redirectAdmin($url); }
$currentUser = null;
try {
    $db = database();
    $installed = (int)$db->query('SELECT COUNT(*) FROM indiba_cms_users')->fetchColumn() > 0;
    if (!$installed) throw new RuntimeException('Portal installation is incomplete.');
    if (!empty($_SESSION['user_id'])) {
        try { $currentUser = cmsUser((int)$_SESSION['user_id']); } catch (PDOException $e) { throw $e; } catch (RuntimeException $e) { $currentUser = null; }
        // Existing sessions can adopt the initial schema version, but cannot bypass a later access change.
        if (!$currentUser || $currentUser['status'] !== 'active' || (int)$currentUser['session_version'] !== (int)($_SESSION['session_version'] ?? 1)) {
            $_SESSION = []; session_regenerate_id(true); $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $currentUser = null; $error = 'Your account access changed. Sign in again to continue.';
        } else {
            $_SESSION['username'] = $currentUser['username']; $_SESSION['session_version'] = (int)$currentUser['session_version'];
        }
    }
} catch (Throwable $e) {
    http_response_code(503);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Management portal unavailable · INDIBA</title><link rel="stylesheet" href="/admin/fonts.css"><link rel="stylesheet" href="/admin/admin.css"></head><body><main class="login-wrap"><div class="login-brand">INDIBA<span>Management portal</span></div><section class="login-card" role="alert"><p class="eyebrow">Website administration</p><h1>Management portal unavailable</h1><p class="muted">Please check the database connection and portal setup, then try again.</p><a class="back-link" href="/">← Back to website</a></section></main></body></html>';
    exit;
}
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$submittedCsrf = $_POST['csrf'] ?? '';
$validCsrf = !$isPost || is_string($submittedCsrf) && hash_equals($_SESSION['csrf'], $submittedCsrf);
if (!$validCsrf) {
    http_response_code(403);
    $error = 'This form has expired. Please try again using the refreshed form.';
    if (in_array($action, ['media_save', 'media_status'], true)) cmsMediaJson(['error'=>'Your session expired. Reload the library and sign in again.'], 403);
    if ($action === 'editor_upload') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Your session expired. Sign in again before uploading.']);
        exit;
    }
}
if ($isPost && $validCsrf) {
    try {
        if ($action === 'login') {
            $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $rate = $db->prepare('SELECT COUNT(*) FROM indiba_cms_login_attempts WHERE ip_hash = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'); $rate->execute([$ipHash]);
            if ((int)$rate->fetchColumn() >= 5) { http_response_code(429); throw new RuntimeException('Too many sign-in attempts. Try again in 15 minutes.'); }
            $userQuery = $db->prepare('SELECT * FROM indiba_cms_users WHERE username = ?'); $userQuery->execute([trim((string)($_POST['username'] ?? ''))]); $user = $userQuery->fetch();
            $hash = $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $valid = password_verify((string)($_POST['password'] ?? ''), $hash);
            if (!$user || !$valid || $user['status'] !== 'active') {
                $db->prepare('INSERT INTO indiba_cms_login_attempts (ip_hash) VALUES (?)')->execute([$ipHash]);
                throw new RuntimeException('The username or password is incorrect.');
            }
            $db->prepare('DELETE FROM indiba_cms_login_attempts WHERE ip_hash = ? OR attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)')->execute([$ipHash]);
            session_regenerate_id(true); $_SESSION['csrf'] = bin2hex(random_bytes(32)); $_SESSION['user_id'] = (int)$user['id']; $_SESSION['username'] = $user['username']; $_SESSION['session_version'] = (int)$user['session_version'];
            $db->prepare('UPDATE indiba_cms_users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$user['id']]);
            cmsAudit('login', $user['username']); redirectAdmin();
        }
        if (empty($_SESSION['user_id'])) { http_response_code(401); throw new RuntimeException('Sign in to continue.'); }
        cmsUserAction($action, $currentUser);
        require dirname(__DIR__, 2) . '/lib/admin/actions.php';
        cmsManagementAction((string)$action);
        cmsMediaAction($action);
        if ($action === 'logout') { cmsAudit('logout', $_SESSION['username']); $_SESSION = []; session_destroy(); redirectAdmin(); }
        if ($action === 'password') {
            cmsUserChangePassword();
            $bootstrap = getenv('CMS_BOOTSTRAP_FILE') ?: dirname(__DIR__, 2) . '/storage/admin-bootstrap.txt';
            if ($_SESSION['username'] === 'admin' && is_file($bootstrap)) @unlink($bootstrap);
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
                $originalBanners = cmsBannerState(cmsDocument(cmsHtml($page)), $page['content_type']);
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
                $bannerDocument = cmsDocument($html);
                if (cmsBannerApply($bannerDocument, (array)($_POST['banner'] ?? []), $originalBanners, (array)($_POST['banner_new'] ?? []))) $html = cmsDocumentHtml($bannerDocument);
                if (strlen($html) > 12 * 1024 * 1024) throw new RuntimeException('The page exceeds the 12 MB limit.');
            } elseif ($page['source_file'] === '') throw new RuntimeException('New pages have no original snapshot to restore.');
            $route = cmsRoute((string)($_POST['route'] ?? $page['route']));
            $group = trim((string)($_POST['group_name'] ?? $page['group_name'])) ?: 'General';
            $seoTitle = trim((string)($_POST['seo_title'] ?? $page['seo_title'] ?? ''));
            $description = array_key_exists('meta_description', $_POST) ? trim((string)$_POST['meta_description']) : $page['meta_description'];
            if (strlen($group) > 100 || strlen($seoTitle) > 490 || strlen($description ?? '') > 2000) throw new RuntimeException('The page group or search settings are too long.');
            if ($html !== null) $html = cmsSeo($html, $seoTitle ?: $title, $description);
            $contentType = (string)($_POST['content_type'] ?? $page['content_type']);
            cmsSave($page, ['title' => $title, 'route' => $route, 'html' => $html, 'status' => $status, 'group_name' => $group, 'seo_title' => $seoTitle ?: null, 'meta_description' => $description, 'content_type' => $contentType], (int)($_POST['version'] ?? 0), (string)$action);
            adminNotice($action === 'reset_page' ? 'Original page restored.' : ($status === 'published' ? 'Page published. Your changes are visible on the website.' : 'Draft saved. Preview it before publishing.'), '/admin/?view=edit&id=' . $page['id'] . '&mode=' . urlencode((string)($_POST['mode'] ?? 'visual')));
        }
        if ($action === 'create_page') {
            $contentType = (string)($_POST['content_type'] ?? 'page');
            if (!array_key_exists($contentType, cmsContentTypes())) throw new RuntimeException('Choose a valid content section.');
            $route = cmsRoute((string)($_POST['route'] ?? '')); $title = trim((string)($_POST['title'] ?? ''));
            cmsRouteAvailable($route);
            if ($title === '' || strlen($title) > 490) throw new RuntimeException('Enter a page title under 490 characters.');
            $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . cmsEscape($title) . '</title><link rel="stylesheet" href="/admin-page.css"></head><body><header><a href="/">INDIBA</a></header><main><h1>' . cmsEscape($title) . '</h1><p>Add your English page content here.</p></main><script src="/mirror.js"></script></body></html>';
            $document = cmsDocument($html); $heading = $document->getElementsByTagName('h1')->item(0); $heading?->parentNode->removeChild($heading);
            cmsBannerCreate($document, $title, ''); $html = cmsDocumentHtml($document);
            $db->prepare('INSERT INTO indiba_cms_pages (route, title, source_file, html, status, content_type) VALUES (?, ?, ?, ?, ?, ?)')->execute([$route, $title, '', $html, 'draft', $contentType]);
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
            try { $db->prepare('INSERT INTO indiba_cms_media (path, name, mime, uploaded, user_id) VALUES (?, ?, ?, 1, ?)')->execute([$path, $name, $mime, $_SESSION['user_id']]); }
            catch (Throwable $e) { unlink(dirname(__DIR__) . $path); throw $e; }
            cmsAudit('upload', $path);
            if ($action === 'editor_upload') { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['location'=>$path,'name'=>$name]); exit; }
            adminNotice('File uploaded. Copy its URL to use it in a page.', '/admin/?view=media&filter=uploads');
        }
    } catch (PDOException $e) {
        $error = $e->getCode() === '23000' ? 'That page path already exists. Choose another path.' : 'The database could not save this change. Please try again.';
    } catch (RuntimeException $e) { $error = $e->getMessage(); }
    if ($action === 'editor_upload' && $error !== '') { http_response_code(422); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error'=>$error]); exit; }
    if (in_array($action, ['media_save', 'media_status'], true) && $error !== '') cmsMediaJson(['error'=>$error], http_response_code() >= 400 ? http_response_code() : 422);
}
$signedIn = !empty($_SESSION['user_id']);
if ($view === 'media_details') {
    if (!$signedIn) cmsMediaJson(['error'=>'Sign in to view attachment details.'], 401);
    try { cmsMediaJson(['file'=>cmsMediaDetails(cmsMedia((int)($_GET['id'] ?? 0)))]); }
    catch (RuntimeException $e) { cmsMediaJson(['error'=>$e->getMessage()], 404); }
}
if (!$signedIn) $view = 'login';
if ($signedIn && $view === 'preview') { require dirname(__DIR__, 2) . '/lib/admin/preview.php'; exit; }
if ($signedIn && $view === 'picker') { require dirname(__DIR__, 2) . '/lib/admin/picker.php'; exit; }
if ($signedIn && in_array($view, ['users', 'user_new', 'user_edit'], true) && !cmsCanManageUsers($currentUser)) {
    http_response_code(403); $error = 'Only administrators can manage users.'; $view = 'dashboard';
}
if (!in_array($view, ['login', 'dashboard', 'pages', 'master', 'news', 'events', 'edit', 'new', 'media', 'account', 'revisions', 'users', 'user_new', 'user_edit'], true)) $view = 'dashboard';
$contentSection = in_array($view, ['pages','master','news','events'], true) ? $view : 'pages';
if ($view === 'new') $contentSection = cmsContentSection((string)($_POST['content_type'] ?? $_GET['content_type'] ?? 'page'))['view'];
if ($signedIn && in_array($view, ['edit', 'revisions'], true)) {
    try { $contextPage = cmsPage((int)($_GET['id'] ?? 0)); $contentSection = cmsContentSection($contextPage['content_type'])['view']; }
    catch (RuntimeException $e) { /* The view renders its missing-content error. */ }
}
$contextType = ['pages'=>'page','master'=>'master','news'=>'news','events'=>'event'][$contentSection] ?? 'page';
$contextSection = cmsContentSection($contextType);
$labels = ['login' => 'Sign in', 'dashboard' => 'Overview', 'pages' => 'Pages', 'master' => 'Master pages', 'news' => 'News', 'events' => 'Events', 'edit' => 'Edit ' . $contextSection['singular'], 'new' => 'New ' . $contextSection['singular'], 'media' => 'Media library', 'account' => 'Your account', 'revisions' => 'Revision history', 'users' => 'Users', 'user_new' => 'Add user', 'user_edit' => 'Edit user'];
$navigation = ['dashboard'=>'Overview', 'pages'=>'Pages', 'master'=>'Master pages', 'news'=>'News', 'events'=>'Events', 'media'=>'Media library'];
if (cmsCanManageUsers($currentUser)) $navigation['users'] = 'Users';
$navigation['account'] = 'Your account';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= cmsEscape($labels[$view]) ?> · INDIBA management</title><link rel="preload" href="/admin/fonts/roboto-latin.woff2" as="font" type="font/woff2" crossorigin><link rel="stylesheet" href="/admin/fonts.css"><link rel="stylesheet" href="/admin/admin.css"><?php if ($view === 'edit'): ?><script defer src="/vendor/tinymce/tinymce.min.js"></script><?php endif ?><script defer src="/admin/admin.js"></script><?php if ($view === 'edit'): ?><script defer src="/admin/classic.js"></script><?php endif ?><?php if ($view === 'media'): ?><script defer src="/admin/media-library.js"></script><?php endif ?></head><body>
<?php if ($signedIn): ?><a class="skip-link" href="#main-content">Skip to content</a><aside class="sidebar"><a class="brand" href="/admin/">INDIBA<span>Management portal</span></a><p class="nav-label">Workspace</p><nav aria-label="Main navigation"><?php foreach ($navigation as $key => $label): $active = $view === $key || $key === $contentSection && in_array($view, ['edit','new','revisions']) || $key === 'users' && in_array($view, ['user_new','user_edit'], true); ?><a class="<?= $active ? 'active' : '' ?>" <?= $active ? 'aria-current="page"' : '' ?> href="/admin/?view=<?= $key ?>"><?= adminIcon($key) ?><span><?= $label ?></span></a><?php endforeach ?></nav><div class="sidebar-bottom"><a href="/" target="_blank" rel="noopener"><?= adminIcon('external') ?>Open website</a><form method="post"><?= csrfInput() ?><input type="hidden" name="action" value="logout"><button class="signout"><?= adminIcon('logout') ?>Sign out</button></form><p class="sidebar-caption">INDIBA · Website administration</p></div></aside><?php endif ?>
<main id="main-content" tabindex="-1" class="<?= $signedIn ? 'workspace' : 'login-wrap' ?>">
<?php if ($signedIn): ?><header class="page-heading"><div><p class="eyebrow">English website</p><h1><?= $labels[$view] ?></h1></div><span class="user-badge"><span class="user-avatar"><?= adminIcon('account') ?></span><span class="user-details"><strong><?= cmsEscape($_SESSION['username']) ?></strong><small><?= cmsEscape(cmsUserRoles()[$currentUser['role']] ?? $currentUser['role']) ?></small></span></span></header><?php endif ?>
<?php if ($error): ?><div class="alert error" role="alert"><?= cmsEscape($error) ?></div><?php endif ?><?php if ($notice): ?><div class="alert success" role="status"><?= cmsEscape($notice) ?></div><?php endif ?>
<?php if ($view === 'login'): ?><div class="login-brand">INDIBA<span>Management portal</span></div><section class="login-card"><p class="eyebrow">Website administration</p><h1>Welcome back</h1><p class="muted">Sign in to manage your English website.</p><form method="post"><?= csrfInput() ?><input type="hidden" name="action" value="login"><label>Username<input name="username" required autocomplete="username" maxlength="100" placeholder="Enter your username" value="<?= cmsEscape(is_string($_POST['username'] ?? null) ? substr($_POST['username'], 0, 100) : '') ?>"></label><label>Password<input type="password" name="password" required autocomplete="current-password" maxlength="72" placeholder="Enter your password"></label><button>Sign in</button></form><a href="/" class="back-link">← Back to website</a></section>
<?php elseif ($view === 'dashboard'):
    $stats = $db->query("SELECT SUM(content_type = 'page') AS total, SUM(content_type = 'master') AS master, SUM(content_type = 'event') AS events, SUM(status = 'published') AS published, SUM(content_type = 'news') AS news FROM indiba_cms_pages WHERE status <> 'trashed'")->fetch();
    $mediaCount = $db->query("SELECT COUNT(*) FROM indiba_cms_media WHERE trashed_at IS NULL AND (mime LIKE 'image/%' OR mime LIKE 'video/%' OR mime LIKE 'audio/%' OR mime = 'application/pdf')")->fetchColumn();
    $activity = $db->query('SELECT a.*, u.username FROM indiba_cms_audit a LEFT JOIN indiba_cms_users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 12')->fetchAll(); ?>
<section class="welcome"><div><p class="eyebrow">Content workspace</p><h2>Your website, in one place</h2><p>Edit page content, publish updates, and manage your images and files.</p></div><a class="button light" href="/admin/?view=pages">Manage pages →</a></section>
<div class="stats"><?php $statIcons = ['Total pages'=>'pages', 'Master pages'=>'master', 'News items'=>'news', 'Events'=>'events', 'Published'=>'published', 'Media files'=>'media']; foreach (['Total pages' => $stats['total'], 'Master pages' => $stats['master'], 'News items' => $stats['news'], 'Events' => $stats['events'], 'Published' => $stats['published'], 'Media files' => $mediaCount] as $label => $number): ?><div class="stat"><div class="stat-body"><span><?= $label ?></span><strong><?= number_format((int)$number) ?></strong></div><span class="stat-icon <?= $statIcons[$label] ?>"><?= adminIcon($statIcons[$label]) ?></span></div><?php endforeach ?></div>
<section class="panel"><div class="panel-heading"><h2>Recent activity</h2><a href="/admin/?view=new">Create a page</a></div><?php if (!$activity): ?><p class="muted">Your changes will appear here.</p><?php endif ?><?php foreach ($activity as $entry): ?><div class="activity"><span><strong><?= cmsEscape(str_replace('_',' ', $entry['action'])) ?></strong><small><?= cmsEscape($entry['subject']) ?></small></span><span class="muted"><?= cmsEscape($entry['username'] ?? 'System') ?> · <?= cmsEscape($entry['created_at']) ?></span></div><?php endforeach ?></section>
<?php elseif (in_array($view, ['pages', 'master', 'news', 'events', 'edit', 'new', 'revisions'], true)): require dirname(__DIR__, 2) . '/lib/admin/' . (in_array($view, ['master','news','events'], true) ? 'pages' : $view) . '.php'; ?>
<?php elseif ($view === 'media'): require dirname(__DIR__, 2) . '/lib/admin/media.php'; ?>
<?php elseif ($view === 'users'): require dirname(__DIR__, 2) . '/lib/admin/users.php'; ?>
<?php elseif (in_array($view, ['user_new', 'user_edit'], true)): require dirname(__DIR__, 2) . '/lib/admin/user-edit.php'; ?>
<?php elseif ($view === 'account'): ?><section class="panel narrow"><h2>Change your password</h2><p class="muted">Signed in as <?= cmsEscape($_SESSION['username']) ?>.</p><form method="post"><?= csrfInput() ?><input type="hidden" name="action" value="password"><label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label><label>New password<input type="password" name="new_password" required minlength="12" maxlength="72" autocomplete="new-password"></label><label>Confirm new password<input type="password" name="confirm_password" required minlength="12" maxlength="72" autocomplete="new-password"></label><button>Change password</button></form></section><?php endif ?>
</main></body></html>
