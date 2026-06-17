# freepanel

> Unofficial fork of CloudPanel CE 2.5.3.

> ⚠️ **This is NOT the official CloudPanel.** This is an independent fork of CloudPanel CE 2.5.3 with security patches and added features. It is not endorsed by, affiliated with, or supported by CloudPanel GmbH or the upstream `cloudpanel-io` project. For the official product, go to <https://www.cloudpanel.io>.
>
> "CloudPanel" is used here as a factual reference to the upstream code this fork is derived from. All upstream trademarks remain with their owners.

Git-based deployment of a patched CloudPanel CE 2.5.3 codebase. Replaces the upstream `apt install cloudpanel` flow — `install.sh` clones this repo and lays the app down directly.

## What's different from upstream

- **Source patched** — ~95 security/correctness/bug findings addressed across `source/src/` and `source/templates/` over two review passes. See [`docs/code-review/2026-05-28-full-codebase-review.md`](docs/code-review/2026-05-28-full-codebase-review.md).
- **Installer forked** — `install.sh` deploys from this git repo instead of `packages.cloudpanel.io`. Upstream is preserved as `original-install.sh` for diffing.
- **Vhost templates bundled** — `source/vhost-templates/` ships in-repo; `clpctl vhost-templates:import` reads from disk, no external clone needed.
- **New features** — see [Added features](#added-features) below.
- **No dependency on `packages.cloudpanel.io`.** OS packages come from community-maintained upstream apt sources: PHP + nginx-mainline from [Ondřej Surý](https://packages.sury.org), Varnish 7 from [varnishcache packagecloud](https://packagecloud.io/varnishcache/varnish70), Percona via `percona-release`, MariaDB from [mariadb.org](https://mariadb.org), proftpd/ufw from distro.

## Added features

Sixteen features added on top of upstream 2.5.3.

**XS — full implementations**
- Brotli + Zstd compression on by default in vhost templates (graceful degrade if modules missing).
- WordPress install locale dropdown (was hardcoded `en_US`).

**S — minimum-viable**
- Certificate dashboard: expiry + last-renew status + Renew-now button.
- Security headers wizard: per-site CSP / HSTS / Permissions-Policy via new vhost processor.
- WordPress auto-updates: per-site `none` / `security` / `all` toggle, daily cron via wp-cli.

**M — minimum-viable**
- Site clone (CLI + UI): `clpctl site:clone` and a Clone button on the site page.
- ClamAV on-demand malware scanner: per-site scan, `ScanResult` entity, results in admin UI.
- Fail2ban web UI: status, banned IPs, unban.
- GeoIP country blocking per site: nginx GeoIP integration, per-site allowlist form.
- ACME DNS-01 wildcard certs: Cloudflare provider for v1.
- Cloudflare integration: auto DNS record on site create, cache purge, WAF toggle.
- Automated backup test-restore: weekly cron picks a rotating site, downloads + validates + logs.

**L / XL — scaffolds (marked `// ponytail: stub`, wired but not feature-complete)**
- One-click app marketplace (WordPress real, others "coming soon" placeholders).
- Per-site resource quotas (Site entity fields + systemd-slice writer).
- Docker site type (`TYPE_DOCKER`, minimal creator/updater, reverse-proxy vhost).
- Web file manager (`FileManagerController` + minimal Twig UI).

## Prerequisites

- Fresh **Ubuntu 24.04 LTS** server (other versions: see `checkOperatingSystem` in `install.sh`).
- Root SSH access.
- This repo is public — no token required to clone.

## Install

On a fresh Ubuntu 24.04 box, as root:

```bash
curl -sS https://raw.githubusercontent.com/7primordial-veep/freepanel/master/install.sh | bash
```

That's it. The installer self-bootstraps git, clones this repo into `/tmp/cloudpanel-repo`, and lays everything down. Defaults: `GIT_BRANCH=master`, `DB_ENGINE=MYSQL_8.4`, `SWAP=true`.

With options:
```bash
curl -sS https://raw.githubusercontent.com/7primordial-veep/freepanel/master/install.sh \
  | DB_ENGINE=MARIADB_11.4 CLOUD=hetzner bash
```

Or run from a local checkout (skips the clone):
```bash
git clone https://github.com/7primordial-veep/freepanel.git && cd freepanel && bash install.sh
```

Optional env vars:
- `DB_ENGINE` — `MYSQL_8.4` (default), `MYSQL_8.0`, `MARIADB_10.11`, `MARIADB_11.4`, `MARIADB_11.8`.
- `CLOUD` — `aws` | `do` | `hetzner` | `gce` | `vultr` to register the install with a provider for snapshot management.
- `SWAP=false` to skip swap creation.

Install takes ~10–15 minutes. On success, the panel is at `https://<server-ip>:8443`.

## Repo layout

```
source/              patched Symfony 6 / PHP 8.1 application (the panel itself)
source/vhost-templates/  bundled nginx vhost templates (imported by clpctl)
services/            nginx + php-fpm + php.ini configs deployed under /home/clp/services/
system/              clpctl + clp-update + systemd units + sudoers fragments
package-data/        composer phar, motd, logrotate unit, apt key, crontabs
scripts/             create_backup.sh (deployed under /home/clp/scripts/)
tools/               dev-only: deobfuscate.php (not deployed)
install.sh           forked installer (git-based deploy)
original-install.sh  upstream installer (kept for diffing only — not used)
docs/                code review report, design specs
```

## Deployment flow

`install.sh` runs in this order — full driver at the bottom of the script:

1. OS detection, port-conflict + DB-engine + hostname checks
2. Apt prereqs, locale generation, removal of unwanted Debian packages
3. MySQL/MariaDB install per `DB_ENGINE`
4. Apt sources added: Surý (PHP + nginx-mainline + brotli/zstd modules), packagecloud varnish-7
5. PHP 8.1–8.4 + nginx + proftpd + ufw installed
6. Repo cloned (skipped if already running from a checkout)
7. `/home/clp` user + dir tree created
8. `source/` copied to `/home/clp/htdocs/app/files/`, services/ + system/ files laid down
9. MySQL secured with random root password (stored at `/root/.mysql-credentials`)
10. Doctrine: drop/create db + schema + fixtures + migrations metadata
11. systemd units enabled (`clp-nginx`, `clp-php-fpm`, `clp-agent`)
12. Crontabs deployed (`clp`, `clp-aws`, `clp-do`, `clp-gce`, `clp-hetzner`, `clp-vultr`)
13. UFW firewall, motd, logrotate, proftpd `MasqueradeAddress`
14. Permissions reset (700 on `/home/clp/`, 770 inside, etc.)

## Updating an installed server

```bash
cd /tmp/cloudpanel-repo   # or wherever you cloned
git pull
bash install.sh           # idempotent — re-applies layout, runs migrations
```

⚠ This wipes the SQLite DB (`drop --force` in step 10). Don't run `install.sh` on a production box after the first install — use a targeted patch script instead.

## Security patches applied

Highlights — full list in [`docs/code-review/2026-05-28-full-codebase-review.md`](docs/code-review/2026-05-28-full-codebase-review.md):

- **Critical**: pre-auth path-traversal arbitrary file deletion in `AutoLoginAuthenticator` (token now whitelist-validated).
- **High**: log-viewer path traversal; `rootDirectory` traversal; nginx config injection (blocked bots + Varnish); `CommandExecutor` double-execution; TLS verification enabled on 7 outbound clients (DO, Hetzner, Vultr, Dropbox ×2, ACME, remote DB); `PasswordGenerator` → CSPRNG; basic-auth → SHA-512 crypt with random salt; CSRF on 10 admin GET endpoints (controllers + Twig templates).
- **Medium**: API IP-bypass removed; `addslashes` SQLi → DBAL `quote()`; rclone.conf + GCE key perms `0600`; `--path` dead code + SSH-user overwrite in `SystemPermissionsReset`; GCE pagination; `RequestListener` unconditional-redirect regression; `Database{Import,Export}` inverted-auth.
- **Low**: validator info-disclosure; missing exception imports; null/JSON guards; UFW `escapeshellarg`; cron CR/LF stripping; Dropbox raw-body-as-token; `phpMyAdmin` duplicate guard; `ExceptionListener` open-redirect; AWS secret field → `PasswordType`.

## License

CloudPanel itself is proprietary (CloudPanel CE Free License) — see `source/composer.json` and upstream terms.
