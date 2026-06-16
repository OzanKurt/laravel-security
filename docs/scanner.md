# Scanner

Three backends, runnable solo or combined:

| Backend | What it does | Requires |
|---|---|---|
| **Native** | Regex / file-hash / string match against `ls_signatures` | Always available |
| **ClamAV** | Streams files through local clamd via Quahog | `composer require xenolope/quahog` + running clamd |
| **Composer audit** | Surfaces CVEs in installed Composer packages | composer 2+ |

## Scan targets

Each target tells the scanner where to look:

| Target | What's scanned | Default quarantine policy |
|---|---|---|
| `vendor` | Composer dependencies | log_only |
| `app_files` | `app/`, `routes/`, `config/` PHP files | log_only |
| `public_uploads` | `storage/app/public/`, `public/uploads/` | move_and_stub |
| `recently_modified` | Files modified in the last N hours | per-finding decision |
| `config_drift` | `config/*.php` checksums vs baseline | log_only |
| `env_audit` | `.env` security checks (APP_DEBUG, weak APP_KEY, session flags) | log_only |
| `dotfiles` | `.htaccess`, `.user.ini`, etc. | log_only |
| `db_content` | Opt-in Eloquent text/json columns | log_only |
| `unknown_files` | Files in `public/` not matching expected patterns | move_and_stub |

Override per-target policy in `shield.scanner.quarantine.per_target.*`.

## Run a scan

```bash
# Manual, all targets, all available backends
php artisan shield:scan

# Specific targets
php artisan shield:scan --target=app_files --target=public_uploads

# Native backend only
php artisan shield:scan --backend=native

# Cancel a running scan
php artisan shield:scan-cancel <run-id>

# Show status of latest run
php artisan shield:scan-status
```

Or from the dashboard at `/shield/scanner`, "Start scan" button.

## Signatures

Built-in baseline (~33 patterns) is seeded on install, covers classic webshells (c99, r57, WSO, b374k, AlfaShell, p0wny, IndoXploit, FilesMan), eval/assert/preg_replace obfuscation chains, exec on superglobals, file_put_contents with chr() chains, etc.

Sync from the hosted feed:

```bash
php artisan shield:signatures-sync
```

Falls back to the embedded baseline when the remote (GitHub Releases on `OzanKurt/laravel-shield-signatures`) is unreachable. Idempotent, upserts by `(source, source_ref)` and bumps `version`.

Pin to a specific tag via `LS_SIGNATURE_PIN=v2026.05` in `.env`.

## Quarantine

Per-target policy controls what happens on a finding:
- `move_and_stub`, moves the file to `storage/shield/quarantine/<uuid>.bin`, writes sidecar metadata JSON, leaves an empty stub at the original path
- `log_only`, never touches the file; just records the finding

Restore from CLI or dashboard:

```bash
php artisan shield:quarantine-list
php artisan shield:quarantine-restore <finding-uuid>
```

## ClamAV setup

```bash
# Install ClamAV daemon (Ubuntu)
sudo apt install clamav clamav-daemon
sudo systemctl enable --now clamav-daemon

# Install the PHP socket client
composer require xenolope/quahog

# Enable the backend
echo "LS_CLAMAV_ENABLED=true" >> .env
echo "LS_CLAMAV_SOCKET=/var/run/clamav/clamd.ctl" >> .env

# Verify
php artisan shield:clamav-status

# Update virus signatures
php artisan shield:clamav-update
```

## File-change watcher

See [security-watch.md](security-watch.md).

## Upload AV middleware

```php
Route::post('/upload', UploadController::class)
    ->middleware('firewall.av_uploads');
```

Streams every uploaded file through native + ClamAV (when available). On detection: rejects with 415 + audit log.

## Spatie Media Library auto-integration

If `spatie/laravel-medialibrary` is installed, Shield auto-attaches a listener to the Media model's `saving` event. No config needed; files rejected before persistence.

## On-demand scan API

```php
use OzanKurt\Shield\Facades\Shield;

$result = Shield::scanUploadedFile($request->file('avatar'));

if (! $result['clean']) {
    abort(415, 'File rejected: contains suspicious content');
}
```
