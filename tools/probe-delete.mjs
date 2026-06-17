import { chromium } from 'playwright';
import { BASE, USER, PASS } from './panel-env.mjs';
const browser = await chromium.launch({ headless: true });
const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await ctx.newPage();
await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
await page.fill('input[type="text"]', USER);
await page.fill('input[type="password"]', PASS);
await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);

// Navigate to the leftover test site's settings and find the delete confirmation
await page.goto(BASE + '/site/wftest9342916.local/settings', { waitUntil: 'domcontentloaded' });
const deleteLink = page.locator('a:has-text("Delete Site"), a:has-text("Delete")').first();
console.log('delete links:', await page.$$eval('a', as => as.filter(a => /delete/i.test(a.textContent || '')).map(a => a.textContent.trim() + ' -> ' + a.getAttribute('href'))));
if (await deleteLink.count()) {
  await deleteLink.click();
  await page.waitForLoadState('domcontentloaded').catch(() => {});
  await page.waitForTimeout(500);
  const fields = await page.$$eval('input, select, textarea', els =>
    els.map(e => ({ tag: e.tagName, type: e.type, name: e.getAttribute('name'), id: e.id, placeholder: e.placeholder, disabled: e.disabled }))
       .filter(f => f.name));
  console.log('\nfields on confirm page:');
  for (const f of fields) console.log(`  ${f.tag}[${f.type}] name=${f.name} id=${f.id} ph="${f.placeholder}" disabled=${f.disabled}`);
}
await browser.close();
