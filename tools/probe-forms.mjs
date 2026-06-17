// One-off probe — dumps real input/select names from key pages so we can
// fix the workflow test's selectors.

import { chromium } from 'playwright';
import { BASE, USER, PASS } from './panel-env.mjs';

const browser = await chromium.launch({ headless: true });
const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await ctx.newPage();

await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
await page.fill('input[type="text"], input[name="userName"], input[name="username"]', USER);
await page.fill('input[type="password"]', PASS);
await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);

async function probe(url) {
  await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
  const fields = await page.$$eval('input, select, textarea', els =>
    els.map(e => ({
      tag: e.tagName.toLowerCase(),
      type: e.type || null,
      name: e.getAttribute('name'),
      id: e.id || null,
      placeholder: e.placeholder || null,
    })).filter(f => f.name)
  );
  const submitBtns = await page.$$eval('button[type="submit"], input[type="submit"], button.btn-primary',
    els => els.map(e => ({ tag: e.tagName.toLowerCase(), text: e.textContent?.trim().slice(0, 40), id: e.id || null, name: e.getAttribute('name') }))
  );
  console.log(`\n=== ${url} ===`);
  console.log('  fields:');
  for (const f of fields) console.log(`    ${f.tag}[type=${f.type}] name="${f.name}" id="${f.id || ''}" ph="${f.placeholder || ''}"`);
  console.log('  submitters:');
  for (const s of submitBtns) console.log(`    ${s.tag} text="${s.text}" id="${s.id || ''}"`);
}

await probe('/');
// Collect site links to see what URLs look like
const links = await page.$$eval('a', as => {
  const out = new Set();
  for (const a of as) {
    const h = a.getAttribute('href') || '';
    const m = h.match(/^\/site\/[^/]+(?:\/[^?#]*)?/);
    if (m) out.add(m[0]);
  }
  return [...out].slice(0, 10);
});
console.log('  /site/... links:');
for (const l of links) console.log(`    ${l}`);

await probe('/site/new/static');
await probe('/site/new/php');
await probe('/site/new/nodejs');
await probe('/site/new/docker');

await browser.close();
