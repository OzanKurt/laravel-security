# Laravel Shield

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ozankurt/laravel-shield.svg?style=flat-square)](https://packagist.org/packages/ozankurt/laravel-shield)
[![License](https://img.shields.io/packagist/l/ozankurt/laravel-shield.svg?style=flat-square)](LICENSE.md)

**Comprehensive security suite for Laravel — the Wordfence equivalent.**

WAF + scanner + ACL + audit log + live traffic + notifications, all configurable, all auditable, all Laravel-native.

> **Brand site:** [laravel-shield.ozankurt.com](https://laravel-shield.ozankurt.com) — docs, pricing, license activation.

---

## Why Laravel Shield

| Need | What Shield gives you |
|---|---|
| Block malicious requests | 15+ WAF middlewares (XSS, SQLi, LFI, RFI, PHP wrappers, sessions, agents, geo, bots, keyword path filters) + DB-backed rule engine |
| Manage allow/deny lists | Unified `ls_acl` table — IP / CIDR / ASN / country / regex / hostname, first-match-wins evaluation, Redis-cached |
| Detect malware | Scanner with native engine + ClamAV + composer audit; quarantine + restore; signature feed sync |
| Audit-log everything | HMAC-chained `ls_audit_log`, file/config/composer drift detection, `HasAuditLog` trait for model events |
| See live traffic | Sampled `ls_live_traffic` table with optional real-time broadcasting (Reverb / Pusher / Ably) |
| Get alerts | Mail / Slack / Discord / Telegram / Webhook channels, severity-routed |
| Stay locked out? | Three-layer bypass (env key + config IPs + Artisan recovery commands) |
| Beyond Wordfence | Security headers + CSP nonce, honeypot routes, generalized redaction, suspicious activity scoring, HTTPS enforcement, cookie security audit, trusted-proxy auto-discovery, pre-configured rate limiters |

---

## Install

```bash
composer require ozankurt/laravel-shield
php artisan shield:install
```

`shield:install` publishes config + migrations + lang + assets, runs migrations, seeds lookup tables + ~47 built-in WAF rules + ~33 built-in malware signatures, generates `LS_AUDIT_HMAC_SECRET` + `LS_BYPASS_KEY` if missing, and optionally whitelists your current IP so you don't lock yourself out.

Then expose the dashboard by allowing the gate it defines:

```php
// AppServiceProvider::boot()
Gate::define('viewShieldDashboard', fn ($user) => $user && $user->is_admin);
```

Visit `/shield`.

## Quickstart middlewares

In your route file or middleware group, attach what you need:

```php
Route::middleware('firewall.all')->group(function () {
    Route::post('/login', LoginController::class);
});

Route::post('/api/upload', UploadController::class)
    ->middleware(['firewall.av_uploads', 'throttle:shield_login']);

Route::middleware(['firewall.acl', 'firewall.headers'])->group(function () {
    // Public site with security headers + ACL evaluation
});
```

## Configuration

After install, see `config/shield.php`. Every limit, threshold, regex, path, and behaviour is exposed. Highlights:

```php
// Storage strategy (sync default; queue/redis_batch for high traffic)
'storage' => ['driver' => env('LS_STORAGE_DRIVER', 'sync'), 'sample_rate' => ['live_traffic' => 0.1]],

// Audit log with HMAC chain tamper evidence
'audit' => ['drift' => ['enabled' => true, 'paths' => ['config/' => '*.php', '.env' => null]]],

// Scanner with ClamAV (composer suggest xenolope/quahog)
'scanner' => ['clamav' => ['enabled' => env('LS_CLAMAV_ENABLED', false)]],

// Three-layer bypass for admin lockout recovery
'bypass' => ['ips' => array_filter(explode(',', env('LS_BYPASS_IPS', '')))],

// Beyond-WF extras (all opt-in)
'headers' => ['enabled' => true, 'csp' => ['enabled' => false, 'use_nonce' => true]],
'honeypot' => ['enabled' => false, 'paths' => ['wp-admin', '.env', 'phpmyadmin', '.git/config']],
'scoring' => ['enabled' => false, 'threshold' => 100, 'window' => 3600],
```

## Documentation

| Topic | Doc |
|---|---|
| Installation + configuration | [docs/installation.md](docs/installation.md) |
| ACL evaluation + matchers | [docs/acl.md](docs/acl.md) |
| Audit log + HMAC chain | [docs/audit-log.md](docs/audit-log.md) |
| Scanner + ClamAV + signatures | [docs/scanner.md](docs/scanner.md) |
| File-change watcher | [docs/security-watch.md](docs/security-watch.md) |
| Notifications + multi-cadence reports | [docs/notifications.md](docs/notifications.md) |
| Bypass mechanism | [docs/bypass.md](docs/bypass.md) |
| Premium tier + license | [docs/premium.md](docs/premium.md) |

## Premium tier

Premium features live in the **same package**, gated by `LS_PREMIUM_LICENSE_KEY` at runtime. No separate composer repo, no Satis, no auth tokens. Buy at [laravel-shield.ozankurt.com](https://laravel-shield.ozankurt.com), paste the key into `.env`, premium features activate on next request.

Premium unlocks:
- Real-time threat feed sync (free tier syncs daily; premium polls every few minutes)
- Real-time IP blocklist subscription
- Hosted audit-log sink (forward audit events to the Shield Central app for cross-site aggregation)
- Future SIEM dashboard integration

The license check is honest soft-enforcement (see [docs/premium.md](docs/premium.md) — the real moat is the API services Ozan hosts, which patching the local check can't unlock).

## Versioning

Semver. Major versions break public API surfaces (table names, namespace, config keys). Beta releases under `1.0.0-beta.N` shipped the foundation; `1.0.0` is the polished v1.

| Version | Headline |
|---|---|
| `1.0.0-beta.1` | Foundation: rename, schema reset, ACL, audit log, bypass |
| `1.0.0-beta.2` | Audit log expansion, file drift detection, Yajra dropped |
| `1.0.0-beta.3` | Scanner + ClamAV + signature sync + file watcher |
| `1.0.0-beta.4` | Live traffic + upload AV + Spatie Media Library integration + WAF rules UI |
| `1.0.0-beta.5` | Headers + honeypots + redaction + scoring + HTTPS + cookies + trusted proxies + rate limiters |
| `1.0.0` | Polished release with multi-cadence reports + Wordfence-style executive email + docs |
| `1.1.0` | Threat feed providers (AbuseIPDB, Spamhaus, MaxMind, OWASP CRS) |
| `1.2.0` | composer audit dashboard, diagnostics + OWASP score card, import/export |
| `2.0.0` | Premium tier activation goes live (license API + SIEM aggregator at `laravel-shield.ozankurt.com`) |

## Companion packages

- **`ozankurt/laravel-shield-filament`** — Filament panel adapter. v1.x for Filament 3 + 4, v2.x for Filament 5+. (Ships post-1.0.)
- **`ozankurt/laravel-shield-signatures`** — Public GitHub repo of malware signatures. `shield:signatures-sync` pulls from here.

## Credits

- Built on top of `voku/anti-xss`, `ramsey/uuid`, `symfony/http-foundation`, `ozankurt/agent`, and the standard Laravel framework
- WAF rule patterns + malware signatures derived in part from [Wordfence Security](https://www.wordfence.com/) (MIT)
- ClamAV integration via [xenolope/quahog](https://github.com/jonnywilliamson/quahog)

## License

MIT — see [LICENSE.md](LICENSE.md).
