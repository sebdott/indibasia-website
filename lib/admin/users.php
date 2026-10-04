<?php
declare(strict_types=1);
if (!cmsCanManageUsers($currentUser)) { http_response_code(403); return; }
$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$status = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : 'all';
$role = is_string($_GET['role'] ?? null) && isset(cmsUserRoles()[$_GET['role']]) ? $_GET['role'] : '';
$conditions = []; $parameters = [];
if ($search !== '') { $conditions[] = '(username LIKE ? OR display_name LIKE ? OR email LIKE ?)'; array_push($parameters, ...array_fill(0, 3, '%' . $search . '%')); }
if ($status !== 'all') { $conditions[] = 'status = ?'; $parameters[] = $status; }
if ($role !== '') { $conditions[] = 'role = ?'; $parameters[] = $role; }
$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$query = $db->prepare('SELECT COUNT(*) FROM indiba_cms_users' . $where); $query->execute($parameters); $total = (int)$query->fetchColumn();
$number = min(max(1, (int)($_GET['page'] ?? 1)), max(1, (int)ceil($total / 30))); $offset = ($number - 1) * 30;
$query = $db->prepare('SELECT id, username, display_name, email, role, status, version, created_at, last_login_at FROM indiba_cms_users' . $where . " ORDER BY username, id LIMIT 30 OFFSET $offset"); $query->execute($parameters); $users = $query->fetchAll();
$counts = ['all'=>0, 'active'=>0, 'inactive'=>0];
foreach ($db->query('SELECT status, COUNT(*) AS total FROM indiba_cms_users GROUP BY status') as $row) { $counts[$row['status']] = (int)$row['total']; $counts['all'] += (int)$row['total']; }
$userLink = fn(array $changes = []) => '/admin/?' . http_build_query(array_replace(['view'=>'users', 'q'=>$search, 'status'=>$status, 'role'=>$role], $changes));
?>
<div class="cms-page-intro"><p class="muted">Manage who can access your website portal.</p><a class="button" href="/admin/?view=user_new">+ Add user</a></div>
<nav class="status-tabs" aria-label="Filter by account status"><?php foreach (['all'=>'All users', 'active'=>'Active', 'inactive'=>'Inactive'] as $key=>$label): ?><a href="<?= cmsEscape($userLink(['status'=>$key])) ?>" class="<?= $status === $key ? 'selected' : '' ?>"><?= $label ?> <span><?= number_format($counts[$key]) ?></span></a><?php endforeach ?></nav>
<form method="get" class="toolbar user-filters"><input type="hidden" name="view" value="users"><input type="hidden" name="status" value="<?= $status ?>"><input name="q" value="<?= cmsEscape($search) ?>" placeholder="Search by username, name, or email" aria-label="Search users"><select name="role" aria-label="User role"><option value="">All roles</option><?php foreach (cmsUserRoles() as $key=>$label): ?><option value="<?= $key ?>" <?= $role === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select><button>Search</button></form>
<section class="panel table-wrap users-table"><table><thead><tr><th scope="col">User</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Last sign-in</th><th scope="col">Created</th></tr></thead><tbody>
<?php foreach ($users as $item): $self = (int)$item['id'] === (int)$currentUser['id']; ?>
<tr data-user-id="<?= $item['id'] ?>"><td><a class="page-title" href="/admin/?view=user_edit&amp;id=<?= $item['id'] ?>"><?= cmsEscape($item['display_name'] ?: $item['username']) ?></a><small><?= cmsEscape($item['username']) ?><?= $self ? ' (you)' : '' ?></small><?php if ($item['email']): ?><small><?= cmsEscape($item['email']) ?></small><?php endif ?><div class="row-actions"><a href="/admin/?view=user_edit&amp;id=<?= $item['id'] ?>">Edit<?= $self ? '' : ' / reset password' ?></a><?php if (!$self): ?><form method="post" <?= $item['status'] === 'active' ? 'data-confirm="Deactivate this user? They will lose access to the portal immediately."' : '' ?>><?= csrfInput() ?><input type="hidden" name="action" value="user_status"><input type="hidden" name="id" value="<?= $item['id'] ?>"><input type="hidden" name="version" value="<?= $item['version'] ?>"><input type="hidden" name="target_status" value="<?= $item['status'] === 'active' ? 'inactive' : 'active' ?>"><button class="text-button <?= $item['status'] === 'active' ? 'danger-text' : '' ?>"><?= $item['status'] === 'active' ? 'Deactivate' : 'Reactivate' ?></button></form><?php endif ?></div></td><td><?= cmsEscape(cmsUserRoles()[$item['role']] ?? $item['role']) ?></td><td><span class="badge <?= $item['status'] === 'active' ? 'published' : 'trashed' ?>"><?= cmsEscape($item['status']) ?></span></td><td class="muted date-cell"><?= cmsEscape($item['last_login_at'] ?? 'Never') ?></td><td class="muted date-cell"><?= cmsEscape($item['created_at']) ?></td></tr>
<?php endforeach ?></tbody></table><?php if (!$users): ?><div class="empty"><h2>No users found</h2><p>Try changing the search or filters.</p></div><?php endif ?></section>
<div class="pagination"><span><?= number_format($total) ?> users · Page <?= $number ?> of <?= max(1, (int)ceil($total / 30)) ?></span><div><?php if ($number > 1): ?><a href="<?= cmsEscape($userLink(['page'=>$number - 1])) ?>">Previous</a><?php endif ?><?php if ($offset + 30 < $total): ?><a href="<?= cmsEscape($userLink(['page'=>$number + 1])) ?>">Next</a><?php endif ?></div></div>
