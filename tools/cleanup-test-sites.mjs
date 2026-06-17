// Delete any wftest*.local sites left behind by failed workflow runs.

import { chromium } from 'playwright';
import { BASE, USER, PASS } from './panel-env.mjs';

const browser = await chromium.launch({ headless: true });
const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await ctx.newPage();

await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
await page.fill('input[type="text"]', USER);
await page.fill('input[type="password"]', PASS);
await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);

await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
const domains = await page.$$eval('a', as => {
  const out = new Set();
  for (const a of as) {
    const m = (a.getAttribute('href') || '').match(/^\/site\/(wftest[^/]+)$/);
    if (m) out.add(m[1]);
  }
  return [...out];
});
console.log(`Found ${domains.length} leftover test site(s):`, domains);

for (const d of domains) {
  console.log(`Deleting ${d}…`);
  try {
    await page.goto(`${BASE}/site/${d}/settings`, { waitUntil: 'domcontentloaded' });
    await page.locator('a:has-text("Delete Site")').first().click();
    await page.waitForTimeout(400);
    const field = page.locator('#site_delete_domainName');
    await field.click();
    await field.pressSequentially(d, { delay: 15 });
    await page.waitForFunction(() => {
      const b = document.querySelector('#site_delete_submit');
      return b && !b.disabled;
    }, null, { timeout: 5000 }).catch(() => {});
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      page.locator('#site_delete_submit').click(),
    ]);
    await page.waitForTimeout(3500);
    console.log(`  ✓ deleted ${d}`);
  } catch (e) {
    console.log(`  ✗ ${d}: ${e.message}`);
  }
}

await browser.close();
