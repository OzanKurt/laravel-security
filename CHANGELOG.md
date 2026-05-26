# Changelog

All notable changes to `ozankurt/laravel-shield` will be documented in this file.

## [1.0.0-beta.1] — 2026-05-26

This is the first beta of the Wordfence-parity upgrade. **Breaking changes throughout** — there is no upgrade path from 0.x (`ozankurt/laravel-security`); fresh install required.

### Breaking changes
- **Package renamed** from `ozankurt/laravel-security` → `ozankurt/laravel-shield`
- **PHP namespace** renamed from `OzanKurt\Security` → `OzanKurt\Shield`
- **Config file** renamed from `config/security.php` → `config/shield.php`
- **All tables** dropped and recreated under `ls_*` prefix (no migration of old data — fresh install)
- **Dashboard routes** moved from `/security/*` to `/shield/*`
- **Artisan commands** renamed from `security:*` to `shield:*`
- **Translation namespace** changed from `security::` to `shield::`
- **View namespace** changed from `security::` to `shield::`
- **Container alias** changed from `app('security')` to `app('shield')`
- **Service provider class** renamed from `SecurityServiceProvider` to `ShieldServiceProvider`
- **Gate name** changed from `viewSecurityDashboard` to `viewShieldDashboard`

### New features
- **Full ACL system** (`ls_acl`) replacing the old `security_ips` — supports ip, cidr, asn, country, region, city, hostname, ua_regex, ref_regex matchers (asn/geo/hostname are stubs returning false in beta.1, full implementation lands in 1.1)
- **DB-backed WAF rules** (`ls_waf_rules`) — 47 builtin patterns extracted from old config arrays, manageable via admin panel (UI in beta.2)
- **Tamper-evident audit log** (`ls_audit_log`) with HMAC-SHA256 chain (each record stores prev_hash + hmac)
- **Three-layer bypass mechanism** preventing admin lockout:
  - Header bypass key (`LS_BYPASS_KEY` env + `X-Security-Bypass` header)
  - Config IP whitelist (`LS_BYPASS_IPS` env, always-on)
  - Recovery Artisan commands (`shield:bypass-add`, `shield:bypass-remove`, `shield:bypass-list`)
- **`shield:install`** one-command setup (publishes, migrates, generates secrets, seeds, optional self-IP whitelist)
- **Cache management dashboard** (Laravel Debugbar-inspired) at `/shield/cache`
- **CorrelationId middleware** — per-request UUID7 propagated to all events (distributed-trace-style)
- **11 lookup tables** replacing all PHP enums (cached forever, sub-ms name→id resolution)

### Internal patterns
- `HasUuid` (UUID v7), `HasUserstamps`, `CorrelationId` project-wide patterns
- `ShieldBlueprint` migration helper (standard column set across every table)
- Cache strategy with Redis recommendation, file fallback
- `firewall.all` middleware group now: correlation → bypass → acl → [config-driven middlewares]

### Known limitations (1.0.0-beta.1 scope)
- Scanner / ClamAV / file watcher / signatures sync: ship in beta.3
- Live traffic stream + AV upload middleware + Spatie Media Library integration: ship in beta.4
- Beyond-WF extras (security headers, honeypots, sensitive-field redaction, suspicious scoring): ship in beta.5
- Threat feed providers (AbuseIPDB / Spamhaus / MaxMind): ship in 1.1
- Composer vuln scanner + diagnostics + OWASP report: ship in 1.2
- Premium tier activation (license API + SIEM aggregator at `laravel-shield.ozankurt.com`): ships in 2.0

### Migration
See `UPGRADING.md` for step-by-step instructions if you were on 0.x.

[1.0.0-beta.1]: https://github.com/OzanKurt/laravel-shield/releases/tag/v1.0.0-beta.1
