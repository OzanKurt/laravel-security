# Wordfence-Parity Upgrade — Brainstorming Questions

Date: 2026-05-25
Branch: `feat/wordfence-upgrade`
Author: Claude (brainstorming with Ozan)

> **Purpose:** Pin down the spec for upgrading `ozankurt/laravel-security` into a Wordfence-equivalent Laravel security package. Wordfence free source is reference-available (port-able); Wordfence premium features must be re-implemented from functional description only (no premium source).
>
> **How to use:** Each question has my recommended pick highlighted as **★ Recommendation**, with reasoning anchored in what's already built. Reply "default" or "★" to accept all recs, or override per-question. Some questions need a real answer (e.g. P2 license) — I won't presume those.

---

## Part 0 — Current Functionality Inventory (read first)

Before the questions, here's a complete inventory of what already exists in the package so my recommendations don't reinvent wheels.

### 0.1 Firewall middlewares already built (15)

| Middleware | Purpose | Notes |
|---|---|---|
| `firewall.ip` | DB block/blacklist enforcement | Hits `security_ips` table; increments `request_count` |
| `firewall.agent` | User-agent filtering | Malicious patterns + browser/platform/device/property allow-block |
| `firewall.bot` | Crawler block | Uses `ozankurt/agent` `isRobot()` + allow/block crawler lists |
| `firewall.geo` | Geographic block | 7 provider backends: `ipapi`, `extremeiplookup`, `ipstack`, `ipdata`, `ipinfo`, `ipregistry`, `ip2locationio` |
| `firewall.lfi` | Local file inclusion | Regex patterns, GET/DELETE by default |
| `firewall.rfi` | Remote file inclusion | Regex + content-fetch verification + exception list |
| `firewall.php` | PHP wrapper protocols | `php://`, `phar://`, `bzip2://`, `zip://`, etc. |
| `firewall.session` | Session deserialization | Detects serialized payload signatures |
| `firewall.sqli` | SQL injection regex | Basic UNION SELECT etc. patterns |
| `firewall.xss` | XSS protection | `voku/anti-xss` + Blade echo cleaner + custom patterns; `block` or `clean` mode |
| `firewall.swear` | Word blacklist | Dynamic regex from word list |
| `firewall.url` | URL-pattern protected paths | Inspection / logging only |
| `firewall.referrer` | Referrer block | Static list match |
| `firewall.whitelist` | IP whitelist enforcement | Forces requests to be whitelisted IPs only |
| `firewall.keyword` | Path keyword block | `wp-admin`, `.env`, `.git`, `xmlrpc`, etc. — already wordfence-style |

Each middleware shares: enabled toggle, methods filter, routes only/except, inputs only/except, `auto_block` config (attempts/frequency/period), patterns array.

Abstract middleware (`Firewall/AbstractMiddleware.php`) handles: skip checks, whitelist short-circuit, method/route filtering, log creation, AttackDetectedEvent dispatch, response (view/redirect/abort/exception/JSON).

### 0.2 Data model already built

- **`security_logs`** — `user_id`, `middleware`, `level` (enum low/medium/high/critical), `ip`, `url`, `user_agent`, `referrer`, `request_data` (JSON, 2KB cap), `meta_data` (JSON), `softDeletes`
- **`security_ips`** — `ip`, `log_id`, `entry_type` (enum BLACKLIST/BLOCK/WHITELIST), `request_count`, `softDeletes`
- **`security_auth_logs`** — `email`, `is_successful`, `user_id`, `ip`, `user_agent`, `referrer`, `request_data`, `meta_data`, `is_notification_sent`, `notification_sent_at`, `softDeletes`
- Configurable connection + table prefix + custom user model class

### 0.3 Events / listeners

- `AttackDetectedEvent` → `AttackDetectedListener` (sends notification) + `BlockIpListener` (auto-block based on window count)
- `Illuminate\Auth\Events\Login` → `SuccessfulLoginListener` (logs + notifies)
- `Illuminate\Auth\Events\Failed` → `FailedLoginListener` (logs + notifies, password redacted)
- Both auth listeners support `setShouldRecordCallback()` and `setShouldSendCallback()` for app-level overrides

### 0.4 Dashboard (Bootstrap 5 Blade + yajra/datatables)

- Stats cards (attacks, IPs blocked, requests blocked)
- "Recently modified files" widget (`RecentlyModifiedFiles` helper, excludes vendor/node_modules/storage)
- `/security/auth-logs` — datatable
- `/security/logs` — datatable, JSON syntax highlighted
- `/security/ips` — datatable + per-row whitelist/blacklist/delete actions
- Theme switcher (light/dark)
- AJAX action protocol — server returns `{ actions: [{ type: 'toastr' | 'reloadDataTable', data }] }`
- Asset version check — shows "republish assets" banner if `public/manifest.json` stale
- Gate: `viewSecurityDashboard`
- Custom dashboard middleware: `SecurityDashboardMiddleware`

### 0.5 Notifications (Laravel native, queueable)

- 4 notification classes: `AttackDetectedNotification`, `FailedLoginNotification`, `SuccessfulLoginNotification`, `SecurityReportNotification`
- 3 channels: mail, slack, custom `DiscordChannel` (with `DiscordMessage` builder)
- Custom `Notifiable` routes to configured recipient(s)
- `routeNotificationFor*` helpers
- Configurable per-channel queue names

### 0.6 Commands + scheduling

- `security:unblock-ips` — un-blocks IPs after their middleware's `auto_block.period`
- `security:send-security-report-notification` — weekly Monday 8am default

### 0.7 Helpers

- `RecentlyModifiedFiles extends DirectoryIterator` — recursive scan, configurable time range + file limit + iteration cap
- `BladeEchoCleaner` — strips Blade echo tags so user input can't smuggle `{{ }}` constructs
- `Helper` trait — Cloudflare-aware IP (`CF_CONNECTING_IP`), user-agent fetch, request data size cap

### 0.8 Service / entry point

- `Security` class — `antiXss` instance, IP-whitelisted-in-DB check (cached per-request), JSON syntax highlight, recently-modified-files cache, asset freshness check, route helpers
- Service provider auto-registers gates, listeners, commands, routes, views, translations, publishables
- Singleton bound as `'security'` and `Security::class`

### 0.9 Tests

7 feature tests in `tests/Feature/`: `IpTest`, `LfiTest`, `NotificationTest`, `RfiTest`, `SqliTest`, `WhitelistTest`, `XssTest`. Orchestra Testbench + SQLite memory. Coverage is sparse but pattern is there.

### 0.10 Implications for the upgrade

**Strong foundations to keep:**
- Middleware factory pattern → extend, don't rewrite
- Event + listener pattern → reuse for new event types
- AJAX action protocol → extends naturally to new dashboard pages
- DataTable + Bootstrap dashboard → can be extended faster than rewriting in Filament
- Auto-block / unblock cycle is already solid → reuse for new triggers
- Discord channel rare-feature → keep, add Telegram/Teams alongside
- 3-state IP model (whitelist/block/blacklist) → just add ranges/ASN/country on top

**Wordfence parity gaps:**
1. No 2FA, no CAPTCHA, no breached-password check, no per-user lockout
2. No malware/signature scanner (only "recently modified files" widget)
3. No threat feed integration (rules are static in config)
4. No real-time IP blocklist subscription
5. No audit log (just attack + auth logs)
6. No live traffic stream (only polled datatables)
7. No bot identification beyond ozankurt/agent's `isRobot`
8. No WHOIS / reverse DNS UI
9. No diagnostics / sysinfo page
10. No config import/export
11. No security headers middleware (HSTS/CSP/X-Frame/etc.)
12. No composer package vulnerability scanner
13. No `.env` security audit
14. No sensitive-field redaction beyond hardcoded `password`
15. No central / multi-site aggregation
16. No tamper-evidence (HMAC chain) on log records
17. No IP-range / CIDR / ASN blocking (only single IPs in DB)
18. No country-list in DB (geo middleware is per-request external lookup)

The questions below cover how we close those gaps.

---

## Part 1 — Scope & Goals

### A1. Which feature areas in v1 of this upgrade?

Areas: WAF, scanner, login security, live traffic, blocking, audit log, threat feed, central, diagnostics, notifications.

**★ Recommendation: (b) Tiered MVP**
- Phase 1 (this upgrade's v1 = 1.0): scanner + login security (2FA/HIBP/CAPTCHA) + live traffic + extended blocking (CIDR/ASN/country DB) + audit log + extended notifications. WAF/notifications are already mostly built, so they go in v1 only as enhancements.
- Phase 2 (v1.x minors): threat feed integration + composer-package vuln scanner + diagnostics + import/export
- Phase 3 (v2): central / multi-site

**Why this split:** Scanner + 2FA + live traffic + audit log are the things users will ask for first and they don't depend on a remote SaaS. Threat feed needs a hosted service or partnerships. Central needs a SaaS. Keep externalities out of v1.

### A2. Parity vs Laravel-native idioms?

**★ Recommendation: (b) Parity of capabilities, Laravel-native implementation**
The current code is already Laravel-idiomatic (Eloquent models, events, listeners, queues, gates, service provider). Strict 1:1 parity would require porting WP hooks and globals that have no Laravel equivalent. Use Wordfence as the *behavior reference*, not the code reference.

### A3. Target Laravel apps — generic, opinionated, Filament-first?

**★ Recommendation: (a) Generic Laravel 9–12 app, no auth/UI assumption**
The current dashboard works in any Laravel app. Adding a hard Filament dep would lock out half the userbase. Instead, ship the current Bootstrap dashboard as default + provide an *optional* Filament-bridge package later.

### A4. Free vs Premium model

**★ Recommendation: (a) Fully free, MIT (or GPLv3 if we port code — see P2), all features**
The current package is already free MIT. Wordfence's premium tier is a business model choice, not a technical necessity. A free Laravel security package with these features will be far more popular than a paid one, and supplements your reputation. Keep the door open for paid support / hosted services if you want them later.

---

## Part 2 — Architecture

### B1. Single package vs split

**★ Recommendation: (a) Single package `ozankurt/laravel-security`**
Current users already depend on this package name. Splitting would force a migration on day one. Internally, use clean namespaces (`OzanKurt\Security\Scanner\`, `OzanKurt\Security\Auth\`, `OzanKurt\Security\Firewall\`) — each module is a self-contained area, removable via config flag.

### B2. Backwards compatibility

**★ Recommendation: (a) Keep all current config keys, tables, and middleware names**
Everything additive. v1.0 stays drop-in compatible with 0.x for existing users. Provide `php artisan security:upgrade` to publish new migrations and config additions.

Bonus: bump to `v1.0.0-beta` first since current is `0.x` and signals stable API.

### B3. Dashboard UI stack

**★ Recommendation: (a) Keep current Bootstrap 5 + Blade + DataTables**
You already shipped a polished theme-switcher dashboard. Rewriting in Filament/Livewire would set us back weeks and break existing dashboard customizations. The AJAX action protocol you built (`toastr` + `reloadDataTable`) already extends naturally.

Add: a `security.dashboard.adapter` config so an optional `ozankurt/laravel-security-filament` companion package can hook in later for Filament users.

### B4. Storage / DB strategy

**B4a — connection:** Keep the dedicated `FIREWALL_DB_CONNECTION` (already exists).

**B4b — hot data:**
**★ Recommendation: Queue+batch.** Live traffic and high-frequency logs go to Redis cache or a queue, flushed to DB every N seconds in a worker. This stops sync DB writes from slowing the request path. Falls back to direct DB on cache miss.

**B4c — retention:**
**★ Recommendation: Per-table configurable, sane defaults:**
- `logs` (attacks): 90 days
- `auth_logs`: 365 days
- `ips` (blocks): rolling — already handled by unblock cron
- `live_traffic`: 7 days
- `audit_log`: 365 days

Provide `security:prune` Artisan command auto-scheduled.

### B5. Performance posture

**★ Recommendation: (d) Queue + sampling hybrid**
- Attacks/blocks: always logged synchronously to DB (they're rare, security-critical)
- Live traffic: sampled at `1/N` (configurable) for non-attack requests, 100% for attacks/blocks
- Notifications: already queued — keep
- Scanner: runs as queued job, never sync

---

## Part 3 — Threat Intelligence Feed

### C1. Where does threat data come from?

**★ Recommendation: (c) Pluggable provider contract with 2-3 free defaults**
Define `OzanKurt\Security\Contracts\ThreatFeedProvider`. Ship defaults:
- **AbuseIPDB** — free tier, 1000 reports/day, very good IP reputation
- **Spamhaus DROP/EDROP** — free, no API key, IP blocklists updated daily
- **Optional MaxMind GeoLite2** — local DB file, country/ASN data

Users plug their own (Wordfence-CLI–style, Cloudflare's intel, custom) via simple interface. Pull on Laravel scheduler (daily for blocklists, weekly for GeoIP).

This is way smaller scope than running our own SaaS, and gets us 80% of premium feed value.

### C2. Default sources?

**★ Recommendation:** AbuseIPDB + Spamhaus DROP + MaxMind GeoLite2 (above).
Plus: optional **OWASP ModSecurity CRS** rule set for WAF (open-source, well-maintained).

### C3. Rule format

**★ Recommendation: (d) Mix — keep simple regex arrays as today, add a structured rule DSL for distributed feeds**
- Existing config-driven regex arrays stay for user-defined rules (familiar, simple)
- New `Rule` class with `match()`, `score()`, `action()` for feed-distributed rules
- A "ruleset" is a versioned bundle of `Rule` objects; rulesets live in `storage/security/rules/` after feed sync

---

## Part 4 — Malware / File Scanner

### D1. What does "scan" mean for a Laravel app?

**★ Recommendation: (h) All of the above, opt-in per category in config**
- **a)** Vendor / composer packages — verify each installed package's hash against `composer.lock` and (optionally) live Packagist
- **b)** App PHP files — scan content for malware signatures
- **c)** Public-writable dirs (`storage/app/public`, `public/uploads`) — high priority because user uploads land here
- **d)** Recently modified files — already done, surface as a "suspicious if mtime in window" check
- **e)** DB-stored content — opt-in via published config (e.g. `posts.body`)
- **f)** Config drift — checksum `config/*.php`, alert on change
- **g)** `.env` / dotfiles — leak detection (e.g. `APP_DEBUG=true`, weak `APP_KEY`)

### D2. Signature source

**★ Recommendation: (b) Pull from a remote signature feed, fallback to bundled**
- Ship a starter signature set in `storage/security/signatures/` (curated subset from Wordfence-free + ClamAV)
- `security:scan-update` Artisan command pulls latest from your hosted signature URL (you can mirror Wordfence-free signatures + your own additions on a static S3/CDN — no SaaS needed)
- Premium-tier-style real-time updates can come later

### D3. Scan triggers

**★ Recommendation: (d) All three**
- Manual: `php artisan security:scan` + dashboard "Start Scan" button (queued)
- Scheduled: daily via Laravel scheduler (full scan slower, quick scan more often)
- Event-driven: optional filesystem watcher (skip for v1, doc for self-host)

Use a scan queue with progress reporting in the dashboard (use the existing AJAX action protocol).

### D4. Repair tools

**★ Recommendation: (c) Report-only, no auto-write**
Show: detected issue, file path, hash diff vs `composer.lock` (for vendor), copy-pasteable `composer install` command. Auto-modifying app code is risky for a security tool — users should review.

Add a "Quarantine" action that *moves* a flagged file to `storage/security/quarantine/` (reversible).

---

## Part 5 — Login Security

### E1. 2FA scope

**★ Recommendation: (a) TOTP only for v1**
TOTP is universal (any authenticator app), no carrier deps, well-understood. WebAuthn/passkeys nice-to-have for v1.x. Email-code fallback is mostly used as account-recovery, which is its own can of worms — handle in v2.

Use `pragmarx/google2fa` (mature) or implement TOTP directly (it's ~50 lines).

### E2. 2FA enforcement

**★ Recommendation: (c) Configurable per-guard, per-role, per-user**
- Global default: optional
- Per-guard config: `security.auth.2fa.guards.web.required = ['admin']`
- Per-user override: a `two_factor_enabled` column on users table (added by published migration if user opts in)

### E3. Integration with existing auth

**★ Recommendation: (a) Drop-in middleware**
`firewall.2fa` middleware sits after `auth`. If user has 2FA enabled and current session isn't 2FA-verified, redirect to a `/security/2fa/challenge` view. Verification sets a session flag. Works with any Laravel auth — Sanctum, Fortify, Breeze, custom guards.

Also ship `\OzanKurt\Security\Auth\Concerns\HasTwoFactorAuthentication` trait for the User model — adds `setupTwoFactor()`, `verifyToken()`, `getRecoveryCodes()`.

### E4. CAPTCHA on login

**★ Recommendation: (a) No CAPTCHA in v1, rely on per-IP + per-user lockout**
Rate limiting is more effective vs scripted attacks. CAPTCHA hurts UX. Provide `firewall.captcha` middleware in v1.x as opt-in (Turnstile / hCaptcha / reCAPTCHA v3, since they're free or cheap).

### E5. Breached password check (HIBP)

**★ Recommendation: (a) Yes, hard-block for admins, soft-warn for others**
HIBP k-anonymous SHA-1 prefix lookup is free and privacy-safe. Check at: login, password change, password reset. Add a `firewall.breached_password` middleware + Laravel password validation rule `BreachedPassword`. Behavior configurable per role.

### E6. Brute force protection extensions

**★ Recommendation: (a) + (b) Both**
- (a) Add per-user lockout in addition to per-IP — track `failed_login_attempts` on user model
- (b) Add timing-safe response on "user not found" vs "wrong password" so attackers can't enumerate accounts
- Plus: an "alert on suspicious login location" feature using geo data (login from a country the user has never logged in from)

---

## Part 6 — Live Traffic

### F1. Capture scope

**★ Recommendation: (b) Sampled, 100% of attacks/blocks**
Default sample rate: 1/10 (configurable). High-traffic sites can drop to 1/100. Attacks/blocks/2FA challenges always 100%. Use a `live_traffic` table with hot retention (7 days).

### F2. UI

**★ Recommendation: (b) Auto-refreshing DataTable, 5-10s poll for v1**
We already use DataTables. Adding 5s polling is trivial. SSE/WebSocket is a nice v1.x upgrade, but requires Reverb/Echo or a similar stack — adds setup friction.

### F3. Bot identification

**★ Recommendation: (a) + (b) Hybrid**
- Keep `ozankurt/agent` for basic detection
- Bundle the browscap data Wordfence ships (under GPLv3 — license check needed)
- Display a "Bot type" column with verified-good (Googlebot, Bingbot — verify via reverse DNS), known-bad (scrapers, scanners), unknown

---

## Part 7 — IP / Country / Bot Blocking

### G1. Block types — add to current

Current: single IP + entry_type only.

**★ Recommendation: Add all of these:**
- IP range / CIDR (already supported in Symfony `IpUtils::checkIp`, plug in)
- ASN block (requires GeoLite2 ASN DB)
- Hostname / reverse DNS
- User-agent regex (already supported by `firewall.agent`)
- Referrer regex (already supported)
- Country (currently per-request via geo middleware → make it a DB-cached country list)
- Continent (already supported by `firewall.geo`)
- Real-time blocklist subscription (Spamhaus DROP / AbuseIPDB sync)

Extend `security_ips` table:
- Add `value` column (IP, CIDR, ASN like "AS12345", country code, regex, etc.)
- Add `kind` enum (ip, cidr, asn, country, hostname, ua_regex, ref_regex, source)
- Add `source` (manual, auto_block, blocklist_subscription, etc.)
- Keep `entry_type` (whitelist/block/blacklist)
- Add `expires_at` (replaces the implicit period calculation; cleaner)
- Add `reason` (human-readable)

### G2. Block enforcement

**★ Recommendation: (a) Middleware only for v1, doc on web-server hardening in v2**
Current middleware approach is in-process and works without server config. Generating nginx/apache snippets is useful but easy to break if we don't know the user's stack.

### G3. Geo-IP source

**★ Recommendation: (b) MaxMind GeoLite2 default, keep multi-provider chain as fallback**
- GeoLite2 is free, local DB, no per-request API calls (huge perf win)
- Auto-update via `security:geoip-update` (MaxMind permalink)
- Keep current 7 providers as fallback for users who don't want a local DB
- Wordfence's bundled `geoip.mmdb` is GPLv3 — we can use it under proper license, but MaxMind direct is cleaner

---

## Part 8 — Audit Log

### H1. What to log

**★ Recommendation: All of these, configurable per category:**
- Authentication events (already partial)
- User CRUD
- Role / permission changes
- Eloquent model events on opt-in models — provide `HasAuditLog` trait
- Config drift (file hashes monitored by scanner already)
- File changes in `app/`, `routes/`, `config/`, `.env`
- Composer.json / composer.lock changes
- Admin panel actions — Filament + Nova auto-integration if installed
- Outbound HTTP calls — opt-in, off by default (noisy)

### H2. Tamper-evidence

**★ Recommendation: (a) + (b) Both**
- Local: HMAC chain — each record stores `prev_hash` + `hmac(prev_hash + record_data, secret)`. Deletion or modification detectable on integrity check.
- Optional remote sink: configurable (S3, Logtail, syslog, custom webhook). For attackers with DB access, an off-host copy is essential.

### H3. Retention

**★ Recommendation: 365 days default**, configurable, with a "archive to cold storage" hook before delete.

---

## Part 9 — Notifications

### I1. New channels

**★ Recommendation: (a) + (c) — Telegram + generic webhook in v1**
- Telegram: free, popular for ops alerts, simple webhook
- Generic webhook: future-proof (Teams, PagerDuty, Opsgenie, custom) without us writing N integrations
- Teams: lower priority — corporate market mostly already on Slack/Discord/Teams; can add later
- SMS: nope, paid carrier dep — skip in v1

### I2. Severity tiers

**★ Recommendation: (a) Add severity tiers + per-channel routing**
Reuse the existing `LogLevel` enum (already low/medium/high/critical). Routing config:
```php
'routing' => [
    'critical' => ['mail', 'discord', 'webhook'],
    'high' => ['mail', 'slack'],
    'medium' => ['slack'],
    'low' => [],  // digest only
],
```

### I3. Digest mode

**★ Recommendation: (b) + (c) Throttle window + daily digest**
- Per-event throttle: same event type from same IP within N minutes coalesces into one notification with a count
- Daily digest at 8am: roll up low/medium events from last 24h
- Existing weekly `SecurityReportNotification` stays — that's the executive summary

---

## Part 10 — Diagnostics / Tools

### J1. WHOIS lookup

**★ Recommendation: (a) Yes — easy win**
Bundle a basic WHOIS via `iodev/whois` or the new `phpWhois`. Show in dashboard for any IP. Cache results 24h.

### J2. Sysinfo / diagnostics page

**★ Recommendation: (a) Yes**
Critical for support / debugging. Show: PHP version, extensions, memory, max_execution_time, Laravel version, queue+cache+session drivers, DB version, mtime of last scan, mtime of last GeoIP update, scheduler last-run, list of enabled middlewares.

### J3. Config import/export

**★ Recommendation: (a) Yes**
`security:export` and `security:import` — JSON dump of: security config + IP table (blocks/whitelist/blacklist with reasons) + custom rules. Useful for multi-app setups before we have Central.

---

## Part 11 — Wordfence Central Analogue

### K1. Timeline

**★ Recommendation: (b) v2/later — design APIs to make it possible now**
Don't build Central in v1. But:
- Each package version exposes a stable REST API (`/security/api/v1/*`) authenticated by a shared secret
- Each package version can push events to a configured webhook
- Future Central app polls those APIs OR receives webhooks

### K2. Contract

**★ Recommendation: (c) Both push + pull**
- Push: hook into existing event listeners, also fire to webhook
- Pull: REST endpoints for inventory (rules, IPs, logs filtered)
- Central app then aggregates many sites

---

## Part 12 — Compatibility & Quality

### L1. PHP / Laravel matrix

**★ Recommendation: (a) Keep current matrix — PHP 8.0+, Laravel 9/10/11/12**
Don't lock out existing users mid-upgrade. PHP 8.0 still common in shared hosting. Some new features (e.g. enums with methods) already require 8.1+; gate those features behind feature detection.

Bump minimum if a future feature requires it, but stay PHP 8.0+ at the start of v1.

### L2. Test coverage target

**★ Recommendation: (a) + (c) hybrid — TDD for new security-critical code, pragmatic for UI/glue**
- TDD for: 2FA, HIBP, audit log integrity, scanner signatures, blocklist sync, rate limiting
- Pragmatic for: dashboard views, notification templates, datatables
- Goal: 80%+ on security-critical paths, no formal target on UI

### L3. Static analysis

**★ Recommendation: (a) PHPStan level 5 on new code, level 6 by end of v1.x**
Current code has no PHPStan setup. Starting at level 5 is realistic. Level 8 is overkill for a Laravel package.

### L4. Multi-site / multi-tenant

**★ Recommendation: (a) Single-tenant in v1**
Multi-tenant adds N×complexity. Most users are single-tenant. If a tenancy package user needs it, document the override pattern (custom Notifiable + scoped models).

---

## Part 13 — Delivery

### M1. Phased release

**★ Recommendation: (b) Phased minor releases**
- `1.0.0-beta.1` — extended blocking (CIDR/ASN/country DB), audit log skeleton, severity routing
- `1.0.0-beta.2` — 2FA + HIBP + per-user lockout
- `1.0.0-beta.3` — file/malware scanner + signatures
- `1.0.0-beta.4` — live traffic streaming
- `1.0.0` — polish, docs, migration guide
- `1.1.0` — threat feed providers (AbuseIPDB / Spamhaus / OWASP CRS)
- `1.2.0` — composer-package vuln scanner, diagnostics, import/export
- `2.0.0` — Central / multi-site

### M2. Migration story for current users

**★ Recommendation: (b) Manual via `security:upgrade` command**
The command runs new migrations, copies/transforms existing config additions, optionally regenerates IP table to new schema. Show a clear `UPGRADING.md` doc.

### M3. Documentation

**★ Recommendation: (b) README + per-feature docs in `docs/`**
README is already 200 lines. New surface is 5x that. Split into `docs/<topic>.md`. Hosted docs site is overkill for v1.

---

## Part 14 — Wordfence Items Needing Laravel Mapping

### N1. "WordPress core file integrity" → ?

**★ Recommendation: (b) Verify vendor packages against composer.lock + Packagist signatures**
This is the cleanest Laravel analogue. Many users won't have signed packages, but the hash comparison still detects tampering.

### N2. "Plugin/theme integrity" → ?

**★ Recommendation:** Same as N1 — composer handles both.

### N3. ".htaccess hardening" → ?

**★ Recommendation: (b) Recommendations doc only, no auto-write**
Provide a `SECURITY_HARDENING.md` with nginx, Apache, Laravel-Octane-specific suggestions. Don't touch user's server config.

### N4. "wp-config.php" → ".env" audit

**★ Recommendation: (a) Yes — detect insecure `.env` settings**
Build a `security:audit-env` command. Checks:
- `APP_DEBUG=true` in `APP_ENV=production`
- Missing/weak `APP_KEY`
- `MAIL_PASSWORD` / `DB_PASSWORD` in obvious-weak patterns
- `SESSION_SECURE_COOKIE` not enabled in production
- `LOG_LEVEL=debug` in production
Report in dashboard + email digest.

### N5. "XML-RPC disable" → ?

**★ Recommendation: (b) Generic "disable risky endpoints" feature**
A `firewall.disabled_routes` middleware that takes a list of route names / patterns to 404 unconditionally. Users add what they want.

### N6. Falcon caching

**★ Recommendation: (a) Skip**

---

## Part 15 — Things to Add Beyond Wordfence

### O1. Beyond-Wordfence features

**★ Recommendation: add these in v1.x:**

1. **Security headers middleware** — HSTS, CSP (configurable), X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy. Way more impactful than half of Wordfence's surface for modern Laravel apps. Easy to ship.
2. **`composer audit` wrapper** — daily check for known CVEs in installed Composer packages. Surface in dashboard.
3. **Sensitive-field redaction (auto)** — currently only `password` is redacted in auth logs. Generalize: configurable regex/key list (`password`, `token`, `secret`, `api_key`, `credit_card`, etc.) globally applied to all request_data captures.
4. **Honeypot routes** — register fake admin routes (`wp-admin`, `phpmyadmin`, `.env`) that always 404 but log+block the requester. Catches bots instantly.
5. **Suspicious activity scoring** — assign each event a score, sum per IP over time, auto-block at threshold. Per-event scores configurable.
6. **Rate limiting helper** — Laravel has rate limiting built-in but provide pre-configured limiters for login, API, password reset, signup with sensible defaults.
7. **2FA recovery codes UI** — generate + show one-time recovery codes during 2FA setup.

### O2. Constraints

(For you to answer — see Section Q below.)

### O3. Stretch goals

**★ Recommendation: defer to v2+:**
- ML / anomaly detection — overkill, false-positive risk
- Request fuzzing detection — possible v1.x with scoring system above
- IDS / signature-based alerting — folded into the scanner + threat feed
- Honeypot routes — actually doable in v1 (see O1.4)

---

## Part 16 — Wordfence Source / License (RESOLVED)

### P1. Mapping of free vs premium

Confirm or correct:

| Subsystem | Source available? | Plan |
|---|---|---|
| Free WAF rules (`lib/wordfenceScanner.php` patterns, `waf/` rules) | Yes (MIT) | Port pattern lists, adapt to PHP regex |
| Free WAF engine (`lib/wfWAF*.php`) | Yes (MIT) | Don't port — already have Laravel middleware. Cherry-pick patterns. |
| Free login security (`modules/login-security/`) | Yes (MIT) | Port behavior, write Laravel-native |
| Free scanner (`lib/wfScan*.php`, `lib/wordfenceHash.php`) | Yes (MIT) | Port file traversal + signature matching |
| Free signatures bundled with plugin | Yes (MIT) | Re-ship with proper attribution |
| GeoIP database (`lib/geoip.mmdb`) | Bundled MaxMind GeoLite2 | Use MaxMind direct, not bundled |
| Browscap data (`lib/wfBrowscap*.php`) | Yes (MIT) | Port for bot detection |
| Common passwords list (`lib/wfCommonPasswords.php`) | Yes (MIT) | Port — useful for breached-password fallback offline |
| **Premium** real-time threat feed | Re-implement from description | Pluggable provider system (Section C) |
| **Premium** real-time IP blocklist | Re-implement | AbuseIPDB / Spamhaus integration |
| **Premium** country blocking logic | Re-implement (free has skeleton) | DB-backed country list |
| **Premium** audit log | Re-implement | HMAC chain + remote sink |
| **Premium** Wordfence Central | Re-implement (defer) | v2 |

### P2. License compatibility — RESOLVED

**Wordfence 8.2.2 is MIT.** Confirmed in three places:
- `wordfence/wordfence.php:14` → `License: MIT`
- `wordfence/readme.txt:8` → `License: MIT`
- `wordfence/license.txt` → `MIT`

(Older Wordfence versions were GPLv3, but the team relicensed to MIT in a later release. Earlier in this doc I mistakenly cited GPLv3 — that was wrong, based on matching `License:` strings inside changelog text.)

`ozankurt/laravel-security` is also MIT. **Both projects are MIT — no license switch needed.** Port WF free code 1:1 as-is. Preserve original MIT attribution / NOTICE for the ported portions.

Wordfence team also gave Ozan verbal permission to reuse the free source — MIT alone permits this; the verbal permission is bonus confirmation.

**Decision: Stay MIT. Port free WF code freely. Re-implement premium features from description only.**

---

## Part 17 — Things you need to tell me (no recommendation possible)

These can't be defaulted — they depend on your situation:

### Q1. License decision — see P2. (Must answer.)

### Q2. Wordfence license exception — do you have it in writing?

### Q3. Time budget — how many weeks/months you can commit?

This affects phasing aggressively. v1 full scope (Phase 1) is roughly 6–10 weeks full-time.

### Q4. Existing user disruption tolerance

Is anyone running this in production today that we can break? If yes, who, and what tables/configs do they depend on?

### Q5. Compliance / regulatory needs

Any apps using this that have HIPAA, PCI-DSS, GDPR audit log requirements? Affects audit log design.

### Q6. Hosting for signature/feed files

If we ship a remote signature feed updater, we need a static URL to host the signatures (S3, R2, even a GitHub release). Where do you want to host these?

### Q7. Filament integration priority

Are you personally using Filament in projects? If yes, I'd accelerate the Filament-bridge companion to v1.x. If no, defer.

### Q8. Anything Wordfence-specific you actively want to NOT replicate

Some Wordfence behaviors are controversial (e.g., aggressive scan that hits the DB hard). Anything we should explicitly avoid?

---

## End — Process

Once you answer (or "all defaults"), I'll:

1. Roll your answers into a design spec at `docs/superpowers/specs/2026-05-25-wordfence-upgrade-design.md`
2. Self-review the spec for placeholders / contradictions / scope / ambiguity
3. Hand it back for your spec review
4. Then invoke `superpowers:writing-plans` for the implementation plan

**Defaults summary** (if you say "all defaults"):

- Tiered MVP, capability parity, generic Laravel, free MIT (pending Q1 answer), single package, BC compatible, current Bootstrap dashboard
- Queue-and-sample perf, per-table retention, dedicated security DB connection
- Pluggable threat feed (AbuseIPDB + Spamhaus + MaxMind defaults)
- Full Laravel-scoped scanner with composer + app files + uploads + config drift + .env audit
- TOTP 2FA, no CAPTCHA v1, HIBP enabled, per-user + per-IP lockout
- Sampled live traffic with 5–10s polling
- CIDR/ASN/country/regex blocking via extended `security_ips` schema
- Audit log with HMAC chain + optional remote sink, 365 day retention
- Telegram + generic webhook channels, severity routing, daily digest
- WHOIS + sysinfo + import/export tools
- Central deferred to v2 — design APIs now
- PHP 8.0+ / Laravel 9–12, TDD for security-critical, PHPStan L5, single-tenant
- Phased beta releases under 1.0.0-beta.N
- License: **Stay MIT, port WF free code freely (both are MIT — resolved)**
