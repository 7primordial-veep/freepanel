// Shared env loader for the Playwright test scripts.
// Required env vars:
//   PANEL_BASE   — e.g. https://45.145.226.137:8443
//   PANEL_USER   — panel admin username
//   PANEL_PASS   — panel admin password
//
// Set them in your shell or in tools/.env (gitignored). Example:
//   export PANEL_BASE=https://your-panel.example.com:8443
//   export PANEL_USER=cmsadmin
//   export PANEL_PASS='your-rotating-password'

import fs from 'node:fs';
import path from 'node:path';

// Minimal .env loader — looks for tools/.env next to this file.
const envFile = path.join(import.meta.dirname || '.', '.env');
if (fs.existsSync(envFile)) {
  for (const line of fs.readFileSync(envFile, 'utf8').split(/\r?\n/)) {
    const m = line.match(/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*?)\s*$/);
    if (m && !process.env[m[1]]) {
      let v = m[2];
      if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) v = v.slice(1, -1);
      process.env[m[1]] = v;
    }
  }
}

function need(name) {
  const v = process.env[name];
  if (!v) {
    console.error(`Missing required env var ${name}. Set PANEL_BASE / PANEL_USER / PANEL_PASS (see tools/panel-env.mjs).`);
    process.exit(2);
  }
  return v;
}

export const BASE = need('PANEL_BASE');
export const USER = need('PANEL_USER');
export const PASS = need('PANEL_PASS');
