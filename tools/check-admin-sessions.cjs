const { chromium } = require('../.tools/browser/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
  const base = process.env.CMS_TEST_URL || 'http://127.0.0.1:9000';
  const secondary = process.env.CMS_SESSION_SECONDARY_URL;
  for (const url of [base, secondary].filter(Boolean)) {
    assert.ok(['127.0.0.1', 'localhost'].includes(new URL(url).hostname), 'Use local portals only.');
  }
  const recreate = process.argv.includes('--recreate');
  if (recreate) assert.equal(base, 'http://127.0.0.1:9000', 'Recreation checks target the local Compose stack.');
  const credentials = fs.readFileSync(process.env.CMS_TEST_BOOTSTRAP_FILE || 'storage/local-admin-bootstrap.txt', 'utf8');
  const password = credentials.match(/^Password: (.+)$/m)[1];
  const browser = await chromium.launch({ executablePath: process.env.MIRROR_CHROME || 'C:/Users/sebastian/AppData/Local/ms-playwright/chromium-1228/chrome-win64/chrome.exe', headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const pass = message => console.log('PASS ' + message);
  const rebuild = () => execFileSync('docker', ['compose', 'up', '-d', '--force-recreate', '--no-deps', '--wait', '--wait-timeout', '90', 'web'], { stdio: 'pipe' });
  const signIn = async () => {
    await page.locator('[name=username]').fill('admin');
    await page.locator('[name=password]').fill(password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.waitForLoadState();
  };
  try {
    await page.goto(base + '/admin/');
    const forged = await context.request.post(base + '/admin/', { form: { action: 'login', username: 'admin', password } });
    assert.equal(forged.status(), 403);
    const forgedBody = await forged.text();
    assert.match(forgedBody, /This form has expired/);
    assert.match(forgedBody, /name="csrf" value="[a-f0-9]{64}"/);
    assert.ok(!forgedBody.includes(password));
    pass('Missing CSRF token is rejected with a refreshed form and no password echo');

    const malformed = await context.request.post(base + '/admin/', { form: { action: 'login', 'csrf[]': 'invalid' } });
    assert.equal(malformed.status(), 403);
    pass('Malformed CSRF tokens are rejected');
    const upload = await context.request.post(base + '/admin/', { form: { action: 'editor_upload', csrf: 'stale' } });
    assert.equal(upload.status(), 403);
    assert.match((await upload.json()).error, /expired/);
    pass('Expired editor uploads return JSON');

    // Keep the old form, but discard its cookie as if its session were lost.
    await context.clearCookies();
    const expiredResponse = page.waitForResponse(response => response.request().method() === 'POST');
    await signIn();
    assert.equal((await expiredResponse).status(), 403);
    assert.match(await page.locator('.error').innerText(), /form has expired/);
    assert.equal(await page.locator('[name=username]').inputValue(), 'admin');
    assert.equal(await page.locator('[name=password]').inputValue(), '');
    pass('A lost session presents a retryable sign-in form');

    if (recreate) rebuild();
    await signIn();
    assert.equal(await page.locator('.stats .stat').count(), 6);
    pass(recreate ? 'The existing sign-in form still works after container recreation' : 'The refreshed sign-in form authenticates successfully');

    const totalPages = await page.locator('.stats .stat strong').first().innerText();
    const deniedMutation = await context.request.post(base + '/admin/', { form: {
      action: 'create_page', csrf: 'stale', title: 'Rejected session test', route: '/rejected-session-test/',
    } });
    assert.equal(deniedMutation.status(), 403);
    await page.reload();
    assert.equal(await page.locator('.stats .stat strong').first().innerText(), totalPages);
    pass('Invalid tokens cannot create content in an authenticated session');

    if (secondary) {
      const second = await context.newPage();
      await second.goto(secondary + '/admin/');
      assert.equal(await second.locator('[name=username]').count(), 1);
      await page.reload();
      assert.equal(await page.locator('.stats .stat').count(), 6);
      const names = (await context.cookies()).map(cookie => cookie.name);
      assert.ok(names.includes('indiba_admin_' + new URL(base).port));
      assert.ok(names.includes('indiba_admin_' + new URL(secondary).port));
      await second.close();
      pass('Portals on different ports have independent session cookies');
    }
    if (recreate) {
      rebuild();
      await page.reload();
      assert.equal(await page.locator('.stats .stat').count(), 6);
      pass('Administrator authentication survives container recreation');
    }
    await page.getByRole('button', { name: 'Sign out', exact: true }).click();
    await page.waitForLoadState();
    assert.equal(await page.locator('[name=username]').count(), 1);
    assert.deepEqual(errors, []);
    pass('Logout works and there are no browser errors');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
