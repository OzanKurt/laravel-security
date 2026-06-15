# Spec 005: File integrity scan + summary notification

- Status: Draft
- Track: Core (file integrity monitoring). New track, separate from the 001-004 premium-parity specs.
- Tier: Baseline scanning is FREE. Only real-time integrity monitoring is premium (deferred to a later phase).
- Premium feature flag (later phase): `integrity_realtime`

This spec reproduces the "file integrity scan summary" notification (the Axel-bot
screenshot: "N new / N modified / N deleted / N files total" with the changed paths
grouped by type, posted to Discord/Slack/email) as a first-class, secure, scalable
feature of `ozankurt/laravel-shield`.

It was hardened by a multi-agent review pass (codebase mapping + 5 adversarial lenses:
security, integration, scale, edge-cases, notification fidelity). The non-obvious
findings from that pass are baked into the design below and called out where they
overturned the naive approach.

---

## 1. Problem / current state

The package can detect malware (signature scanner) and watch files in real time
(`shield:watch`), but it has no "diff the filesystem against an approved baseline and
send one grouped summary" capability. The closest pieces today:

- `shield:watch` ([src/Console/Commands/WatchCommand.php](../../src/Console/Commands/WatchCommand.php)) keeps an in-memory `path => sha256` baseline and emits **per-file** `created`/`updated`/`deleted` events. It is a long-running process, not a periodic summary, and the baseline is lost on restart.
- The `config_drift` scanner target does checksums-vs-baseline but only for `config/*.php`.
- `ScannerRun` / `ScannerFinding` model malware findings, not integrity deltas.

So the exact thing in the screenshot, a scheduled batch that snapshots the whole file
set, diffs it, and posts a single digest, does not exist.

### 1.1 Prerequisite reality check (these blocked the naive design)

The naive plan assumed infrastructure that is **not in the codebase**. Confirmed against source:

1. **No routing matrix, no throttle.** `docs/notifications.md` documents a severity routing matrix and a throttle, but `config/shield.php` has only `notifications` (event toggles) and `notification_channels` (delivery). `via()` enables a channel purely on its `enabled` flag. There is no severity routing and no coalescing anywhere. This spec must NOT claim them as existing infra; it ships a flat per-event config plus a small feature-local throttle.
2. **Slack channel is not installed.** `composer.json` declares no Slack channel and `vendor/laravel/` contains only `framework`, `prompts`, `serializable-closure`, `tinker`. `Illuminate\Notifications\Messages\SlackMessage` is not guaranteed present on Laravel 10-12 (it was extracted to `laravel/slack-notification-channel`). The existing `toSlack()` methods would fatal the moment Slack is an active channel.
3. **`Notifiable` reads the wrong config paths.** [src/Notifications/Notifiable.php](../../src/Notifications/Notifiable.php) reads `config('shield.notifications.mail.to')` / `...slack.to` / `...discord.webhook_url`, but delivery settings live under `config('shield.notification_channels.*')`. Mail/Slack `to` resolve to `null` today.
4. **`DiscordMessage::toArray()` bugs** ([src/Notifications/Channels/Discord/DiscordMessage.php:114-135](../../src/Notifications/Channels/Discord/DiscordMessage.php#L114)): `hexdec($this->color)` with a nullable `$color` default (TypeError when no color method was called), reads an undefined `$this->footerImg` (should be `$this->footerUrl`, so the footer icon never renders), and `timestamp ?? now()` emits a Carbon object instead of an ISO-8601 string.
5. **`via()`/`viaQueues()` are buggy** in `AttackDetectedNotification` (iterates event keys as if they were channel names), and `FailedLoginNotification` / `SuccessfulLoginNotification` have a literal `$this->$this->config` typo. These must NOT be copied as the template.

Section 9 fixes 1-5 as in-scope prerequisites with regression tests, because the new
notification depends on every one of them working.

---

## 2. Goal

A scheduled (and on-demand) **file integrity scan** that:

1. Snapshots a manifest of file hashes for a configured Laravel filesystem disk.
2. Diffs the current state against a **pinned known-good baseline** AND against the **previous run** (hybrid model).
3. Persists a run + per-file changes for the dashboard.
4. Sends one grouped **summary notification** (mail / Slack / Discord) that looks like the screenshot, leading with the per-run delta and carrying a known-good drift indicator.
5. Is **safe by default on an already-compromised host** and **viable at ~92,000 files**, including the cPanel/FTP docroot scenario in the screenshot.

---

## 3. Non-goals (Phase 1)

- **Remote disks (sftp/s3) as a first-class, tuned path.** The engine is disk-abstraction-based so a remote disk *works*, but the S3 ETag pre-filter, partial-read failure budget, and cleartext-FTP warnings are Phase 2. Phase 1 targets the local disk (including a docroot outside `base_path()`).
- **Premium real-time integrity** (reuse the watcher, gated by `integrity_realtime`). Phase 2.
- **Off-box authority** (Central attestation of the baseline root hash, critical-alert decisioning, two-person bless, dead-man's-switch). Phase 3. Phase 1 keeps a *local* heartbeat + dashboard banner and stores the root hash in DB + the HMAC audit chain so Phase 3 can slot in.
- **Known-good cross-check against `composer.lock` dist hashes / framework manifests.** Strongly desired (it is the only real "good" reference on a fresh-but-infected host) but heavy; Phase 2. Phase 1 mitigates with the provisional-first-run posture (Section 6.1).
- Building the documented severity routing matrix as a general framework. This spec ships only what the integrity card needs.

---

## 4. Architecture

New module `src/Services/Integrity/` with three single-purpose units.

### 4.1 `Manifest` (the shared hasher)

Given a disk + scan spec (include/exclude globs, `follow_symlinks`, `max_file_size`,
`hash_algo`), walks files and produces an **ordered** (sorted by path) list of entries:

```
{ path, sha256|null, size, mtime, kind }   // kind: hashed | size_only | symlink | unreadable
```

Rules that the review made non-negotiable:

- **Canonical key** = disk-relative POSIX path (forward slashes), one normalized form. This is the single key shared with the refactored `shield:watch` baseline so path formats never drift between runs or between watch and integrity.
- **Disk-aware streaming hash.** Laravel `Storage` has no streaming-hash primitive. Local disks use a fast path (`hash_file` on the absolute path). Non-local disks use `hash_init` + `Storage::readStream` + chunked `hash_update` (never `Storage::get`, which loads the whole file into memory).
- **Incremental.** Accepts the prior (known-good) manifest. A file whose `size` AND `mtime` both match the baseline is carried forward as unchanged WITHOUT re-hashing. Only new files and size/mtime-changed files are hashed. This is the single biggest scale win and is mandatory at 92k files. A configurable `full_rehash_cron` forces a periodic content re-verification (defends against an attacker who preserves size+mtime; see 6.5).
- **Symlinks not followed by default.** A symlink is recorded as its own entry (`kind=symlink`, with target) so a newly appeared symlink is itself a change, even though its target is not hashed. A resolved path that escapes the disk root (traversal) is flagged, not hashed.
- **Always hash script extensions** (`.php .phtml .phar .phps .inc` etc.) regardless of `max_file_size`. The oversized-skip-hash optimization must never apply to executable content (anti-evasion).
- **Iteration caps** carried over from the existing `DirectoryIterator` (`max_files`, `max_iterations`, symlink-loop protection). Exceeding a cap aborts the run with `status=aborted_limit` + an ops signal, never a silent truncation.
- **`->hashes(): array<path,sha256>` view** for back-compat: `WatchCommand::scanPaths()` delegates to `Manifest` but consumes this flat view, preserving its exact string-hash compare. Watch currently keys on **absolute OS paths**; the back-compat view returns those same absolute-path keys for the watch use case, so watch's `file.drift` audit-log path format is unchanged. The integrity engine uses canonical disk-relative POSIX keys internally. A regression test asserts identical created/updated/deleted output before and after the refactor.

### 4.2 `Baseline` (pinned known-good)

- Stored as a **sorted gzipped NDJSON artifact** with a header recording the **scope fingerprint** (disk, include, exclude, follow_symlinks, max_file_size, hash_algo) and the **root hash** (a hash over the sorted manifest). Default path `storage/shield/integrity/<disk>/baseline.ndjson.gz`; `storage/` is in the default exclude set so the artifact is never self-scanned.
- **HMAC-signed**, with honest limits documented:
  - Signing key resolves from `integrity.baseline.hmac_key_path` (a file `chmod 600` **outside** the scan root) and falls back to `LS_INTEGRITY_HMAC_KEY`. The spec states plainly that any same-host key is readable by an attacker who already has file write; HMAC defends against an unprivileged process that can write `storage/` but not read the key, and against accidental corruption. Real authority is Phase 3 (off-box).
  - **Hard-fail (refuse to sign/run, raise an alert)** if the resolved secret is empty or equals the audit-log dev fallback constant. No silent signing with a public constant.
  - Domain separation: the HMAC payload is prefixed with `shield.integrity.baseline.v1`, distinct from the audit chain.
  - Recursive canonical serialization before HMAC (sorted NDJSON is already canonical).
- **Root hash is mirrored into `ls_integrity_baselines` (DB) and the HMAC-chained audit log**, so hiding a tamper requires forging the artifact, the DB row, and the audit chain.
- **Atomic write** (tmp file + rename). The active-baseline pointer (`ls_integrity_baselines`) flips inside a DB transaction only after the artifact is fully written and re-verified.
- **HMAC verify failure on an existing artifact = security event** (`tamper_suspected`): the run records that status, fires a high/critical security signal, and does NOT auto-rebless. A **missing** artifact is the first-run path (provisional, Section 6.1). A truncated/unparseable artifact fails the run and requires an explicit re-bless. The design never silently rebuilds the baseline on a verification failure.

### 4.3 `IntegrityScanner` (orchestrator)

Per run:

1. Acquire a per-disk `Cache::lock("shield:integrity:{disk}", ttl=max_runtime)`. Require an atomic-lock-capable cache store (redis/memcached/database); fail loud if the store is `array`. If the lock is held, record `status=skipped` and exit 0 (do not pile up). If skipped for N consecutive intervals, escalate (heartbeat, 6.4).
2. Load + HMAC-verify the known-good baseline. Compare its scope fingerprint to the current run's spec; on mismatch, either refuse and require re-bless, or diff over the scope intersection and label entrants/leavers as `scope_changed` (a non-alarming change type), never as new/deleted. The scope intersection is the set of paths that match include-and-not-exclude under the roots in BOTH the baseline spec and the current spec; a path in-scope under only one spec is `scope_changed`.
3. Build the current manifest incrementally (4.1).
4. **Streaming merge-join diff** over sorted manifests vs known-good AND vs the previous run's stored manifest. Peak memory is O(1) in file count, not O(n): never hold three full 92k maps simultaneously.
5. Classify each change: `new | modified | deleted | scope_changed | became_unreadable | vanished | volatile | symlink_new`. A file that vanished mid-scan is dropped. A tracked file that became unreadable is `became_unreadable` (medium), never "deleted" and never "unchanged"; its last-known hash is carried with a stale flag. A file whose mtime/size changed again right after hashing is `volatile` and excluded from severity escalation.
6. Compute per-change severity (6.5) and run severity. Alarm/paging severity derives from the **per-run delta**; a critical **known-good drift** path can still escalate the card and is always listed explicitly (no slow-drip hiding).
7. Persist the run + changes with **chunked `DB::table()->insert()`** in batches (~1000), carrying the hash from the manifest (no re-hash in the persist loop), capped at `max_persisted_changes_per_run` with a "truncated, N more" marker.
8. Write the current manifest as the run artifact (for the next run's previous-run diff).
9. Honor a `max_runtime` budget: on exceed, finish as `status=timed_out` recording files processed.
10. Fire `IntegrityScanCompletedEvent($run)`; release the lock.

`shield:integrity-bless` **promotes the most recent successful run's manifest** to known-good (it does not re-walk the disk), writes atomically, takes the per-disk lock, is audited (actor + diff summary + old/new root hash), and is idempotent by content hash.

---

## 5. Data model

Conventions (from `src/Support/Migrations/ShieldBlueprint.php`, `src/Concerns/*`,
`src/Models/Lookups/Lookup.php`): data tables get `id` + `uuid` (uuid7) + optional
`correlation_id` + userstamps + softDeletes + timestamps via `ShieldBlueprint::applyStandard()`;
lookup tables get only `id/name/label/description/sort_order/meta/timestamps`; FK columns
are `unsignedBigInteger` + indexed + explicit `foreign()`; **no enums, int FK to seeded
lookups**; connection set from `config('shield.database.connection')`; lookups created
in a migration BEFORE the data tables that reference them.

### 5.1 Data tables

`ls_integrity_runs`:

| column | notes |
|---|---|
| `status_id` | FK `ls_integrity_statuses` |
| `trigger_id` | FK `ls_scanner_triggers` (reused; new names seeded) |
| `severity_id` | FK `ls_log_levels` (reused; same lookup `ScannerFinding.severity_id` uses) |
| `disk` | string |
| `scope_fingerprint` | hash of the effective scan spec |
| `baseline_root_hash`, `current_root_hash` | string |
| `files_total`, `files_hashed`, `files_size_only`, `files_unreadable`, `files_excluded` | int counters (5.4) |
| `count_new`, `count_modified`, `count_deleted`, `count_scope_changed`, `count_vs_known_good` | int |
| `started_at`, `finished_at`, `duration_ms`, `error_message` | |
| + `applyCorrelationId()` + `applyStandard()` | |

`ls_integrity_changes` (high volume):

| column | notes |
|---|---|
| `integrity_run_id` | FK `ls_integrity_runs` |
| `change_type_id` | FK `ls_integrity_change_types` |
| `compared_to_id` | FK `ls_integrity_comparison_bases` (NOT a string enum) |
| `severity_id` | FK `ls_log_levels` |
| `path` | string, indexed |
| `old_hash`, `new_hash`, `size_bytes`, `file_mtime`, `symlink_target` | |
| + `applyCorrelationId()` + `applyStandard()` | keeps the house uuid7/userstamps/softDeletes convention |

> Volume note: keeping softDeletes on a derived, regenerable, high-volume table is a
> deliberate consistency choice (the package's hard rule is "every table gets
> uuid7+userstamps+softdeletes"). It is mitigated by `max_persisted_changes_per_run`
> and a `shield:integrity-prune` command that **force-deletes** (bypasses softDelete)
> rows older than `retention.changes_days`. The notification never hydrates this table
> (5.5).

`ls_integrity_baselines`:

| column | notes |
|---|---|
| `disk`, `scope_fingerprint`, `root_hash`, `artifact_path`, `algo` | |
| `files_total`, `signed` (bool), `blessed_at` | |
| + `applyStandard()` | `blessed_by` comes from the userstamp `created_by_id` |

One active baseline per disk (older ones soft-deleted).

### 5.2 New lookup tables (dedicated, per the convention rec)

- `ls_integrity_statuses`: `running, completed, failed, baseline_established, tamper_suspected, incomplete, timed_out, skipped, aborted_limit, cancelled`.
- `ls_integrity_change_types`: `new, modified, deleted, scope_changed, became_unreadable, vanished, volatile, symlink_new`.
- `ls_integrity_comparison_bases`: `last_run, known_good`.

### 5.3 Lookup seeding

Add `seedIntegrityStatuses()`, `seedIntegrityChangeTypes()`, `seedIntegrityComparisonBases()`
to `database/seeders/LookupTableSeeder.php` (same `updateOrCreate(['name'=>...])` loop as
`seedScannerStatuses()`), called from `run()`. Add the new `ScannerTrigger` names
(`integrity_scheduled, integrity_manual, integrity_dashboard, integrity_watch`) to
`seedScannerTriggers()`. After seeding new lookups at runtime, `LookupResolver::flush()`
the affected classes.

### 5.4 `files_total` definition (stability matters)

`files_total` = distinct in-scope paths **present** at enumeration, whether hashed,
size-only, or unreadable. A file that is present-but-unreadable is still counted (it
exists), not dropped; only paths that are **absent** now are excluded from `files_total`.
Reported alongside `files_hashed`, `files_size_only`, `files_unreadable`,
`files_excluded`. `count_*` is computed over the hashed + size-only set. A tracked file
that is present but unreadable is classified `became_unreadable` and counted in
`files_unreadable`, never as `deleted`; this keeps `files_total` stable run-to-run when a
file is transiently locked. The card renders `files_total` identically to the dashboard.

### 5.5 Models

`IntegrityRun`, `IntegrityChange`, `IntegrityBaseline` follow the `ScannerRun` pattern
(`HasUuid, HasUserstamps, SoftDeletes`, connection in `__construct`, `belongsTo` lookups,
`hasMany` changes). Lookup models (`IntegrityStatus`, `IntegrityChangeType`,
`IntegrityComparisonBasis`) extend `Lookup` with just `$table`.

---

## 6. Security model (the part that makes it a real control)

### 6.1 No silent auto-bless on first run (default `auto_bless_on_first_run = false`)

FIM is often installed onto an already-compromised host, so trusting "whatever is on
disk at install" certifies the webshell as good forever. First run therefore:

- Establishes a **provisional** baseline (`status=baseline_established`), marked unverified.
- Sends a loud, non-suppressible "baseline established from N files, NOT independently verified, review before trusting" notice that **lists executable/script files found in web-reachable paths** at bless time, applying the critical severity heuristic.
- Requires an explicit `shield:integrity-bless --confirm` (or the dashboard button) to promote provisional to trusted.
- Phase 2 adds the `composer.lock` dist-hash / framework cross-check so `vendor/` and framework files get a real external "good" reference on first run.

### 6.2 Bless is an audited, attributable, permission-gated action

Every bless writes an HMAC-chained audit entry: actor, the diff being blessed, old/new
root hash. The dashboard button is gated behind a dedicated permission, not generic
dashboard access. Blessing the same content twice is a no-op.

### 6.3 Self-protection scope (non-excludable targets)

`config/shield.php`, the package service provider, the host scheduler entrypoint
(`app/Console/Kernel.php`, or `bootstrap/app.php` on Laravel 11+), the package's own
`src/`, and the baseline/key artifacts are always-tracked and cannot be excluded.
Enable/disable state transitions are written to the HMAC audit log.

### 6.4 Local heartbeat / dead-man's-switch

A scheduled check raises a high-severity dashboard banner + alert when there has been no
successful integrity run in N schedule intervals, or when runs are repeatedly `skipped`
(lock starvation) or `timed_out`. This converts "attacker disabled the scanner" and
"silent stop on a leaked lock" into a positive signal. (`suppress_when_no_changes` makes
silence ambiguous; the heartbeat removes that ambiguity.) Full off-box dead-man is Phase 3.

### 6.5 Severity heuristic (ordered, first-match, configurable)

Run severity = max over its changes. Drives the card color and the throttle decision.

- **critical**: new/modified script-extension file under a web-reachable docroot (`public/`, `storage/app/public/`, the configured public docroot). This is the exact screenshot case (`footer.php`/`header.php` under a cache dir). This rule is **non-suppressible**: a script file may be excluded from NOISE but never from script-extension detection, and is critical even on the first run or right after a bless (a script not in the blessed set is suspicious by definition).
- **high**: new/modified `.php` in `app/ routes/ config/`; deletion of any tracked file; change under `vendor/` (Phase 2 refines this to "differs from `composer.lock` dist hash" to kill deploy false-positives).
- **medium**: `.env`/`.htaccess`/`.user.ini` change; `became_unreadable`; modified non-code in sensitive dirs.
- **low**: everything else (e.g. the screenshot's `error_log` churn).

### 6.6 Anti-evasion details

- Schedule **jitter** (randomized offset) so the scan instant is not predictable (defeats minute-59 swap-back); the cron is not exposed externally.
- Script-extension files always hashed (6.1 / 4.1), so size+mtime backdating cannot skip them.
- mtime going backwards with differing content is flagged suspicious.
- Abnormally large change set outside a maintenance window is itself a high-severity signal ("possible mass tampering / log-flood evasion"), not merely a truncated card.

### 6.7 Information-disclosure containment

- Secret-bearing files (`.env`, key files) are tracked by **existence + mtime only by default** (or HMAC, not bare sha256) so the stored value is not a guess-verification oracle and never lands on the wire.
- Outbound notifications **redact sensitive paths** for those categories ("`.env` changed" without the tree), and external-channel path disclosure is opt-in with a clear warning. Default to "N changes, view in dashboard" over dumping a full path list to a third-party webhook.
- The manifest artifact and webhook URLs are documented as secrets; the artifact reveals filesystem layout.

### 6.8 Notifications cannot be silently suppressed or flooded around

- Within each group, sort by **severity DESC then path**; a critical finding is NEVER pushed into "+N more" and forces the card to critical.
- Critical-severity integrity changes **bypass the feature-local throttle** (the throttle is for low/medium noise).
- Security-critical signals (`tamper_suspected`, baseline missing/corrupt, repeated remote failure, provisional first bless) **always** go to the audit log + Laravel log + a persistent dashboard banner, regardless of notification-channel config, and bypass `suppress_when_no_changes`.

---

## 7. Notification

### 7.1 `IntegrityScanCompletedNotification`

- `via()` reads the **event block** `config('shield.notifications.integrity_changed')` and iterates its `channels` array (NOT the broken `AttackDetectedNotification` pattern that iterates event keys). Channel delivery comes from `config('shield.notification_channels.<channel>')`.
- Card is built from **bounded queries**: `WHERE integrity_run_id=? AND change_type_id=? ORDER BY severity DESC, path LIMIT (max_paths_per_group+1)` per group, plus persisted `count_*` for headers. The full change set is never hydrated (avoids OOM on a 20k-file deploy).
- Header = **delta only**, labeled "Since last scan". A separate, muted "Vs approved baseline: N files differ (since `<blessed_at>`)" block carries the known-good drift. The two counts are never merged. Per-group "view all" deep-links to `compared_to=last_run`; the drift CTA links to `compared_to=known_good`.
- Timestamp rendered with explicit `->setTimezone(config('shield.integrity.notify.timezone','UTC'))` so "(UTC)" is a conversion, not a mislabel. Paths shortened (strip `base_path()`).
- Translations under `shield::notifications.integrity_changed.{mail,slack,discord}.*`: title, summary line, group headers, "+N more", drift line, and the `baseline_established` / `baseline_missing` / `all_clear` variants.

### 7.2 Per-channel rendering + hard limits

A shared `SeverityColor` helper maps `critical/high/medium/low/all_clear` to hex, reused
by all three channels.

- **Mail**: executive markdown theme like `SecurityReportNotification` (grouped tables + `mail::button` CTA to the run page).
- **Discord**: `toDiscord()` with NO `$notifiable` param (matches `DiscordChannel::send`). Put group lists in the embed **description** (4096 limit) or budget field values under ~1000 chars; the single authoritative link goes in the embed **title url** (no per-field links). A validator enforces Discord limits before POST (field value <=1024, name/title <=256, description <=4096, <=10 fields, <=6000 embed total, <=2000 message, <=10 embeds) and truncates deterministically with a trailing "+N more -> dashboard", degrading to counts-only if even truncated lists overflow. Requires the `DiscordMessage` fixes in Section 9.
- **Slack**: Block Kit (`laravel/slack-notification-channel` v3, added in Section 9). Groups as section blocks (mrkdwn), <=3000 chars/section, <=50 blocks, a `<url|View all>` link or actions block.

### 7.3 `IntegrityScanCompletedListener` + feature-local throttle

- Modeled on `AttackDetectedListener` (thin, **no** `ListenerHelper`: integrity runs in console with no HTTP request/auth-log context). Checks `config('shield.notifications.integrity_changed.enabled')`, then `(new Notifiable)->notify(new IntegrityScanCompletedNotification($run))` wrapped in `try/report()`.
- Throttle: a `Cache` counter keyed by `disk + delta-severity + changeset-hash` over a window, emitting a "N similar runs suppressed" continuation line. Critical bypasses it (6.8).
- `suppress_when_no_changes` suppresses ONLY when the per-run delta is empty AND there is no security event. Persistent known-good drift does not re-page hourly; instead a separate `notify.drift_reminder_cadence` (default daily, not hourly) nags until re-bless.
- Registered in `ShieldServiceProvider::registerListeners()` via `$this->app['events']->listen(IntegrityScanCompletedEvent::class, IntegrityScanCompletedListener::class)`.

---

## 8. Commands, scheduling, dashboard, queue

### 8.1 Commands (registered in `ShieldServiceProvider::registerCommands()`)

- `shield:integrity {--disk=} {--trigger=integrity_manual} {--sync}` (run; `--sync` only for tiny sites/tests).
- `shield:integrity-bless {--disk=} {--confirm}` (promote last run's manifest; audited; atomic).
- `shield:integrity-status {--disk=}`.
- `shield:integrity-prune` (retention; force-delete).

### 8.2 Scheduling (config-driven, jittered)

In the `booted()` callback, guarded by `config('shield.integrity.schedule.enabled')`:
`->command('shield:integrity')->cron(config('shield.integrity.schedule.cron'))->withoutOverlapping($ttl)`
plus a jitter offset. Cron read from config only (env default lives in `config/shield.php`,
so `config:cache` bakes it). A separate heartbeat check (6.4) and the
`full_rehash_cron` are wired the same way.

### 8.3 Queue isolation

The scan runs on a **dedicated queue** `config('shield.integrity.queue','shield-integrity')`,
NOT `default` (which carries mail/slack notifications, so a long scan must not delay
attack alerts). Job `$timeout >= max_runtime`, `$tries = 1` (a half-finished integrity
scan must not silently retry and re-hammer the disk). The dashboard "Run now" button
**dispatches a job and polls run status** (mirrors `ScannerController`); it is never
synchronous over 92k files. Polling endpoint: `GET shield/integrity/runs/{uuid}?mode=status`
returns JSON `{ status, count_new, count_modified, count_deleted, files_processed, finished_at }`;
the page polls every few seconds until a terminal status (`completed`/`failed`/`timed_out`/`incomplete`).

### 8.4 Dashboard

`IntegrityController` (extends `App\Http\Controllers\Controller`), methods
`index / runs / changes / scan / bless`, splitting on `request->get('mode')==='dataTable'`,
returning the `{ actions: [ {type:'toastr'}, {type:'reloadDataTable', data:{dataTableId:'integrityRunsTable'}} ] }`
envelope for POST actions. Routes added inside the existing `registerRoutes()` group
(config-driven prefix + middleware, `name('integrity.*')`), gated by
`config('shield.dashboard.enabled')`. Views under `resources/views/dashboard/integrity/`
(`@extends('shield::layouts.bootstrap.app')`, Bootstrap 5 + DataTables.net, links via
`app('shield')->route('shield.integrity.*')`). Surfaces: stats cards (last run, baseline
age, delta, known-good drift), run history table, per-run changes drill-down filterable
by type/severity, the provisional/tamper banners (6.1/6.4/4.2), "Bless current state" and
"Run now" buttons.

---

## 9. Prerequisite fixes (in scope, with regression tests)

These ship in the same PR because the integrity notification depends on them:

| Fix | File |
|---|---|
| Add `laravel/slack-notification-channel: ^3.0`; standardize on Block Kit `SlackMessage`; fix the two existing broken `toSlack()` methods | `composer.json`, `AttackDetectedNotification`, `SecurityReportNotification` |
| `Notifiable` reads `config('shield.notification_channels.{mail,slack,discord}.*')` | [src/Notifications/Notifiable.php](../../src/Notifications/Notifiable.php) |
| `DiscordMessage::toArray()`: `footerImg`->`footerUrl`; null-safe color default (gray) so `hexdec` never gets null; `timestamp` -> `toIso8601String()` string; drop the `'Laravel Backup'` default | [src/Notifications/Channels/Discord/DiscordMessage.php](../../src/Notifications/Channels/Discord/DiscordMessage.php) |
| Correct `via()`/`viaQueues()`: read the event block's `channels` and per-channel `queue` from `notification_channels`; fix `$this->$this->config` typos | `AttackDetectedNotification`, `FailedLoginNotification`, `SuccessfulLoginNotification`, `SecurityReportNotification` |

The new `IntegrityScanCompletedNotification` is the **reference** for the correct `via()`;
the existing classes are fixed to match it.

---

## 10. Config & env

```php
// config/shield.php

'integrity' => [
    'enabled' => env('LS_INTEGRITY_ENABLED', false),

    'disks' => [
        'app' => [
            // Docroot-aware: scans base_path() AND the real public docroot even when
            // it lives outside base_path() (cPanel/FTP layout from the screenshot).
            'disk' => env('LS_INTEGRITY_DISK', 'local'),
            'roots' => [base_path(), public_path()],
            'include' => ['**/*'],
            'exclude' => [
                'vendor/**', 'node_modules/**', '.git/**',
                'storage/framework/**', 'storage/logs/**', 'bootstrap/cache/**',
                'storage/shield/**', // the baseline artifact lives here
            ],
            'follow_symlinks' => false,
            'max_file_size' => 50 * 1024 * 1024, // size-only above this, EXCEPT script extensions
        ],
    ],

    'hash_algo' => 'sha256',
    'queue' => env('LS_INTEGRITY_QUEUE', 'shield-integrity'),

    'schedule' => [
        'enabled' => env('LS_INTEGRITY_SCHEDULE_ENABLED', true),
        'cron' => env('LS_INTEGRITY_SCAN_CRON', '0 * * * *'), // hourly, jittered
        'jitter_seconds' => 300,
    ],
    'full_rehash_cron' => env('LS_INTEGRITY_FULL_REHASH_CRON', '0 3 * * 0'), // weekly content re-verify

    'baseline' => [
        'auto_bless_on_first_run' => false, // see 6.1
        'sign_artifact' => true,
        'hmac_key_path' => env('LS_INTEGRITY_HMAC_KEY_PATH'), // file chmod 600 OUTSIDE scan root
    ],

    'limits' => [
        'max_files' => 500000,
        'max_iterations' => 1000000,
        'max_runtime' => 3600,
        'max_persisted_changes_per_run' => 5000,
    ],

    'notify' => [
        'suppress_when_no_changes' => true,   // delta==0 AND no security event
        'send_all_clear' => false,
        'max_paths_per_group' => 15,
        'timezone' => 'UTC',
        'disclose_paths_to_external_channels' => false, // redact sensitive paths by default
        'drift_reminder_cadence' => 'daily',
        'throttle' => ['window' => 1800, 'max_per_window' => 1], // critical bypasses
    ],

    'retention' => [
        'runs_days' => 90,
        'changes_days' => 30,
    ],

    // Ordered, first match wins. Each rule matches on path glob(s), extensions,
    // and/or change types, and assigns a severity. {public_docroot} expands to the
    // resolved public web root. See 6.5.
    'severity_rules' => [
        ['when' => ['path_any' => ['public/**', 'storage/app/public/**', '{public_docroot}/**'], 'ext_any' => ['php', 'phtml', 'phar', 'phps', 'inc']], 'severity' => 'critical', 'non_suppressible' => true],
        ['when' => ['change_type_any' => ['deleted']], 'severity' => 'high'],
        ['when' => ['path_any' => ['app/**', 'routes/**', 'config/**'], 'ext_any' => ['php']], 'severity' => 'high'],
        ['when' => ['path_any' => ['vendor/**']], 'severity' => 'high'], // Phase 2: refine to "differs from composer.lock dist hash"
        ['when' => ['path_any' => ['.env', '.env.*', '**/.htaccess', '**/.user.ini']], 'severity' => 'medium'],
        ['when' => ['change_type_any' => ['became_unreadable']], 'severity' => 'medium'],
        ['when' => ['always' => true], 'severity' => 'low'], // default
    ],
],
```

```php
// config/shield.php -> notifications (event toggle, consistent with siblings)
'integrity_changed' => [
    'enabled' => env('FIREWALL_NOTIFICATIONS_INTEGRITY_CHANGED_ENABLED', false),
    'channels' => ['mail', 'discord'],
],
```

> Env-prefix note: the engine block uses `LS_INTEGRITY_*` (matches the scanner era); the
> notification **event toggle** uses `FIREWALL_NOTIFICATIONS_*` to stay consistent with
> its siblings (`FIREWALL_NOTIFICATIONS_ATTACK_DETECTED_ENABLED`). Migrating the whole
> notifications block to `LS_` is a separate cleanup, out of scope here.

---

## 11. Premium gating & free fallback

- Baseline integrity scanning, the hybrid diff, the dashboard, and the summary notification are **FREE** (mirrors Wordfence's free file-integrity model).
- **Phase 2** gates only real-time integrity monitoring via `Shield::isFeatureAvailable('integrity_realtime')`, independent of the free `shield.scanner.watch.enabled` toggle (do not overload that flag as the premium switch). Listed in `docs/premium.md` (anything not listed is free).
- Premium paths degrade to free behavior when the license is missing/expired/unreachable, per the shared conventions in `docs/specs/README.md`.

---

## 12. Files to add / change

**Add**

- `src/Services/Integrity/{Manifest,Baseline,IntegrityScanner,SeverityColor}.php`
- `src/Events/IntegrityScanCompletedEvent.php`
- `src/Listeners/IntegrityScanCompletedListener.php`
- `src/Notifications/IntegrityScanCompletedNotification.php`
- `src/Console/Commands/{IntegrityScanCommand,IntegrityBlessCommand,IntegrityStatusCommand,IntegrityPruneCommand,IntegrityHeartbeatCommand}.php`
- `src/Models/{IntegrityRun,IntegrityChange,IntegrityBaseline}.php`
- `src/Models/Lookups/{IntegrityStatus,IntegrityChangeType,IntegrityComparisonBasis}.php`
- `src/Http/Controllers/IntegrityController.php`
- `database/migrations/*_create_ls_integrity_lookup_tables.php` (before data tables)
- `database/migrations/*_create_ls_integrity_{runs,changes,baselines}_tables.php`
- `resources/views/dashboard/integrity/{index,runs,changes}.blade.php`
- `resources/lang/en/notifications.php` keys under `integrity_changed`
- `docs/integrity.md`

**Change**

- `composer.json` (add `laravel/slack-notification-channel`)
- `src/ShieldServiceProvider.php` (register commands, listener, routes, schedules, the `Manifest` singleton)
- `src/Console/Commands/WatchCommand.php` (delegate `scanPaths()` to `Manifest->hashes()`)
- `database/seeders/LookupTableSeeder.php` (seed new integrity lookups + new trigger names)
- `config/shield.php` (the `integrity` block + `notifications.integrity_changed`)
- Prerequisite fixes in Section 9
- `docs/notifications.md` (mark routing/throttle as planned, not shipped)

---

## 13. Acceptance criteria

1. First run with no baseline creates a **provisional** baseline, does NOT auto-trust it, and sends a non-suppressible "review before trusting" notice listing web-reachable script files. `auto_bless_on_first_run` defaults false.
2. A webshell dropped as `footer.php`/`header.php` under a (scanned) public cache dir surfaces as a **critical** card with the path shown explicitly (never in "+N more"), even right after a bless.
3. After a bless, an unchanged subsequent run produces a delta of 0 and (with `suppress_when_no_changes`) sends nothing, but still records the run; a standing known-good drift triggers the daily drift reminder, not hourly pages.
4. A run over 92k files hashes only size/mtime-changed files (incremental), holds bounded memory (streaming diff), and completes within `max_runtime` or records `timed_out`.
5. `tamper_suspected` (HMAC mismatch on an existing artifact) does NOT auto-rebless and raises a high/critical signal that reaches the audit log + Laravel log + dashboard even with all notification channels disabled.
6. A scope/exclude config change labels affected files `scope_changed`, not new/deleted, and shows a "baseline built with a different config" banner.
7. `files_total` is stable run-to-run (a transiently unreadable file does not flip to "deleted"); the counter breakdown is reported.
8. The Discord card never exceeds Discord limits (validated pre-POST, deterministic truncation); the Slack card renders via Block Kit; the mail card uses the executive theme. Severity color is consistent across channels and never null.
9. `WatchCommand --once` produces identical created/updated/deleted output before and after the `Manifest` extraction (regression test).
10. The dashboard "Run now" dispatches async and polls; the integrity queue is isolated from `default`.
11. Prerequisite regression tests pass: `Notifiable` resolves real `to`/webhook addresses; `DiscordMessage::toArray()` has correct footer icon, string timestamp, and non-null color; `toSlack()` constructs without a class-not-found fatal; `via()` returns channel names, not event names.

## 14. Test plan

- **Unit**: `Manifest` diff (new/modified/deleted/scope_changed/unreadable/symlink), incremental size+mtime skip, always-hash-script-extensions, canonical key, iteration caps; `Baseline` read/write/sign/verify, atomic write, tamper-vs-corruption, dev-secret hard-fail, root hash + scope fingerprint; `SeverityColor`; severity rules (critical webshell, vendor, .env, low).
- **Feature**: `shield:integrity` first-run provisional bless; change detection; `Notification::fake()` dispatch on `IntegrityScanCompletedEvent`; per-channel render (mail/slack/discord) with truncation + limit validation; `shield:integrity-bless` promotes last run + audits; `tamper_suspected` no-rebless + always-on escalation; suppress-when-no-changes vs drift reminder; heartbeat alert; prune retention; queue isolation; `WatchCommand --once` regression.
- **Edge**: file vanished mid-scan, became unreadable, mass-deletion suppression on partial read, huge-diff truncation ordering + "+N more" boundary (15/16/0), timezone conversion, all-channels-disabled escalation path.

## 15. Phasing

- **Phase 1 (this spec)**: local-disk (docroot-aware) integrity scan + summary notification, hybrid baseline, incremental + streaming, secure-by-default, dashboard, free tier, prerequisite fixes.
- **Phase 2 (future spec)**: first-class remote disks (sftp/s3) with ETag/LastModified pre-filter + partial-read failure budget + cleartext-FTP warning; `composer.lock` dist-hash / framework cross-check for a real known-good on first run; deploy-coordination (`bless --auto` / maintenance window) to kill `vendor/` deploy false-positives; premium real-time integrity via the watcher.
- **Phase 3 (future spec)**: off-box authority via Central, baseline root-hash attestation, critical-alert decisioning off-box, two-person/Central-approved bless, full dead-man's-switch.

## 16. Rollout notes

- Ships disabled (`LS_INTEGRITY_ENABLED=false`). Enabling triggers a provisional first-run baseline + the review notice; operators must `shield:integrity-bless --confirm` after reviewing.
- Requires a cache store with atomic locks (redis/memcached/database) when scheduled; documented, with a loud failure on the `array` store.
- Document the honest HMAC limitation (same-host key) and that real attestation arrives in Phase 3; recommend a key file outside the web root now.
- Document that remote-disk FIM (Phase 2) detects drift, not active tampering, and cannot defend against an attacker controlling the remote endpoint.
