# Diagnostics + composer audit (1.2+)

Two operator-facing pages: a system overview + OWASP-style configuration grade, and a Composer vulnerability scanner that surfaces CVEs in installed dependencies.

## Diagnostics page

`/shield/diagnostics` shows:

### System info

| Field | Source |
|---|---|
| PHP version | `PHP_VERSION` |
| Laravel version | `app()->version()` |
| Memory limit | `ini_get('memory_limit')` |
| Max execution time | `ini_get('max_execution_time')` |
| Queue connection | `config('queue.default')` |
| Cache driver | `config('cache.default')` |
| Session driver | `config('session.driver')` |
| DB connection + driver + version | `DB::connection()->getDriverName()` + `SELECT version()` |
| Last scanner run | `ScannerRun::latest('id')->first()` timestamp |
| Last signature DB update | most recent `threat_feed.sync_completed` for `builtin_native` or `wf_free` source |
| Scheduler last run | mtime of `storage/logs/laravel.log` (best-effort heuristic) |
| Optional integrations | `class_exists` checks for ClamAV, GeoIP, Spatie file-watcher, Spatie MediaLibrary, Predis, Reverb |

### Environment audit

Runs `EnvAuditor::audit()` which checks:

| Check | Severity | Recommendation |
|---|---|---|
| `APP_DEBUG=true` in production | critical | Set `APP_DEBUG=false` in production env |
| `APP_KEY` empty or weak | critical / high | Run `php artisan key:generate` |
| `SESSION_SECURE_COOKIE=false` in production | high | Set `SESSION_SECURE_COOKIE=true` |
| `SESSION_HTTP_ONLY=false` | medium | Set `SESSION_HTTP_ONLY=true` to prevent JS access |
| `SESSION_SAME_SITE` not `lax`/`strict`/`none` | medium | Set to `lax` for most apps |
| `APP_URL` blank or `http://localhost` in production | low | Set the production domain |
| `SESSION_DRIVER=file` in production | low | Switch to `redis` or `database` for atomicity |

### OWASP-style grade

A weighted score across the env audit findings produces a grade A–F:

- **A**, no findings
- **B**, score ≤ 2 (low-severity items only)
- **C**, score ≤ 5
- **D**, score ≤ 10
- **E**, score ≤ 20
- **F**, score > 20

Weights: critical = 10, high = 5, medium = 2, low = 1.

A new install on a properly-configured production app should grade A. Anything below B warrants attention before going live.

## Composer audit

`/shield/composer-audit` lists CVEs in installed Composer packages via `composer audit --format=json`.

| Column | What |
|---|---|
| Advisory | CVE / GHSA / FriendsOfPHP ID |
| Severity | Mapped from CVSS where present |
| Summary | Truncated advisory description |
| Detected | When the scan first reported the CVE |

Click "Run audit now" to trigger a fresh scan (queued background job; usually completes in <5s).

### Triggering from CLI

```bash
# Single audit run
php artisan shield:scan --backend=composer_audit

# Scheduled (auto-registered when shield.scanner.composer_audit.cron is set)
# Default: runs daily at 6am
```

The findings land in `ls_scanner_findings` with `backend_id = composer_audit`. Severity inherits from the advisory's CVSS score where available.

### How it works

The `ComposerAuditBackend` wraps:

```bash
composer audit --format=json --no-interaction
```

Parses the output's `advisories` array. Each advisory becomes a `ScannerFinding` row with:
- `signature_ref` = the advisory ID (e.g. `CVE-2024-XXXXX`)
- `file_path` = `composer.lock`
- `severity_id` = mapped from CVSS:
  - 9.0+ → critical
  - 7.0+ → high
  - 4.0+ → medium
  - else → low
- `matched_content` = `{ summary, package, version, fixed_in }`
- `meta` = the raw advisory JSON

### Surfacing in reports

The "Required updates" section of every multi-cadence report (daily digest, 7-day, etc.) reads from the most recent composer audit run. See [notifications.md#wordfence-style-executive-email](notifications.md#wordfence-style-executive-email) for the report layout.

## Operator workflow

1. **After every dependency update**, run `shield:scan --backend=composer_audit` (or wait for the scheduled run) to refresh CVE state
2. **Before going to production**, visit `/shield/diagnostics` and aim for grade A or B
3. **On a stale install**, visit `/shield/composer-audit` for CVEs that accumulated since last `composer update`

## CLI shortcuts

```bash
# Quick env audit (no DB writes, just stdout)
php artisan shield:audit-env

# Full diagnostics summary (JSON)
php artisan shield:diag

# Composer-only audit run
php artisan shield:composer-audit
```

`shield:diag` is the right command to ask users to run when filing a bug, it captures everything the diagnostics page shows, plus version pins from `composer.lock`.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Diagnostics page grade = F but env looks fine | `APP_KEY` was regenerated without restarting the queue/cache | `php artisan optimize:clear` + restart workers |
| Composer audit returns "command not found" | Composer 1.x installed (audit is 2.4+) | Upgrade Composer: `composer self-update` |
| Composer audit hangs | Network blocked from production server | Run on CI / staging instead and import findings via `shield:import` (1.2+) |
| Diagnostics shows `Spatie file-watcher: not installed` but `shield:watch` works | Polling-fallback mode (chokidar absent) | Optional: `composer require spatie/file-system-watcher && npm install chokidar` |
