// Panel smoke test — logs in, walks the major pages, captures screenshots + console errors.
// Run: node tools/panel-test.mjs

import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { BASE, USER, PASS } from './panel-env.mjs';
const OUT = path.join(import.meta.dirname || '.', 'panel-test-out');
fs.mkdirSync(OUT, { recursive: true });

const PAGES = [
  { name: '01-login',                    path: '/login',                              expect: ['Sign in', 'Login', 'login'], skipAuthCheck: true },
  { name: '02-home',                     path: '/',                                   expect: ['Sites'] },
  { name: '03-sites-list',               path: '/',                                   expect: ['Sites'] },
  { name: '04-admin-users',              path: '/admin/users',                        expect: ['Users'] },
  { name: '05-malware-scan',             path: '/admin/security/malware-scan',        expect: ['Malware'] },
  { name: '06-marketplace',              path: '/admin/marketplace',                  expect: ['Marketplace'] },
  { name: '07-marketplace-ghost',        path: '/admin/marketplace/ghost',            expect: ['Ghost'] },
  { name: '08-marketplace-nextcloud',    path: '/admin/marketplace/nextcloud',        expect: ['Nextcloud'] },
  { name: '09-remote-backup',            path: '/admin/remote-backup',                expect: ['Backup'] },
  { name: '10-cloudflare-settings',      path: '/admin/cloudflare/settings',          expect: ['Cloudflare'] },
  { name: '11-certificates',             path: '/admin/certificates',                 expect: ['Certificate'] },
  { name: '12-new-site',                 path: '/site/new',                           expect: ['Create'] },
  { name: '13-new-php-site',             path: '/site/new/php',                       expect: ['PHP'] },
  { name: '14-new-static-site',          path: '/site/new/static',                    expect: ['Static'] },
  { name: '15-new-nodejs-site',          path: '/site/new/nodejs',                    expect: ['Node'] },
  { name: '16-new-docker-site',          path: '/site/new/docker',                    expect: ['Docker'] },
];

// Per-site pages — discovered dynamically after login from the sites list.
const PER_SITE_PATHS = [
  { name: 'site-settings',     path: domain => `/site/${domain}/settings` },
  { name: 'site-file-manager', path: domain => `/site/${domain}/file-manager` },
  { name: 'site-logs',         path: domain => `/site/${domain}/logs` },
  { name: 'site-security',     path: domain => `/site/${domain}/security` },
];

const results = [];
const browser = await chromium.launch({ headless: true });
const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await ctx.newPage();

const consoleEvents = [];
page.on('console', m => consoleEvents.push({ type: m.type(), text: m.text(), location: m.location() }));
page.on('pageerror', e => consoleEvents.push({ type: 'pageerror', text: String(e), location: {} }));
page.on('requestfailed', r => consoleEvents.push({ type: 'requestfailed', text: `${r.method()} ${r.url()} — ${r.failure()?.errorText}`, location: {} }));

async function shot(name) {
  const f = path.join(OUT, `${name}.png`);
  await page.screenshot({ path: f, fullPage: true });
  return f;
}

async function visit({ name, path: p, expect, skipAuthCheck }) {
  const start = consoleEvents.length;
  const url = BASE + p;
  let status = null, ok = false, body = '', error = null;
  try {
    const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 20000 });
    status = resp?.status() ?? null;
    body = await page.content();
    ok = status >= 200 && status < 400;
    if (!skipAuthCheck && page.url().endsWith('/login')) {
      error = `redirected to /login — session lost`;
      ok = false;
    }
    if (expect && expect.length) {
      const matched = expect.some(s => body.toLowerCase().includes(s.toLowerCase()));
      if (!matched) error = (error || '') + `; expected one of [${expect.join(', ')}] in body`;
    }
  } catch (e) {
    error = String(e);
  }
  const errs = consoleEvents.slice(start).filter(e => e.type === 'error' || e.type === 'pageerror' || e.type === 'requestfailed');
  const file = await shot(name);
  results.push({ name, path: p, finalUrl: page.url(), status, ok: ok && !error, error, errs: errs.slice(0, 5), screenshot: file });
}

// 1) Login
await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
await shot('01-login-form');

// Try to detect & fill the form
const userField = await page.$('input[name="userName"], input[name="username"], input[type="text"]');
const passField = await page.$('input[name="password"], input[type="password"]');
if (!userField || !passField) {
  console.error('Could not find login form fields');
  await browser.close();
  process.exit(2);
}
await userField.fill(USER);
await passField.fill(PASS);
await Promise.all([
  page.waitForLoadState('domcontentloaded'),
  page.click('button[type="submit"], input[type="submit"]'),
]);
await page.waitForTimeout(800);
await shot('02-after-login');

const loggedInUrl = page.url();
if (loggedInUrl.endsWith('/login')) {
  console.error('Login failed — still on /login');
  await browser.close();
  process.exit(3);
}

// 2) Walk static pages
for (const p of PAGES.slice(1)) await visit(p);

// 3) Discover a site domain from the home page and walk its per-site pages
await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
const domains = await page.$$eval('a', as => as.map(a => a.getAttribute('href') || '').map(h => {
  const m = h.match(/^\/site\/([^/]+)\//);
  return m ? m[1] : null;
}).filter(Boolean));
const uniqueDomains = [...new Set(domains)].slice(0, 1); // just one site, save time
for (const d of uniqueDomains) {
  for (const sp of PER_SITE_PATHS) {
    await visit({ name: `${sp.name}-${d}`, path: sp.path(d), expect: [d] });
  }
}

await browser.close();

const summary = {
  base: BASE,
  total: results.length,
  passed: results.filter(r => r.ok).length,
  failed: results.filter(r => !r.ok).length,
  results,
};
fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(summary, null, 2));

// Concise console summary
console.log(`\n=== Panel Test Summary (${summary.passed}/${summary.total} passed) ===`);
for (const r of results) {
  const tag = r.ok ? '✓' : '✗';
  console.log(`${tag} [${r.status ?? '---'}] ${r.path}`);
  if (!r.ok) {
    if (r.error) console.log(`    error: ${r.error}`);
    for (const e of r.errs) console.log(`    ${e.type}: ${e.text.slice(0, 200)}`);
  }
}
console.log(`\nScreenshots + JSON report: ${OUT}`);
process.exit(summary.failed > 0 ? 1 : 0);
