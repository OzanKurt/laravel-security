# Configuration

Every behavior is exposed in `config/shield.php`. After `composer require` + `shield:install`, you have a published copy in your app, edit it directly. This page is the authoritative reference.

## Top-level keys

| Key | Purpose | Default |
|---|---|---|
| `enabled` | Master toggle. When `false`, every middleware is a no-op. | `env('FIREWALL_ENABLED', true)` |
| `database` | Connection name + table prefix + model bindings | `DB_CONNECTION`, prefix `security_` |
| `storage` | Storage driver + sampling + queue connection | `sync`, no sampling |
| `cache` | Cache store for ACL/decision/lookups | uses Laravel default |
| `dashboard` | Route prefix, gate name, middleware stack, user name field | prefix `shield`, gate `viewShieldDashboard` |
| `bypass` | Admin lockout recovery (env key + config IPs) | empty list |
| `whitelist` | Quick whitelist override (CIDR list) | `127.0.0.0/24` |
| `acl` | ACL evaluator config (caching, kind gating) | 60s decision TTL, geo/asn off |
| `waf` | WAF rules config (always-on patterns, sync cron, scoring) | scoring off |
| `audit` | Audit-log retention + drift detection paths | 365d default retention |
| `scanner` | Scanner backends + targets + signature feed URL | native only, ClamAV opt-in |
| `live_traffic` | Sampling + real-time broadcasting | 0.1 sample rate, polling default |
| `notifications` | Channel config + routing matrix + throttle + reports | mail off, no routing |
| `headers` | Security headers middleware | all enabled, no HSTS by default |
| `honeypot` | Honeypot routes + auto-block duration | empty paths |
| `redaction` | Request-data redaction keys + placeholder | passwords, tokens, secrets |
| `disabled_routes` | Unconditional 404 patterns | empty |
| `https` | HTTPS enforcement | off |
| `scoring` | Suspicion scoring engine | off |
| `rate_limiters` | Pre-configured Laravel rate limiters | login/password_reset on |
| `trusted_proxies` | Cloudflare/AWS/GCP IP auto-detect | off |
| `threat_feed` | Provider list + per-provider env keys | providers registered, off until LS_* keys set |
| `reports` | Multi-cadence reports (1/3/7/14/30 day) | 7-day on |
| `responses` | Response shapes when a request is blocked | abort 403 |
| `middleware` | Per-middleware config (enabled, methods, routes, patterns, auto_block) | varies |

## Storage drivers

```php
'storage' => [
    'driver' => env('LS_STORAGE_DRIVER', 'sync'),  // sync | queue | redis_batch
    'queue_connection' => env('LS_QUEUE_CONNECTION', 'default'),
    'queue_name' => env('LS_QUEUE_NAME', 'shield'),
    'batch_size' => 100,
    'batch_interval' => 5,
    'sample_rate' => [
        'live_traffic' => 0.1,
        'logs' => 1.0,
        'audit_log' => 1.0,
    ],
],
```

- `sync`, every write is a synchronous Eloquent save. Default. Fine for low-medium traffic.
- `queue`, every write dispatches a job. Requires a queue worker (`php artisan queue:work --queue=shield`). Use for medium-high traffic.
- `redis_batch`, buffers in Redis sorted set, flushes every `batch_interval` seconds. Highest throughput. Requires Redis.

Sampling applies to live_traffic non-attack rows. Attacks always 100%.

## Caching

| Key | TTL | Invalidation |
|---|---|---|
| `shield.acl.live` | forever | `AclObserver` on any `ls_acl` write |
| `shield.acl.decision.{hash}` | `acl.decision_cache_ttl` (60s) | TTL only |
| `shield.lookups.{table}` | forever | manual (cache:clear) |
| `shield.waf.rules.{category}` | forever | `WafRuleObserver` on rule write |
| `shield.signatures.{enabled}` | 5 min | TTL |
| `shield.geo.country.{md5(ip)}` | 24 h | TTL |
| `shield.geo.asn.{md5(ip)}` | 24 h | TTL |
| `shield.scoring.{md5(ip)}` | `scoring.window` (3600s) | TTL |
| `shield.trusted_proxies` | 24 h | TTL |
| `shield.premium.license` | 24 h | manual via `LicenseChecker::refresh()` |

Clear any key from `/shield/cache` (or programmatically: `Cache::forget('shield.acl.live')`).

## Dashboard

```php
'dashboard' => [
    'enabled' => env('FIREWALL_DASHBOARD_ENABLED', true),
    'route_prefix' => 'shield',                    // mounted at /shield/*
    'route_name' => 'shield.',                     // named route prefix
    'date_format' => 'd/m/Y H:i:s',
    'middleware' => ['auth', ShieldDashboardMiddleware::class],
    'user_name_field' => 'full_name',              // which user column to display in tables
    'logo_target_route_name' => null,              // logo links to app home by default
],
```

Define the gate in your AppServiceProvider:

```php
Gate::define('viewShieldDashboard', fn ($user) => $user && $user->is_admin);
```

## Bypass mechanism

See [bypass.md](bypass.md), three layers:

1. `LS_BYPASS_KEY` env (32+ char random string, sent as `X-Security-Bypass` header)
2. `LS_BYPASS_IPS` env (comma-separated IP/CIDR list)
3. Artisan `shield:bypass-add <ip>`

## ACL

```php
'acl' => [
    'decision_cache_ttl' => 60,                    // seconds; per-IP decision cached
    'geo' => ['enabled' => false],                 // requires geoip2/geoip2 + MaxMind DB
    'asn' => ['enabled' => false],                 // same
    'hostname' => ['enabled' => false],            // DNS-lookup; slow; off by default
],
```

## Audit log

```php
'audit' => [
    'drift' => [
        'enabled' => env('SHIELD_DRIFT_ENABLED', true),
        'paths' => [
            'config/'       => '*.php',
            '.env'          => null,
            'composer.json' => null,
            'composer.lock' => null,
        ],
        'baseline_path' => 'storage/shield/baselines/files.json',
        'cron' => '0 4 * * *',                     // daily 4am
    ],
],
```

HMAC chain secret comes from `LS_AUDIT_HMAC_SECRET` env (generated by `shield:install`).

## Scanner

```php
'scanner' => [
    'clamav' => [
        'enabled' => env('LS_CLAMAV_ENABLED', false),
        'socket' => env('LS_CLAMAV_SOCKET', '/var/run/clamav/clamd.ctl'),
        'timeout' => 30,
    ],
    'native' => [
        'max_file_bytes' => 5 * 1024 * 1024,       // skip files larger than 5 MB
    ],
    'signatures' => [
        'url' => env('LS_SIGNATURE_URL', 'https://api.github.com/repos/OzanKurt/laravel-shield-signatures/releases/latest'),
        'pin' => env('LS_SIGNATURE_PIN'),          // pin to a specific tag
        'sync_cron' => '0 5 * * *',                // daily 5am
    ],
    'quarantine' => [
        'path' => 'storage/shield/quarantine',
        'per_target' => [
            'public_uploads' => 'move_and_stub',
            'unknown_files' => 'move_and_stub',
            // app_files / vendor / config / dotfiles / db_content default to log_only
        ],
    ],
    'watch' => [
        'enabled' => env('LS_WATCH_ENABLED', false),
        'paths' => [],                             // empty = app/, config/, routes/, .env
        'poll_interval_ms' => env('LS_WATCH_POLL_MS', 3000),
    ],
],
```

## Notifications + reports

See [notifications.md](notifications.md) for the routing matrix shape and the multi-cadence reports schedule.

## Threat feeds (1.1+)

```php
'threat_feed' => [
    'providers' => [
        \OzanKurt\Shield\Services\ThreatFeed\Providers\SpamhausProvider::class,
        \OzanKurt\Shield\Services\ThreatFeed\Providers\AbuseIpDbProvider::class,
        \OzanKurt\Shield\Services\ThreatFeed\Providers\OwaspCrsProvider::class,
        \OzanKurt\Shield\Services\ThreatFeed\Providers\MaxMindGeoLite2Provider::class,
    ],
    'sync_cron' => '0 3 * * *',
    'spamhaus' => ['enabled' => true],
    'abuseipdb' => [
        'enabled' => env('LS_ABUSEIPDB_ENABLED', false),
        'key' => env('LS_ABUSEIPDB_KEY'),
        'confidence_minimum' => 90,
    ],
    'owasp_crs' => ['enabled' => true],
    'maxmind' => [
        'enabled' => env('LS_MAXMIND_ENABLED', false),
        'license_key' => env('LS_MAXMIND_LICENSE_KEY'),
    ],
],
```

## Environment variables (complete list)

| Variable | Used for |
|---|---|
| `FIREWALL_ENABLED` | Master toggle |
| `FIREWALL_DB_CONNECTION` | DB connection name |
| `FIREWALL_DB_PREFIX` | Table prefix (`security_` legacy; `ls_` going forward) |
| `FIREWALL_DASHBOARD_ENABLED` | Dashboard toggle |
| `FIREWALL_WHITELIST` | Quick CIDR whitelist (comma-sep) |
| `LS_AUDIT_HMAC_SECRET` | Audit log tamper-evidence secret (auto-generated) |
| `LS_BYPASS_KEY` | Header-based bypass key (auto-generated) |
| `LS_BYPASS_IPS` | Always-on bypass IPs (comma-sep) |
| `LS_STORAGE_DRIVER` | `sync` / `queue` / `redis_batch` |
| `LS_QUEUE_CONNECTION` / `LS_QUEUE_NAME` | Queue driver wiring |
| `LS_CLAMAV_ENABLED` / `LS_CLAMAV_SOCKET` | ClamAV backend |
| `LS_SIGNATURE_URL` / `LS_SIGNATURE_PIN` | Signature feed source |
| `LS_WATCH_ENABLED` / `LS_WATCH_POLL_MS` | File watcher |
| `LS_HEADERS_ENABLED` / `LS_HSTS_ENABLED` / `LS_CSP_ENABLED` | Security headers |
| `LS_HONEYPOT_ENABLED` | Honeypot routes |
| `LS_ENFORCE_HTTPS` | HTTPS enforcement middleware |
| `LS_DISABLED_ROUTES_ENABLED` | Disabled routes middleware |
| `LS_SCORING_ENABLED` / `LS_SCORING_THRESHOLD` / `LS_SCORING_WINDOW` / `LS_SCORING_BLOCK_DURATION` | Suspicion scoring |
| `LS_TRUST_CLOUDFLARE` | Cloudflare proxy auto-trust |
| `LS_LIVE_TRAFFIC_ENABLED` / `LS_LIVE_TRAFFIC_SAMPLE_RATE` / `LS_LIVE_TRAFFIC_REALTIME` / `LS_LIVE_TRAFFIC_CHANNEL` | Live traffic |
| `LS_ABUSEIPDB_ENABLED` / `LS_ABUSEIPDB_KEY` | AbuseIPDB threat feed |
| `LS_SPAMHAUS_ENABLED` | Spamhaus DROP/EDROP feed |
| `LS_OWASP_CRS_ENABLED` | OWASP CRS feed |
| `LS_MAXMIND_ENABLED` / `LS_MAXMIND_LICENSE_KEY` | MaxMind GeoLite2 feed |
| `LS_REPORT_DAILY` / `LS_REPORT_3DAY` / `LS_REPORT_7DAY` / `LS_REPORT_14DAY` / `LS_REPORT_30DAY` | Multi-cadence reports |
| `LS_PREMIUM_LICENSE_KEY` | Premium activation |
| `LS_PREMIUM_LICENSE_CHECK_URL` | Override license-check endpoint |
| `LS_PREMIUM_LICENSE_CACHE_TTL` / `LS_PREMIUM_LICENSE_GRACE_DAYS` | License caching + outage grace |
