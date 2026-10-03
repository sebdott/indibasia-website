const { chromium } = require('../.tools/browser/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
(async () => {
  const executablePath = process.env.MIRROR_CHROME || 'C:/Users/sebastian/AppData/Local/ms-playwright/chromium-1228/chrome-win64/chrome.exe';
  const browser = await chromium.launch({ executablePath, headless: true });
  const results = [];
  for (const viewport of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    const errors = []; const missing = []; const external = [];
    page.on('pageerror', error => errors.push(error.stack || error.message));
    page.on('response', response => { if (response.status() >= 400) missing.push({ status: response.status(), url: response.url() }); });
    await page.route('**/*', route => {
      const url = new URL(route.request().url());
      if (!['127.0.0.1', 'localhost'].includes(url.hostname)) { external.push(url.href); return route.abort(); }
      return route.continue();
    });
    await page.goto('http://127.0.0.1:8082/', { waitUntil: 'domcontentloaded', timeout: 120000 });
    await page.waitForTimeout(8000);
    await page.evaluate(async () => {
      for (let y = 0; y < document.documentElement.scrollHeight; y += innerHeight) {
        scrollTo(0, y); await new Promise(resolve => setTimeout(resolve, 100));
      }
      scrollTo(0, 0);
    });
    await page.waitForTimeout(1000);
    const metrics = await page.evaluate(() => ({
      title: document.title,
      width: innerWidth,
      scrollWidth: document.documentElement.scrollWidth,
      links: document.querySelectorAll('a').length,
      images: [...document.images].map(img => ({ src: img.currentSrc || img.src, complete: img.complete && img.naturalWidth > 0, loading: img.loading })),
      stylesheets: [...document.querySelectorAll('link[rel=stylesheet]')].map(node => node.href),
      navigation: [...document.querySelectorAll('header a')].slice(0, 8).map(node => ({ text: node.textContent.trim(), href: node.getAttribute('href') })),
    }));
    await page.screenshot({ path: path.resolve('storage', `home-${viewport.width}.png`), fullPage: true });
    results.push({ viewport, metrics, errors, missing, external: [...new Set(external)] });
    await context.close();
  }
  fs.writeFileSync('storage/browser-check.json', JSON.stringify(results, null, 2));
  console.log(JSON.stringify(results.map(({viewport, metrics, errors, missing, external}) => ({viewport, title: metrics.title, width: metrics.width, scrollWidth: metrics.scrollWidth, images: metrics.images.length, loadedImages: metrics.images.filter(img=>img.complete).length, stylesheets: metrics.stylesheets.length, errors: [...new Set(errors)].slice(0, 5), missing: missing.length, external: external.length})), null, 2));
  await browser.close();
})().catch(error => { console.error(error); process.exit(1); });
