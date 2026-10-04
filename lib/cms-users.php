<?php
declare(strict_types=1);
require_once __DIR__ . '/cms.php';

function cmsUserRoles(): array { return ['admin' => 'Administrator', 'editor' => 'Editor']; }
function cmsCanManageUsers(?array $user): bool { return $user && $user['role'] === 'admin' && $user['status'] === 'active'; }
function cmsUser(int $id): array {
    $query = database()->prepare('SELECT id, username, display_name, email, role, status, version, session_version, created_at, last_login_at FROM indiba_cms_users WHERE id = ?');
    $query->execute([$id]);
    $user = $query->fetch();
    if (!$user) throw new RuntimeException('User not found.');
    return $user;
}

function cmsUserInput(string $key): string {
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) throw new RuntimeException('Please use the user form to submit this change.');
    return $value;
}
function cmsUserPassword(): string {
    $password = cmsUserInput('new_password');
    if (str_contains($password, "\0")) throw new RuntimeException('The password contains an unsupported character.');
    if (strlen($password) < 12 || strlen($password) > 72) throw new RuntimeException('Choose a password between 12 and 72 bytes.');
    if ($password !== cmsUserInput('confirm_password')) throw new RuntimeException('The new passwords do not match.');
    return password_hash($password, PASSWORD_DEFAULT);
}
function cmsUserFields(): array {
    $username = trim(cmsUserInput('username'));
    $displayName = trim(cmsUserInput('display_name'));
    $email = trim(cmsUserInput('email'));
    $role = cmsUserInput('role');
    $status = cmsUserInput('status');
    if (!preg_match('/\A[A-Za-z0-9._@-]{3,100}\z/', $username)) throw new RuntimeException('Use 3 to 100 letters, numbers, dots, underscores, @ signs, or hyphens for the username.');
    if (!preg_match('/\A.{0,100}\z/us', $displayName)) throw new RuntimeException('Use a display name of 100 characters or fewer.');
    if ($email !== '' && (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL))) throw new RuntimeException('Enter a valid email address or leave it blank.');
    if (!isset(cmsUserRoles()[$role]) || !in_array($status, ['active', 'inactive'], true)) throw new RuntimeException('Choose a valid role and account status.');
    return [$username, $displayName, $email ?: null, $role, $status];
}

// Lock accounts in a consistent order so simultaneous changes cannot remove the last administrator.
function cmsUserLock(bool $requireAdmin = true): array {
    $users = database()->query('SELECT * FROM indiba_cms_users ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_UNIQUE);
    $actor = $users[(int)($_SESSION['user_id'] ?? 0)] ?? null;
    if (!$actor || $actor['status'] !== 'active' || (int)$actor['session_version'] !== (int)($_SESSION['session_version'] ?? 0)) {
        http_response_code(401); throw new RuntimeException('Your account access changed. Sign in again.');
    }
    if ($requireAdmin && !cmsCanManageUsers($actor)) { http_response_code(403); throw new RuntimeException('Only administrators can manage users.'); }
    return $users;
}
function cmsUserGuard(array $users, int $id, string $role, string $status): void {
    if ($role === 'admin' && $status === 'active') return;
    $otherAdmins = array_filter($users, fn($user, $key) => (int)$key !== $id && $user['role'] === 'admin' && $user['status'] === 'active', ARRAY_FILTER_USE_BOTH);
    if (!$otherAdmins) throw new RuntimeException('Keep at least one active administrator.');
    if ($id === (int)$_SESSION['user_id']) throw new RuntimeException('You cannot deactivate your own account or remove your administrator role.');
}
function cmsUserAction(string $action, ?array $currentUser): void {
    if (!in_array($action, ['create_user', 'save_user', 'user_status', 'reset_user_password'], true)) return;
    if (!cmsCanManageUsers($currentUser)) { http_response_code(403); throw new RuntimeException('Only administrators can manage users.'); }
    $db = database(); $db->beginTransaction();
    try {
        $users = cmsUserLock();
        if ($action === 'create_user') {
            $fields = cmsUserFields(); $hash = cmsUserPassword();
            $db->prepare('INSERT INTO indiba_cms_users (username, display_name, email, role, status, password_hash) VALUES (?, ?, ?, ?, ?, ?)')->execute([...$fields, $hash]);
            $id = (int)$db->lastInsertId(); $username = $fields[0];
            $message = 'User created. Share their sign-in details privately.';
        } else {
            $id = (int)cmsUserInput('id'); $user = $users[$id] ?? null;
            if (!$user) throw new RuntimeException('User not found.');
            if ((int)$user['version'] !== (int)cmsUserInput('version')) throw new RuntimeException('Someone else changed this user. Reload before saving.');
            $username = $user['username'];
            if ($action === 'save_user') {
                $fields = cmsUserFields(); cmsUserGuard($users, $id, $fields[3], $fields[4]);
                $db->prepare('UPDATE indiba_cms_users SET username = ?, display_name = ?, email = ?, role = ?, status = ?, version = version + 1, session_version = session_version + 1 WHERE id = ?')->execute([...$fields, $id]);
                $username = $fields[0]; $message = 'User updated.';
            } elseif ($action === 'user_status') {
                $status = cmsUserInput('target_status');
                if (!in_array($status, ['active', 'inactive'], true)) throw new RuntimeException('Choose a valid account status.');
                cmsUserGuard($users, $id, $user['role'], $status);
                $db->prepare('UPDATE indiba_cms_users SET status = ?, version = version + 1, session_version = session_version + 1 WHERE id = ?')->execute([$status, $id]);
                $message = $status === 'active' ? 'User reactivated.' : 'User deactivated. Their portal access has been revoked.';
            } else {
                if ($id === (int)$_SESSION['user_id']) throw new RuntimeException('Use Your account to change your own password.');
                $hash = cmsUserPassword();
                $db->prepare('UPDATE indiba_cms_users SET password_hash = ?, version = version + 1, session_version = session_version + 1 WHERE id = ?')->execute([$hash, $id]);
                $message = 'Password reset. Existing sessions for this user have been signed out.';
            }
        }
        cmsAudit($action, 'User #' . $id . ': ' . $username); $db->commit();
        if ($id === (int)$_SESSION['user_id']) {
            $_SESSION['username'] = $username; $_SESSION['session_version'] = (int)$user['session_version'] + 1;
            session_regenerate_id(true);
        }
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e->getCode() === '23000') throw new RuntimeException('That username is already in use. Choose another username.');
        throw $e;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    adminNotice($message, $action === 'user_status' ? '/admin/?view=users' : '/admin/?view=user_edit&id=' . $id);
}

function cmsUserChangePassword(): void {
    $hash = cmsUserPassword();
    $db = database(); $db->beginTransaction();
    try {
        $users = cmsUserLock(false); $user = $users[(int)$_SESSION['user_id']];
        if (!password_verify(cmsUserInput('current_password'), $user['password_hash'])) throw new RuntimeException('The current password is incorrect.');
        $db->prepare('UPDATE indiba_cms_users SET password_hash = ?, version = version + 1, session_version = session_version + 1 WHERE id = ?')->execute([$hash, $_SESSION['user_id']]);
        cmsAudit('password_changed', $_SESSION['username']); $db->commit();
        $_SESSION['session_version'] = (int)$user['session_version'] + 1; session_regenerate_id(true);
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
