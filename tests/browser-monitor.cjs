/* Read-only browser checks. Uses an installed Chrome and puppeteer-core. */
const assert = require('node:assert/strict');
const path = require('node:path');
const puppeteer = require(process.env.FACT_BROWSER_DRIVER || 'puppeteer-core');

(async () => {
  const browser = await puppeteer.launch({
    executablePath: process.env.FACT_CHROME || path.join(process.env.PROGRAMFILES || 'C:/Program Files', 'Google/Chrome/Application/chrome.exe'),
    headless: true,
    pipe: true,
    timeout: 20000,
  });
  const errors = [];
  try {
    const page = await browser.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.setViewport({ width: 1440, height: 1100 });
    const base = process.env.FACT_URL || 'http://localhost:8085/';
    await page.goto(base, { waitUntil: 'networkidle0' });
    await page.waitForFunction(() => document.querySelector('#monitor-status').textContent !== 'Checking');
    assert.equal(await page.$eval('#monitor-summary', element => element.children.length), 4);
    const state = await page.evaluate(async () => (await fetch('index.php?action=api')).json());
    assert.equal(await page.$eval('#stat-total', element => Number(element.textContent)), state.monitor.total);
    await page.screenshot({ path: path.join(__dirname, '../storage/monitor-desktop.png'), fullPage: true });
    await page.click('[data-open-filter="review"]');
    await page.waitForFunction(() => document.querySelector('#library-count').textContent !== '');
    assert.match(page.url(), /filter=review/);
    assert.equal(await page.$eval('[data-filter="review"]', element => element.classList.contains('selected')), true);
    if (state.monitor.awaiting_review) {
      await page.click('#library-jobs [data-job]');
      await page.waitForSelector('#job-dialog[open]');
      assert.ok(await page.$('#job-detail video'));
      assert.ok(await page.$('[data-approve]'));
      assert.equal(await page.$eval('.review-note strong', element => element.textContent), 'Watch the video, then approve publishing');
      await page.screenshot({ path: path.join(__dirname, '../storage/review-desktop.png'), fullPage: true });
      await page.click('#close-dialog');
    }
    await page.goto(base + '?view=automation', { waitUntil: 'networkidle0' });
    await page.waitForFunction(() => document.querySelector('[name=niche]').value !== '');
    assert.equal(await page.$eval('[name=review_required]', element => element.checked), state.settings.review_required === '1');
    await page.setViewport({ width: 390, height: 844 });
    for (const view of ['overview', 'library&filter=review', 'automation', 'connections']) {
      await page.goto(base + '?view=' + view, { waitUntil: 'networkidle0' });
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Mobile overflow on ' + view);
    }
    await page.goto(base, { waitUntil: 'networkidle0' });
    await page.screenshot({ path: path.join(__dirname, '../storage/monitor-mobile.png'), fullPage: true });
    assert.deepEqual(errors, []);
    console.log('PASS: monitoring totals, review filter, video review, publishing controls, saved review setting, and mobile layout.');
    console.log('No settings, videos, paid requests or publishing destinations were changed.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
