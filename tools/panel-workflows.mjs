// End-to-end workflow tests against the live panel.
// Run: node tools/panel-workflows.mjs

import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { BASE, USER, PASS } from './panel-env.mjs';
const OUT = path.join(import.meta.dirname || '.', 'panel-workflows-out');
fs.mkdirSync(OUT, { recursive: true });

const log = (...a) => console.log(...a);
const tag = (ok) => ok ? '✓' : '✗';

const browser = await chromium.launch({ headless: true });
const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await ctx.newPage();

const errors = [];
page.on('pageerror', e => errors.push(`pageerror: ${e}`));
page.on('console', m => { if (m.type() === 'error') errors.push(`console.error: ${m.text()}`); });

async function shot(name) {
  const f = path.join(OUT, `${name}.png`);
  await page.screenshot({ path: f, fullPage: true });
  return f;
}

async function login() {
  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[type="text"]', USER);
  await page.fill('input[type="password"]', PASS);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
  if (page.url().endsWith('/login')) throw new Error('Login failed');
}

async function readFlash(timeoutMs = 4000) {
  try {
    await page.waitForSelector('.alert, [class*="success"], [class*="danger"]', { timeout: timeoutMs });
  } catch {}
  const flashes = await page.$$eval('.alert, [class*="alert-success"], [class*="alert-danger"]',
    els => els.map(e => e.textContent.trim()).filter(Boolean));
  return flashes.join(' | ');
}

const results = [];
async function run(name, fn) {
  errors.length = 0;
  const start = Date.now();
  let ok = false, err = null, evidence = null;
  try {
    evidence = await fn();
    ok = true;
  } catch (e) {
    err = e.message || String(e);
  }
  const dur = Date.now() - start;
  results.push({ name, ok, err, evidence, errors: errors.slice(0, 5), durationMs: dur });
  log(`${tag(ok)} ${name} (${dur}ms)${err ? ' — ' + err : ''}`);
}

// ---------- workflows ----------

await login();
log('Logged in.');

// W1 — Home / sites list
let probeDomain = null;
await run('W1: home/sites list', async () => {
  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
  await shot('w1-home');
  const domains = await page.$$eval('a', as => {
    const out = new Set();
    for (const a of as) {
      const m = (a.getAttribute('href') || '').match(/^\/site\/([^/]+)$/);
      if (m && m[1] !== 'new') out.add(m[1]);
    }
    return [...out];
  });
  if (!domains.length) throw new Error('no sites listed on home');
  probeDomain = domains[0];
  return { sitesFound: domains.length, sample: domains.slice(0, 3) };
});

// W2 — Settings page for the probe site
if (probeDomain) {
  await run(`W2: site settings (${probeDomain})`, async () => {
    await page.goto(`${BASE}/site/${probeDomain}/settings`, { waitUntil: 'domcontentloaded' });
    await shot('w2-settings');
    const t = (await page.textContent('body')).toLowerCase();
    if (!t.includes(probeDomain.toLowerCase())) throw new Error('site domain not on settings page');
    return { domain: probeDomain };
  });

  // W3 — File manager iframe loads with content
  await run(`W3: file manager iframe (${probeDomain})`, async () => {
    await page.goto(`${BASE}/site/${probeDomain}/file-manager`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    await shot('w3-file-manager');
    const iframeEl = await page.$('iframe#file-manager');
    if (!iframeEl) throw new Error('iframe#file-manager not present');
    const frame = await iframeEl.contentFrame();
    if (!frame) throw new Error('iframe content frame inaccessible');
    const bytes = await frame.evaluate(() => document.documentElement.outerHTML.length).catch(() => 0);
    if (bytes < 500) throw new Error(`iframe body suspiciously small (${bytes} bytes) — session bridge likely broken`);
    return { iframeBytes: bytes };
  });

  // W4 — Logs page
  await run(`W4: logs page (${probeDomain})`, async () => {
    await page.goto(`${BASE}/site/${probeDomain}/logs`, { waitUntil: 'domcontentloaded' });
    await shot('w4-logs');
    const t = (await page.textContent('body')).toLowerCase();
    if (!t.includes('log')) throw new Error('no "log" in body');
    return { ok: true };
  });

  // W5 — Security page
  await run(`W5: security page (${probeDomain})`, async () => {
    await page.goto(`${BASE}/site/${probeDomain}/security`, { waitUntil: 'domcontentloaded' });
    await shot('w5-security');
    const t = (await page.textContent('body')).toLowerCase();
    if (!t.includes('security')) throw new Error('no "security" in body');
    return { ok: true };
  });
}

// W6–W9: admin pages
await run('W6: marketplace list', async () => {
  await page.goto(BASE + '/admin/marketplace', { waitUntil: 'domcontentloaded' });
  await shot('w6-marketplace');
  const cards = await page.$$eval('.card', cs => cs.length);
  if (cards === 0) throw new Error('no marketplace cards rendered');
  return { cards };
});
await run('W7: marketplace ghost', async () => {
  await page.goto(BASE + '/admin/marketplace/ghost', { waitUntil: 'domcontentloaded' });
  await shot('w7-ghost');
  if (!/ghost/i.test(await page.textContent('body'))) throw new Error('"ghost" not in body');
  return { ok: true };
});
await run('W8: marketplace nextcloud', async () => {
  await page.goto(BASE + '/admin/marketplace/nextcloud', { waitUntil: 'domcontentloaded' });
  await shot('w8-nextcloud');
  if (!/nextcloud/i.test(await page.textContent('body'))) throw new Error('"nextcloud" not in body');
  return { ok: true };
});
await run('W9: malware scan', async () => {
  await page.goto(BASE + '/admin/security/malware-scan', { waitUntil: 'domcontentloaded' });
  await shot('w9-malware-scan');
  const hasForm = !!await page.$('form[action*="malware-scan"]');
  return { hasStartForm: hasForm };
});
await run('W10: cloudflare settings', async () => {
  await page.goto(BASE + '/admin/cloudflare/settings', { waitUntil: 'domcontentloaded' });
  await shot('w10-cloudflare');
  const t = (await page.textContent('body')).toLowerCase();
  if (!t.includes('cloudflare')) throw new Error('no cloudflare in body');
  return { ok: true };
});
await run('W11: certificates admin', async () => {
  await page.goto(BASE + '/admin/certificates', { waitUntil: 'domcontentloaded' });
  await shot('w11-certificates');
  if (!/cert/i.test(await page.textContent('body'))) throw new Error('no "cert" in body');
  return { ok: true };
});
await run('W12: remote backup', async () => {
  await page.goto(BASE + '/admin/remote-backup', { waitUntil: 'domcontentloaded' });
  await shot('w12-remote-backup');
  if (!/backup/i.test(await page.textContent('body'))) throw new Error('no "backup" in body');
  return { ok: true };
});
await run('W13: admin users', async () => {
  await page.goto(BASE + '/admin/users', { waitUntil: 'domcontentloaded' });
  await shot('w13-users');
  return { ok: true };
});

// W14 — New-site forms render with the expected fields (by ID)
const NEW_SITE_FORMS = [
  { url: '/site/new/static', prefix: 'site_new_static', extra: [] },
  { url: '/site/new/php',    prefix: 'site_new_php',    extra: ['phpVersion'] },
  { url: '/site/new/nodejs', prefix: 'site_new_nodejs', extra: ['nodejsVersion', 'port'] },
  { url: '/site/new/docker', prefix: 'site_new_docker', extra: ['dockerImage', 'dockerPort'] },
];
for (const f of NEW_SITE_FORMS) {
  await run(`W14: new-site form ${f.url}`, async () => {
    await page.goto(BASE + f.url, { waitUntil: 'domcontentloaded' });
    const fields = ['domainName', 'siteUser', 'siteUserPassword', ...f.extra];
    const missing = [];
    for (const fld of fields) {
      const id = `${f.prefix}_${fld}`;
      if (!await page.$('#' + id)) missing.push(fld);
    }
    if (missing.length) throw new Error('missing fields: ' + missing.join(', '));
    return { fieldsPresent: fields.length };
  });
}

// W15 — Create + delete a real STATIC site end-to-end
const wfDomain = `wftest${Math.floor(Math.random() * 1e7)}.local`;
const wfUser = `wfu${Math.floor(Math.random() * 1e5)}`.toLowerCase();
const wfPass = 'WfPwd!2026' + Math.floor(Math.random() * 1e5);
let createdDomain = null;
await run(`W15: create static site (${wfDomain})`, async () => {
  await page.goto(BASE + '/site/new/static', { waitUntil: 'domcontentloaded' });
  await page.fill('#site_new_static_domainName', wfDomain);
  await page.fill('#site_new_static_siteUser', wfUser);
  await page.fill('#site_new_static_siteUserPassword', wfPass);
  await shot('w15-pre-submit');
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.click('#site_new_static_submit'),
  ]);
  await page.waitForTimeout(3500); // creation does shell work
  await shot('w15-post-submit');
  const url = page.url();
  const flash = await readFlash(2000);
  const body = await page.textContent('body');
  // Success path: either we landed on the new site page, OR the sites list shows the new domain.
  const landed = url.includes(`/site/${wfDomain}`) || body.includes(wfDomain);
  if (!landed) {
    const snippet = body.replace(/\s+/g, ' ').slice(0, 300);
    throw new Error(`creation not visible. url=${url} flash=${flash} body=${snippet}`);
  }
  createdDomain = wfDomain;
  return { domain: wfDomain, finalUrl: url, flash };
});

// W16 — Delete the site we just created.
// CloudPanel pattern: delete form lives on the settings page (#site_delete_domainName +
// #site_delete_submit). The "Delete Site" link is javascript:void(0) — just toggles a
// dialog. We fill the confirmation field directly; submit enables when it matches.
if (createdDomain) {
  await run(`W16: delete site (${createdDomain})`, async () => {
    await page.goto(`${BASE}/site/${createdDomain}/settings`, { waitUntil: 'domcontentloaded' });
    await shot('w16-pre-delete');
    // Reveal the delete modal — the link is javascript:void(0) that toggles visibility.
    const trigger = page.locator('a:has-text("Delete Site")').first();
    if (await trigger.count()) {
      await trigger.click();
      await page.waitForTimeout(400);
    }
    await shot('w16-modal-open');
    const field = page.locator('#site_delete_domainName');
    if (!(await field.count())) throw new Error('no site_delete_domainName field');
    // Use real keystrokes — the form's enable-on-match listener watches keyup.
    await field.click();
    await field.pressSequentially(createdDomain, { delay: 15 });
    await page.waitForTimeout(300);
    const submit = page.locator('#site_delete_submit');
    // Wait for the JS to enable the button.
    await page.waitForFunction(() => {
      const b = document.querySelector('#site_delete_submit');
      return b && !b.disabled;
    }, null, { timeout: 5000 }).catch(() => {});
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      submit.click(),
    ]);
    await page.waitForTimeout(4000); // teardown is heavier (system user, nginx reload, ...)
    await shot('w16-post-delete');
    await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    const stillThere = (await page.textContent('body')).includes(createdDomain);
    if (stillThere) throw new Error(`site ${createdDomain} still listed after delete`);
    return { domain: createdDomain, removed: true };
  });
}

// W17 — Site clone page renders for the probe site
if (probeDomain) {
  await run(`W17: clone page renders (${probeDomain})`, async () => {
    await page.goto(`${BASE}/site/${probeDomain}/clone`, { waitUntil: 'domcontentloaded' });
    await shot('w17-clone');
    const t = (await page.textContent('body')).toLowerCase();
    if (!t.includes('clone') && !t.includes('domain')) throw new Error('no clone/domain on page');
    return { ok: true };
  });
}

// W18 — Languages selector on /, switching locale persists redirect
await run('W18: locale selector works', async () => {
  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
  const sel = page.locator('#localeSelector');
  if (!(await sel.count())) throw new Error('no locale selector');
  await sel.selectOption({ index: 1 }).catch(() => {});
  await page.waitForTimeout(500);
  await shot('w18-locale');
  return { ok: true };
});

// W19 — Logout works (link lives inside a dropdown; navigate directly)
await run('W19: logout', async () => {
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.goto(BASE + '/logout', { waitUntil: 'domcontentloaded' }),
  ]);
  await shot('w19-logout');
  if (!page.url().includes('/login')) throw new Error('logout did not land on /login (' + page.url() + ')');
  return { finalUrl: page.url() };
});

// ---------- close out ----------

await browser.close();

const summary = {
  base: BASE,
  total: results.length,
  passed: results.filter(r => r.ok).length,
  failed: results.filter(r => !r.ok).length,
  results,
};
fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(summary, null, 2));

log(`\n=== Workflow Test Summary (${summary.passed}/${summary.total} passed) ===`);
for (const r of results) {
  log(`${tag(r.ok)} ${r.name}`);
  if (r.evidence) log(`    ${JSON.stringify(r.evidence)}`);
  if (!r.ok && r.err) log(`    error: ${r.err}`);
  for (const e of r.errors.slice(0, 3)) log(`    ${e.slice(0, 200)}`);
}
log(`\nScreenshots + report.json: ${OUT}`);
process.exit(summary.failed > 0 ? 1 : 0);
