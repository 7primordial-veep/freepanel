# Streaming Compressed Remote Backups — Design

Date: 2026-05-26
Status: Approved design (pending spec review)
Area: `source/src` — remote backup creation

## Problem

`App\Command\RemoteBackupCreateCommand::backupSite()` currently backs up each site by:

1. Running `sudo tar cfv /home/{user}/tmp/backup.tar <home> <vhost> --exclude=…` — a full,
   **uncompressed** archive written to the **local** disk.
2. `Rclone::copy(/home/{user}/tmp/backup.tar, <remote dir>)` to upload it.
3. Deleting the local `backup.tar` in a `finally` block.

Two problems:

- **Local disk pressure.** The entire uncompressed archive must fit on the local disk
  before upload. On a near-full server this can fail or fill the disk.
- **Remote storage cost.** Uploaded archives are uncompressed and larger than necessary.

## Goal

Stream the backup directly to the remote as a **gzip-compressed** archive, using **no
local temporary file**. Compressed bytes are produced by `tar` and piped straight into
`rclone rcat`, which writes a single remote object.

## Non-goals

- Configurable compression codec (zstd, levels) or an enable/disable toggle — gzip is
  hardcoded. (This was Approach C, explicitly deferred.)
- Changing retention/cleanup, provider config templates, or the database backup step.
- Implementing or changing restore tooling (restore lives outside this repository).

## Context / constraints discovered

- `backup.tar` is referenced only on the **creation** side in this repo. Restore is handled
  by CloudPanel's external cloud-installer tooling, which hardcodes the `backup.tar`
  filename.
- GNU `tar -xf` auto-detects gzip/zstd/xz on extraction regardless of file extension, so
  extracting a `.tar.gz` requires no flag change on the restore side. The compatibility
  concern is the **filename**, not the extract command.
- All configured providers — Amazon S3, DigitalOcean Spaces, Wasabi, Dropbox, Google Drive,
  SFTP — support streaming/chunked uploads, so `rclone rcat` avoids a local temp file for
  every backend actually in use.
- Existing rclone invocation pattern (from `RcloneCopyCommand`):
  `/usr/bin/sudo /usr/bin/rclone -v copy <src> remote:<dst> --flag='val' …`. The rclone
  remote is literally named `remote`. Flags render as `--flag='value'` via `escapeshellarg`.

## Decisions

- **Filename:** new backups are named `backup.tar.gz`. External restore tooling and any UI
  listing that hardcodes `backup.tar` must be updated in lockstep (flagged below). Existing
  `backup.tar` archives remain valid and are extracted the same way.
- **tar exit code 1 = success:** `tar` exits 1 when a file changes while being read.
  Volatile directories (`logs`, `tmp`, `.ssh`) are already excluded. The backup is treated
  as successful for tar exit codes 0 and 1, and failed only for tar exit code ≥ 2 or any
  non-zero `rclone` exit. Implemented via `PIPESTATUS` (see below), not bare `pipefail`,
  because bare `pipefail` cannot distinguish tar's warning exit (1) from a fatal one (≥2).
- **Remove the remote object on failure:** if the pipeline fails (tar ≥ 2 or any rclone
  error), the possibly-truncated `backup.tar.gz` is deleted from the remote so no partial
  archive is left behind. Done PHP-side in the existing `catch` (see Orchestration), not
  inside the bash string, to keep the upload command single-purpose and the cleanup
  independently testable.

## Design

### New component: `App\System\Command\TarStreamUploadCommand`

Extends `App\System\Command`, following the existing one-object-per-shell-command pattern.
Renders a single piped pipeline executed through `bash -c`:

```
/usr/bin/sudo /bin/bash -c '\
  /bin/tar czf - <renderedExcludes> <sources> --warning=no-file-changed \
  | /usr/bin/rclone -v rcat <renderedRcloneFlags> remote:<destinationObject>; \
  ts=${PIPESTATUS[0]}; rs=${PIPESTATUS[1]}; \
  if [ "$ts" -gt 1 ] || [ "$rs" -ne 0 ]; then exit 1; fi; exit 0'
```

Notes:
- `tar czf -` writes the gzip stream to stdout (drops the `v` verbose flag used by the
  non-streaming command, since file lists are noise here).
- `PIPESTATUS` is captured immediately after the pipe; `${PIPESTATUS[0]}` is tar's status,
  `${PIPESTATUS[1]}` is rclone's. The explicit `if` implements the "tar exit 1 = success"
  decision.
- The inner command string is built so the single-quoted `bash -c` argument is assembled in
  PHP; sources, excludes, the destination object, and rclone flag values all pass through
  `escapeshellarg()`, matching `TarCreateCommand`/`RcloneCopyCommand`.

Setters (mirroring the two commands it replaces):
- `setSources(array $sources)` / `getSources()`
- `setExcludes(array $excludes)` → rendered as `--exclude='…'` flags (same as `TarCreateCommand`)
- `setRcloneConfigFile(string $file)` → adds `--config='…'`
- `addRcloneFlag(string $flag, string $value)` → for `--drive-impersonate`, etc.
- `setDestinationObject(string $object)` → the remote path **including** `backup.tar.gz`
- `isSuccessful(): bool` returns `true` (consistent with sibling commands; success is
  enforced by the process exit code via `CommandExecutor`).

### New component: `App\System\Command\RcloneDeleteFileCommand`

Single-file remote delete, modeled directly on `RclonePurgeCommand` (which purges a whole
path). Renders:

```
/usr/bin/sudo /usr/bin/rclone deletefile remote:<object> <renderedFlags>
```

Setters: `setRemotePath(string $object)`, `setConfigFile(string $file)` (adds `--config`),
`setGoogleDriveEmail(string $email)` (adds `--drive-impersonate`), `addFlag()`,
`isSuccessful(): bool` → `true`. `rclone deletefile` is idempotent enough for our use: a
"not found" (nothing was uploaded) is treated as a non-fatal cleanup outcome.

A `Rclone::deleteFile(string $object)` method wraps it (mirroring `Rclone::purge()`),
applying the current `$flags` and config file, executed via `CommandExecutor`.

### Orchestration change: `RemoteBackupCreateCommand::backupSite()`

- Remove the `TarCreator` usage, the `Rclone::copy()` call, and the `backup.tar` entry from
  the `finally` delete list (there is no local archive to delete anymore).
- Keep building the destination directory via `getDestination($siteEntity)` (still ends in
  `/home/{user}/`), then form the destination object by appending `backup.tar.gz`.
- Construct a `TarStreamUploadCommand` with the same `$sources` (`$homeDirectory`,
  `$vhostFile`) and `$excludes` as today, set the rclone config file
  (`/root/.config/rclone/rclone.conf` — the `Rclone` default), and re-apply the existing
  provider-specific flag (Google Drive `--drive-impersonate=<email>`).
- Execute via the existing `CommandExecutor` with the current `21600` second timeout (now
  covering tar + upload in one process).
- `site-settings.json` and `site-vhost` are still written before the archive command and
  deleted in `finally` (unchanged). The Dropbox token refresh path is unchanged.
- **On failure, delete the remote object.** The destination object string is computed
  before the `try` (so it is in scope in `catch`). In the existing `catch`, before adding
  the failure notification, call `Rclone::deleteFile(<destinationObject>)` (with the same
  provider flag, e.g. Google Drive impersonation) to remove any truncated upload. The
  cleanup is itself wrapped so a cleanup error does not replace the original failure
  message — the admin is still notified of the backup failure, and a cleanup failure is
  logged via `$this->logger`.

### Unchanged

- `cleanBackups()` — operates on dated directory names, not archive filenames.
- Provider config templates and `Rclone` config/credential-writing methods.
- The database backup sub-step (`db:backup`).
- `TarCreator` and `Rclone::copy()` remain in the codebase for any other callers; this
  change only stops `backupSite()` from using them. (Confirm no other production caller
  depends on them during implementation; if none, note for possible later cleanup — not in
  scope here.)

## Error handling

- Pipeline failure (tar ≥ 2 or any rclone error) → non-zero process exit → `CommandExecutor`
  throws → `backupSite()`'s existing `catch` raises a `SEVERITY_CRITICAL` notification
  ("Remote Backup failed: {domain}"), as today.
- **Truncated upload is cleaned up:** because bytes are uploaded as they are produced, a
  mid-stream failure (tar exit ≥ 2 or rclone error) can leave a **truncated**
  `backup.tar.gz` on the remote. The `catch` removes it via `Rclone::deleteFile()` (see
  Orchestration), so no partial archive remains. Each run also writes to a new timestamped
  directory, so a good prior backup is never overwritten. Residual edge case: if both the
  upload **and** the subsequent delete fail (e.g. remote unreachable), a partial object may
  persist until the next successful run or retention cleanup; this is logged and notified.
- **Integrity:** `rclone rcat` performs per-chunk integrity during upload rather than
  `copy`'s post-upload size/checksum comparison against a known local file. This is a minor
  reduction in end-to-end verification and is accepted as the cost of not staging locally.

## Testing

Unit tests assert the rendered command string for `TarStreamUploadCommand`:
- Plain provider (config file + sources + excludes + `backup.tar.gz` destination).
- Google Drive variant (adds `--drive-impersonate='…'`).
- Excludes rendered as repeated `--exclude='…'` flags.
- Shell-escaping of a source path containing a space.
- The `PIPESTATUS` guard is present (tar exit 1 tolerated, ≥ 2 fails).

And for `RcloneDeleteFileCommand`:
- Plain delete (`deletefile remote:<object>` with `--config`).
- Google Drive variant (adds `--drive-impersonate='…'`).

No integration test against live remotes (consistent with the existing suite, which does
not exercise real rclone).

## External coordination required (out of repo)

The CloudPanel **restore tooling** (cloud-installer) and any backup-listing UI that assumes
the literal `backup.tar` filename must be updated to recognize `backup.tar.gz` (ideally
accept both, so old and new backups both restore). This change must ship in lockstep with
that update. Extraction itself needs no change (`tar -xf` auto-detects gzip).
