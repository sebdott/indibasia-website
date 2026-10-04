const { chromium } = require('../.tools/browser/node_modules/playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
  const base = process.env.CMS_TEST_URL || 'http://127.0.0.1:9002';
  const target = new URL(base);
  if (!['127.0.0.1', 'localhost'].includes(target.hostname) || target.port !== '9002') throw Error('Banner mutations require the isolated test portal on port 9002.');
  const password = fs.readFileSync('storage/cms-test-bootstrap.txt', 'utf8').match(/^Password: (.+)$/m)[1];
  const browser = await chromium.launch({ executablePath: process.env.MIRROR_CHROME || 'C:/Users/sebastian/AppData/Local/ms-playwright/chromium-1228/chrome-win64/chrome.exe', headless: true });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    await context.route('**/*', route => ['127.0.0.1', 'localhost'].includes(new URL(route.request().url()).hostname) ? route.continue() : route.abort());
    const page = await context.newPage(); const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const go = async path => { assert.equal((await page.goto(base + path)).status(), 200); };
    const save = async () => {
      await page.getByRole('button', { name: 'Save changes', exact: true }).first().click();
      await page.waitForLoadState(); assert.equal(await page.locator('.error').count(), 0);
    };
    await go('/admin/'); await page.locator('[name=username]').fill('admin'); await page.locator('[name=password]').fill(password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click(); await page.waitForURL(base + '/admin/');
    await go('/admin/?view=media');
    await page.locator('[name=file]').setInputFiles({ name: 'banner-regression.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jbuQAAAAASUVORK5CYII=', 'base64') });
    await page.getByRole('button', { name: 'Upload file', exact: true }).click(); await page.waitForURL(/filter=uploads/);
    const image = await page.locator('.media-card input').first().inputValue();
    for (const [view, route] of [['pages', '/asia/products/ct8/'], ['master', '/news/'], ['news', '/asia/news/physiotherapy-for-cats-enhancing-feline-wellbeing-with-indibas-radiofrequency/'], ['events', '/events/how-indiba-works-at-a-cellular-level/']]) {
      await go('/admin/?view=' + view + '&q=' + encodeURIComponent(route));
      const editPath = await page.locator('a.page-title').first().getAttribute('href');
      await go(editPath + '&mode=html');
      assert.equal(await page.locator('.banner-panel').count(), 1);
      assert.ok(await page.locator('[data-banner-image]').first().inputValue());
      const wording = page.locator('.banner-panel textarea[name*="[text]"]').first();
      assert.ok(await wording.inputValue());
      await wording.fill('Banner wording for ' + view);
      await page.getByRole('button', { name: 'Choose featured image', exact: true }).first().click();
      await page.locator('#picker-query').fill('banner-regression'); await page.getByRole('button', { name: 'Search', exact: true }).click();
      await page.locator('.picker-image').first().click();
      assert.equal(await page.locator('[data-banner-image]').first().inputValue(), image);
      assert.ok(await page.locator('.banner-image-preview img').first().isVisible());
      await save();
      assert.equal(await page.locator('[data-banner-image]').first().inputValue(), image);
      assert.equal(await page.locator('.banner-panel textarea[name*="[text]"]').first().inputValue(), 'Banner wording for ' + view);
      const preview = await context.newPage(); await preview.goto(base + editPath.replace('view=edit', 'view=preview'));
      assert.ok((await preview.content()).includes('Banner wording for ' + view));
      const background = await preview.locator('[data-cms-featured-image]').first().evaluate(node => getComputedStyle(node).backgroundImage);
      assert.ok(background.includes(image), background);
      await preview.close();
      if (view === 'news') {
        await page.screenshot({ path: 'storage/admin-banner-desktop.png', fullPage: false });
        await page.setViewportSize({ width: 390, height: 844 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        await page.screenshot({ path: 'storage/admin-banner-mobile.png', fullPage: false });
        await page.setViewportSize({ width: 1440, height: 1000 });
      }
      await page.getByRole('button', { name: 'Remove image', exact: true }).first().click();
      assert.equal(await page.locator('[data-banner-image]').first().inputValue(), '');
      assert.ok(!(await page.locator('.banner-image-preview img').first().isVisible()));
      await save(); assert.equal(await page.locator('[data-banner-image]').first().inputValue(), '');
      console.log('PASS ' + view + ': existing banner, picker, save, preview background, wording, and image removal');
    }
    for (const type of ['page', 'master', 'news', 'event']) {
      await go('/admin/?view=new&content_type=' + type); await page.locator('[name=title]').fill('New banner ' + type);
      await page.getByRole('button', { name: 'Create draft', exact: true }).click(); await page.waitForURL(/view=edit/);
      assert.equal(await page.locator('.banner-panel textarea[name*="[text]"]').first().inputValue(), 'New banner ' + type);
      await page.locator('[data-banner-image]').fill(image);
      await page.locator('.banner-panel textarea[name$="[description]"]').fill('A short introduction.');
      await page.locator('[name=status]').selectOption('published');
      await page.waitForFunction(() => window.tinymce?.get('classic-content')?.initialized);
      assert.equal(await page.evaluate(() => tinymce.get('classic-content').getBody().querySelector('[data-cms-banner]')), null);
      assert.ok(!(await page.evaluate(() => tinymce.get('classic-content').getBody().innerText)).includes('New banner ' + type));
      await page.getByRole('button', { name: 'Code', exact: true }).click();
      assert.ok(!(await page.locator('#classic-code').inputValue()).includes('New banner ' + type));
      await page.getByRole('button', { name: 'Visual', exact: true }).click();
      await page.evaluate(() => { const editor = tinymce.get('classic-content'); editor.setContent(editor.getContent() + '<p>Body edited alongside the banner.</p>'); editor.fire('change'); });
      await save();
      const route = await page.locator('[name=route]').inputValue(); const live = await context.request.get(base + route);
      assert.equal(live.status(), 200); const html = await live.text();
      assert.ok(html.includes(image) && html.includes('A short introduction.') && html.includes('Body edited alongside the banner.'));
      assert.equal((html.match(/<h1\b/g) || []).length, 1);
      console.log('PASS New ' + type + ': banner and Classic body edits publish together');
    }
    assert.deepEqual(errors, []);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
