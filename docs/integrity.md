# File integrity monitoring

Scan the filesystem, diff it against an approved baseline, and post a grouped
"New / Modified / Deleted" summary to mail / Slack / Discord. The Wordfence file
integrity check, for Laravel.

```
File integrity scan: app (2026-06-15 12:07 UTC)
8 new · 2 modified · 0 deleted · 92106 files total
🚩 New (8): public/cache/ff/3776/footer.php · ...
✏️ Modified (2): public/error_log · ...
```

## How it works

Each run builds a manifest of file hashes for a configured disk and compares it
two ways (hybrid):

- **Per-run delta** (vs the previous run): low-noise, drives the card.
- **Drift vs the pinned known-good baseline**: persists until you re-approve, so slow tampering keeps surfacing.

## Quick start

```bash
echo "LS_INTEGRITY_ENABLED=true" >> .env
echo "LS_INTEGRITY_HMAC_KEY=$(php -r 'echo bin2hex(random_bytes(32));')" >> .env

# First run establishes a PROVISIONAL baseline from the current disk state.
# It is NOT trusted yet (your host could already be compromised). Review, then approve.
php artisan shield:integrity

php artisan shield:integrity-status
php artisan shield:integrity-bless     # approve the reviewed baseline

# Later runs diff against the approved baseline and fire the notification on changes.
php artisan shield:integrity
```

Or from the dashboard at `/shield/integrity`: "Run scan" and "Approve baseline".

## Why the first run is not trusted

FIM is often installed onto an already-infected host. If the first scan silently
trusted "whatever is on disk", a webshell present at install would be baked into
the baseline and never reported again. So `auto_bless_on_first_run` defaults to
`false`: the first run records a `baseline_established` status, raises a loud
"review before trusting" notice, and you must `shield:integrity-bless` to approve it.

## Commands

```bash
php artisan shield:integrity            # run a scan (--disk, --trigger)
php artisan shield:integrity-bless      # approve the latest state as known-good (--disk)
php artisan shield:integrity-status     # latest run + baseline state (--disk)
php artisan shield:integrity-prune      # hard-delete runs/changes past retention
php artisan shield:integrity-heartbeat  # warn if scans have silently stopped (--disk)
```

Enable the schedule with `LS_INTEGRITY_SCHEDULE_ENABLED=true` (default cron hourly).
The scheduler also wires nightly pruning and an hourly heartbeat.

## Severity

A configurable, ordered, first-match ruleset (`shield.integrity.severity_rules`)
scores each change. The defaults:

| Severity | Matches |
|---|---|
| **critical** | new/modified script file (`.php`, `.phtml`, ...) under a web-reachable docroot (`public/`), the classic dropped webshell |
| **high** | new/modified `.php` in `app/ routes/ config/`; any deletion; any change under `vendor/` |
| **medium** | `.env` / `.htaccess` / `.user.ini` changes; a file that became unreadable |
| **low** | everything else |

Script-extension files are always hashed (even over `max_file_size`), so an
attacker cannot hide a backdated/oversized payload.

## Baseline integrity

The known-good baseline is a gzipped NDJSON artifact, written atomically and
HMAC-signed. A signature that fails to verify is reported as `tamper_suspected`
(critical) and the baseline is **not** rebuilt. The root hash is stored in the
DB and audit chain as well.

Honest limitation: on a fully compromised host the attacker can read the key and
re-sign, so the HMAC defends against unprivileged `storage/` writes and accidental
corruption, not a root compromise. Off-box attestation (via the Central app) is a
later phase. Keep the key in a file outside the web root: `LS_INTEGRITY_HMAC_KEY_PATH`.

## Notifications

Routed through the standard notification config. Enable the event and pick channels:

```env
FIREWALL_NOTIFICATIONS_INTEGRITY_CHANGED_ENABLED=true
```

```php
// config/shield.php
'notifications' => [
    'integrity_changed' => ['enabled' => ..., 'channels' => ['mail', 'discord']],
],
```

The card leads with the per-run delta and carries a "N files differ from the
approved baseline" drift line. Zero-delta runs are suppressed
(`integrity.notify.suppress_when_no_changes`), but security/operational events
(`tamper_suspected`, `baseline_established`, `failed`, `aborted_limit`) always
notify. Per-channel limits are respected and the highest-severity paths are shown
first, never truncated into "+N more".

## Configuration

The full `shield.integrity` block lives in `config/shield.php`: `disks` (roots +
include/exclude globs + `follow_symlinks` + `max_file_size`), `schedule`,
`baseline` (`auto_bless_on_first_run`, `hmac_key_path`, `hmac_key`), `limits`
(`max_files`, `max_iterations`, `max_runtime`, `max_persisted_changes_per_run`),
`notify`, `retention`, `heartbeat`, and `severity_rules`.

The default `app` disk scans `base_path()` excluding `vendor/`, `node_modules/`,
`.git/`, `storage/framework/`, `storage/logs/`, `bootstrap/cache/`, and
`storage/shield/` (where the baseline artifact lives).

## Scope notes

- Phase 1 targets the **local filesystem** (`hash_file` streams, so memory stays flat).
- Remote disks (sftp/s3) via the `Storage` abstraction, an S3 ETag pre-filter, a
  `composer.lock` dist-hash cross-check, and premium real-time monitoring are later phases.
- The existing `config_drift` scanner target overlaps narrowly and is unchanged.
