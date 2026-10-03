const { chromium } = require('../.tools/browser/node_modules/playwright');
const fs = require('node:fs');
(async () => {
  const browser = await chromium.launch({ executablePath: process.env.MIRROR_CHROME || 'C:/Users/sebastian/AppData/Local/ms-playwright/chromium-1228/chrome-win64/chrome.exe', headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  await context.route('**/*', route => new URL(route.request().url()).hostname === '127.0.0.1' ? route.continue() : route.abort());
  const page = await context.newPage();
  const result = { pages: [], tabs: null, mobileMenu: null, form: null };
  for (const route of ['/asia/products/ct8/', '/us/products/compact-pro/', '/about-us/', '/asia/contact/']) {
    const errors = []; const missing = [];
    const onError = error => errors.push(error.stack || error.message);
    const onResponse = response => { if (response.url().startsWith('http://127.0.0.1:8082/') && response.status() >= 400) missing.push(response.url()); };
    page.on('pageerror', onError); page.on('response', onResponse);
    const response = await page.goto('http://127.0.0.1:8082' + route, { waitUntil: 'domcontentloaded', timeout: 120000 });
    await page.waitForTimeout(2500);
    result.pages.push({ route, status: response.status(), title: await page.title(), errors: [...new Set(errors)], missing: [...new Set(missing)] });
    if (route.endsWith('/contact/')) result.form = await page.evaluate(() => {
      const form = document.querySelector('form'); if (!form) return 'No form found';
      const allowed = form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      return { prevented: !allowed, message: form.querySelector('.mirror-form-message')?.textContent };
    });
    page.off('pageerror', onError); page.off('response', onResponse);
  }
  await page.goto('http://127.0.0.1:8082/', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  const tab = page.getByRole('tab', { name: 'Medical Aesthetics', exact: true }).first();
  if (await tab.count()) { await tab.click(); await page.waitForTimeout(500); result.tabs = await tab.getAttribute('aria-selected'); }
  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  const menu = page.locator('.mobile-toggle').first();
  if (await menu.count()) {
    await menu.click(); await page.waitForTimeout(500);
    result.mobileMenu = await page.evaluate(() => {
      const panel = document.querySelector('#nav-panel'); const bounds = panel?.getBoundingClientRect();
      return { open: Boolean(bounds && bounds.width > 0 && bounds.left < innerWidth && bounds.right > 0), visibleLinks: [...document.querySelectorAll('#nav-panel a')].filter(a => { const box = a.getBoundingClientRect(); return box.width > 0 && box.left < innerWidth && box.right > 0; }).length };
    });
  }
  fs.writeFileSync('storage/interactions-check.json', JSON.stringify(result, null, 2));
  console.log(JSON.stringify(result, null, 2));
  await browser.close();
})().catch(error => { console.error(error); process.exit(1); });
