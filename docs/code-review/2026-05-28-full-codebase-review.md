# CloudPanel 2.5.3 — Full Codebase Security & Correctness Review

_Date: 2026-05-28 · Scope: first-party `source/src` (488 PHP files) · Method: 17 subsystem reviewers + adversarial verification of every medium+ finding (66 agents, 74 raw findings → 65 confirmed)._

> Excluded as third-party: `source/vendor`, `source/public/phpmyadmin`, `source/public/file-manager`.

## Overall posture

Functional but security-fragile: concentrated critical/high gaps amplified by a root execution context and a pervasive disabled-TLS / missing-CSRF pattern, requiring urgent targeted hardening.

## Executive summary

CloudPanel runs as root and manages web servers, databases, SSL, cloud-provider snapshots, and remote backups, so the impact of any flaw is amplified to full-host or full-account compromise. The review surfaced multiple confirmed critical/high issues that are individually exploitable: an unauthenticated path-traversal/arbitrary-file-deletion via the autologin token, a root-level path-traversal arbitrary file read in the site log viewer, unsanitized user-controlled values concatenated into privileged filesystem paths and nginx config, and credentials generated with the non-cryptographic rand(). A pervasive systemic weakness is disabled TLS certificate verification across nearly every outbound integration (DigitalOcean, Hetzner, Vultr, Dropbox, Let's Encrypt/ACME, and remote DB connections), which exposes bearer tokens, API keys, and ACME flows to trivial man-in-the-middle interception. State-changing destructive admin actions (delete database server, delete cloud snapshots/AMIs across all five providers, set active DB server) are routed through unprotected GET requests with no CSRF token, making one-click/forced-request attacks viable against an authenticated admin. Several command-construction paths place secrets and user input on the command line or into root-owned config (cron.d, rclone.conf, GCE/SQL identifiers) without proper escaping or restrictive permissions. Correctness defects compound the risk: a foreground command is executed twice, broken/inverted authorization in db:import and db:export, and broken pagination/owner-overwrite logic in permission reset and GCE snapshot listing. The codebase shows recurring carelessness around trust boundaries, secret handling, and transport security rather than isolated one-off mistakes, though the high-severity items are concentrated and individually fixable.

## Severity counts (effective, post-verification)

| Severity | Count |
|---|---|
| critical | 1 |
| high | 14 |
| medium | 17 |
| low | 33 |
| **total** | **65** |

Findings by module: system-core (3), system-shell-commands (1), clpctl-site-user-data (4), clpctl-rest (4), controllers-frontend (2), controllers-admin (9), security (5), site-core (2), site-nginx-varnish (2), site-phpfpm-ssl-app (2), backup (7), cloud-providers (5), entities (1), repositories-db (3), forms (2), validators (8), services-infra (5)

## Top risks (fix first)

1. Unauthenticated path traversal / arbitrary file deletion via the autologin token (AutoLoginAuthenticator.php) — remote, pre-auth, root impact; fix path resolution and remove the unconditional finally-unlink.
2. Path traversal in the site log viewer (Frontend/SitesController.php) allows arbitrary file read as root — sanitize/whitelist the requested log path.
3. Credentials generated with non-cryptographic rand() (Util/PasswordGenerator.php) — switch to random_bytes/random_int for all generated passwords and secrets.
4. TLS verification disabled across all outbound clients (DigitalOcean, Hetzner, Vultr, Dropbox, ACME/Let's Encrypt, remote DB) — bearer tokens/API keys are MITM-exposable; enable certificate verification everywhere.
5. Unsanitized rootDirectory concatenated into privileged filesystem paths in Site/Updater.php — validate/canonicalize to prevent path traversal and privilege escalation.
6. Destructive admin actions via unprotected GET with no CSRF (delete DB server, set active DB server, delete snapshots/AMIs across AWS/DO/GCE/Hetzner/Vultr) — convert to POST with CSRF tokens.
7. Config injection: blocked bot name and Varnish server value interpolated into nginx config/proxy_pass without validation — validate inputs before templating.
8. API auth bypass for whitelisted source IPs (ApiTokenAuthenticator.php) — IP allowlisting grants token-free API access; reconsider or require token regardless.
9. cron.d line built with unescaped, newline-capable command (Entity/CronJob.php) — newline injection into a root cron file enables privilege escalation; sanitize/escape.
10. Foreground commands executed twice in CommandExecutor.php (run() then unconditional start()) — fix the double-execution which can duplicate side effects.
11. Broken/inverted non-root authorization in db:export (medium) and db:import (DatabaseImportCommand/DatabaseExportCommand) causing NULL deref and confused access control.
12. Secrets written without restrictive permissions: rclone.conf cleartext creds and world-readable Google service-account key (Backup/Rclone.php) — set 0600 before/at write time.
13. DB password escaped with addslashes() instead of proper quoting (Database/Connection.php) — charset-dependent SQL injection; use parameterization/driver quoting.

## Systemic themes

### Disabled TLS certificate verification is the dominant systemic flaw

At least seven confirmed findings disable TLS peer verification on outbound HTTPS: DigitalOcean, Hetzner, and Vultr API clients (all sending bearer tokens/API keys), Dropbox refresh-token and OAuth-code exchanges, the Let's Encrypt/ACME client, and remote DB connections (even when a CA cert is configured). Any network-positioned attacker can MITM these channels to steal long-lived cloud credentials, hijack the ACME flow, or intercept DB traffic. This is a copy-paste anti-pattern that should be fixed centrally.

### State-changing destructive actions exposed over unprotected GET (CSRF)

Eight confirmed findings show admin destructive operations triggered by GET with no CSRF token: delete database server, set active DB server, and delete snapshots/AMIs across all five cloud providers (AWS deregister AMI + delete snapshots, DO/GCE/Hetzner/Vultr deleteSnapshot), plus notification mutations. An authenticated admin visiting a malicious page (or a prefetcher/crawler hitting a link) can be forced to destroy infrastructure. All should be POST + CSRF-protected, and ideally also require explicit confirmation.

### Untrusted input crossing into privileged execution and config without sanitization

User-controlled values flow unsanitized into root-context sinks: rootDirectory into privileged filesystem paths, blocked-bot name and Varnish server into nginx config/proxy_pass, log path into root file reads, autologin token into a filesystem path used for deletion, cron command into an /etc/cron.d line (newline-capable), and DB/user identifiers raw into SQL. Because the panel runs as root, these trust-boundary failures escalate directly to host compromise. A consistent validate-and-canonicalize-at-the-edge discipline is missing.

### Weak and improper cryptography / secret handling

Credentials are generated with non-cryptographic rand(); basic-auth passwords are hashed with weak DES crypt using a password-derived salt; DB password escaping uses addslashes() (charset-dependent SQLi) rather than safe quoting. Secrets are also mishandled at rest and in transit: rclone.conf written with cleartext credentials and no restrictive permissions, Google service-account key written world-readable (chmod applied before the file exists), and command strings/exception messages that embed secrets via the process list. Cryptographic and secret-storage choices are repeatedly the weakest viable option.

### Authorization and command-execution correctness defects

Several confirmed correctness bugs undermine intended access control and reliable execution: inverted/broken non-root authorization in db:import and db:export (NULL deref, confused access control), the SystemPermissionsResetCommand ignoring --path (dead validation) and overwriting the SSH-user branch so chown runs with the wrong owner, foreground commands executed twice in CommandExecutor, and broken GCE snapshot pagination reading only the first page. These erode the reliability of privileged operations and the authorization model.

### Information disclosure via raw exception messages surfaced to users

Multiple validators and flows render raw backend exception messages to end users (DatabaseNameValidator confirmed, plus DB-user-name, rclone/SFTP, Dropbox, and custom-rclone validators, and the open-redirect/exception listener). These can leak connection strings, configuration, or credential detail and aid attacker reconnaissance. Errors should be logged server-side and replaced with generic user-facing messages.

## Critical findings (1)

### [CRITICAL] Unauthenticated path traversal / arbitrary file deletion via autologin token

- **File:** `source/src/Security/AutoLoginAuthenticator.php:67-70, 74-78, 62-65`
- **Module:** security · **Category:** path-traversal
- **Verdict:** real (confidence 0.85)

**Issue.** The autologin `token` is taken directly from the request (`$request->get("token")` in authenticate(), reached from supports() which only checks the path begins with "autologin" and the token is non-empty) and interpolated into a filesystem path with no sanitization: `getTokenFile()` builds `sprintf("%s/var/.token_%s", projectDir, $token)`. This path is then passed to file_exists()/file_get_contents() (getTokenData) and, critically, to `@unlink($tokenFile)` inside a `finally` block (line 64) that runs on EVERY request regardless of success/failure. Because the token may contain `../`, an attacker can traverse outside the var/ directory. The `^/autologin` route is PUBLIC_ACCESS (config/packages/security.yaml line 30), so this is reachable pre-authentication, and the panel runs as root. A request such as `/autologin?token=../../../<path>` results in `@unlink` of an attacker-chosen file as root (arbitrary file deletion / DoS / tampering), and file_get_contents reads any attacker-chosen JSON-parseable file as the authentication source (allowing a forged token file elsewhere on disk to be used to authenticate as any active user). The token is fully unvalidated (no hex/charset/length check anywhere in the codebase).

**Fix.** Validate the token against a strict whitelist before building any path, e.g. reject unless it matches `^[A-Fa-f0-9]{32,}$` (or use basename() and verify the resolved realpath() is still inside the var/ directory). Do not perform the unlink unless the token passed validation, and never let attacker input contribute path separators to a filesystem operation performed as root.

<details><summary>Verification evidence</summary>

Confirmed against actual code. AutoLoginAuthenticator.php: supports() (line 31) gates only on path starting with "autologin" and a non-empty token; the ^/autologin route is PUBLIC_ACCESS (security.yaml line 30) and the authenticator is wired into the main firewall (security.yaml lines 19-21), so it executes pre-authentication. authenticate() reads $request->get("token") (line 38), a fully attacker-controlled query/body param NOT subject to getPathInfo() normalization. getTokenFile() (line 69) interpolates the raw token via sprintf("%s/var/.token_%s", projectDir, $token) with zero sanitization. I confirmed by grep that no basename/str_replace/preg_match/ctype_xdigit/length or charset validation is applied to this token anywhere in src/. The resulting path reaches file_exists/file_get_contents (lines 75/78) and, critically, @unlink($tokenFile) in a finally block (line 64) that runs on EVERY request. A token containing ../ traverses out of var/. The panel runs as root. This yields: (1) a read primitive — any JSON file matching the .token_* basename with userName/expiration can authenticate the attacker as that active user (auth bypass via forged token file); (2) a delete primitive on traversed paths. One nuance tempering full confidence: the .token_ prefix is hard-coded into the filename portion, so the unlink/read targets are constrained to files whose basename begins with ".token_" — it is NOT truly arbitrary-filename deletion (cannot directly unlink /etc/passwd), so that part of the description is somewhat overstated. However the directory-traversal-out-of-var defect, the unauthenticated reachability, the root context, and the forged-token-file auth-bypass read primitive all genuinely hold. Severity critical is justified by the auth-bypass vector.

</details>

## High findings (14)

### [HIGH] TLS certificate verification disabled when exchanging Dropbox OAuth access code

- **File:** `source/src/Backup/Dropbox/AccessCodeValidator.php:58`
- **Module:** backup · **Category:** crypto
- **Verdict:** real (confidence 0.9)

**Issue.** Same defect as Client.php: "verify" => false disables TLS certificate validation for the POST that exchanges the user's OAuth `code` for an access_token and refresh_token. A MITM can intercept the OAuth code or substitute attacker-controlled tokens that are then persisted and used for all subsequent backups.

**Fix.** Enable TLS verification (remove "verify" => false).

<details><summary>Verification evidence</summary>

Confirmed at source/src/Backup/Dropbox/AccessCodeValidator.php line 58: getHttpClient() builds the Guzzle config as ["timeout" => 10, "verify" => false, ...]. This client is used in isValid() (line 17-22) to POST the user's OAuth `code` to https://dropbox-auth.cloudpanel.io/ and parse the returned access_token/refresh_token, which are then persisted (setToken/setRefreshToken, and in the caller stored into the session). The input is attacker-reachable: caller src/Validator/Constraints/DropboxAccessCodeValidator.php takes the `accessCode` from a user-submitted form and immediately invokes isValid(); on success the returned token/refreshToken are written to the session and used to build the rclone config for actual backups. With "verify" => false the TLS certificate of the endpoint is not validated, so an on-path MITM can impersonate dropbox-auth.cloudpanel.io, capture the OAuth code, and/or substitute attacker-controlled tokens that are persisted and used for all subsequent backups. No mitigation exists: the connection is HTTPS but cert pinning/verification is explicitly disabled, and there is no out-of-band integrity check on the response. The defect as described holds exactly; it is the same crypto defect class as Client.php. Severity nuance: exploitation requires an active network MITM position against the panel's outbound connection, so it is not trivially remote, but the impact (token/code interception and substitution feeding the backup pipeline) is significant — consistent with high.

</details>

### [HIGH] TLS certificate verification disabled when exchanging Dropbox refresh token

- **File:** `source/src/Backup/Dropbox/Client.php:36`
- **Module:** backup · **Category:** crypto
- **Verdict:** real (confidence 0.9)

**Issue.** getHttpClient() configures the Guzzle client with "verify" => false, then getAccessToken() POSTs the long-lived Dropbox refreshToken to https://dropbox-auth.cloudpanel.io/ and receives back an access token. With certificate verification disabled, a network man-in-the-middle can impersonate the endpoint, capture the refresh token (full account access to the user's Dropbox backups) and/or return an attacker-controlled access token that is then written into rclone.conf. AccessCodeValidator.php line 58 has the identical flaw for the initial OAuth code exchange.

**Fix.** Remove "verify" => false (default to true) so the server certificate of dropbox-auth.cloudpanel.io is validated. If a custom CA bundle is needed, point "verify" at it instead of disabling validation.

<details><summary>Verification evidence</summary>

Confirmed in actual code. source/src/Backup/Dropbox/Client.php line 36: getHttpClient() builds the Guzzle client config with "verify" => false, disabling TLS certificate verification. getAccessToken() (line 12-32) then POSTs ["refreshToken" => $refreshToken] to https://dropbox-auth.cloudpanel.io/ (ENDPOINT, line 10) over that client and parses the JSON response for "access_token". The refresh token IS a long-lived high-value secret: RemoteBackupCreateCommand::refreshDropboxAccessToken() (line 318-326) pulls it from config ("remote_backup_refresh_token") and the returned access token is written straight into rclone.conf via DropboxConfigTemplate -> RcloneConfigBuilder -> Rclone::writeConfig (lines 330-335). With verification off, an active network MITM on the HTTPS path to dropbox-auth.cloudpanel.io can impersonate the endpoint to capture the refresh token (full Dropbox backup account access) and/or return an attacker-controlled access token that gets persisted to rclone config, redirecting/exposing backups. The identical flaw is present in AccessCodeValidator.php line 58 for the initial OAuth code exchange (which additionally exposes the refresh_token in the response, line 32). This is a genuine, exploitable TLS verification defect on a sensitive credential exchange — not a false positive. Caveats lowering confidence slightly: exploitation requires an active on-path MITM with the ability to present a forged cert for the real hostname (not trivially remote/unauthenticated from the app's perspective), and the operation runs in a backup CLI/cron context. Severity remains high given the secret exposed (refresh token = full backup account compromise) and the trivial code fix.

</details>

### [HIGH] DigitalOcean API client disables TLS certificate verification while sending bearer token

- **File:** `source/src/Do/Client.php:280`
- **Module:** cloud-providers · **Category:** crypto
- **Verdict:** real (confidence 0.92)

**Issue.** getHttpClient() builds the Guzzle client for https://api.digitalocean.com with "verify" => false and an "Authorization: Bearer <token>" header containing the DigitalOcean account API token. With certificate validation disabled, a network man-in-the-middle can present any certificate, terminate the TLS connection, and capture the account-wide API token (which can create/delete droplets, snapshots and volumes). This is over the public Internet, not the link-local metadata endpoint, so verification must not be disabled.

**Fix.** Remove "verify" => false for the api.digitalocean.com client (default to true, or point to the system CA bundle). If a separate, unauthenticated metadata client genuinely needs relaxed verification, keep that scoped to the 169.254.169.254 client only.

<details><summary>Verification evidence</summary>

Confirmed at source/src/Do/Client.php:280. getHttpClient() builds a single Guzzle client with "verify" => false and an "Authorization: Bearer <token>" header. This same client is used for all calls to self::API_ENDPOINT = "https://api.digitalocean.com/v2/" — getDroplet (l60), getVolume (l80), getDropletSnapshots (l113), deleteDropletSnapshot (l179), createDropletSnapshot (l194), createVolumeSnapshot (l210), getVolumeSnapshots (l222), deleteVolumeSnapshot (l256). The token is a genuine account-wide DO API token: InitializeListener.php:174 sets it from configManager->get("do_access_token") (also DoController.php:254), and DoInstance wraps this client. So a real bearer token is transmitted over public-Internet TLS with certificate validation disabled, allowing a network MITM to present any cert, terminate TLS, and capture a token that can create/delete droplets, snapshots, and volumes. The finding correctly distinguishes the public API endpoint from the link-local metadata endpoint (http://169.254.169.254, l14/289), which is plain HTTP and unaffected. No compensating control exists — verify=>false is unconditional and applies to all API calls. The defect holds as described. The only reason confidence is not higher is that exploitation requires an active on-path MITM (outbound server-to-DO traffic), but that is precisely the threat TLS verification defends against, so the crypto defect is real regardless.

</details>

### [HIGH] Hetzner API client disables TLS certificate verification while sending bearer token

- **File:** `source/src/Hetzner/Client.php:219`
- **Module:** cloud-providers · **Category:** crypto
- **Verdict:** real (confidence 0.92)

**Issue.** getHttpClient() configures the client for https://api.hetzner.cloud with "verify" => false and "Authorization: Bearer <token>". The Hetzner Cloud API token is account-wide; disabling certificate verification exposes it to interception by a MITM on the path to the public API endpoint.

**Fix.** Enable TLS verification for the authenticated api.hetzner.cloud client. Limit any relaxed verification to the link-local metadata client only.

<details><summary>Verification evidence</summary>

Confirmed in source/src/Hetzner/Client.php line 219: getHttpClient() builds a Guzzle client with config ["timeout" => ..., "verify" => false, "headers" => ["Authorization" => sprintf("Bearer %s", $token)]]. This client is used (line 43) to request API_ENDPOINT = "https://api.hetzner.cloud/v1/" (line 13) — a public internet HTTPS endpoint. "verify" => false disables TLS certificate verification, so the account-wide bearer token is exposed to MITM interception on the path to the public API. The token is a real credential: it is entered by an admin and passed via setToken() from Controller/Admin/HetznerController.php:239 (validateApiToken) and EventListener/InitializeListener.php:218, not a dev placeholder. The separate metaDataHttpClient at line 210 also uses verify=false but targets a link-local http://169.254.169.254 metadata endpoint and carries no token, so that one is benign — the finding correctly targets line 219. Access control (admin-only) does not mitigate a network-path MITM, so no offsetting control neutralizes the defect. The only minor uncertainty is whether any global Guzzle/CA override is in effect, but the explicit per-client verify=>false would take precedence regardless.

</details>

### [HIGH] Vultr API client disables TLS certificate verification while sending bearer API key

- **File:** `source/src/Vultr/Client.php:243`
- **Module:** cloud-providers · **Category:** crypto
- **Verdict:** real (confidence 0.93)

**Issue.** getHttpClient() configures the client for https://api.vultr.com with "verify" => false and "Authorization: Bearer <apiKey>". The Vultr API key is account-wide; sending it over a TLS connection with certificate validation disabled allows a man-in-the-middle to steal the key.

**Fix.** Enable TLS verification for the authenticated api.vultr.com client; scope any relaxed verification to the metadata endpoint only.

<details><summary>Verification evidence</summary>

Confirmed at source/src/Vultr/Client.php:243. getHttpClient() builds a Guzzle config with "verify" => false alongside an "Authorization: Bearer <apiKey>" header. The client's base endpoint (line 15) is the real public HTTPS API https://api.vultr.com/v2/, not the link-local metadata service. The bearer value is a genuine account-wide Vultr API key supplied via setApiKey() (line 32) and reached from live call sites: Controller\Admin\VultrController.php:241 and EventListener\InitializeListener.php:233. Disabling certificate verification turns off cert chain and hostname validation, so an active man-in-the-middle can present a forged certificate, terminate the TLS connection, and harvest the bearer token on every request (createSnapshot, getInstanceData, getSnapshots, etc.). No mitigating validation/escaping/access-control applies — this is a TLS-layer issue, and the surrounding logic does nothing to protect the key in transit. Caveat: exploitation requires an on-path network attacker, not a remote unauthenticated attacker, so it is not trivially exploitable. Note this is a systemic pattern (Do\Client.php:280, Hetzner\Client.php:219 do the same with their tokens), but the finding as scoped to Vultr line 243 is factually accurate.

</details>

### [HIGH] deleteDatabaseServer performs destructive action via unprotected GET (no CSRF token)

- **File:** `source/src/Controller/Admin/SettingsController.php:276-295`
- **Module:** controllers-admin · **Category:** csrf
- **Verdict:** real (confidence 0.93)

**Issue.** deleteDatabaseServer() reads the entity id straight from the request ((int) $request->get("id")) and deletes the DatabaseServer with no CSRF check. The route clp_admin_database_server_delete (routes.yaml:370 /admin/database-server/delete/{id}) declares no methods restriction, so it is reachable via a simple GET. An attacker who lures a logged-in admin to a crafted URL/<img> can delete configured database servers. Sibling state-changing GET endpoints in this codebase explicitly call $this->checkCsrfToken(...) (e.g. FirewallController::deleteRule, UserController::delete, InstanceController::restartService), confirming CSRF protection is the intended pattern and is missing here.

**Fix.** Call $this->checkCsrfToken($request, "delete-database-server") (matching the token rendered in the template) before deleting, and/or restrict the route to POST via methods: [POST].

<details><summary>Verification evidence</summary>

Confirmed in SettingsController.php:276-295: deleteDatabaseServer() reads $id = (int)$request->get("id"), loads the entity, and calls deleteEntity() with NO CSRF validation. The route clp_admin_database_server_delete (routes.yaml:370-372, path /admin/database-server/delete/{id}) declares no `methods` restriction, so it is reachable via GET — exploitable through an <img>/link CSRF against a logged-in admin. The codebase's established protection pattern is clearly violated: checkCsrfToken() (Controller.php:59-66, reads ?token and calls isCsrfTokenValid) is invoked by every sibling destructive GET endpoint — FirewallController::deleteRule, UserController::delete, InstanceController::restartService, RemoteBackupController delete — and their Twig templates generate links WITH 'token': csrf_token('...'). The database-server delete link (Database-Servers/edit.html.twig:58) generates NO token, and the controller never validates one. The ^/admin ROLE_ADMIN firewall does not mitigate CSRF (attack rides the victim's authenticated session); the in-handler isDefault()/isActive() checks and the client-side confirm() dialog only narrow the target set / are UI-only and do not stop a forged request. Confidence slightly below max only because exploitation requires a valid non-default, non-active server id, a minor bar that does not neutralize the flaw.

</details>

### [HIGH] Path traversal in log viewer allows arbitrary file read as root

- **File:** `source/src/Controller/Frontend/SitesController.php:170-176`
- **Module:** controllers-frontend · **Category:** path-traversal
- **Verdict:** real (confidence 0.85)

**Issue.** In logFileContent() the `service` parameter is sanitized with basename() (line 174) but the `logFile` parameter is NOT. The raw, attacker-controlled `$logfileName` is interpolated directly into the file path at line 175: `$logfile = sprintf("/home/%s/logs/%s/%s", $site->getUser(), $service, $logfileName);`. That path is passed to LogfileReader -> TailCommand, which runs `/usr/bin/sudo /usr/bin/tail <file>` (file is escapeshellarg-escaped so no shell injection, but traversal sequences survive). A request like `GET /site/{ownedDomain}/log-file-content?service=nginx&logFile=../../../../etc/shadow&numberOfLines=200` produces `/home/<user>/logs/nginx/../../../../etc/shadow`, which sudo tail reads as root and returns to the user in the JSON `logMessagesHtml`. Any authenticated user who owns at least one site (getSite() enforces only site ownership, not admin) can read arbitrary files on the host as root: /etc/shadow, other tenants' SSH/TLS private keys, the panel's db.sq3, etc. The route clp_site_log_file_content has no HTTP method restriction, so this is reachable via GET.

**Fix.** Sanitize `$logfileName` the same way as `$service`: `$logfileName = basename($logfileName);` before building the path, and/or validate it against an allow-list of known log filenames returned by LogsFinder. Consider also resolving the final path with realpath() and verifying it stays within `/home/<user>/logs/<service>/`.

<details><summary>Verification evidence</summary>

The path-traversal defect is genuinely present. In logFileContent() (SitesController.php:164-206), the `service` param gets basename() sanitization (line 174) but `logFile` does NOT — the raw `$logfileName = trim($request->get("logFile"))` (line 170) is interpolated directly into `sprintf("/home/%s/logs/%s/%s", $site->getUser(), $service, $logfileName)` (line 175). That path flows to LogfileReader -> TailCommand, which runs `/usr/bin/sudo /usr/bin/tail <file>`. escapeshellarg() (TailCommand.php:15) blocks shell injection but preserves `../` sequences, which the filesystem resolves, so sudo tail reads the target as root. Access control is confirmed weak: getSite() (line 2252) only checks site ownership for ROLE_USER (`$user->hasSite($siteEntity)`), no admin gate, so any authenticated user owning a site reaches it. The route clp_site_log_file_content (routes.yaml:206) has no methods restriction, so GET works. The sibling loadLogfilesForService() applies basename() to its service param, showing the missing-basename on logFile is a real omission. ONE caveat lowers confidence from certainty: the finding overstates exfiltration impact. The file content is not returned verbatim — it is filtered through grok parsers (NginxErrorLogParser/NginxAccessLogParser/PhpFpmErrorLogParser) that only emit lines matching strict log-format patterns (parser line 34/35). Arbitrary content like /etc/shadow, PEM private keys, or binary db.sq3 would not match the grok patterns and would be silently dropped, yielding empty logMessagesHtml. So verbatim dumping of the specific cited files is not reliable. Nonetheless the core vulnerability — unsanitized attacker-controlled path traversal reaching a root-privileged file read with only site-ownership auth — is real and high severity (read-as-root primitive, file-existence oracle, and leakage of any log-formatted content host-wide).

</details>

### [HIGH] Broad catch masks failure cause and finally-unlink always deletes the resolved path

- **File:** `source/src/Security/AutoLoginAuthenticator.php:60-66`
- **Module:** security · **Category:** error-handling
- **Verdict:** real (confidence 0.85) · severity adjusted medium → high

**Issue.** The whole authenticate() body is wrapped in `catch (\Exception $e)` which rethrows as CustomUserMessageAuthenticationException($e->getMessage()), and the `finally` block unconditionally calls `@unlink($this->getTokenFile($token))`. Combined with the unsanitized token (see the path-traversal finding), the finally block means the delete fires even when authentication fails or the file is not a legitimate token file, so a single crafted request both attempts auth and deletes an arbitrary path. Independently, rethrowing the raw exception message into the auth-failure response (onAuthenticationFailure echoes the message) can leak internal details such as filesystem error text.

**Fix.** Only unlink the token file after the token has been validated as a legitimate, in-bounds token and only on the success path (or for a confirmed-valid-but-expired token). Return a generic failure message to the client instead of echoing the underlying exception text.

<details><summary>Verification evidence</summary>

Confirmed against source/src/Security/AutoLoginAuthenticator.php (lines 36-71), config/routes.yaml (clp_autologin -> /autologin), and security.yaml (line 30: `^/autologin` is PUBLIC_ACCESS, unauthenticated). The token is fully attacker-controlled: `$token = $request->get("token")` (line 38) flows unsanitized into `getTokenFile()` which builds `sprintf("%s/var/.token_%s", projectDir, $token)` (line 69). No sanitization, ownership check, or path normalization anywhere; the only gate is `supports()` requiring the path to start with "autologin" and a non-empty token. The `finally` block (lines 62-64) unconditionally calls `@unlink($this->getTokenFile($token))` on EVERY request, including ones that throw before any auth succeeds. Because traversal sequences in the token (e.g. token=`../../../etc/passwd`) collapse the `.token_` filename prefix away during path normalization, the `.token_` prefix does NOT constrain the target — an unauthenticated attacker can trigger `@unlink` on an arbitrary filesystem path (subject to the web user's permissions). This is a genuine unauthenticated arbitrary-file-deletion primitive and the core of the finding holds.

The secondary info-leak sub-claim is weaker: the catch rethrows `$e->getMessage()` and `onAuthenticationFailure` does echo it via `getMessageKey()` (CustomUserMessageAuthenticationException returns the raw message as its key). However, every inner throw uses benign constant strings ("Unauthorized", "Token expired", "Token file not found"), and file_get_contents is guarded by file_exists, so realistic leaked content is not sensitive. That part is largely theoretical.

Overall the dominant, dangerous claim (unconditional finally-unlink on an attacker-controlled, traversal-capable, unauthenticated path) is real.

</details>

### [HIGH] Credentials generated with non-cryptographic rand()

- **File:** `source/src/Util/PasswordGenerator.php:14`
- **Module:** services-infra · **Category:** crypto
- **Verdict:** real (confidence 0.9)

**Issue.** PasswordGenerator::generate() builds passwords by drawing each character with rand(0, $count - 1). rand() is a non-cryptographically-secure PRNG with limited internal state and is predictable/seedable; it must not be used to generate secrets. This generator produces real credentials: InitializeListener::setDatabaseServer() uses it for the MySQL root password (InitializeListener.php:127-128, then fed to ChangeDatabaseUserPasswordCommand), and it is also used in Controller/Frontend/NewSiteController.php for site/database credentials. An attacker who can observe a few generated values, or who knows/affects the PRNG seed, can recover or brute-force these passwords, including the database root password the panel sets on the host.

**Fix.** Use a cryptographically secure source, e.g. random_int(0, $count - 1) (CSPRNG, throws on failure) per character, or generate bytes with random_bytes() and map to the charset. Avoid rand()/mt_rand() for any secret.

<details><summary>Verification evidence</summary>

Verified directly. PasswordGenerator.php:14 uses rand(0, $count-1) per character — a non-cryptographic, seedable PRNG, not random_int/random_bytes, with no CSPRNG fallback. The generator produces real production secrets: InitializeListener::setDatabaseServer() (lines 127-128, non-dev branch) generates the MySQL root password and applies it to the live host via ChangeDatabaseUserPasswordCommand (lines 142-148); NewSiteController.php:443 generates database user passwords during provisioning. No escaping, validation, or access control is relevant here — the vulnerability is the entropy/predictability of the secret-generation primitive itself, so route-level authz does not neutralize it, and Crypto::encrypt only protects storage, not the value's randomness. The described defect holds exactly. Minor nuance: practical exploitability depends on PHP-version PRNG internals and seed/state recovery rather than trivial enumeration of a 62^16 space, but generating a host DB root credential with a non-CSPRNG is a genuine high-severity crypto flaw.

</details>

### [HIGH] Unsanitized rootDirectory concatenated into privileged filesystem paths (path traversal / privilege escalation)

- **File:** `source/src/Site/Updater.php:343-349 (also Creator.php:121-127)`
- **Module:** site-core · **Category:** path-traversal
- **Verdict:** real (confidence 0.85)

**Issue.** getRootDirectory() builds the path as sprintf("/home/%s/htdocs/%s", $siteUser, $rootDirectory) using $this->site->getRootDirectory() with no validation that the value is free of '../'. The rootDirectory value is user-controlled: in Controller/Frontend/SitesController.php:2017 it is taken from the SiteDomainSettingsType form, which only enforces Assert\NotNull() (Form/SiteDomainSettingsType.php:14) — no path-traversal restriction. The resulting path is then fed (in scope) into deleteLetsEncryptChallengeDirectory()/createLetsEncryptChallengeFiles() which run CreateDirectoryCommand, WriteFileCommand, recursive ChownCommand (chown -R siteUser:siteUser) and FindChmodCommand on rootDirectory/.well-known. The command builders use escapeshellarg(), which preserves '..' verbatim, so a value like '../../../../etc/nginx' resolves to a directory outside the site sandbox. A panel user controlling their own site's root directory can therefore cause directories outside /home/<user>/htdocs to be created, chowned to their unprivileged site user, and written to — a privilege-escalation / arbitrary-write primitive on the server.

**Fix.** Validate/normalize rootDirectory at the trust boundary and in getRootDirectory(): reject any value containing '..' or a leading '/', and after building the absolute path, realpath()-canonicalize it and assert it is still within /home/<siteUser>/htdocs/. Prefer a strict whitelist regex (e.g. ^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$) on the form field as well.

<details><summary>Verification evidence</summary>

Verified the full chain in the actual code. (1) Input is attacker-controlled: SiteDomainSettingsType (Form/SiteDomainSettingsType.php:14) enforces only Assert\NotNull; the Site entity (Entity/Site.php:83-87) has only @Assert\NotBlank on rootDirectory — no path-traversal constraint. SitesController::handleDomainSettingsForm (line 2017-2020) takes form data, applies only rtrim(...,'/'), and persists it verbatim to the entity. (2) Reachable by non-admins: routes clp_site_settings (/site/{domainName}/settings) and clp_site_lets_encrypt_certificate_new (/site/{domainName}/lets-encrypt-certificate/new) fall under security.yaml access_control rule `^/ => IS_AUTHENTICATED_FULLY`, NOT `^/admin => ROLE_ADMIN`, so any authenticated panel user can reach them. (3) getRootDirectory() (Updater.php:343-349 and Creator.php:121-127) does sprintf("/home/%s/htdocs/%s", $siteUser, $rootDirectory) with no realpath/sanitization; PhpSite updater (Updater/PhpSite.php:95-97) just delegates to parent. (4) createLetsEncryptChallengeFiles()/deleteLetsEncryptChallengeDirectory() (Updater.php:237-286), invoked from SitesController::handleLetsEncryptCertificateForm (lines 1169-1178,1217) during user-triggered cert issuance, feed that path into CreateDirectoryCommand, WriteFileCommand, recursive ChownCommand (chown -R siteUser:siteUser) and FindChmodCommand. (5) Confirmed CreateDirectoryCommand builds `sudo /bin/mkdir -p <escapeshellarg(dir)>` and ChownCommand builds `sudo /bin/chown -R user:user <escapeshellarg(file)>`. escapeshellarg only quotes the argument; it does NOT strip or reject `..`, so a rootDirectory of `../../../../etc/nginx` resolves to a path outside /home/<user>/htdocs and is created/chowned/chmodded with sudo. Caveat lowering confidence below certainty: the LE path uses setDryRun(true) on the first ACME pass and a real server is needed to confirm exploitability end-to-end, and the precise blast radius depends on directory existence; but the privileged mkdir/chown/chmod on an unsanitized, user-controlled, traversal-capable path is unambiguous in the code. The finding's only minor inaccuracy is implying the domain-settings save itself runs the LE writes — actually domainSettings() only writes the nginx vhost; the privileged writes occur on the subsequent (also user-triggered, non-admin) cert-issuance action. This does not change the conclusion.

</details>

### [HIGH] Blocked bot name interpolated into nginx config without sanitization

- **File:** `source/src/Site/Nginx/Vhost/Processor/Settings.php:79-91`
- **Module:** site-nginx-varnish · **Category:** config-injection
- **Verdict:** real (confidence 0.85)

**Issue.** addBlockedBots() builds an nginx directive `if ($http_user_agent ~* (<names>)) { return 444; }` by interpolating each blocked bot's name via str_replace([' '],['\\s']) + strtolower only (line 85), then sprintf into the config string (line 89). The bot name originates from user input (Form\SiteBlockedBotType -> Entity\BlockedBot::$name), which carries only @Assert\NotBlank and @Assert\Length(max=125) and NO character whitelist. A site owner can submit a name containing nginx syntax / newlines (e.g. `evil) { return 200; } location /x { root /etc; } if ($x ~ (z`), breaking out of the generated `if` block and injecting arbitrary directives into the root-owned nginx vhost. nginx -t may catch malformed output, but a carefully crafted value remains syntactically valid, allowing the operator-tier nginx config (running as root) to be subverted (e.g. exposing files via a rogue root/alias/location).

**Fix.** Strictly whitelist the bot name (e.g. allow only [A-Za-z0-9 ._-]) at the validation boundary, and additionally escape/strip regex- and config-significant characters (parentheses, braces, semicolons, quotes, newlines, backslashes) before embedding the value into the nginx regex group.

<details><summary>Verification evidence</summary>

The finding holds against the actual code. Data flow confirmed end to end: Form\SiteBlockedBotType is a plain TextType (no constraints), Entity\BlockedBot::$name carries only @Assert\NotBlank + @Assert\Length(max=125) and Column length=128 — no character whitelist. In Settings.php addBlockedBots() (lines 79-92) the name is transformed only by str_replace([" "],["\\s"], strtolower(...)) which leaves nginx metacharacters ) { } ; and newlines intact, then sprintf'd raw into `if ($http_user_agent ~* (<names>)) { return 444; }` (line 89) and implode'd into the vhost via replace() (process(), lines 21-22) with no escaping. The value is fully attacker-controlled by a site owner: the route clp_site_security_blocked_bot_new is /site/{domainName}/security/blocked/bot/new — under the frontend tier (^/site), NOT ^/admin, so a non-admin site owner reaches it. handleBlockedBotForm() (SitesController.php ~972-1002) validates only $form->isValid() (the weak entity constraints) then writes the root-owned vhost in /etc/nginx/sites-enabled/. Notably the controller calls updateNginxVhost() + reloadNginxService(), and updateNginxVhost() (Updater.php lines 52-82) writes the file with WriteFileCommand and does NOT run NginxConfigTestCommand at all — the nginx -t check exists only in the unused updateNginxVhostWithRollback(). So even the partial 'nginx -t may catch it' mitigation the finding concedes is absent on this path; a syntactically valid crafted name (closing the regex/if parens and adding a rogue location/root) injects arbitrary directives into the root-owned config. Confidence held below 1.0 because successful exploitation requires crafting input that survives nginx's own parse on reload, and there could be input-length (125) limits constraining payload size, but neither prevents a compact valid injection.

</details>

### [HIGH] Varnish server value interpolated into nginx proxy_pass without validation

- **File:** `source/src/Site/Nginx/Vhost/Processor/VarnishProxyPass.php:14-20`
- **Module:** site-nginx-varnish · **Category:** config-injection
- **Verdict:** real (confidence 0.85)

**Issue.** process() builds `proxy_pass http://<server>;` by interpolating $varnishCacheSettings['server'] after only ltrim/rtrim of '/' (line 15), then sprintf into the directive (line 19) and into the vhost content (line 20). The 'server' value comes from Form\SiteVarnishCacheSettingsType, which declares the field as a plain TextType with no Assert validation (no host/port/URL constraint), and handleVarnishCacheSettingsForm() in SitesController stores it verbatim. A value such as `127.0.0.1:8080; } location / { root /etc;` injects arbitrary directives into the root-owned nginx vhost. (The same unvalidated value also reaches VarnishCacheClient::setServer() and is used as the target of a PURGE request, enabling SSRF, but that root cause is in the controller/form, not this file.)

**Fix.** Validate the Varnish server value (host[:port] or full URL) with a strict constraint at the form/entity boundary, and reject characters that are meaningful in nginx config (whitespace, ';', '{', '}', '#', newlines) before interpolating into the proxy_pass directive.

<details><summary>Verification evidence</summary>

Verified the full chain. (1) VarnishProxyPass::process() (lines 14-20) interpolates $varnishCacheSettings["server"] into `proxy_pass http://%s;` after only ltrim/rtrim('/') and replaces via Processor::replace() which is a plain str_replace (Processor.php:29) — no escaping, no quoting, no newline/semicolon stripping. (2) The source value comes from Form\SiteVarnishCacheSettingsType where "server" is a plain TextType with NO Assert constraints (line 15). (3) SitesController::handleVarnishCacheSettingsForm() reads $form->get("server")->getData() and stores it verbatim into the settings array (lines 1683/1688/1689); form->isValid() enforces nothing for this field. (4) The settings reach the vhost: handleVarnishCacheSettingsForm calls $siteUpdater->phpSettings() → PhpSite::phpSettings() → updateNginxVhost(), which builds the vhost with the VarnishProxyPass processor (PhpTemplate registers it) and writes it via WriteFileCommand to /etc/nginx/sites-enabled/<domain>.conf (Updater.php:37,74-81) — a root-owned file written through the root CommandExecutor. So a value like `127.0.0.1:8080; } location / { root /etc; ... ` injects arbitrary nginx directives into a root-owned config. Access control: the route clp_site_varnish_cache is /site/{domainName}/varnish-cache (frontend, not /admin), reachable by a regular authenticated site owner — i.e. privilege escalation from a low-privilege panel user into root-controlled nginx config (and RCE-equivalent via nginx directives / arbitrary file roots). The described defect holds in the real code; no neutralizing escaping/validation found. Confidence is 0.85 rather than higher because exploitation also depends on the persisted JSON round-tripping the injected characters intact (writeVarnishCacheSettingsFile stores JSON to the site user's home and the in-memory array is used for the vhost build in the same request, so the chars survive), and the "enabled" gate requires the checkbox set — both consistent with the code I read.

</details>

### [HIGH] ACME (Let's Encrypt) HTTP client disables TLS certificate verification

- **File:** `source/src/Site/Ssl/LetsEncryptClient.php:45`
- **Module:** site-phpfpm-ssl-app · **Category:** crypto
- **Verdict:** real (confidence 0.93)

**Issue.** getHttpClient() builds the Guzzle client with "verify" => false, disabling TLS certificate verification for every request to the Let's Encrypt ACME API (account registration, nonce, order, challenge validation, finalize, certificate download). All ACME traffic is the trust anchor for issuing TLS certificates for the panel's hosted domains. With verification disabled, a network man-in-the-middle (e.g. compromised upstream, malicious resolver/route) can impersonate acme-v02.api.letsencrypt.org, observe the account key signed JWS, hand back attacker-controlled order/challenge/finalize endpoints, and influence which certificate the panel installs. Because the panel runs as root and writes the returned certificate/chain to disk and into nginx, a forged ACME response can lead to installing a certificate the attacker controls or controls the issuance flow of. There is no dev short-circuit guard around this; it applies in production.

**Fix.** Remove "verify" => false (default Guzzle verification uses the system CA bundle), or set it to the path of a trusted CA bundle. Only ever relax verification behind an explicit, dev-only flag, never by default.

<details><summary>Verification evidence</summary>

Confirmed at source/src/Site/Ssl/LetsEncryptClient.php line 45: getHttpClient() builds the Guzzle client with `"verify" => false`, unconditionally disabling TLS cert/host verification. This single client is reused for the entire ACME conversation over HTTPS (acme-v02.api.letsencrypt.org): registerAccount, getNonce, getAccountEndpoint, requestOrder, validateDomain, finalizeOrder, and certificate download. There is no dev/env guard — `verify` is hardcoded; the only env-dependent logic is production-vs-staging endpoint selection in getEndpoint() (both HTTPS, both unverified). The class is reachable in production from Frontend\SitesController (lines 1164/1173), Admin\SettingsController (line 81), and automated CLI renewal commands (LetsEncryptRenewCertificatesCommand, LetsEncryptRenewCustomDomainCertificateCommand). ACME's account-key JWS protects request integrity to the server but does NOT protect the client against an impersonated server, which is exactly what TLS verification provides; finalizeOrder writes the returned certificate body to disk/nginx as root without pinning. A network MITM can impersonate the ACME server, redirect Location/authorization/finalize/certificate URLs, and influence the cert installed. The challenge-validation step provides only partial mitigation against fully arbitrary issuance, so the defect is real and security-relevant. Confidence slightly below 1.0 only because the residual JWS/challenge mitigations reduce worst-case exploitability somewhat.

</details>

### [HIGH] Foreground commands are executed twice (run() then unconditional start())

- **File:** `source/src/System/CommandExecutor.php:13-20`
- **Module:** system-core · **Category:** correctness
- **Verdict:** real (confidence 0.95)

**Issue.** In execute(), when the command is NOT run in background, the code calls $process->run() (lines 14-15), which executes the command synchronously to completion and checks the result. Execution then falls through to line 20, $process->start(), which is called UNCONDITIONALLY (it is not inside an else/branch). Symfony\Component\Process::start() on an already-terminated process resets and re-runs the same command. Therefore every foreground command runs a second time. Because this executor is the single chokepoint that shells out as root for the whole panel (tar backups, useradd/userdel, mysql ALTER/DROP, rclone copy/delete, snapshot delete, systemctl restart, certbot, etc.), non-idempotent operations are performed twice. For destructive or stateful operations this can cause spurious failures, duplicate side effects, restarts, or data-integrity problems. The second start() also runs with no timeout set, unlike the first invocation.

**Fix.** Make the foreground execution mutually exclusive with the background start. Use an if/else: run synchronously (with timeout + success check) when not background, and only call start() in the background branch. e.g. `if (!$runInBackground) { $process->setTimeout($timeout); $process->run(); if (!$process->isSuccessful()) { throw ... } } else { $process->start(); }`.

<details><summary>Verification evidence</summary>

Confirmed in source/src/System/CommandExecutor.php lines 13-20. The foreground branch (when !runInBackground) calls $process->setTimeout(), $process->run() (synchronous, runs to completion and checks isSuccessful()), and then execution falls through to $process->start() on line 20, which is NOT inside an else branch. Symfony's Process::start() resets process data and re-launches the command on an already-terminated process (it only throws if isRunning() is true, which it is not after run() completes). So every foreground command is launched a second time, the second time with no timeout set (setTimeout is inside the if). The custom App\System\Process subclass does not override start() or run(), so standard Symfony behavior applies.

Decisive corroborating evidence: the git diff shows the original CloudPanel 2.5.3 code was obfuscated with goto statements. Tracing the original goto chain (f00e8: "if (true === $runInBackground) { goto C20e2; }"), $process->start() lived at the C20e2 label reached ONLY in the background branch; the foreground path ran run()/success-check then jumped (F8d92: goto Fe12b) straight to the end, SKIPPING start(). The de-obfuscated version in this working tree incorrectly relocated $process->start() outside the runInBackground guard, turning a background-only call into an unconditional one. This is a genuine regression that double-executes every foreground system command (tar, useradd/userdel, mysql DDL, rclone, systemctl, certbot, etc.) routed through this single executor. Not an injection/access-control issue but a clear correctness defect with destructive side-effect potential. Slightly less than full confidence only because the live Symfony Process source isn't vendored here to inspect directly, but its documented start() semantics are well established.

</details>

## Medium findings (17)

### [MEDIUM] rclone.conf with cleartext credentials written without restrictive permissions

- **File:** `source/src/Backup/Rclone.php:84-94`
- **Module:** backup · **Category:** secret-leak
- **Verdict:** real (confidence 0.78) · severity adjusted high → medium

**Issue.** writeConfig() builds the rclone configuration (which contains secret_access_key for S3/Wasabi/DigitalOcean, the Dropbox OAuth token, and SFTP passwords in cleartext) and writes it via WriteFileCommand, which runs `echo ... | sudo tee /root/.config/rclone/rclone.conf`. `tee` creates the file using the process umask (typically 0644 = world-readable) and no chmod 600 is ever applied to the config file. Any local user (including unprivileged site users sharing the box) can read the cloud storage credentials. Contrast with writeCredentialsFile(), which at least attempts (incorrectly) to restrict permissions. rclone itself refuses to use a world-readable config for this reason.

**Fix.** After writing the config, explicitly chmod the file to 0600 and chown to the owner that runs rclone (root). Do not rely on the inherited umask for a file holding plaintext secrets.

### [MEDIUM] Google service-account private key file written world-readable; chmod applied before file exists

- **File:** `source/src/Backup/Rclone.php:95-112`
- **Module:** backup · **Category:** secret-leak
- **Verdict:** real (confidence 0.78) · severity adjusted high → medium

**Issue.** writeCredentialsFile() chmods the config directory contents to 0770 (FindChmodCommand) on line 104-110 and THEN writes the actual credentials file with a plain file_put_contents($file, $content) on line 111. Because the file is created after the chmod, the permission hardening never covers it, and file_put_contents creates it with the default umask (typically 0644). The content is the Google Drive service-account JSON, which contains the private key used to access all backups. This leaves the private key world-readable for any local user. The directory chmod of 0770 also does nothing to protect a 0644 file inside it for the read bit on the file itself.

**Fix.** Write the file first, then chmod it to 0600 (or 0640 owned by clp) and chown it. Avoid mixing a direct file_put_contents (running as the web/CLI user) with sudo-based directory ownership changes; write secrets atomically with explicit restrictive mode.

### [MEDIUM] GCE snapshot listing pagination is broken; only the first page is ever read

- **File:** `source/src/Gce/Client.php:109-145`
- **Module:** cloud-providers · **Category:** correctness
- **Verdict:** real (confidence 0.97)

**Issue.** In getSnapshots(), the do/while loop resets $optParams["pageToken"] = null on every iteration (line 111), and the next page token returned by the API is assigned to the wrong variable: $params["pageToken"] = $snapshotList->getNextPageToken() (line 143) instead of $optParams. Because $optParams["pageToken"] is therefore always null, the loop condition while (false === empty($optParams["pageToken"])) is always false and the loop runs exactly once. For projects with more snapshots than a single API page, snapshots beyond the first page are silently never enumerated, which corrupts any logic built on the full list (e.g. retention cleanup and the snapshots UI). Also note $params is never initialised, so the assignment is to an otherwise-unused variable.

**Fix.** Initialise $optParams = [] once before the loop, set $optParams["pageToken"] = $snapshotList->getNextPageToken() (the correct variable) after processing each page, and do not reset the token to null inside the loop.

### [MEDIUM] --path option silently ignored; path validation logic is dead code

- **File:** `source/src/Command/SystemPermissionsResetCommand.php:37-49`
- **Module:** clpctl-rest · **Category:** correctness
- **Verdict:** real (confidence 0.95)

**Issue.** The command reads the operator-supplied path into $path (line 37) and runs a series of normalization / traversal checks on it (lines 40-48: rejecting "..", prefixing cwd for non-/home/ paths). Line 49 then unconditionally overwrites the value: `$path = sprintf("%s/", rtrim(getcwd(), "/"));`. As a result the entire block at lines 40-48 has no effect and the --path argument documented in the command description (`--path=.`) is completely discarded — the command always operates on getcwd(). Anyone using `clpctl system:permissions:reset --path=/home/site/specific/dir` will instead get cwd reset, which is surprising and can reset permissions on the wrong subtree. (Note: this is exposed to unprivileged site users via the 'default' user branch in Console\Application::getCommands, so the operator surface is real.)

**Fix.** Decide whether the command should honor --path or always use cwd. If --path is intended, remove line 49 and use the validated $path computed in lines 37-48. If cwd-only is intended, delete the dead --path handling (lines 37-48) and the option to avoid confusion.

### [MEDIUM] SSH-user branch results overwritten; chown applied with wrong owner and possible null dereference

- **File:** `source/src/Command/SystemPermissionsResetCommand.php:60-71`
- **Module:** clpctl-rest · **Category:** correctness
- **Verdict:** real (confidence 0.9)

**Issue.** Lines 60-67 handle the case where the invoking SUDO_USER is an SSH user: they look up the SshUserEntity, set $user to the SSH user name, $group to the site's main user, and build $allowedDirectories for both home dirs. However lines 69-71 then run UNCONDITIONALLY (still inside the `if (is_null($siteEntity))` block) and overwrite that work: they append the site user's home again and reset `$user = $siteEntity->getUser(); $group = $user;`. So for an SSH user the subsequent `chown -R` (line 98-102, run as root) ends up chowning files to the SITE's main user instead of the SSH user that invoked the command — corrupting ownership inside an SSH user's home directory. Additionally, if the invoking user has neither a site nor a matching SSH user, $siteEntity remains null and line 69 (`$siteEntity->getUser()`) is a null-method-call fatal that is only caught by the outer try/catch.

**Fix.** Move the $allowedDirectories[]/$user/$group assignment at lines 69-71 into the `else` branch (when no SSH user was matched and $siteEntity came directly from findOneByUser), and guard against $siteEntity being null before dereferencing it. Preserve the SSH-user-specific $user/$group computed at lines 64-66.

### [MEDIUM] Inverted/broken non-root authorization in db:export causes NULL deref

- **File:** `source/src/Command/DatabaseExportCommand.php:53-62`
- **Module:** clpctl-site-user-data · **Category:** correctness
- **Verdict:** real (confidence 0.9)

**Issue.** Mirror of the db:import defect: line 55 `if (!(false === is_null($siteEntity)))` enters the SSH-user resolution block only when `$siteEntity` is null, yet line 62 unconditionally calls `$siteUser = $siteEntity->getUser();`. A non-root SUDO_USER who is neither a site owner nor an SSH user will trigger a fatal NULL method call instead of a clean 'permission denied', and the `$userBelongsToSite` ownership gate (line 63) is computed from this inconsistent state. Root callers are unaffected because `$isSuperUser` short-circuits the check.

**Fix.** Same fix as DatabaseImportCommand: resolve the caller's site with a single correct condition, fail explicitly if it cannot be resolved, and null-guard before dereferencing $siteEntity.

### [MEDIUM] AWS deleteImage deregisters an AMI and deletes snapshots via unprotected GET

- **File:** `source/src/Controller/Admin/AwsController.php:263-310`
- **Module:** controllers-admin · **Category:** csrf
- **Verdict:** real (confidence 0.92)

**Issue.** deleteImage() reads imageId from $request->get("id") and, with no CSRF check, calls deregisterImage and deleteSnapshot against the configured AWS account. Route clp_admin_aws_image_delete (routes.yaml:398) has no method restriction, so a GET initiates a destructive cloud operation. A CSRF attack against a logged-in admin can delete backup AMIs/EBS snapshots (data loss). The provider deleteSnapshot siblings (DO/GCE/Hetzner/Vultr) share the same flaw.

**Fix.** Require a CSRF token (checkCsrfToken) and/or POST for all snapshot/image delete endpoints.

### [MEDIUM] DigitalOcean deleteSnapshot deletes droplet snapshot via unprotected GET (no CSRF token)

- **File:** `source/src/Controller/Admin/DoController.php:220-246`
- **Module:** controllers-admin · **Category:** csrf
- **Verdict:** real (confidence 0.9)

**Issue.** deleteSnapshot() takes id from $request->get("id") and calls $doClient->deleteDropletSnapshot($id) without any CSRF check. Route clp_admin_do_snapshot_delete (routes.yaml:418) has no method restriction, allowing a GET to permanently delete a DigitalOcean snapshot. A logged-in admin can be tricked into destroying backups via CSRF.

**Fix.** Add checkCsrfToken and/or restrict the route to POST.

### [MEDIUM] GCE deleteSnapshot deletes a snapshot via unprotected GET (no CSRF token)

- **File:** `source/src/Controller/Admin/GceController.php:204-230`
- **Module:** controllers-admin · **Category:** csrf
- **Verdict:** real (confidence 0.9)

**Issue.** deleteSnapshot() reads id from $request->get("id") and calls $gceClient->deleteSnapshot($id) with no CSRF protection. Route clp_admin_gce_snapshot_delete (routes.yaml:438) allows GET. CSRF can delete GCE disk snapshots (backup data loss) against a logged-in admin.

**Fix.** Add checkCsrfToken and/or restrict the route to POST.

### [MEDIUM] Hetzner deleteSnapshot deletes a snapshot via unprotected GET (no CSRF token)

- **File:** `source/src/Controller/Admin/HetznerController.php:142-168`
- **Module:** controllers-admin · **Category:** csrf
- **Verdict:** real (confidence 0.9)

**Issue.** deleteSnapshot() takes id from $request->get("id") and calls $hetznerClient->deleteSnapshot($id) without a CSRF check. Route clp_admin_hetzner_snapshot_delete (routes.yaml:454) has no method restriction; a forged GET deletes a Hetzner snapshot, enabling CSRF-driven backup data loss.

**Fix.** Add checkCsrfToken and/or restrict the route to POST.

### [MEDIUM] setDatabaseServerActive switches the active DB server via unprotected GET (no CSRF token)

- **File:** `source/src/Controller/Admin/SettingsController.php:251-275`
- **Module:** controllers-admin · **Category:** csrf
- **Verdict:** real (confidence 0.9)

**Issue.** setDatabaseServerActive() takes id from $request->get("id") and flips which database server is active with no CSRF check. Route clp_admin_database_server_set_active (routes.yaml:374) has no methods restriction, so a GET triggers it. A CSRF attack can switch the panel onto an attacker-influenced or wrong DB server, causing disruption. Other state-changing endpoints in the project guard with checkCsrfToken.

**Fix.** Add a CSRF token check (checkCsrfToken) and/or limit the route to POST.

### [MEDIUM] Vultr deleteSnapshot deletes a snapshot via unprotected GET (no CSRF token)

- **File:** `source/src/Controller/Admin/VultrController.php:207-233`
- **Module:** controllers-admin · **Category:** csrf
- **Verdict:** real (confidence 0.9)

**Issue.** deleteSnapshot() reads id from $request->get("id") and calls $vultrClient->deleteSnapshot($id) with no CSRF protection. Route clp_admin_vultr_snapshot_delete (routes.yaml:478) allows GET. CSRF can delete Vultr snapshots (backup data loss) against a logged-in admin.

**Fix.** Add checkCsrfToken and/or restrict the route to POST.

### [MEDIUM] Database user password escaped with addslashes() instead of being safely quoted, charset-dependent SQL injection

- **File:** `source/src/Database/Connection.php:90-92`
- **Module:** repositories-db · **Category:** sqli
- **Verdict:** real (confidence 0.7) · severity adjusted high → medium

**Issue.** createUser() builds the CREATE USER ... IDENTIFIED [WITH ...] BY '%s' statement by interpolating the decrypted user password with sprintf and only addslashes(): `sprintf("CREATE USER '%s'@'%%' IDENTIFIED WITH mysql_native_password BY '%s';", $databaseUserName, addslashes($databaseUserPassword))` (line 90, and the MariaDB branch line 92). The password is fully attacker-controlled and free-form: it is set verbatim from --databaseUserPassword (DatabaseAddCommand.php line 50 only trims it) and from the web database-user forms, with NO regex/character validation (unlike userName/name which are constrained to /^[a-z][-a-z0-9]+$/). addslashes() is NOT a correct MySQL string-literal escaper: under multibyte server charsets such as GBK/Big5/SJIS the classic 0x5c sequence lets an attacker break out of the quoted literal and inject arbitrary SQL/grants, and addslashes does not account for the active connection charset (no SET NAMES / charset is configured anywhere). Because this code runs as root against the DB server and executes statement-by-statement via executeStatement(), a breakout yields privilege escalation on the database server (e.g. granting itself global privileges). Identifiers should never carry secrets via string concatenation.

**Fix.** Do not use addslashes() for SQL escaping. Use the DBAL connection's quote() for the password literal (e.g. $this->connection->quote($databaseUserPassword)) or set a known-safe connection charset (utf8mb4) and use quote(). Better: enforce a server charset of utf8mb4 in the PDO init command and quote all literals through the driver rather than sprintf.

### [MEDIUM] TLS server-certificate verification disabled for remote DB connections even when a CA cert is configured

- **File:** `source/src/Database/Connection.php:31`
- **Module:** repositories-db · **Category:** crypto
- **Verdict:** real (confidence 0.9)

**Issue.** When a certificate is configured for the database server, connect() sets PDO::MYSQL_ATTR_SSL_CA to the admin-provided CA (line 30) but then sets PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT = false (line 31). Disabling server-certificate verification defeats the purpose of supplying a CA: an active network attacker can MITM the connection to a remote database server and capture/alter the credentials (the panel sends the DB password over this connection) and query traffic. Because the panel manages credentials for remote DB servers as root, this materially weakens the security of remote DB management.

**Fix.** Set PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT to true whenever a CA certificate is supplied so the server certificate is validated against the provided CA. If hostname mismatch is a concern, document and configure it explicitly rather than disabling verification entirely.

### [MEDIUM] API authentication bypassed for whitelisted source IPs (no token required)

- **File:** `source/src/Security/ApiTokenAuthenticator.php:20, 34-40`
- **Module:** security · **Category:** auth-bypass
- **Verdict:** real (confidence 0.82) · severity adjusted high → medium

**Issue.** authenticate() grants full API access with NO token whenever `$request->getClientIp()` is in the static whitelist ["172.17.0.1", "127.0.0.1"] (the Docker bridge gateway and loopback). It fabricates an `api` User and returns a SelfValidatingPassport, so any request originating from loopback or the Docker bridge is treated as an authenticated API caller for the entire `^/api` firewall. This means any local process, any other container on the default Docker bridge, or any server-side request reflector (SSRF) that can reach the app over 127.0.0.1/172.17.0.1 obtains unauthenticated access to the operator API. While no Symfony trusted-proxy is configured (so X-Forwarded-For spoofing is not the primary vector), the loopback/bridge implicit-trust is a privileged bypass that does not require knowledge of any token.

**Fix.** Remove the IP-based bypass and require a valid API token for all API requests, or at minimum make the bypass opt-in/configurable, narrowly scoped, and combined with a token. If an internal-access path is genuinely required, use a dedicated authenticated service account rather than implicit IP trust.

### [MEDIUM] Unconditional redirect to user-creation route set on every request

- **File:** `source/src/EventListener/RequestListener.php:40-43`
- **Module:** services-infra · **Category:** correctness
- **Verdict:** real (confidence 0.9)

**Issue.** In onKernelRequest(), the MFA/setup logic at lines 32-39 is wrapped in a conditional, but lines 40-42 then unconditionally build a RedirectResponse to clp_admin_user_creation and call $event->setResponse($redirect) for EVERY main request, overriding any earlier response and the normal route handling. As written this forces all traffic to the user-creation page regardless of whether users already exist or MFA state. These lines appear to belong inside the 'no users exist yet' branch (the guard checks 0 == $numberOfUsers and ROUTE_ADMIN_USER_CREATION). The placement looks like a lost-brace artifact from source extraction, but as committed it is a logic defect that breaks routing/auth flow.

**Fix.** Move the redirect to clp_admin_user_creation inside an `if (0 == $numberOfUsers && current route is not the creation route)` block (returning/short-circuiting only in the no-users bootstrap case), rather than running it unconditionally for every request.

### [MEDIUM] Basic-auth password hashed with weak DES crypt using a password-derived salt

- **File:** `source/src/Site/Updater.php:248`
- **Module:** site-core · **Category:** crypto
- **Verdict:** real (confidence 0.92)

**Issue.** createBasicAuthFile() builds the htpasswd line as sprintf("%s:%s", userName, crypt($password, base64_encode($password))). The salt is derived from the password itself (so it is not random), and base64_encode() output does not begin with a recognized crypt prefix ($1$/$5$/$6$/$2y$); crypt() therefore falls back to the legacy DES algorithm, which only considers the first 8 characters of the password and is trivially brute-forceable. Combined with a non-random, attacker-derivable salt, this materially weakens the basic-auth credential stored in /etc/nginx/basic-auth/.

**Fix.** Generate a strong random salt and use a modern algorithm, e.g. crypt($password, '$6$' . bin2hex(random_bytes(8))) for SHA-512, or use Apache APR1/bcrypt which nginx supports. Never derive the salt from the secret being hashed.

## Low findings (33)

### [LOW] Malformed Dropbox response treated as valid; raw body stored as token

- **File:** `source/src/Backup/Dropbox/AccessCodeValidator.php:26-37`
- **Module:** backup · **Category:** correctness
- **Verdict:** real (confidence 0.7) · severity adjusted medium → low

**Issue.** isValid() only throws when access_token/refresh_token are missing AND an errorMessage key is present. If the 200 response lacks both tokens and lacks errorMessage, execution falls through to line 31-32 where $token is set to trim($responseData) (the entire raw, possibly-garbage body) and $refreshToken = $responseDataDecoded["refresh_token"] is read unconditionally (undefined-index warning / null), then isValid returns true. The bogus token/refresh token are then saved and written into rclone.conf, silently producing a broken backup configuration that appears successfully validated.

**Fix.** Validate that both access_token and refresh_token exist; throw or return false otherwise. Set $token to the decoded access_token field, not the raw response body.

### [LOW] getAccessToken returns raw response body as access token on malformed response

- **File:** `source/src/Backup/Dropbox/Client.php:24-31`
- **Module:** backup · **Category:** correctness
- **Verdict:** uncertain (confidence 0.6) · severity adjusted medium → low

**Issue.** When the 200 response is missing access_token and also missing errorMessage, the function does not throw; it falls through to line 29 and returns trim($responseData) — the entire raw response body — as if it were the access token. That value is then used as the Dropbox token in DropboxConfigTemplate->setToken() and written into rclone.conf. Backups will silently fail (or use an invalid token) while the caller believes it received a token. Additionally $accessToken should come from $responseDataDecoded["access_token"], not the whole body.

**Fix.** Return $responseDataDecoded["access_token"] only when present; otherwise throw. Do not fall back to returning the raw body.

### [LOW] Config values not sanitized for newlines, allowing rclone config key injection

- **File:** `source/src/Backup/Rclone/ConfigBuilder.php:13-21`
- **Module:** backup · **Category:** injection
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** build() emits each setting as sprintf("%s%s = %s", PHP_EOL, $key, $value) with no escaping or newline-stripping of $value. Several values reaching the templates are free-text (e.g. DigitalOcean spaceEndpoint, access/secret keys, custom rclone). A value containing a newline would inject additional rclone config directives into the generated [remote] section. Impact is limited because these inputs come from a ROLE_ADMIN form and the admin already controls the resulting config, but the lack of validation is a real defect that could enable enabling unexpected rclone options.

**Fix.** Strip/reject CR/LF in setting values before writing them, or validate values against expected formats at the trust boundary.

### [LOW] Hetzner getSnapshot()/deleteSnapshot() do not verify the snapshot belongs to the current instance

- **File:** `source/src/Hetzner/Client.php:111-145`
- **Module:** cloud-providers · **Category:** idor
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** getSnapshots() filters images to those whose created_from.id matches the current instance, but getSnapshot($id) (called by HetznerController::deleteSnapshot before deleteSnapshot($id)) fetches and deletes any image id in the account without confirming it was created from this server. An operator can therefore delete unrelated images/snapshots in the Hetzner account by supplying an arbitrary id. Impact is bounded because the route is ROLE_ADMIN-gated and the stored token is already account-wide, but the delete path lacks the ownership check that the listing path enforces.

**Fix.** In getSnapshot()/deleteSnapshot(), confirm the image's created_from.id equals $this->instance->getInstanceId() (or reuse the filtered getSnapshots() result) before allowing deletion, mirroring the DO getDropletSnapshot() approach.

### [LOW] Per-site delete failures are silently swallowed and command still reports SUCCESS

- **File:** `source/src/Command/CloudPanelDeleteSitesCommand.php:31-40`
- **Module:** clpctl-rest · **Category:** error-handling
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** The loop deletes every site by invoking the site:delete subcommand, but wraps each call in `try { ... } catch (\Exception $e) {}` (lines 31-37) with an empty catch and ignores the subcommand's $returnCode. After the loop the command always returns SUCCESS (line 40). If individual site deletions fail (left-over nginx config, FPM pools, system users, or filesystem data), the operator/automation gets no indication and the panel reports a clean wipe that did not actually happen.

**Fix.** At minimum log the caught exception and inspect $returnCode; aggregate failures and return FAILURE (or surface the failed domains) if any site:delete did not succeed.

### [LOW] vhost-template:add reads arbitrary local files or remote URLs as template source

- **File:** `source/src/Command/VhostTemplateAddCommand.php:86-94`
- **Module:** clpctl-rest · **Category:** path-traversal
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** getVhostTemplate() takes the --file option and does `file_get_contents($file)` when `file_exists($file)` is true OR the value starts with 'http' (lines 90-91). There is no restriction on the path, so an operator can read any file readable by the panel process (which runs as root) into a stored vhost template, and the 'http' branch performs an outbound fetch to an attacker-influenced URL (SSRF). This command is in the root/clp command set so impact is limited to already-privileged operators, but the unrestricted local-file read plus URL fetch is broader than the documented '/tmp/template.tpl' use case.

**Fix.** Constrain the source to an expected upload directory (e.g. /tmp or a dedicated templates dir) and validate the path with realpath() before reading. If remote fetch is required, restrict scheme/host and use a hardened HTTP client rather than file_get_contents on the raw option value.

### [LOW] explode() on null when --ignoreDatabases is omitted

- **File:** `source/src/Command/DatabaseBackupCommand.php:48`
- **Module:** clpctl-site-user-data · **Category:** correctness
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** `--ignoreDatabases` is declared VALUE_OPTIONAL with no default, so when omitted `$input->getOption('ignoreDatabases')` is null. `explode(',', null)` is deprecated in PHP 8.1 (passing null to a non-nullable string param) and yields `['']`. Functionally the resulting `['']` array is harmless for the in_array check, but it emits a deprecation notice and reflects missing input validation at the CLI trust boundary.

**Fix.** Coalesce to an empty string: `explode(',', (string) $input->getOption('ignoreDatabases'))`, or skip the explode entirely when the option is empty.

### [LOW] Inverted/broken non-root authorization in db:import causes NULL deref and confused access control

- **File:** `source/src/Command/DatabaseImportCommand.php:58-67`
- **Module:** clpctl-site-user-data · **Category:** correctness
- **Verdict:** real (confidence 0.82) · severity adjusted medium → low

**Issue.** For a non-root SUDO_USER, line 58 does `$siteEntity = findOneByUser($systemUserName)`. The block on lines 60-66 is gated by `if (!(false === is_null($siteEntity)))`, i.e. it ONLY runs when `$siteEntity` is null, then dereferences `$sshUserEntity->getSite()`. Regardless, line 67 unconditionally executes `$siteUser = $siteEntity->getUser();` — if `findOneByUser` returned null and the user is not an SSH user, `$siteEntity` is still null and this is a fatal NULL-method-call. The intended ownership check (`$userBelongsToSite`, line 68) is built on this tangled, half-inverted logic, so the access-control decision for non-root callers is unreliable. In practice clpctl runs as root (where `$isSuperUser` short-circuits), which masks the bug, but any non-root invocation path is broken. Same pattern exists in DatabaseExportCommand.

**Fix.** Restructure: load the caller's site via findOneByUser OR the SSH-user lookup with a single, correctly-signed condition, bail out with a clear error if neither resolves, and only then compute `$siteUser`/`$userBelongsToSite`. Guard every `$siteEntity->getUser()` behind a null check.

### [LOW] json_decode of remote_backup_excludes assigned to array-typed property without validation

- **File:** `source/src/Command/RemoteBackupCreateCommand.php:194`
- **Module:** clpctl-site-user-data · **Category:** correctness
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** `getExcludes()` returns `array` (declared return type) but assigns `$this->excludes = json_decode($excludes)` (an array property) without the second `true` argument and without checking the result. If the stored config value is malformed JSON, `json_decode` returns null and the subsequent `return $this->excludes;` (typed `: array`) throws a TypeError; if it decodes to a non-array (e.g. a JSON string/number) it also violates the declared property/return type. The exclude list ultimately drives which site home directories are backed up, so a corrupt value silently aborts backup logic via exception rather than backing data up.

**Fix.** Use `json_decode($excludes, true)` and validate: `$decoded = json_decode($excludes, true); $this->excludes = is_array($decoded) ? $decoded : [];`.

### [LOW] createSnapshot catch block references $session that may be unset on early failure

- **File:** `source/src/Controller/Admin/GceController.php:148-160`
- **Module:** controllers-admin · **Category:** error-handling
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** In createSnapshot() the GET branch assigns $session = $request->getSession() at line 151 inside the try, but the catch at line 156-159 uses $session->getFlashBag(). If $request->getSession() itself were to throw (or future code reorders the try), $session is undefined in the catch and produces a fatal error instead of a clean flash. AwsController::createImage has the same shape (session assigned inside try at 131, used in catch at 153). Minor robustness issue, not a security bug.

**Fix.** Assign $session = $request->getSession() before the try block so the catch can always render an error flash.

### [LOW] Notification delete/mark-read/mark-unread are state-changing GET routes without CSRF protection

- **File:** `source/src/Controller/Admin/NotificationsController.php:59-75`
- **Module:** controllers-admin · **Category:** csrf
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** delete(), markAsRead() and markAsUnread() mutate notification state based on (int) $request->get("id") with no CSRF check; the routes (routes.yaml:282-292) impose no method restriction so they are GET-triggerable. Impact is low (notification flags), but it is inconsistent with the checkCsrfToken pattern used elsewhere and lets an attacker forge these actions against a logged-in admin.

**Fix.** Add checkCsrfToken and/or restrict to POST.

### [LOW] Unchecked null from findOneByUserName in phpMyAdmin()

- **File:** `source/src/Controller/Frontend/SitesController.php:1428-1432`
- **Module:** controllers-frontend · **Category:** error-handling
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** In phpMyAdmin(), `$databaseUserEntity = $this->databaseUserEntityManager->findOneByUserName($databaseUserName);` (line 1428) is used at line 1431 (`$databaseEntity = $databaseUserEntity->getDatabase();`) with no null guard. If `databaseUserName` is missing or does not exist, findOneByUserName returns null and line 1431 triggers a fatal 'call to a member function on null', producing an uncaught 500 for an authenticated site owner. The line 1430 condition `false === is_null($activeDatabaseServerEntity) && false === is_null($activeDatabaseServerEntity)` is also a duplicated check (same variable tested twice), indicating the intended `$databaseUserEntity` null check was likely dropped. No authorization bypass (ownership is still verified at line 1432), but it is a real robustness defect at a trust boundary.

**Fix.** Add `false === is_null($databaseUserEntity)` to the guard before dereferencing it, and fix the duplicated `$activeDatabaseServerEntity` check so the second clause tests `$databaseUserEntity`.

### [LOW] getCrontabExpression() builds an /etc/cron.d line with unescaped, newline-capable command (potential root privilege escalation)

- **File:** `source/src/Entity/CronJob.php:155-166`
- **Module:** entities · **Category:** command-injection
- **Verdict:** real (confidence 0.8) · severity adjusted medium → low

**Issue.** getCrontabExpression() composes a system-crontab line via sprintf('%s %s %s %s %s %s %s', $minute, $hour, $day, $month, $weekday, $siteUser, $command). The result is written, one entry per line, to /etc/cron.d/<siteUser> by App\Site\Updater::updateUserCrontab() (Updater.php:290-305, CRON_DIRECTORY = "/etc/cron.d/"). Files in /etc/cron.d use the system crontab format that includes a user field (which is why $siteUser is embedded). The CronJob::$command field has NO validation in the entity (only nullable=false, length=255) forbidding newline characters, and getCrontabExpression() performs no escaping or newline stripping. If any caller persists a command containing a newline (0x0A), the attacker (a site owner, who legitimately controls cron commands for their own unprivileged user) could inject an additional crontab line specifying an arbitrary user field such as root, e.g. command = "foo\n* * * * * root /tmp/x.sh", yielding arbitrary code execution as root. Currently the sole web caller (App\Form\SiteCronJobType) mitigates this with a regex constraint (?!.*\n) on the command field, so the live web flow is guarded; note that the form's own str_replace(["\\r","\\n","\\\\"], '', ...) only removes the literal two-character sequences \r and \n and backslashes, not actual newline bytes, so the regex constraint is the only effective newline guard. The data-layer object that actually emits the privileged crontab line provides no protection, leaving a high-impact escalation path open to any future or non-form caller (e.g. CLI, import, fixtures).

**Fix.** Sanitize/validate at the entity boundary: in CronJob::setCommand() (and the schedule field setters) reject or strip CR/LF and other control characters, or in getCrontabExpression() strip newlines from $command (and validate $siteUser) before composing the line. Add an entity-level @Assert constraint forbidding \r and \n on $command rather than relying solely on the form constraint.

### [LOW] AWS secret access key field uses TextType instead of PasswordType

- **File:** `source/src/Form/AdminAwsAccessKeysType.php:13`
- **Module:** forms · **Category:** secret-leak
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** `secretAccessKey` is added with TextType (and `secretAccessKey` in AdminRemoteBackupAmazonS3Type.php:29 / AdminAwsImagesSettingsType likewise). TextType renders the submitted value back into the HTML value attribute in plaintext on re-render and is shown on screen as the operator types, unlike PasswordType. For the credential-creation forms this is a long-lived high-value AWS secret. Note the remote-backup edit flow (RemoteBackupController) deliberately omits the secret from the prefill array and the Hetzner controller masks its token before prefill, so the secret is not echoed back on edit; the exposure is limited to plaintext-on-screen during entry and any value retained on a validation-error re-render of the create form. Admin-only.

**Fix.** Use PasswordType (with `always_empty`) for secret/credential fields so the value is masked on screen and never re-rendered into the response.

### [LOW] Cron command PRE_SUBMIT sanitization strips literal escape sequences, not real control characters

- **File:** `source/src/Form/SiteCronJobType.php:36`
- **Module:** forms · **Category:** correctness
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** The PRE_SUBMIT listener does `str_replace(["\\r", "\\n", "\\"], '', $data["command"])`. In PHP double-quoted strings `"\\r"`/`"\\n"` are the two-character literals backslash+r / backslash+n and `"\\"` is a single backslash, so this only removes literal `\r`/`\n`/`\` text from the submitted command, not actual carriage-return/newline characters. The intent was clearly to neutralize newline injection into the generated /etc/cron.d/<siteUser> file (Site/Updater.php writes getCrontabExpression() joined by PHP_EOL). The actual protection against a multi-line cron injection comes solely from the Regex constraint `(?!.*\n).*` on the same field, which (without the s modifier) does reject embedded newlines. So this str_replace is dead/ineffective defense-in-depth rather than an exploitable hole, but it gives a false sense of sanitization and would be the only guard if the regex were ever weakened. Same code appears in SiteCronJobEditType.php line 28.

**Fix.** Sanitize on the real characters, e.g. `str_replace(["\r", "\n"], '', $data["command"])` (single-quoted or actual control chars) and keep the regex as a hard reject. Do not rely on the literal-sequence replace.

### [LOW] User/database identifiers interpolated raw into SQL with no escaping in the Connection layer (relies solely on caller validation)

- **File:** `source/src/Database/Connection.php:85-114`
- **Module:** repositories-db · **Category:** sqli
- **Verdict:** real (confidence 0.8) · severity adjusted medium → low

**Issue.** createUser() (lines 90, 100-103) and deleteUser() (line 114) interpolate the user-supplied $databaseUserName and $databaseName directly into SQL strings via sprintf with single-quote/backtick wrapping and no escaping at all (e.g. DROP USER IF EXISTS `%s`;`). createDatabase()/deleteDatabase() do the same with the database name. Injection is currently prevented only because every caller (DatabaseAddCommand validator, the Symfony forms) validates these fields against /^[a-z][-a-z0-9]+$/. The Connection class itself trusts its inputs entirely, so any future or alternate caller that skips entity validation (e.g. a restore/import path that constructs entities directly) would be immediately injectable. This is a defense-in-depth defect: the SQL-building layer should not depend on far-away validation for safety, especially for code running as root.

**Fix.** Quote identifiers using the platform identifier quoting (DBAL's quoteIdentifier / AbstractPlatform) and validate the identifier characters inside the Connection layer itself, rejecting anything outside [A-Za-z0-9_-] before building the statement, rather than relying solely on caller-side entity validation.

### [LOW] base32Decode declared to return string but returns false on malformed input

- **File:** `source/src/Security/Authenticator/MfaAuthenticator.php:60, 69-71, 74-76, 85-87`
- **Module:** security · **Category:** correctness
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** base32Decode() has return type `: string` yet returns `false` (boolean) on several invalid-input branches (padding check line 70, allowed-values loop line 75, invalid-char check line 86). Under PHP 8.1 returning false from a `: string`-typed method raises a TypeError instead of a graceful failure. This is reached from getCode()/verifyCode() (verifyCode passes the secret to getCode -> base32Decode); a malformed or attacker-influenced secret string would cause a fatal TypeError rather than a clean 'invalid code' result. Impact is limited because the MFA secret is normally server-generated, but it is a real type-contract defect.

**Fix.** Change the failure paths to throw a domain exception or return '' consistently with the declared string return type, and have callers treat decode failure as verification failure.

### [LOW] getQrCodeLink does not encode the TOTP secret or account name in the otpauth URI

- **File:** `source/src/Security/Authenticator/MfaAuthenticator.php:125-129`
- **Module:** security · **Category:** injection
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** getQrCodeLink() builds `otpauth://totp/%s?secret=%s&issuer=%s` with `$name` and `$secret` inserted via raw %s (only $issuer is urlencode()'d). If $name (account/label) ever contains characters such as `?`, `&`, `#`, or `/`, it can break or inject parameters into the otpauth URI (e.g. overriding the secret/issuer parameters seen by the authenticator app). The secret is base32 so usually safe, but the label is the riskier value.

**Fix.** rawurlencode() the label/name path segment and the secret value when constructing the otpauth URI.

### [LOW] Audit-event persistence failures are silently swallowed

- **File:** `source/src/EventListener/EventListener.php:49-51`
- **Module:** services-infra · **Category:** error-handling
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** onTerminate() persists queued audit events (logins, firewall changes, user/DB changes, etc.). Any exception during persistence is caught and only logged via $this->logger->exception($e); the loop is abandoned and the request still completes successfully. A failure (e.g. DB write error) therefore drops security-relevant audit records without surfacing the failure, weakening the audit trail. NotificationsListener.php:28-30 has the same pattern for notifications.

**Fix.** This is acceptable for non-blocking side effects, but consider per-event try/catch so one bad event does not drop the rest of the batch, and ensure the swallowed error is at least monitored/alerted rather than only written to the same log being audited.

### [LOW] Open redirect via Referer header and use of possibly-undefined $response on AccessDenied

- **File:** `source/src/EventListener/ExceptionListener.php:28-37`
- **Module:** services-infra · **Category:** correctness
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** On AccessDeniedHttpException the listener reads the client-supplied Referer header and, if non-empty, redirects there: new RedirectResponse($referer). The Referer is fully attacker-controllable, allowing an open redirect to an external URL after an access-denied event. Additionally, if Referer is empty, $response is never assigned before $event->setResponse($response) at line 36, referencing an undefined variable (null), which produces a warning and a broken/empty response instead of a sensible fallback.

**Fix.** Do not redirect to a raw Referer; redirect to a known internal route (e.g. dashboard or login) or validate the referer against an allowlist of the panel's own host. Always initialize $response to a safe default before setResponse().

### [LOW] Firewall rule IP/port interpolated into sudo ufw command without escapeshellarg (defense-in-depth)

- **File:** `source/src/Ufw/Command/AllowTcpRule.php:21`
- **Module:** services-infra · **Category:** command-injection
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** AllowTcpRule::getCommand() (and AllowUdpRule.php:21) build a `/usr/bin/sudo /usr/sbin/ufw ... from %s to any port %s` string via sprintf with the raw $ip and $portRange, no escapeshellarg(). The values originate from the admin Firewall form (FirewallController) where source is validated by App\Validator\Constraints\IpValidator (filter_var FILTER_VALIDATE_IP) and the port range is coerced to integers in AdminFirewallRuleAddType, so current call paths are not exploitable. However, the command builder itself performs no escaping and trusts its caller; any future caller (or a validator regression) that passes an unsanitized value would yield argument/command injection into a root sudo invocation. Unlike sibling builders (TailCommand/LsCommand) which use escapeshellarg, these do not.

**Fix.** Apply escapeshellarg() to $ip and $portRange in the command builders so the shell-safety guarantee lives at the point of command construction rather than depending solely on upstream validation.

### [LOW] finalizeOrder dereferences responseData['finalize'] without checking it is set

- **File:** `source/src/Site/Ssl/LetsEncryptClient.php:330`
- **Module:** site-phpfpm-ssl-app · **Category:** error-handling
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** In finalizeOrder(), after getJsonResponse() the code reads $finalizeUrl = $responseData["finalize"]; directly (line 330) rather than using the null-coalescing pattern used elsewhere in this class. If the ACME order response lacks a 'finalize' key (error response, unexpected status, or a forged response — see the TLS-verification finding), this raises an undefined-array-key warning/notice and proceeds with an empty/garbage URL. It is caught by the surrounding catch and rewrapped, so impact is limited to a confusing error, but it is an unguarded trust-boundary read of a remote response.

**Fix.** Use $finalizeUrl = $responseData["finalize"] ?? null; and explicitly fail with a clear message when it is missing, consistent with the other response-field reads in this class.

### [LOW] Exception message includes the full raw command string, which can leak secrets for commands that embed credentials inline

- **File:** `source/src/System/CommandExecutor.php:21-24`
- **Module:** system-core · **Category:** secret-leak
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** On any failure, execute() builds an exception message containing the complete unmasked command string via $command->getCommand() (lines 22-24) and rethrows it up the stack. Most well-written command builders route secrets through tmpfiles (e.g. ChangeDatabaseUserPasswordCommand, CreateUserCommand), so their command strings are not sensitive. However, any current or future command builder that interpolates a password/token/key directly into the command string would have that secret embedded verbatim in the thrown exception, which may then be logged or surfaced to an operator/HTTP response. The masking facility used elsewhere (App\Command\Command::maskString) is not applied here.

**Fix.** Do not include the raw command string in exception messages, or pass it through a masking routine before interpolation. Prefer logging only the command name plus stderr, and mask known secret patterns (e.g. -p'...', IDENTIFIED BY '...') if the command string must be included.

### [LOW] isSuccessful() dereferences nullable $command without null guard

- **File:** `source/src/System/Process.php:18-24`
- **Module:** system-core · **Category:** error-handling
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** isSuccessful() calls $this->command->setOutput(...) (line 20) and $this->command->isSuccessful() (line 21) but $command is declared nullable (?Command, line 9) and getCommand() returns ?Command. If isSuccessful() is ever invoked before setCommand() (e.g. a future caller, or Symfony Process internals calling the overridden method during teardown), this throws a fatal 'call to a member function on null'. The override unconditionally assumes a command was attached. CommandExecutor currently always calls setCommand() before isSuccessful(), so impact is presently limited, but the override silently relies on that ordering invariant.

**Fix.** Guard with a null check: if $this->command is null, fall back to parent::isSuccessful() and skip the setOutput/addErrorOutput side effects.

### [LOW] File content (often secrets) placed in command line, exposing it via process list and exception messages

- **File:** `source/src/System/Command/WriteFileCommand.php:15`
- **Module:** system-shell-commands · **Category:** secret-leak
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** getCommand() builds `echo %s | /usr/bin/sudo /usr/bin/tee %s` with the full file content as an argv element (escapeshellarg-quoted, so no injection). WriteFileCommand is used to write config/credential files; whatever content is passed appears in the command string. CommandExecutor::execute (src/System/CommandExecutor.php:22-24) embeds the entire command string into a thrown \Exception message on any failure, and the content is also visible in the OS process list (ps) for the lifetime of the echo|tee. Sensitive values written this way (e.g. credentials) can leak into exception output/logs and to other local users. Other builders deliberately avoid this by writing secrets to 0400 temp files (CreateDatabaseDumpCommand, ImportDatabaseDumpCommand, WordPressConfigCreateCommand); WriteFileCommand does not.

**Fix.** For sensitive content, write via a 0400 temp file piped into tee (as the DB/WordPress builders do) instead of placing it in argv, and/or mask it before any logging. Audit callers that write secrets through WriteFileCommand.

### [LOW] Custom rclone config exception message echoed to user

- **File:** `source/src/Validator/Constraints/CustomRcloneConfigValidator.php:20-23`
- **Module:** validators · **Category:** error-handling
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** `catch (\Exception $e) { $errorMessage = $e->getMessage(); $this->context->buildViolation($errorMessage)->addViolation(); }`. The exception comes from `Rclone::lsJson($remotePath)` against a user-supplied storage directory; rclone errors (path, command, remote detail) are rendered verbatim to the user instead of a controlled message.

**Fix.** Surface a generic validation message and log the rclone error server-side.

### [LOW] Raw DB-connection exception message rendered to user (info disclosure)

- **File:** `source/src/Validator/Constraints/DatabaseNameValidator.php:29-31`
- **Module:** validators · **Category:** error-handling
- **Verdict:** real (confidence 0.7) · severity adjusted medium → low

**Issue.** The catch block adds the raw exception message as a validation violation: `catch (\Exception $e) { $this->context->addViolation($e->getMessage()); }`. The exception originates from `new DatabaseConnection($activeDatabaseServerEntity)` / `getDatabases()` -> `App\Database\Connection::connect()`, which builds a PDO MySQL connection from the configured DB server. A PDO/Doctrine failure message typically embeds the database host, port and username (e.g. "SQLSTATE[HY000] [2002] Connection refused" or "Access denied for user 'clp'@'10.0.0.5'"). Echoing that verbatim into the form discloses internal DB-server topology/credentials hints to the (authenticated) panel user. The validator's `messageAlreadyExists` is the intended user-facing string; the failure path leaks internals instead of a generic message.

**Fix.** On the catch path, add a generic constant message (e.g. $constraint->message) rather than $e->getMessage(); log the real exception server-side only.

### [LOW] Raw exception message rendered to user in DB-user-name validator

- **File:** `source/src/Validator/Constraints/DatabaseUserNameValidator.php:26-28`
- **Module:** validators · **Category:** error-handling
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** Same pattern as DatabaseNameValidator: `catch (\Exception $e) { $this->context->addViolation($e->getMessage()); }`. Any internal error from the entity manager lookup is surfaced verbatim to the user instead of a controlled validation message, leaking internal error detail.

**Fix.** Use the constraint's generic message on the catch path and log the underlying exception server-side.

### [LOW] Reference to undefined UnexpectedTypeException causes fatal error instead of graceful handling

- **File:** `source/src/Validator/Constraints/DomainNameValidator.php:16`
- **Module:** validators · **Category:** correctness
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** `throw new UnexpectedTypeException($value, 'string');` is reached when a non-scalar, non-stringable value is supplied, but `UnexpectedTypeException` is neither imported nor defined in the App\Validator\Constraints namespace (no `use` statement, no such class under source/src). PHP will resolve it to the non-existent App\Validator\Constraints\UnexpectedTypeException and raise a fatal `Error: Class not found`, turning an intended validation rejection into a 500. Since this is the validator guarding domain-name input (a value that flows into nginx/site provisioning), a non-string array submission for the field yields an unhandled fatal rather than a clean validation failure.

**Fix.** Import Symfony\Component\Validator\Exception\UnexpectedTypeException (the intended class) or remove the type guard; ensure non-scalar input is rejected gracefully.

### [LOW] Dropbox validation exception message echoed to user

- **File:** `source/src/Validator/Constraints/DropboxAccessCodeValidator.php:49-52`
- **Module:** validators · **Category:** secret-leak
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** The outer catch does `$errorMessage = $e->getMessage(); $this->context->buildViolation($errorMessage)->addViolation();`. The try block exchanges an OAuth access code for a token/refresh token and runs rclone against a temp config built from that token. Failures (from DropboxAccessValidator, rclone, or token exchange) surface their raw message to the user, which can include OAuth/HTTP error bodies and command context. Unlike the S3/Wasabi/GDrive validators which return a fixed $constraint->message, this one leaks the underlying detail.

**Fix.** Use the constraint's generic message for the user-facing violation; log the real error server-side.

### [LOW] Reference to undefined UnexpectedTypeException in IP validator

- **File:** `source/src/Validator/Constraints/IpValidator.php:16`
- **Module:** validators · **Category:** correctness
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** Identical defect to DomainNameValidator: `throw new UnexpectedTypeException($value, 'string');` with no import and no such class in the namespace. A non-scalar submission (e.g. array) for an IP/CIDR field used in firewall/whitelist rules triggers a fatal `Class not found` Error instead of a validation violation. Also note the class constant PATTERN here is a copy of the domain regex and is dead/unused, suggesting copy-paste.

**Fix.** Import Symfony\Component\Validator\Exception\UnexpectedTypeException (or remove the guard) so malformed input is rejected cleanly; drop the unused PATTERN constant.

### [LOW] rclone/SFTP exception message surfaced to user may leak config/credential detail

- **File:** `source/src/Validator/Constraints/SftpCredentialsValidator.php:61-63`
- **Module:** validators · **Category:** secret-leak
- **Verdict:** uncertain (confidence 0.7) · severity adjusted medium → low

**Issue.** The outer catch does `$this->context->buildViolation($e->getMessage())->addViolation();`. The try block builds an rclone config file containing the SFTP host/user and the obscured password, then runs `rclone lsJson`. When rclone fails it throws with stderr/command context; that message (which can reference the temp config path, host, user, or command line) is rendered directly to the requesting user. Combined with the obscured-password handling this risks disclosing connection details and command internals to the browser.

**Fix.** Render a generic constraint message on failure (as the other credential validators do with $constraint->message) and log the raw rclone error server-side only.

### [LOW] Validator mutates entity state (site assignment) as a side effect during validation

- **File:** `source/src/Validator/Constraints/UserSitesValidator.php:23-35`
- **Module:** validators · **Category:** correctness
- **Verdict:** not adversarially verified (low severity, pattern-level)

**Issue.** validate() calls `$userEntity->removeSites()` and then `$userEntity->addSite($site)` for each domain name resolved from the submitted comma list. Validators are expected to be side-effect-free; performing the authoritative user->site assignment inside validation is fragile (it runs even if other fields fail validation, and the removeSites() at line 23 clears existing assignments before the role check fully completes). If the role is not ROLE_USER the sites are removed but never re-added on this path. This is a logic/robustness defect; any access-control gating for which sites a user may be granted is the controller's responsibility, but the unconditional removeSites() can drop a user's site associations as a side effect of a validation pass.

**Fix.** Move the site-assignment mutation out of the validator into the controller/form handler that runs only after successful validation; keep validate() read-only.

---

_Caveats: adversarial verification confirms a defect exists in the code as described; it is not a guarantee of exploitability in every deployment. Low-severity items were collected at pattern level and not individually re-verified. Restore-side tooling and runtime config (nginx/php templates, deploy scripts) live outside `source/src` and were not in scope._