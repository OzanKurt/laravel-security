# Laravel Shield Documentation

Comprehensive Laravel security suite — WAF, malware scanner, ACL, audit log, live traffic, and notifications.

## Getting started

| Topic | What |
|---|---|
| [Installation](installation.md) | `composer require` + `shield:install` flow, post-install setup, optional dependencies |
| [Configuration](configuration.md) | Every config key in `config/shield.php` explained — storage drivers, caching, sampling, retention |
| [Architecture](architecture.md) | Contracts, service container bindings, extension points, premium hook design |

## Defense layers

| Topic | What |
|---|---|
| [ACL — Access Control List](acl.md) | Unified allow/deny across IP/CIDR/ASN/country/regex with first-match-wins evaluation |
| [Middleware reference](middleware.md) | Every `firewall.*` alias + what it does + when to attach it |
| [Bypass mechanism](bypass.md) | Three-layer admin lockout recovery: env key, config IPs, Artisan commands |

## Observability

| Topic | What |
|---|---|
| [Audit log](audit-log.md) | HMAC-chained tamper-evident audit trail with file/config/composer drift detection |
| [Scanner](scanner.md) | Native + ClamAV + composer-audit backends, signature sync, quarantine + restore |
| [File watcher](security-watch.md) | `shield:watch` long-running command for real-time file-change detection |
| [Dashboard](dashboard.md) | Bootstrap dashboard page tour + the cache management page |

## Outbound

| Topic | What |
|---|---|
| [Notifications](notifications.md) | 5 channels (mail/Slack/Discord/Telegram/webhook), severity routing, throttling, multi-cadence reports |
| [Premium tier](premium.md) | What activates with a license key, the honest soft-enforcement model |

## Threat intelligence (1.1+)

| Topic | What |
|---|---|
| [Threat feeds](threat-feeds.md) | AbuseIPDB, Spamhaus DROP/EDROP, MaxMind GeoLite2, OWASP CRS provider setup |
| [Composer audit](diagnostics.md#composer-audit) | CVE detection in installed Composer packages, surfaced in dashboard + reports |

## Operations

| Topic | What |
|---|---|
| [Diagnostics](diagnostics.md) | System info + OWASP environment audit grade + integration health |
| [Troubleshooting](troubleshooting.md) | Common issues with concrete fixes |

## Companion packages

- [`ozankurt/laravel-shield-filament`](https://github.com/OzanKurt/laravel-shield-filament) — Filament panel adapter. v1.x for Filament 3+4. v2.x for Filament 5+.
- [`ozankurt/laravel-shield-signatures`](https://github.com/OzanKurt/laravel-shield-signatures) — Public signature feed consumed by `shield:signatures-sync`.

## Brand site

[laravel-shield.ozankurt.com](https://laravel-shield.ozankurt.com) — pricing, license activation, support.
