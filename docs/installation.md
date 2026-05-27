# Installation

```bash
composer require ozankurt/laravel-shield
php artisan shield:install
```

`shield:install` is idempotent — safe to re-run.

## What the install command does

1. Publishes `config/shield.php`, migrations, lang files, and assets (`public/vendor/shield/`)
2. Runs migrations (`ls_*` tables + lookups)
3. Seeds lookup tables + ~47 built-in WAF rules + ~33 built-in malware signatures
4. Generates `LS_AUDIT_HMAC_SECRET` (64 chars) and `LS_BYPASS_KEY` (32 chars) in your `.env` if missing
5. Asks if you want to whitelist your current IP (skip with `--no-interaction`)
6. Prints next steps

## Define the dashboard gate

The dashboard ships locked. Allow access by defining the gate:

```php
// AppServiceProvider::boot()
use Illuminate\Support\Facades\Gate;

Gate::define('viewShieldDashboard', fn ($user) => $user && $user->is_admin);
```

The package's `ShieldDashboardMiddleware` calls `Gate::allows('viewShieldDashboard')`. If your app's user model has an `is_admin` column or role check, plug it in here.

## Configure storage driver

For low-traffic sites, defaults are fine. For high-traffic sites, switch to queue or Redis-batched writes:

```env
LS_STORAGE_DRIVER=queue
LS_QUEUE_CONNECTION=redis
LS_QUEUE_NAME=shield
```

Then ensure a queue worker is running:

```bash
php artisan queue:work --queue=shield
```

## Schedule

Add Shield's scheduled tasks to your scheduler. Shield self-registers when `shield.audit.drift.enabled` etc. are true — if your scheduler is `php artisan schedule:run` (cron every minute), there's nothing else to do.

Common scheduled commands:
- `shield:audit-drift` (daily 4am) — re-checksum monitored files
- `shield:signatures-sync` (daily 5am) — pull latest signatures from GitHub releases
- `shield:unblock-ips` — ACL expiry sweep

## Optional dependencies (composer suggests)

```bash
# ClamAV scanner backend
composer require xenolope/quahog

# Real-time file watcher (chokidar-backed)
composer require spatie/file-system-watcher
npm install chokidar

# GeoIP for country/ASN ACL matchers (1.1+)
composer require geoip2/geoip2

# Redis for cache + queue (strongly recommended)
composer require predis/predis

# Self-hosted broadcasting for live traffic real-time mode
composer require laravel/reverb
```
