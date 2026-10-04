const { chromium } = require('../.tools/browser/node_modules/playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
  const readOnly = process.argv.includes('--read-only');
  const base = process.env.CMS_TEST_URL || (readOnly ? 'http://127.0.0.1:9000' : 'http://127.0.0.1:9002');
  const url = new URL(base);
  if (!['127.0.0.1', 'localhost'].includes(url.hostname) || !readOnly && url.port !== '9002') {
    throw Error('Content mutations are restricted to the isolated test portal on port 9002.');
  }
  const credentials = fs.readFileSync(readOnly ? 'storage/local-admin-bootstrap.txt' : 'storage/cms-test-bootstrap.txt', 'utf8');
  const password = credentials.match(/^Password: (.+)$/m)[1];
  const browser = await chromium.launch({ executablePath: process.env.MIRROR_CHROME || 'C:/Users/sebastian/AppData/Local/ms-playwright/chromium-1228/chrome-win64/chrome.exe', headless: true });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage(); const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await context.route('**/*', route => ['127.0.0.1', 'localhost'].includes(new URL(route.request().url()).hostname) ? route.continue() : route.abort());
    const go = async path => { const response = await page.goto(base + path); assert.equal(response.status(), 200); };
    const pass = message => console.log('PASS ' + message);
    await go('/admin/');
    await page.locator('[name=username]').fill('admin'); await page.locator('[name=password]').fill(password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click(); await page.waitForURL(base + '/admin/');
    assert.ok(await page.locator('.stats').innerText().then(text => text.includes('News items')));
    await go('/admin/?view=pages');
    const pageIds = await page.locator('.page-selection').evaluateAll(items => items.map(item => item.value));
    for (const route of await page.locator('td:nth-child(2) > small').allTextContents()) assert.doesNotMatch(route, /^\/(?:asia\/|us\/)?news\//);
    await go('/admin/?view=new'); await page.locator('[name=title]').fill('A regular page');
    assert.equal(await page.locator('[name=route]').inputValue(), '/a-regular-page/');
    await go('/admin/?view=new&content_type=news'); await page.locator('[name=title]').fill('A news item');
    assert.equal(await page.locator('[name=route]').inputValue(), '/news/a-news-item/');
    await go('/admin/?view=news&region=asia');
    assert.ok(await page.locator('a.page-title').count() > 0);
    for (const route of await page.locator('td:nth-child(2) > small').allTextContents()) assert.match(route, /^\/asia\/news\//);
    for (const id of await page.locator('.page-selection').evaluateAll(items => items.map(item => item.value))) assert.ok(!pageIds.includes(id));
    const existingEditor = await page.locator('a.page-title').first().getAttribute('href');
    await page.getByRole('button', { name: 'Filter', exact: true }).click(); await page.waitForLoadState();
    assert.equal(new URL(page.url()).searchParams.get('view'), 'news');
    await go(existingEditor + '&mode=html');
    assert.equal(await page.locator('[name=content_type]').inputValue(), 'news');
    assert.equal(await page.locator('nav[aria-label="Main navigation"] a[aria-current=page]').innerText(), 'News');
    assert.equal(await page.locator('.editor-top > a').getAttribute('href'), '/admin/?view=news');
    await go('/admin/?view=news');
    await page.screenshot({ path: 'storage/admin-news-desktop.png', fullPage: false });
    await page.setViewportSize({ width: 390, height: 844 });
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await page.screenshot({ path: 'storage/admin-news-mobile.png', fullPage: false });
    await page.setViewportSize({ width: 1440, height: 1000 });
    pass('Separate existing lists, regional filters, creation paths, navigation, and mobile layout');
    for (const [route, view, label] of [['/us/events/', 'master', 'Master pages'], ['/news/', 'master', 'Master pages'], ['/us/event-brands/rehabilitation/', 'master', 'Master pages'], ['/events/how-indiba-works-at-a-cellular-level/', 'events', 'Events']]) {
      const region = route.startsWith('/us/') ? 'us' : route.startsWith('/asia/') ? 'asia' : 'global';
      await go('/admin/?view=' + view + '&sort=path&region=' + region + '&q=' + encodeURIComponent(route));
      const row = page.locator('tbody tr').filter({ has: page.locator('td:nth-child(2) > small', { hasText: route }) });
      const exactRow = row.filter({ has: page.getByText(route, { exact: true }) });
      assert.equal(await exactRow.count(), 1, route + ' should appear in ' + view + ': ' + (await page.locator('td:nth-child(2) > small').allTextContents()).join(', '));
      const href = await exactRow.locator('a.page-title').getAttribute('href');
      await go(href + '&mode=html');
      assert.equal(await page.locator('nav[aria-label="Main navigation"] a[aria-current=page]').innerText(), label);
      assert.equal(await page.locator('.editor-top > a').getAttribute('href'), '/admin/?view=' + view);
      for (const other of ['pages', 'master', 'news', 'events'].filter(other => other !== view)) {
        await go('/admin/?view=' + other + '&q=' + encodeURIComponent(route));
        assert.equal(await page.getByText(route, { exact: true }).count(), 0);
      }
    }
    await go('/admin/?view=master');
    await page.screenshot({ path: 'storage/admin-master-pages.png', fullPage: false });
    pass('Events and News listing screens belong to Master pages; individual events belong to Events');
    if (!readOnly) {
      const title = 'News separation regression';
      await go('/admin/?view=new&content_type=news'); await page.locator('[name=title]').fill(title);
      await page.getByRole('button', { name: 'Create draft', exact: true }).click(); await page.waitForURL(/view=edit/);
      const edit = new URL(page.url()); const id = edit.searchParams.get('id');
      const editorPath = '/admin/?view=edit&id=' + id + '&mode=html';
      const newsList = '/admin/?view=news&q=' + encodeURIComponent(title);
      const pagesList = '/admin/?view=pages&q=' + encodeURIComponent(title);
      assert.equal(await context.request.get(base + '/news/news-separation-regression/').then(r => r.status()), 404);
      await go(pagesList); assert.equal(await page.locator('a.page-title').count(), 0);
      await go(newsList); assert.equal(await page.locator('a.page-title').count(), 1);
      const save = async () => { await page.getByRole('button', { name: 'Save changes', exact: true }).first().click(); await page.waitForLoadState(); assert.equal(await page.locator('.error').count(), 0); };
      await go(editorPath); await page.locator('[name=route]').fill('/renamed-news-regression/'); await save();
      assert.equal(await page.locator('[name=content_type]').inputValue(), 'news');
      await go(newsList); assert.equal(await page.locator('a.page-title').count(), 1);
      await page.getByRole('button', { name: 'Duplicate', exact: true }).click(); await page.waitForURL(/view=edit/);
      assert.equal(await page.locator('[name=content_type]').inputValue(), 'news');
      pass('News drafts stay separate after URL changes and duplication');
      await go(newsList);
      const bulk = async target => {
        await page.locator('#select-all-pages').check(); await page.locator('#bulk-pages [name=target_status]').selectOption(target);
        if (target === 'trashed') page.once('dialog', dialog => dialog.accept());
        await page.locator('#bulk-pages button').click(); await page.waitForLoadState();
        assert.equal(new URL(page.url()).searchParams.get('view'), 'news'); assert.equal(await page.locator('.error').count(), 0);
      };
      await bulk('trashed'); assert.equal(await page.locator('a.page-title').count(), 2);
      await go(pagesList + '&status=trashed'); assert.equal(await page.locator('a.page-title').count(), 0);
      await go(newsList + '&status=trashed'); await bulk('draft'); assert.equal(await page.locator('a.page-title').count(), 2);
      pass('News Trash and bulk restore stay in News');
      await go(editorPath); await page.locator('[name=content_type]').selectOption('page'); await save();
      await go(pagesList); assert.equal(await page.locator('a.page-title').count(), 1);
      await go('/admin/?view=revisions&id=' + id); page.once('dialog', dialog => dialog.accept());
      await page.getByRole('button', { name: 'Restore version', exact: true }).first().click(); await page.waitForLoadState();
      assert.equal(await page.locator('[name=content_type]').inputValue(), 'news');
      await go(pagesList); assert.equal(await page.locator('a.page-title').count(), 0);
      await go(newsList); assert.equal(await page.locator('a.page-title').count(), 2);
      pass('Moving content between sections and restoring its revision preserve the correct type');
      const csrf = await page.locator('[name=csrf]').first().inputValue();
      const newsVersion = await page.locator(`[name="versions[${id}]"]`).inputValue();
      const original = await page.locator(`.page-selection[value="${id}"]`).locator('xpath=../..').innerText();
      await go('/admin/?view=pages');
      const otherId = await page.locator('.page-selection').first().inputValue();
      const otherVersion = await page.locator(`[name="versions[${otherId}]"]`).inputValue();
      const result = await context.request.post(base + '/admin/?view=news', { form: { csrf, action: 'bulk_pages', content_type: 'news', target_status: 'published', 'ids[0]': id, 'ids[1]': otherId, [`versions[${id}]`]: newsVersion, [`versions[${otherId}]`]: otherVersion } });
      assert.match(await result.text(), /Select content from the same section/);
      await go(newsList); assert.equal(await page.locator(`.page-selection[value="${id}"]`).locator('xpath=../..').innerText(), original);
      pass('Mixed-section bulk requests are rejected and rolled back');
      for (const [type, view, prefix, label] of [['event','events','/events/','Events'], ['master','master','/','Master pages']]) {
        const entryTitle = type + ' section regression';
        const list = '/admin/?view=' + view + '&q=' + encodeURIComponent(entryTitle);
        await go('/admin/?view=new&content_type=' + type);
        await page.locator('[name=title]').fill(entryTitle);
        assert.equal(await page.locator('[name=route]').inputValue(), prefix + type + '-section-regression/');
        await page.getByRole('button', { name: 'Create draft', exact: true }).click(); await page.waitForURL(/view=edit/);
        const entryId = new URL(page.url()).searchParams.get('id');
        await go('/admin/?view=edit&id=' + entryId + '&mode=html');
        await page.locator('[name=route]').fill('/renamed-' + type + '-regression/'); await save();
        assert.equal(await page.locator('[name=content_type]').inputValue(), type);
        assert.equal(await page.locator('nav[aria-label="Main navigation"] a[aria-current=page]').innerText(), label);
        await go(list); await page.getByRole('button', { name: 'Duplicate', exact: true }).click(); await page.waitForURL(/view=edit/);
        assert.equal(await page.locator('[name=content_type]').inputValue(), type);
        const apply = async target => {
          await page.locator('#select-all-pages').check(); await page.locator('#bulk-pages [name=target_status]').selectOption(target);
          if (target === 'trashed') page.once('dialog', dialog => dialog.accept());
          await page.locator('#bulk-pages button').click(); await page.waitForLoadState();
          assert.equal(new URL(page.url()).searchParams.get('view'), view); assert.equal(await page.locator('.error').count(), 0);
        };
        await go(list); await apply('trashed'); assert.equal(await page.locator('a.page-title').count(), 2);
        await go(list + '&status=trashed'); await apply('draft'); assert.equal(await page.locator('a.page-title').count(), 2);
        await go('/admin/?view=pages&q=' + encodeURIComponent(entryTitle)); assert.equal(await page.locator('a.page-title').count(), 0);
        pass(label + ' drafts, URL changes, duplication, and Trash/restore stay in their own section');
      }
    }
    assert.deepEqual(errors, []);
    await page.getByRole('button', { name: 'Sign out', exact: true }).click(); await page.waitForLoadState();
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
