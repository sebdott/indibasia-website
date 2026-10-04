const { chromium } = require('../.tools/browser/node_modules/playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
  const base = process.env.MIRROR_TEST_URL || 'http://127.0.0.1:9000';
  assert.ok(['127.0.0.1', 'localhost'].includes(new URL(base).hostname), 'Use a local website URL.');
  const browser = await chromium.launch({ executablePath: process.env.MIRROR_CHROME || 'C:/Users/sebastian/AppData/Local/ms-playwright/chromium-1228/chrome-win64/chrome.exe', headless: true });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();
    await context.route('**/*', route => ['localhost', '127.0.0.1'].includes(new URL(route.request().url()).hostname) ? route.continue() : route.abort());
    for (const path of ['/asia/products/ah-100/', '/asia/products/equus/']) {
      const response = await page.goto(base + path, { waitUntil: 'networkidle' }); assert.equal(response.status(), 200);
      assert.match(await page.title(), /AH-100|Equus|EQUUS/);
      await page.locator('#main img[src]').evaluateAll(async images => { for (const img of images) img.loading = 'eager'; await Promise.all(images.map(img => img.decode().catch(() => {}))); });
      const missingImages = await page.locator('#main img[src]').evaluateAll(images => images.filter(img => img.getBoundingClientRect().height > 0 && !img.src.startsWith('data:') && img.complete && img.naturalWidth === 0).map(img => img.src));
      assert.deepEqual(missingImages, [], 'Product images must load.');
      await page.screenshot({ path: 'storage/recovered-' + (path.includes('ah-100') ? 'ah100' : 'equus') + '-desktop.png', fullPage: true });
      await page.setViewportSize({ width: 390, height: 844 }); await page.reload({ waitUntil: 'networkidle' });
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Product layout must fit the mobile viewport.');
      await page.screenshot({ path: 'storage/recovered-' + (path.includes('ah-100') ? 'ah100' : 'equus') + '-mobile.png', fullPage: true });
      await page.setViewportSize({ width: 1440, height: 1000 });
      const head = await context.request.head(base + path); assert.equal(head.status(), 200); assert.equal((await head.body()).length, 0);
    }
    console.log('PASS AH-100 and EQUUS content, images, desktop/mobile layout, and HEAD');
    await page.goto(base + '/asia/trainings/'); assert.ok(await page.locator('.recovered-card').count() > 0);
    await page.locator('[name=training-division]').selectOption('animal-health'); await page.getByRole('button', { name: 'Apply filters' }).click(); await page.waitForLoadState();
    assert.match(await page.locator('.recovered-count').innerText(), /Animal Health/);
    const catalog = JSON.parse(fs.readFileSync('storage/recovered-catalog.json', 'utf8'));
    for (const link of await page.locator('.recovered-card h2 a').evaluateAll(links => links.map(a => new URL(a.href).pathname))) assert.ok(catalog[link].taxonomies['training-division'].includes('animal-health'));
    await page.locator('[name=q]').fill('Equus'); await page.getByRole('button', { name: 'Apply filters' }).click(); await page.waitForLoadState();
    assert.equal(await page.locator('.recovered-card').count(), 1); assert.match(await page.locator('.recovered-card').innerText(), /Equus/);
    console.log('PASS Training archive, regional fallback, taxonomy filtering, and search');
    for (const path of ['/scientific-literature/', '/asia/scientific-literature/', '/us/scientific-literature/']) {
      await page.goto(base + path); assert.ok(await page.locator('.recovered-card').count() > 0);
      assert.ok(await page.getByRole('link', { name: 'Next →', exact: true }).count());
    }
    const combination = '/us/events/event-brands-animal-health-or-rehabilitation/event-category-webinar/';
    assert.equal((await page.goto(base + combination)).status(), 200);
    assert.ok(await page.locator('.recovered-card').count() > 0);
    for (const link of await page.locator('.recovered-card h2 a').evaluateAll(links => links.map(a => new URL(a.href).pathname))) {
      assert.ok(catalog[link].taxonomies['event-category'].includes('webinar'));
      assert.ok(catalog[link].taxonomies['event-brands'].some(term => ['animal-health', 'rehabilitation'].includes(term)));
    }
    await page.getByRole('button', { name: 'Apply filters' }).click(); await page.waitForLoadState(); assert.match(await page.locator('.recovered-count').innerText(), /Animal Health or Rehabilitation/i);
    assert.equal((await context.request.get(base + '/us/events/event-category-event-or-therapy/')).status(), 200);
    assert.equal((await context.request.get(base + '/asia/hof-type/legacy/member_cat-athletic-or-freestyle-ski/')).status(), 200);
    assert.equal((await context.request.get(base + '/asia/hof-category/animal-health/member_cat-animal-health-or-handball/')).status(), 200);
    console.log('PASS Scientific literature, pagination, combined archive filters, and legacy taxonomy names');
    await page.goto(base + '/asia/trainings/'); await page.setViewportSize({ width: 390, height: 844 });
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    await page.screenshot({ path: 'storage/recovered-trainings-mobile.png', fullPage: true });
    for (const path of ['/missing-local-test-1934/', '/asia/products/not-a-real-device/', '/us/events/event-brands-not-a-real-category/', '/fr/', '/es/', '/it/']) assert.equal((await context.request.get(base + path)).status(), 404, path);
    const redirect = await context.request.get(base + '/aia/products/k-laser-speciale-live-vet-series/', { maxRedirects: 0 }); assert.equal(redirect.status(), 301); assert.equal(redirect.headers().location, '/asia/products/k-laser-speciale-live-vet-series/');
    console.log('PASS Mobile archive, targeted redirects, and genuine 404 handling');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
