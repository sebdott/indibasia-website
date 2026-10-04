<?php
declare(strict_types=1);
if (!cmsCanManageUsers($currentUser)) { http_response_code(403); return; }
$creating = $view === 'user_new';
try { $editingUser = $creating ? ['username'=>'', 'display_name'=>'', 'email'=>'', 'role'=>'editor', 'status'=>'active'] : cmsUser((int)($_GET['id'] ?? 0)); }
catch (RuntimeException $e) { http_response_code(404); echo '<div class="alert error" role="alert">' . cmsEscape($e->getMessage()) . '</div>'; return; }
$self = !$creating && (int)$editingUser['id'] === (int)$currentUser['id'];
$formValues = $editingUser;
if ($error !== '' && $validCsrf && ($creating && $action === 'create_user' || !$creating && $action === 'save_user' && (int)($_POST['id'] ?? 0) === (int)$editingUser['id'])) {
    foreach (['username','display_name','email','role','status','version'] as $field) if (is_string($_POST[$field] ?? null)) $formValues[$field] = $_POST[$field];
}
?>
<p><a href="/admin/?view=users">← Back to users</a></p>
<section class="panel narrow"><h2><?= $creating ? 'Create portal user' : 'Account details' ?></h2><p class="muted">Administrators manage content and users. Editors manage pages, news, events, and media.</p>
<form method="post" id="user-details-form"><?= csrfInput() ?><input type="hidden" name="action" value="<?= $creating ? 'create_user' : 'save_user' ?>"><?php if (!$creating): ?><input type="hidden" name="id" value="<?= $editingUser['id'] ?>"><input type="hidden" name="version" value="<?= cmsEscape($formValues['version']) ?>"><?php endif ?>
<label>Username<input name="username" value="<?= cmsEscape($formValues['username']) ?>" required minlength="3" maxlength="100" pattern="[A-Za-z0-9._@\-]{3,100}" autocomplete="off" aria-describedby="username-help"></label><p class="muted field-help" id="username-help">3–100 letters, numbers, dots, underscores, @ signs, or hyphens. Used to sign in.</p>
<label>Display name<input name="display_name" value="<?= cmsEscape($formValues['display_name']) ?>" maxlength="100" autocomplete="off"></label>
<label>Email (optional)<input type="email" name="email" value="<?= cmsEscape($formValues['email']) ?>" maxlength="254" autocomplete="off"></label>
<?php if ($self): ?><input type="hidden" name="role" value="admin"><input type="hidden" name="status" value="active"><p class="muted">Your account is an active Administrator. Another administrator can change your access.</p><?php else: ?>
<div class="user-form-grid"><label>Role<select name="role"><?php foreach (cmsUserRoles() as $key=>$label): ?><option value="<?= $key ?>" <?= $formValues['role'] === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label><label>Account status<select name="status"><option value="active" <?= $formValues['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $formValues['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></label></div>
<?php endif ?>
<?php if ($creating): ?><label>Password<input type="password" name="new_password" required minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="password-help"></label><label>Confirm password<input type="password" name="confirm_password" required minlength="12" maxlength="72" autocomplete="new-password"></label><p class="muted field-help" id="password-help">Use 12–72 bytes; each English letter or symbol uses one byte. Share the password privately. The user can change it in Your account.</p><?php endif ?>
<div class="user-form-actions"><button><?= $creating ? 'Create user' : 'Save user' ?></button><a class="button secondary" href="/admin/?view=users">Cancel</a></div>
</form></section>
<?php if (!$creating && !$self): ?><section class="panel narrow"><h2>Reset password</h2><p class="muted">Set a new password for <?= cmsEscape($editingUser['username']) ?>. This signs them out of every existing session.</p><form method="post" data-confirm="Reset this password and sign the user out of all sessions?" id="user-password-form"><?= csrfInput() ?><input type="hidden" name="action" value="reset_user_password"><input type="hidden" name="id" value="<?= $editingUser['id'] ?>"><input type="hidden" name="version" value="<?= $editingUser['version'] ?>"><label>New password<input type="password" name="new_password" required minlength="12" maxlength="72" autocomplete="new-password"></label><label>Confirm new password<input type="password" name="confirm_password" required minlength="12" maxlength="72" autocomplete="new-password"></label><p class="muted">Use 12–72 bytes and share the new password privately.</p><button>Reset password</button></form></section><?php elseif ($self): ?><p><a href="/admin/?view=account">Change your password in Your account</a></p><?php endif ?>
