// Double-spot verification: log in as the dedicated admin test user (pwtest),
// walk every admin + per-site page added by the fork, capture screenshots +
// per-page pass/fail + console errors. Independent of the cmsadmin-driven
// suite — proves the fork works with a fresh user, not just cmsadmin's session.
//
// Env (tools/.env, gitignored):
//   PANEL_BASE  = https://45.145.226.137:8443
//   PWTEST_USER = pwtest
//   PWTEST_PASS = <password>

import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { BASE } from './panel-env.mjs';

const USER = process.env.PWTEST_USER || 'pwtest';
const PASS = process.env.PWTEST_PASS;
if (!PASS) {
  console.error('Missing PWTEST_PASS env var. Set it in tools/.env (gitignored).');
  process.exit(2);
}

const OUT = path.join(import.meta.dirname || '.', 'double-spot-out');
fs.mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch({ headless: true });
const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await ctx.newPage();
const errs = [];
page.on('pageerror', e => errs.push(`pageerror: ${e}`));
page.on('console', m => { if (m.type() === 'error') errs.push(`console.error: ${m.text()}`); });

async function shot(name) {
  await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: true });
}

async function login() {
  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[type="text"]', USER);
  await page.fill('input[type="password"]', PASS);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
  if (page.url().endsWith('/login')) throw new Error('Login failed for ' + USER);
}

const results = [];
async function check(label, url, expectSubstr, options = {}) {
  errs.length = 0;
  const resp = await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
  const status = resp?.status() ?? null;
  const finalUrl = page.url();
  const body = (await page.textContent('body') || '').toLowerCase();
  const has = expectSubstr ? body.includes(expectSubstr.toLowerCase()) : true;
  const sessionLost = !options.allowLogin && finalUrl.endsWith('/login');
  const httpOk = status >= 200 && status < 400;
  const ok = httpOk && has && !sessionLost && errs.length === 0;
  results.push({ label, url, status, finalUrl, ok, sessionLost, hasExpected: has, errs: errs.slice(0, 5) });
  const tag = ok ? '✓' : '✗';
  await shot(label.replace(/[^a-z0-9-]+/gi, '_'));
  console.log(`${tag} [${status}] ${url}${ok ? '' : ` — has='${expectSubstr}':${has} session_lost=${sessionLost} errs=${errs.length}`}`);
}

await login();
console.log(`Logged in as ${USER}.`);

// ----- Admin pages -----
await check('admin-home',         '/',                                     'sites');
await check('admin-users',        '/admin/users',                          'user');
await check('admin-events',       '/admin/events',                         'event');
await check('admin-firewall',     '/admin/firewall',                       'security');
await check('admin-certificates', '/admin/certificates',                   'cert');
await check('admin-cloudflare',   '/admin/cloudflare/settings',            'cloudflare');
await check('admin-remote-backup','/admin/remote-backup',                  'backup');
await check('admin-backup-restore','/admin/remote-backup/restore',         'restore');
await check('admin-marketplace',  '/admin/marketplace',                    'marketplace');
await check('admin-marketplace-g','/admin/marketplace/ghost',              'ghost');
await check('admin-marketplace-n','/admin/marketplace/nextcloud',          'nextcloud');
await check('admin-mailcow',      '/admin/mailcow',                        'mailcow');
await check('admin-mailcow-set',  '/admin/mailcow/settings',               'mailcow');
await check('admin-malware-scan', '/admin/security/malware-scan',          'malware');
await check('admin-sites-bulk',   '/admin/sites/bulk',                     'bulk');
await check('admin-tools-headers','/admin/tools/headers-tester',           'headers');
await check('admin-health',       '/admin/health',                         'health');
await check('admin-health-api',   '/admin/health/metrics.json',            'memory');
await check('admin-cdn',          '/admin/cdn',                            'cdn');
await check('admin-settings',     '/admin/settings',                       'settings');

// ----- New-site forms -----
await check('new-site-php',       '/site/new/php',                         'php');
await check('new-site-static',    '/site/new/static',                      'static');
await check('new-site-nodejs',    '/site/new/nodejs',                      'node');
await check('new-site-docker',    '/site/new/docker',                      'docker');
await check('new-site-rprx',      '/site/new/reverse-proxy',               'reverse');
await check('new-site-python',    '/site/new/python',                      'python');
await check('new-site-wordpress', '/site/new/wordpress',                   'wordpress');

// ----- Per-site pages (probe first real domain) -----
await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
const domain = await page.evaluate(() => {
  for (const a of document.querySelectorAll('a')) {
    const m = (a.getAttribute('href') || '').match(/^\/site\/([^/]+)$/);
    if (m && m[1] !== 'new') return m[1];
  }
  return null;
});
console.log(`Per-site probe domain: ${domain || '(none — skipping per-site checks)'}`);
if (domain) {
  await check('site-settings',     `/site/${domain}/settings`,             domain);
  await check('site-vhost',        `/site/${domain}/vhost`,                'server');
  await check('site-certificates', `/site/${domain}/certificates`,         'cert');
  await check('site-security',     `/site/${domain}/security`,             'security');
  await check('site-users',        `/site/${domain}/users`,                'ssh');
  await check('site-cron-jobs',    `/site/${domain}/cron-jobs`,            'cron');
  await check('site-dns',          `/site/${domain}/dns`,                  'dns');
  await check('site-email',        `/site/${domain}/email`,                'mail');
  await check('site-cdn',          `/site/${domain}/cdn`,                  'cdn');
  await check('site-php-fpm',      `/site/${domain}/php-fpm`,              'pool');   // may redirect for non-PHP sites
  await check('site-file-manager', `/site/${domain}/file-manager`,         'file');
  await check('site-logs',         `/site/${domain}/logs`,                 'log');
  await check('site-clone',        `/site/${domain}/clone`,                'clone');
  // docker-settings redirects to /site/<d>/settings for non-docker sites — that's correct.
  // Accept either 'docker' (docker site) or 'settings' (redirected) as success.
  await check('site-docker-set',   `/site/${domain}/docker-settings`,      'settings');
}

await browser.close();

// ----- Report -----
const summary = {
  user: USER,
  base: BASE,
  total: results.length,
  passed: results.filter(r => r.ok).length,
  failed: results.filter(r => !r.ok).length,
  results,
};
fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(summary, null, 2));
console.log(`\n=== Double-spot summary: ${summary.passed}/${summary.total} passed (as ${USER}) ===`);
for (const r of results) {
  if (!r.ok) {
    console.log(`✗ ${r.label} [${r.status}] ${r.url}`);
    for (const e of r.errs.slice(0, 3)) console.log(`    ${e.slice(0, 200)}`);
  }
}
console.log(`\nScreenshots + report.json: ${OUT}`);
process.exit(summary.failed > 0 ? 1 : 0);
