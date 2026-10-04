const { chromium } = require('../.tools/browser/node_modules/playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
  const base = process.env.CMS_TEST_URL || 'http://127.0.0.1:9002';
  const target = new URL(base);
  assert.ok(['127.0.0.1', 'localhost'].includes(target.hostname) && target.port === '9002', 'Media mutations require the isolated test portal on port 9002.');
  const browser = await chromium.launch({ executablePath: process.env.MIRROR_CHROME || 'C:/Users/sebastian/AppData/Local/ms-playwright/chromium-1228/chrome-win64/chrome.exe', headless: true });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, permissions: ['clipboard-read', 'clipboard-write'] });
    const page = await context.newPage(); const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => dialog.accept());
    assert.equal((await context.request.get(base + '/admin/?view=media_details&id=1')).status(), 401);
    await page.goto(base + '/admin/');
    await page.locator('[name=username]').fill('admin');
    await page.locator('[name=password]').fill(fs.readFileSync('storage/cms-test-bootstrap.txt', 'utf8').match(/^Password: (.+)$/m)[1]);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click(); await page.waitForURL(base + '/admin/');
    const upload = async (name, mimeType = 'image/png', buffer = fs.readFileSync('public/assets/eddc3faf0b40b524b18421dde70005193e83985f9f972092321234428632d3a8.png')) => {
      await page.goto(base + '/admin/?view=media');
      await page.getByRole('button', { name: '+ Add new media file', exact: true }).click();
      await page.locator('[name=file]').setInputFiles({ name, mimeType, buffer });
      await page.getByRole('button', { name: 'Upload file', exact: true }).click(); await page.waitForURL(/filter=uploads/);
      return await page.locator('.library-card').first().getAttribute('data-media-id');
    };
    await upload('media-navigation-test.png');
    const id = await upload('media-details-test.png');
    const open = async () => {
      await page.locator(`[data-media-id="${id}"] [data-media-open]`).first().click();
      await page.waitForFunction(() => !document.getElementById('attachment-fields').disabled);
    };
    const close = () => page.getByRole('button', { name: 'Close attachment details', exact: true }).click();
    await open();
    await page.locator('#attachment-preview img').evaluate(img => img.decode());
    assert.match(await page.locator('#attachment-facts').innerText(), /pixels/);
    assert.match(await page.locator('#attachment-facts').innerText(), /admin/);
    const fileURL = await page.locator('#attachment-url').inputValue();
    const csrf = await page.locator('#attachment-form [name=csrf]').inputValue();
    const details = async () => (await (await context.request.get(base + '/admin/?view=media_details&id=' + id)).json()).file;
    assert.ok((await details()).width > 0 && (await details()).size > 0);
    await page.locator('#attachment-title').fill('Reverso media regression');
    await page.locator('#attachment-alt').fill('Reverso treatment device');
    await page.locator('#attachment-caption').fill('A caption with <strong>plain text</strong>.');
    await page.locator('#attachment-description').fill('Details retained after saving and reopening.');
    await page.getByRole('button', { name: 'Save details', exact: true }).click();
    await page.waitForFunction(() => document.getElementById('attachment-status').textContent === 'Attachment details saved.');
    const saved = await details(); assert.equal(saved.title, 'Reverso media regression'); assert.equal(saved.alt_text, 'Reverso treatment device');
    assert.equal(saved.caption, 'A caption with <strong>plain text</strong>.'); assert.match(saved.description, /retained/);
    await page.getByRole('button', { name: 'Copy URL', exact: true }).click();
    await page.getByRole('button', { name: 'Copied!', exact: true }).waitFor();
    assert.equal(await page.evaluate(() => navigator.clipboard.readText()), fileURL);
    await page.getByRole('button', { name: 'Next attachment', exact: true }).click();
    await page.waitForFunction(() => document.getElementById('attachment-title').value === 'media-navigation-test' && !document.getElementById('attachment-fields').disabled);
    await page.getByRole('button', { name: 'Previous attachment', exact: true }).click();
    await page.waitForFunction(() => document.getElementById('attachment-title').value === 'Reverso media regression' && !document.getElementById('attachment-fields').disabled);
    await page.screenshot({ path: 'storage/media-attachment-desktop.png' });
    await close(); await page.reload(); await open(); assert.equal(await page.locator('#attachment-alt').inputValue(), saved.alt_text); await close();
    console.log('PASS Upload, attachment preview, dimensions, author, metadata persistence, clipboard, and navigation');

    await page.getByRole('link', { name: 'List view', exact: true }).click();
    assert.equal(await page.locator('.media-list-table').count(), 1); await open(); await close();
    await page.getByRole('link', { name: 'Grid view', exact: true }).click();
    await page.locator('[name=q]').fill('Reverso media regression');
    await page.locator('[name=kind]').selectOption('image');
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    assert.equal(await page.locator('.library-card').count(), 1);
    const month = saved.created_at.slice(0, 7); await page.locator('[name=month]').selectOption(month);
    await page.getByRole('button', { name: 'Filter', exact: true }).click(); assert.equal(await page.locator('.library-card').count(), 1);
    await page.locator('[name=kind]').selectOption('document'); await page.getByRole('button', { name: 'Filter', exact: true }).click();
    assert.equal(await page.locator('.library-card').count(), 0);
    await page.goto(base + '/admin/?view=media&filter=uploads');
    console.log('PASS Grid/list switching, title search, media type, date, and source filters');

    for (const action of ['media_save', 'media_status']) {
      const forged = await context.request.post(base + '/admin/?view=media', { form: { action, id, target_status: 'trash', title: 'Forged' } });
      assert.equal(forged.status(), 403); assert.match((await forged.json()).error, /expired/);
    }
    const invalid = await context.request.post(base + '/admin/?view=media', { form: { action: 'media_save', csrf, id, title: '', alt_text: '', caption: '', description: '' } });
    assert.equal(invalid.status(), 422); assert.equal((await details()).title, saved.title);
    const rollbackData = new URLSearchParams({ action: 'bulk_media', csrf, target_status: 'trash' }); rollbackData.append('ids[]', id); rollbackData.append('ids[]', '99999999');
    const rollback = await context.request.post(base + '/admin/?view=media', { data: rollbackData.toString(), headers: {'Content-Type':'application/x-www-form-urlencoded'} });
    assert.match(await rollback.text(), /no longer exists/); assert.equal((await details()).trashed_at, null);
    console.log('PASS Authentication, CSRF, field validation, and atomic bulk changes');

    await page.getByRole('button', { name: 'Bulk select', exact: true }).click();
    await page.locator(`[data-media-id="${id}"] .media-selection`).check();
    assert.equal(await page.locator('#media-selected-count').innerText(), '1 selected');
    await page.getByRole('button', { name: 'Move to Trash', exact: true }).click(); await page.waitForURL(/filter=trash/);
    assert.ok((await details()).trashed_at);
    const picker = await (await context.request.get(base + '/admin/?view=picker&q=Reverso%20media%20regression')).json();
    assert.ok(!picker.items.some(item => String(item.id) === id));
    assert.equal((await context.request.get(fileURL)).status(), 200);
    await page.locator(`[data-media-id="${id}"] [data-media-open]`).first().click();
    await page.getByRole('button', { name: 'Restore file', exact: true }).waitFor();
    await page.waitForFunction(() => !document.getElementById('attachment-trash').disabled);
    await page.getByRole('button', { name: 'Restore file', exact: true }).click(); await page.waitForFunction(() => !document.getElementById('attachment-details').open);
    assert.equal((await details()).trashed_at, null);
    const restoredPicker = await (await context.request.get(base + '/admin/?view=picker&q=Reverso%20media%20regression')).json();
    assert.equal(restoredPicker.items.find(item => String(item.id) === id).alt_text, saved.alt_text);
    console.log('PASS Bulk Trash, single restore, picker exclusion, saved alternative text, and file preservation');

    await page.goto(base + '/admin/?view=media&filter=uploads');
    await page.setViewportSize({ width: 390, height: 844 });
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await open(); await page.screenshot({ path: 'storage/media-attachment-mobile.png' });
    assert.ok(await page.locator('#attachment-details').evaluate(dialog => dialog.scrollWidth <= dialog.clientWidth));
    await close(); assert.deepEqual(errors, []);
    console.log('PASS Mobile grid and attachment details without browser errors');
    await page.setViewportSize({ width: 1440, height: 1000 });
    const pdfId = await upload('media-document-test.pdf', 'application/pdf', fs.readFileSync('public/assets/3c4244ce2fd216851be55865080a1515f1157a736d77b30e2acbd5e7c814e75e.pdf'));
    await page.locator(`[data-media-id="${pdfId}"] [data-media-open]`).first().click();
    await page.waitForFunction(() => !document.getElementById('attachment-fields').disabled);
    assert.equal(await page.locator('#attachment-preview iframe').count(), 1);
    assert.equal(await page.locator('#attachment-alt-label').isVisible(), false);
    await close();
    await page.getByRole('button', { name: 'Bulk select', exact: true }).click();
    await page.locator(`[data-media-id="${pdfId}"] .media-selection`).check();
    await page.getByRole('button', { name: 'Move to Trash', exact: true }).click(); await page.waitForURL(/filter=trash/);
    await page.getByRole('button', { name: 'Bulk select', exact: true }).click(); await page.locator('#media-select-all').check();
    await page.getByRole('button', { name: 'Restore selected', exact: true }).click(); await page.waitForURL(/filter=all/);
    const documentFile = (await (await context.request.get(base + '/admin/?view=media_details&id=' + pdfId)).json()).file;
    assert.equal(documentFile.trashed_at, null); assert.equal(documentFile.mime, 'application/pdf'); assert.equal(documentFile.width, null);
    assert.deepEqual(errors, []);
    console.log('PASS PDF attachment preview and bulk restore');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
