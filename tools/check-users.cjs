const { chromium } = require('../.tools/browser/node_modules/playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
  const base = process.env.CMS_TEST_URL || 'http://127.0.0.1:9002';
  const target = new URL(base);
  assert.ok(['localhost', '127.0.0.1'].includes(target.hostname) && target.port === '9002', 'User mutations require the isolated test portal on port 9002.');
  const adminPassword = fs.readFileSync('storage/cms-test-bootstrap.txt', 'utf8').match(/^Password: (.+)$/m)[1];
  const password = 'User-check-' + Date.now() + '!';
  const nextPassword = password + 'New!';
  const prefix = 'users-' + Date.now();
  const browser = await chromium.launch({ executablePath: process.env.MIRROR_CHROME || 'C:/Users/sebastian/AppData/Local/ms-playwright/chromium-1228/chrome-win64/chrome.exe', headless: true });
  const errors = [];
  const contexts = [];
  const newPage = async () => {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } }); contexts.push(context);
    const page = await context.newPage(); page.on('pageerror', error => errors.push(error.message)); page.on('dialog', dialog => dialog.accept());
    return page;
  };
  const login = async (page, username, pass) => {
    await page.goto(base + '/admin/');
    await page.locator('[name=username]').fill(username); await page.locator('[name=password]').fill(pass);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click(); await page.waitForLoadState();
  };
  const csrf = page => page.locator('[name=csrf]').first().inputValue();
  const post = async (page, form, url = '/admin/?view=users') => page.context().request.post(base + url, { form: { csrf: await csrf(page), ...form } });
  const editFields = async (page, id) => {
    await page.goto(base + '/admin/?view=user_edit&id=' + id);
    const form = page.locator('#user-details-form');
    const data = { id: String(id) };
    for (const key of ['version', 'username', 'display_name', 'email', 'role', 'status']) data[key] = await form.locator(`[name=${key}]`).inputValue();
    return data;
  };
  let admin;
  const created = [];
  try {
    admin = await newPage(); await login(admin, 'admin', adminPassword);
    assert.equal(await admin.locator('.error').count(), 0);
    await admin.getByRole('link', { name: 'Users', exact: true }).click();
    const rootId = await admin.locator('.users-table tr').filter({ hasText: 'admin (you)' }).getAttribute('data-user-id');
    const create = async (username, role = 'editor', status = 'active') => {
      await admin.goto(base + '/admin/?view=user_new');
      const form = admin.locator('#user-details-form');
      await form.locator('[name=username]').fill(username); await form.locator('[name=display_name]').fill('User management check');
      await form.locator('[name=email]').fill('users@example.com'); await form.locator('[name=role]').selectOption(role); await form.locator('[name=status]').selectOption(status);
      await form.locator('[name=new_password]').fill(password); await form.locator('[name=confirm_password]').fill(password);
      await admin.getByRole('button', { name: 'Create user', exact: true }).click(); await admin.waitForURL(/view=user_edit/);
      assert.equal(await admin.locator('.error').count(), 0);
      const id = new URL(admin.url()).searchParams.get('id'); created.push(id); return id;
    };
    const editorId = await create(prefix + '-editor');
    const inactiveId = await create(prefix + '-inactive', 'editor', 'inactive');
    console.log('PASS Create editor and inactive accounts through portal forms');

    const fields = await editFields(admin, editorId);
    const saved = await post(admin, { action: 'save_user', ...fields, display_name: 'Updated name', email: 'changed@example.com' });
    assert.match(await saved.text(), /User updated/);
    const stale = await post(admin, { action: 'save_user', ...fields, display_name: 'Stale value' }); assert.match(await stale.text(), /Someone else changed this user/);
    await editFields(admin, editorId); assert.equal(await admin.locator('[name=display_name]').inputValue(), 'Updated name');
    for (const data of [
      { username: prefix + '-bad', new_password: 'short', confirm_password: 'short' },
      { username: prefix + '-bad', new_password: password, confirm_password: password + 'mismatch' },
      { username: prefix + '-bad', new_password: '密'.repeat(25), confirm_password: '密'.repeat(25) },
      { username: prefix + '-bad', email: 'bad-email' },
      { username: prefix + '-bad', role: 'superadmin' },
      { username: prefix + '-bad', status: 'unknown' },
      { username: 'invalid name' },
      { username: (prefix + '-editor').toUpperCase() },
    ]) {
      const invalid = await post(admin, { action: 'create_user', display_name: '', email: '', role: 'editor', status: 'active', new_password: password, confirm_password: password, ...data }, '/admin/?view=user_new');
      assert.match(await invalid.text(), /class="alert error"/);
      assert.ok(!(await invalid.text()).includes(`value="${password}"`), 'Passwords must never be reflected into HTML.');
    }
    const forged = await post(admin, { action: 'user_status', id: editorId, version: '2', target_status: 'inactive', csrf: 'forged' }); assert.equal(forged.status(), 403);
    await admin.goto(base + '/admin/?view=users&q=' + prefix);
    assert.equal(await admin.locator('.users-table tbody tr').count(), 2);
    await admin.getByRole('link', { name: /^Inactive/ }).click(); assert.equal(await admin.locator('.users-table tbody tr').count(), 1);
    console.log('PASS Validation, duplicate usernames, search/status filters, CSRF, and stale-edit protection');

    const editor = await newPage(); await login(editor, prefix + '-editor', password);
    assert.equal(await editor.getByRole('link', { name: 'Users', exact: true }).count(), 0);
    assert.equal(await editor.locator('.user-details small').innerText(), 'Editor');
    for (const view of ['users', 'user_new', 'user_edit&id=' + rootId]) {
      const denied = await editor.context().request.get(base + '/admin/?view=' + view); assert.equal(denied.status(), 403); assert.match(await denied.text(), /Only administrators/);
    }
    for (const action of ['create_user', 'save_user', 'user_status', 'reset_user_password']) {
      const denied = await post(editor, { action, id: rootId, version: '1', target_status: 'inactive' }); assert.equal(denied.status(), 403);
    }
    for (const view of ['pages', 'news', 'events', 'media', 'new', 'account']) assert.equal((await editor.context().request.get(base + '/admin/?view=' + view)).status(), 200);
    const anonymous = await newPage(); await anonymous.goto(base + '/admin/');
    assert.equal((await post(anonymous, { action: 'create_user' })).status(), 401);
    console.log('PASS Server-side administrator permissions and editor content access');

    const secondEditorSession = await newPage(); await login(secondEditorSession, prefix + '-editor', password);
    const invalidCurrent = await post(editor, { action: 'password', current_password: 'wrong', new_password: nextPassword, confirm_password: nextPassword }, '/admin/?view=account');
    assert.match(await invalidCurrent.text(), /current password is incorrect/);
    const changed = await post(editor, { action: 'password', current_password: password, new_password: nextPassword, confirm_password: nextPassword }, '/admin/?view=account');
    assert.match(await changed.text(), /Your password has been changed/);
    await editor.goto(base + '/admin/'); assert.equal(await editor.locator('.stats').count(), 1);
    await secondEditorSession.goto(base + '/admin/'); assert.equal(await secondEditorSession.getByRole('button', { name: 'Sign in', exact: true }).count(), 1);
    const resetFields = await editFields(admin, editorId);
    const reset = await post(admin, { action: 'reset_user_password', id: editorId, version: resetFields.version, new_password: password, confirm_password: password }, '/admin/?view=user_edit&id=' + editorId);
    assert.match(await reset.text(), /Password reset/);
    await editor.goto(base + '/admin/?view=media_details&id=1'); assert.match(await editor.locator('body').innerText(), /Sign in/);
    await login(editor, prefix + '-editor', nextPassword); assert.match(await editor.locator('.error').innerText(), /incorrect/);
    await login(editor, prefix + '-editor', password); assert.equal(await editor.locator('.stats').count(), 1);
    console.log('PASS Own password change keeps current session; changes and resets revoke other sessions');

    const current = await editFields(admin, editorId);
    const deactivated = await post(admin, { action: 'user_status', id: editorId, version: current.version, target_status: 'inactive' }); assert.match(await deactivated.text(), /User deactivated/);
    await editor.goto(base + '/admin/'); assert.equal(await editor.getByRole('button', { name: 'Sign in', exact: true }).count(), 1);
    await login(editor, prefix + '-editor', password); assert.match(await editor.locator('.error').innerText(), /incorrect/);
    const disabledFields = await editFields(admin, editorId);
    await post(admin, { action: 'user_status', id: editorId, version: disabledFields.version, target_status: 'active' });
    await login(editor, prefix + '-editor', password); assert.equal(await editor.locator('.stats').count(), 1);
    const promoteFields = await editFields(admin, editorId);
    await post(admin, { action: 'save_user', ...promoteFields, role: 'admin' });
    await editor.goto(base + '/admin/'); assert.equal(await editor.getByRole('button', { name: 'Sign in', exact: true }).count(), 1);
    await login(editor, prefix + '-editor', password); assert.equal(await editor.getByRole('link', { name: 'Users', exact: true }).count(), 1);
    const demoteFields = await editFields(admin, editorId); await post(admin, { action: 'save_user', ...demoteFields, role: 'editor' });
    await editor.goto(base + '/admin/'); assert.equal(await editor.getByRole('button', { name: 'Sign in', exact: true }).count(), 1);
    await login(editor, prefix + '-editor', password); assert.equal(await editor.getByRole('link', { name: 'Users', exact: true }).count(), 0);
    console.log('PASS Deactivation, reactivation, and role changes revoke existing access');

    const rootFields = await editFields(admin, rootId);
    for (const data of [{ action: 'user_status', id: rootId, version: rootFields.version, target_status: 'inactive' }, { action: 'save_user', ...rootFields, role: 'editor' }]) {
      const denied = await post(admin, data); assert.match(await denied.text(), /Keep at least one active administrator/);
    }
    const ownReset = await post(admin, { action: 'reset_user_password', id: rootId, version: rootFields.version, new_password: password, confirm_password: password }); assert.match(await ownReset.text(), /Use Your account/);
    const secondAdminId = await create(prefix + '-admin', 'admin');
    const selfDenied = await post(admin, { action: 'user_status', id: rootId, version: rootFields.version, target_status: 'inactive' }); assert.match(await selfDenied.text(), /cannot deactivate your own/);
    const rename = await post(admin, { action: 'save_user', ...rootFields, display_name: 'Portal administrator' }); assert.match(await rename.text(), /User updated/);
    await admin.goto(base + '/admin/'); assert.equal(await admin.locator('.stats').count(), 1);
    console.log('PASS Last-administrator/self-access protection and current session preservation');

    const secondAdmin = await newPage(); await login(secondAdmin, prefix + '-admin', password);
    const secondFields = await editFields(admin, secondAdminId);
    const latestRootFields = await editFields(secondAdmin, rootId);
    const competing = await Promise.all([
      post(admin, { action: 'user_status', id: secondAdminId, version: secondFields.version, target_status: 'inactive' }),
      post(secondAdmin, { action: 'user_status', id: rootId, version: latestRootFields.version, target_status: 'inactive' }),
    ]);
    const rootWon = /User deactivated/.test(await competing[0].text());
    assert.notEqual(rootWon, /User deactivated/.test(await competing[1].text()), 'Exactly one administrator may revoke the other.');
    const survivor = rootWon ? admin : secondAdmin;
    const rootAfterRace = await editFields(survivor, rootId);
    const secondAfterRace = await editFields(survivor, secondAdminId);
    assert.equal([rootAfterRace, secondAfterRace].filter(item => item.status === 'active').length, 1);
    const loser = rootWon ? secondAfterRace : rootAfterRace;
    const restored = await post(survivor, { action: 'user_status', id: loser.id, version: loser.version, target_status: 'active' }); assert.match(await restored.text(), /User reactivated/);
    if (!rootWon) await login(admin, 'admin', adminPassword);
    console.log('PASS Simultaneous administrator deactivations preserve one active administrator');

    await admin.goto(base + '/admin/?view=users&q=' + prefix);
    await admin.screenshot({ path: 'storage/users-desktop.png', fullPage: true });
    await admin.setViewportSize({ width: 390, height: 844 });
    assert.ok(await admin.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await admin.screenshot({ path: 'storage/users-mobile.png', fullPage: true });
    await editFields(admin, secondAdminId);
    assert.ok(await admin.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await admin.screenshot({ path: 'storage/user-edit-mobile.png', fullPage: true });
    assert.deepEqual(errors, []);
    console.log('PASS Responsive user list/forms and no browser errors');
    await admin.goto(base + '/admin/'); assert.match(await admin.locator('.activity').allTextContents().then(items => items.join('\n')), /create user|save user|user status/);
    console.log('All user management checks passed.');
  } finally {
    if (admin) {
      for (const id of created) {
        try {
          const fields = await editFields(admin, id);
          if (fields.status === 'active') await post(admin, { action: 'user_status', id, version: fields.version, target_status: 'inactive' });
        } catch (error) { console.error('Test account cleanup failed:', error.message); }
      }
    }
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
