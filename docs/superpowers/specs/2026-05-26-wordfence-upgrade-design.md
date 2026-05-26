# Laravel Security — Wordfence-Parity Upgrade Design Spec

**Document:** `docs/superpowers/specs/2026-05-26-wordfence-upgrade-design.md`
**Branch:** `feat/wordfence-upgrade`
**Author:** Ozan Kurt + Claude
**Date:** 2026-05-26
**Status:** Draft — awaiting Ozan's review before phase-by-phase implementation specs

---

## 1. Document Purpose

This is the **master design spec** for upgrading `ozankurt/laravel-security` from its current 0.x state into a Wordfence-equivalent Laravel security suite. Every architectural decision and data model belongs here.

Downstream documents (created later):
- Phase-by-phase implementation specs: `docs/superpowers/specs/phases/2026-MM-DD-phase-N-<topic>.md` — one per beta release
- Test plans woven into each phase spec (Orchestra Testbench)

This master spec is the **single source of truth** when decisions conflict. Implementation specs reference back to this one.

---

## 2. Executive Summary

`ozankurt/laravel-security` becomes a **WAF / analyze / log / scan / alert** suite for Laravel apps, modelled on Wordfence's free + premium feature surface. The plugin's job is observability and defense at the request boundary; it does NOT own authentication, user management, or MFA — apps handle those.

Two composer packages will ship:
- **`ozankurt/laravel-security`** (Packagist, MIT) — single package containing all features (free + premium-gated); premium features activate at runtime via `LS_PREMIUM_LICENSE_KEY`
- **`ozankurt/laravel-security-filament`** (Packagist, MIT, later) — Filament dashboard adapter (v1.x for Filament 3+4, v2.x for Filament 5+)

The brand domain `laravel-shield.ozankurt.com` hosts the marketing/sales site, license-check API, customer accounts, and (later) the SIEM-aggregator dashboard that consumes webhook events from many plugin instances.

Wordfence (current version 8.2.2) is MIT-licensed; this package is MIT. Free Wordfence source can be ported 1:1 with attribution. Premium Wordfence features are re-implemented from behavioral description only.

A future separate Laravel app (out of scope here) will aggregate data from many `laravel-security` instances — like a Sentry for WAF — via the webhook notification channel.

---

## 3. Goals & Non-Goals

### Goals

1. **Feature parity with Wordfence** in observability/defense capabilities (WAF, scanner, ACL, audit log, live traffic, notifications)
2. **Laravel-native idioms** throughout (Eloquent, events, queues, gates, service providers)
3. **Configurable to the point of obsession** — every limit, threshold, retention period, regex, signature path is an exposed config key
4. **Drop-in safety primitives** — security headers middleware, honeypot routes, sensitive-field redaction, generic disabled-routes middleware
5. **Mobile-friendly dashboard** — every table works at 360px viewport
6. **Free + premium business model** — single package on Packagist; premium features gated at runtime by license key, code lives alongside free code in the same repo
7. **Tamper-evident audit trail** — HMAC chain, optional remote sink
8. **Extensibility** — pluggable backends (storage, scanner, threat-feed, notification), service-container hooks for premium overrides

### Non-Goals (out of scope for the plugin)

- 2FA enrollment / TOTP generation / WebAuthn — apps handle this (we log auth events emitted by the app's stack)
- HIBP breached password checks — apps' responsibility
- CAPTCHA on login — apps' responsibility
- Per-user account lockout — apps' responsibility
- Any columns added to the `users` table — we never touch the user model
- WordPress Multisite analogue — Laravel apps that need multi-tenant scoping use a tenancy package; out of scope for v1
- The future "Central / SaaS aggregator" app — separate Laravel project, separate brainstorm, separate spec

---

## 4. License & Permissions

### Package license

`ozankurt/laravel-security` stays **MIT**. Wordfence 8.2.2 is also MIT (confirmed in `wordfence/wordfence.php:14`, `wordfence/readme.txt:8`, `wordfence/license.txt`). Both projects are MIT — port free Wordfence code 1:1 with attribution. No license switch required.

### Wordfence author permission

Ozan has verbal permission from the Wordfence author to reuse the free source. MIT alone permits this; the verbal permission is extra confirmation.

### Porting rules

- **Free Wordfence source**: port verbatim where Laravel-portable. Preserve original MIT attribution headers when porting verbatim. Add `NOTICE` file enumerating ported modules.
- **Premium Wordfence source**: NEVER reference or include premium source. Only behavioral descriptions are usable.
- Code style of ported modules gets normalized to PSR-12 + project conventions; comments preserved with origin notes.

### Premium package license

Premium features ship inside the same `ozankurt/laravel-security` package under the **same MIT license** as the rest of the code. The license **key** (sold via the Laravel Shield site) is what controls feature activation at runtime — not a code-distribution license. Anyone can read/copy the premium code; without a valid runtime license key (and the API-gated services it unlocks), the premium feature value is incomplete. See §29 for the honest threat model.

---

## 5. Scope Boundary

### In scope — five capability areas

| Area | What | Notes |
|---|---|---|
| **WAF** | Block malicious HTTP requests via middlewares | 15+ middlewares, DB-backed rules |
| **Analyze** | Detect patterns, score suspicious behavior | Suspicious activity scoring in v1.0 |
| **Log** | Capture attacks, auth events, audit trail, live traffic | 5 dedicated tables |
| **Scan** | File integrity, malware, signatures, ClamAV, composer audit | 3 backends, file watcher |
| **Alert** | Notifications via 5 channels, severity routing, throttling | Mail/Slack/Discord/Telegram/Webhook |

### Out of scope

See §3 Non-Goals.

---

## 6. Roadmap & Phases

The upgrade ships as a series of versioned betas under `1.0.0-beta.N`, then incremental minor releases.

| Version | Scope | Effort estimate |
|---|---|---|
| `1.0.0-beta.1` | Schema reset + ACL system + audit log skeleton + severity routing + lookup tables + bypass mechanism + cache dashboard | ~1 week |
| `1.0.0-beta.2` | Audit log expansion (file/config/composer drift detection) + dashboard polish + datatables.net direct migration | ~1 week |
| `1.0.0-beta.3` | Scanner (native + ClamAV + composer audit) + signatures GitHub Releases sync + file-change watcher (`security:watch`) + quarantine | ~2 weeks |
| `1.0.0-beta.4` | Live traffic stream + AV upload middleware + Spatie Media Library integration + WAF rules DB management UI | ~1 week |
| `1.0.0-beta.5` | Beyond-WF extras: security headers, honeypots, redaction, disabled-routes, suspicious scoring, CSP nonce, HTTPS enforce, cookie audit | ~1 week |
| `1.0.0` | Polish, docs, migration guide, comprehensive Orchestra Testbench coverage | ~1 week |
| `1.1.0` | Threat feed providers (AbuseIPDB, Spamhaus, MaxMind, OWASP CRS) | ~1 week |
| `1.2.0` | Composer vuln scanner + diagnostics page + OWASP report card + import/export | ~1 week |
| `2.0.0` | Premium tier activation goes live (license API + SIEM-aggregator dashboard at `laravel-shield.ozankurt.com`); premium features in the single package start being purchasable | Out of v1.0 scope; separately specced post-1.0 |

Total: ~8–10 weeks of focused engineering to v1.0.

### Central app (separate project)

A future standalone Laravel app — like Sentry for WAF data — aggregates events from many `laravel-security` installations via the generic webhook notification channel. **Not part of this plugin.** This plugin's design ensures the webhook channel emits a stable, versioned payload that the Central app will consume.

---

## 7. Package Structure

**Two packages. One on Packagist for everything. One adapter.**

### `ozankurt/laravel-security` (single package, free on Packagist)

- Packagist, MIT
- **Contains ALL features — both free-tier and premium-tier code lives in this same package**
- Premium-tier features are gated at runtime by `Security::isPremium()` / `Security::isFeatureAvailable($feature)`
- Without a valid `LS_PREMIUM_LICENSE_KEY`, premium-tier code paths gracefully fall back to free behavior
- One repo, one CI, one release flow

**Why single-package:** Same enforcement level as a separate package (local checks are patchable either way; real moat is server-side API gating). Drops Satis, drops Composer auth, drops separate-repo sync overhead. This is the standard model for ACF, Yoast, Wordfence, and most successful "free+premium" PHP plugins.

### `ozankurt/laravel-security-filament` (free, later, separate)

- Packagist, MIT
- Filament panel adapter
- **v1.x** supports Filament 3 + 4
- **v2.x** supports Filament 5+
- Drops in for users who want a Filament dashboard instead of the Bootstrap one

### Future split (if ever needed)

If we someday have code we genuinely don't want public (proprietary detection algorithms, ML models, etc.), we can spin out `ozankurt/laravel-security-premium` then — with private repo + Composer auth. The `Security::isPremium()` API is designed to be agnostic of where the premium code lives.

For v1.0–v2.0: no split needed. All premium features are gated runtime checks within the single package.

### Service contracts (in the single package, free + premium implementations side by side)

```php
namespace OzanKurt\Security\Contracts;

interface ThreatFeedProvider     { public function sync(): SyncResult; }
interface IpBlocklistProvider    { public function pull(): Collection; }
interface AuditSink              { public function push(AuditRecord $record): void; }
interface StorageDriver          { public function persist(string $table, array $data): void; }
interface ScannerBackend         { public function scanFile(string $path): array; }
interface NotificationChannel    { public function send(Notification $notification): void; }
interface SuspicionScorer        { public function score(string $ip): int; public function bump(string $ip, int $by): void; }
```

Default implementations live in `OzanKurt\Security\Services\` (free behavior). Premium implementations live in `OzanKurt\Security\Premium\` (premium behavior). The `SecurityServiceProvider` binds the premium implementation when `Security::isPremium()` is true, otherwise the free one — runtime decision per service.

```php
// In SecurityServiceProvider::register()
$this->app->bind(ThreatFeedProvider::class, function ($app) {
    return Security::isPremium()
        ? new Premium\RealtimeThreatFeedProvider()
        : new Services\DailyThreatFeedProvider();
});
```

---

## 8. Core Design Principles

1. **Breaking changes OK in v1.0** — no production users on 0.x worth preserving (per Ozan).
2. **Configurability is paramount** — every limit, threshold, attempt count, retention period, regex, signature path, etc. is exposed as a config key. Hard-coded magic numbers are a code review failure.
3. **Database table prefix: `ls_`** (was `security_`) — cleaner, shorter, matches a future "Laravel Shield" rename.
4. **No PHP enums** — int FK to seeded cached lookup tables (per Ozan).
5. **UUIDs are v7 (time-sortable)** — every table has both an `id` (bigint PK) and `uuid` (UUID7).
6. **Userstamps on every table** — `created_by_id` / `updated_by_id` / `deleted_by_id` nullable FK to users, auto-filled by `HasUserstamps` trait.
7. **Soft deletes everywhere** — preserves audit history.
8. **`correlation_id` on every event-style table** — UUID7 generated once per HTTP request / queue job / CLI command, propagated to all events from that context.
9. **In-house traits for cross-cutting concerns** — no `spatie/laravel-userstamps`, no `spatie/laravel-medialibrary` for our own UUIDs. Keep composer footprint small.
10. **First-match-wins ACL evaluation** with explicit allow > blacklist > block precedence.
11. **Redis strongly suggested for cache + queue** — file cache fallback works but slower.
12. **Mobile-friendly tables** — every dashboard table tested at 360px viewport.
13. **No new datatable PHP wrapper** — drop `yajra/laravel-datatables`, use `datatables.net` JS direct with a thin Laravel JSON endpoint.

---

## 9. Storage Strategy

Pluggable storage driver per `StorageDriver` contract. Three default implementations + sampling on entry.

```php
'storage' => [
    'driver' => env('LS_STORAGE_DRIVER', 'sync'), // sync | queue | redis_batch
    'queue_connection' => env('LS_QUEUE_CONNECTION', 'default'),
    'queue_name' => env('LS_QUEUE_NAME', 'security'),
    'batch_size' => 100,
    'batch_interval' => 5, // seconds
    'sample_rate' => [
        'live_traffic' => 0.1, // 1 in 10 non-attack requests
        'logs' => 1.0,
        'audit_log' => 1.0,
    ],
],
```

### Drivers

| Driver | Mechanism | Use case |
|---|---|---|
| `sync` | Direct Eloquent save | Default, low-traffic, dev |
| `queue` | Laravel job dispatched per write | Medium traffic, needs worker |
| `redis_batch` | Buffers in Redis sorted set, flushes every `batch_interval` | High traffic, requires Redis |

### Sampling

- Attacks / blocks / audit events: always 100% (security-critical, low volume)
- Live traffic non-attacks: configurable sample rate, default 1/10
- Each table type has its own `sample_rate` key

Custom drivers (ClickHouse, Elasticsearch, Datadog, etc.) implement `StorageDriver` and register via service container.

---

## 10. Schema Patterns (apply to every new table)

### Standard column set

```php
$table->id();                                   // bigint autoincrement PK
$table->uuid('uuid')->unique();                 // UUID7, time-sortable
$table->uuid('correlation_id')->index();        // links events from same request/job/command
// ... domain columns ...
$table->timestamps();                            // created_at, updated_at
$table->softDeletes();                           // deleted_at
$table->foreignId('created_by_id')->nullable(); // FK to users
$table->foreignId('updated_by_id')->nullable();
$table->foreignId('deleted_by_id')->nullable();
```

Indexes: every FK gets `->index()`, plus domain-specific composite indexes.

### UUID generation

- Use `Ramsey\Uuid\Uuid::uuid7()` (already in Laravel via `ramsey/uuid` 4.7+)
- `HasUuid` trait (in-house) hooks `creating` event, auto-fills

### Userstamps

- `HasUserstamps` trait (in-house) hooks `creating`, `updating`, `deleting` events
- Auto-fills from `auth()->id()`, gracefully nullable when unauthenticated (auto-block, CLI, etc.)

### Lookup tables (no enums)

Every "type" / "kind" / "status" / "category" field becomes:
- A small lookup table: `id`, `name` (slug), `label` (human), `description`, `sort_order`
- `xxx_id` foreign key on the parent table
- Seeded by migration
- Cached via `Cache::rememberForever('ls.lookups.{table}', ...)` keyed name→id
- Sub-ms hot-path resolution, no joins in hot loops

### Correlation ID propagation

- Per HTTP request: earliest middleware generates UUID7, stored in request attributes + container singleton
- Per queue job: generated in `handle()` start
- Per CLI command: generated in command `handle()` start
- Helper `app('security')->correlationId(): string` returns current id (defensive: generates if missing)
- Every event listener / observer / scanner pulls this and persists alongside the event

---

## 11. Data Model — Full Table Inventory

All tables follow the standard column set above unless noted.

### 11.1 Core event tables

#### `ls_logs` (attack / firewall hit log)

```sql
ls_logs
├── id, uuid, correlation_id
├── middleware_id        FK → ls_log_kinds (xss, sqli, lfi, rfi, agent, bot, geo, ...)
├── severity_id          FK → ls_log_levels (low/medium/high/critical)
├── ip                   varchar(45) indexed
├── user_id              FK to users nullable
├── url                  text
├── user_agent           text nullable
├── referrer             varchar(255) nullable
├── method               varchar(8)
├── status_code          smallint nullable
├── response_time_ms     int nullable
├── matched_rule_id      FK → ls_waf_rules nullable
├── request_data         json nullable -- redacted per config
├── meta                 json nullable -- geo, asn, bot ID, etc.
├── timestamps, softdeletes, userstamps
```

Replaces current `security_logs`. Extended with `matched_rule_id` (links to which rule fired), `method`, `status_code`, `response_time_ms`.

#### `ls_auth_logs` (login attempts)

```sql
ls_auth_logs
├── id, uuid, correlation_id
├── email                varchar(255) indexed
├── is_successful        boolean indexed
├── auth_event_id        FK → ls_auth_event_kinds (login, logout, failed, password_reset, 2fa_challenge, 2fa_verified, ...)
├── user_id              FK to users nullable
├── ip                   varchar(45) indexed
├── user_agent           text nullable
├── referrer             varchar(255) nullable
├── request_data         json nullable
├── meta                 json nullable
├── is_notification_sent boolean default false
├── notification_sent_at timestamp nullable
├── timestamps, softdeletes, userstamps
```

Replaces current `security_auth_logs`. Extended with `auth_event_id` to capture 2FA outcomes (when app's auth stack emits 2FA events, we listen and log).

#### `ls_live_traffic` (real-time request stream, sampled)

```sql
ls_live_traffic
├── id, uuid, correlation_id
├── ip                   varchar(45) indexed
├── country_code         char(2) nullable
├── asn                  varchar(16) nullable
├── user_id              FK to users nullable
├── method               varchar(8)
├── url                  text
├── status_code          smallint
├── response_time_ms     int
├── user_agent           text nullable
├── referrer             varchar(255) nullable
├── bot_identity         varchar(64) nullable -- googlebot, bingbot, etc.
├── action_taken_id      FK → ls_action_kinds (passed, blocked, whitelisted)
├── matched_rule_id      FK → ls_waf_rules nullable
├── fingerprint_hash     char(32) nullable -- request fingerprint for dedup
├── timestamps, userstamps (no softdelete — short retention)
```

7-day default retention. Sampling configurable; attacks/blocks always 100%.

**Real-time streaming (v1.0 option):** When `live_traffic.real_time.enabled` is true, the capture pipeline fires `LiveTrafficCapturedEvent implements ShouldBroadcast` on the Laravel broadcasting bus. Dashboard subscribes via Laravel Echo to `private-security.live-traffic`. Works with any broadcast driver (Reverb, Pusher, Ably, Soketi). Default behavior is **polling (5–10s DataTable refresh)** — real-time is opt-in to avoid forcing broadcast infra on users.

```php
'live_traffic' => [
    'real_time' => [
        'enabled' => false,
        'broadcast_driver' => null,            // null = Laravel default
        'channel' => 'private-security.live-traffic',
        'authorize_via' => 'viewSecurityDashboard',  // existing gate
        'rate_limit_per_minute' => 600,        // protects clients from event flood
    ],
],
```

Composer suggests `laravel/reverb` for self-hosted broadcast.

#### `ls_audit_log` (tamper-evident audit trail)

```sql
ls_audit_log
├── id, uuid, correlation_id
├── kind_id              FK → ls_audit_log_kinds
├── severity_id          FK → ls_log_levels
├── actor_type           varchar(64) nullable -- 'user', 'system', 'cli', 'cron', 'feed'
├── actor_id             bigint nullable
├── subject_type         varchar(64) nullable -- morph type
├── subject_id           bigint nullable -- morph id
├── ip                   varchar(45)
├── user_agent           text nullable
├── url                  text nullable
├── description          text
├── changes              json nullable -- before/after for CRUD events
├── meta                 json nullable
├── prev_hash            char(64) nullable -- previous record's hmac
├── hmac                 char(64) -- HMAC-SHA256(prev_hash + serialized_record, secret)
├── timestamps, softdeletes, userstamps
```

Tamper evidence via HMAC chain. See §16.

### 11.2 ACL tables

#### `ls_acl` (unified access control list)

```sql
ls_acl
├── id, uuid, correlation_id
├── kind_id              FK → ls_acl_kinds
├── value                varchar(255) indexed -- IP / "AS12345" / "TR" / "10.0.0.0/8" / regex / etc.
├── action_id            FK → ls_acl_actions (allow / block / blacklist)
├── source               varchar(64) -- 'manual', 'auto_block', 'spamhaus', 'abuseipdb', 'honeypot', 'import', ...
├── reason               varchar(255)
├── log_id               FK → ls_logs nullable
├── hit_count            int unsigned default 0
├── expires_at           timestamp nullable -- null = permanent
├── meta                 json nullable
├── timestamps, softdeletes, userstamps
```

Indexes: `(kind_id, value)`, `(action_id, expires_at)`, `(source)`.

### 11.3 Lookup tables (seeded, cached forever)

Standard shape for all:
```sql
ls_<topic>_kinds (or _actions, _levels, _categories, _statuses, _targets, _backends, ...)
├── id, uuid
├── name           varchar(64) unique -- slug
├── label          varchar(128) -- human
├── description    text nullable
├── sort_order     int default 0
├── meta           json nullable
├── timestamps
```

Inventory:

- `ls_acl_kinds` — ip, cidr, asn, country, region, city, hostname, ua_regex, ref_regex
- `ls_acl_actions` — allow, block, blacklist
- `ls_log_kinds` — agent, bot, geo, ip, lfi, php, referrer, rfi, session, sqli, swear, url, whitelist, xss, keyword, honeypot, scoring, https_enforce, ...
- `ls_log_levels` — low, medium, high, critical
- `ls_auth_event_kinds` — login, logout, failed_login, password_reset_requested, password_reset_completed, 2fa_challenge_issued, 2fa_verified, 2fa_recovery_used
- `ls_audit_log_kinds` — auth.*, user.*, role.*, model.*, config.drift, file.drift, composer.changed, acl.*, scanner.*, threat_feed.*, notification.sent, dashboard.action, http.outbound
- `ls_action_kinds` — passed, blocked, whitelisted, redirected, throttled
- `ls_waf_rule_categories` — xss, sqli, lfi, rfi, php_protocols, session, agent, bot, keyword, custom
- `ls_waf_rule_kinds` — regex, header_match, ip_match, ua_match
- `ls_waf_rule_targets` — request_input, request_url, request_path, request_query, request_header, request_body, full_request
- `ls_waf_rule_actions` — block, log, score
- `ls_signature_categories` — malware, backdoor, webshell, phishing, heuristic
- `ls_scanner_targets` — vendor, app_files, public_uploads, recently_modified, config_drift, env_audit, dotfiles, db_content, unknown_files
- `ls_scanner_backends` — native, clamav, composer_audit
- `ls_scanner_statuses` — queued, running, completed, failed, cancelled
- `ls_scanner_finding_statuses` — open, quarantined, resolved, ignored, false_positive
- `ls_scanner_triggers` — manual, scheduled, file_change, webhook
- `ls_threat_feed_sources` — abuseipdb, spamhaus, maxmind, owasp_crs, custom

### 11.4 WAF rule tables

#### `ls_waf_rules`

```sql
ls_waf_rules
├── id, uuid, correlation_id
├── source               varchar(64) -- 'builtin', 'user', 'wf_free', 'owasp_crs', 'custom_feed'
├── source_ref           varchar(255) nullable -- external rule id
├── name                 varchar(255)
├── description          text nullable
├── category_id          FK → ls_waf_rule_categories
├── kind_id              FK → ls_waf_rule_kinds
├── target_id            FK → ls_waf_rule_targets
├── pattern              text
├── action_id            FK → ls_waf_rule_actions
├── severity_id          FK → ls_log_levels
├── score                smallint default 0 -- for action=score
├── is_enabled           boolean default true
├── meta                 json nullable
├── version              int default 1
├── timestamps, softdeletes, userstamps
```

### 11.5 Scanner tables

#### `ls_signatures`

```sql
ls_signatures
├── id, uuid, correlation_id
├── source               varchar(64) -- 'builtin_native', 'wf_free', 'user', 'clamav'
├── source_ref           varchar(255) nullable
├── name                 varchar(255)
├── description          text nullable
├── category_id          FK → ls_signature_categories
├── kind                 varchar(32) -- 'regex', 'file_hash', 'string_match'
├── pattern              text
├── severity_id          FK → ls_log_levels
├── version              int default 1
├── is_enabled           boolean default true
├── meta                 json nullable
├── timestamps, softdeletes, userstamps
```

#### `ls_scanner_runs`

```sql
ls_scanner_runs
├── id, uuid, correlation_id
├── trigger_id           FK → ls_scanner_triggers
├── status_id            FK → ls_scanner_statuses
├── targets              json -- which targets were configured for this run
├── backends             json -- which backends executed
├── started_at, finished_at
├── files_scanned        int default 0
├── findings_count       int default 0
├── findings_critical_count int default 0
├── error_message        text nullable
├── timestamps, softdeletes, userstamps
```

#### `ls_scanner_findings`

```sql
ls_scanner_findings
├── id, uuid, correlation_id
├── scanner_run_id       FK → ls_scanner_runs
├── target_id            FK → ls_scanner_targets
├── backend_id           FK → ls_scanner_backends
├── signature_id         FK → ls_signatures nullable -- null when backend=clamav (use signature_ref)
├── signature_ref        varchar(255) nullable -- e.g. "Win.Trojan.Generic-1234"
├── severity_id          FK → ls_log_levels
├── status_id            FK → ls_scanner_finding_statuses
├── file_path            text
├── file_hash            char(64) nullable
├── line_number          int nullable
├── matched_content      text nullable -- truncated
├── notes                text nullable -- user annotation
├── quarantine_path      text nullable -- if quarantined, where it lives now
├── timestamps, softdeletes, userstamps
```

### 11.6 Threat feed tables (1.1+)

#### `ls_threat_feed_runs`

```sql
ls_threat_feed_runs
├── id, uuid, correlation_id
├── source_id            FK → ls_threat_feed_sources
├── status_id            FK → ls_scanner_statuses (reused)
├── started_at, finished_at
├── records_imported     int default 0
├── records_updated      int default 0
├── error_message        text nullable
├── timestamps, softdeletes, userstamps
```

Feed-imported items land in `ls_acl` (for IP/CIDR blocklists) or `ls_waf_rules` (for rule feeds) tagged by source.

---

## 12. ACL System

### Evaluation algorithm (first-match-wins per tier)

```
for kind in (ip, cidr, asn, country, region, city, hostname, ua_regex, ref_regex):
    if WHITELIST entry matches request → return ALLOW

for kind in (...):
    if BLACKLIST entry matches request → return DENY(403, cached 60s)

for kind in (...):
    if BLOCK entry matches request → return DENY(403, cached 60s)

return PASS (continue through firewall middlewares)
```

Whitelist always wins. Blacklist (permanent) beats block (temporary).

### Kind support

| kind | hot-path | gated by config |
|---|---|---|
| ip | yes | always on |
| cidr | yes (Symfony IpUtils) | always on |
| country, region, city | yes (GeoIP local DB) | opt-in (`security.acl.geo.enabled`) |
| asn | yes (MaxMind ASN DB) | **opt-in + composer suggest `geoip2/geoip2`** |
| ua_regex, ref_regex | yes (cheap regex) | always on |
| hostname (reverse DNS) | no — slow DNS lookup | **opt-in only, perf warning in config** |

### Caching

- **ACL live set** → cached forever (`ls.acl.live`), invalidated on any `ls_acl` write (model observer)
- **Per-IP decision cache** → TTL configurable (default 60s, `security.acl.decision_cache_ttl`)
- **GeoIP / ASN per-IP** → cached for request lifetime
- **Lookup tables** (kinds, actions, levels) → `Cache::rememberForever`

### Cache backend

- **Redis strongly recommended** — composer suggests `predis/predis` or PHP ext `redis`
- **File store fallback** — works without Redis, slower

### Sweeping expired entries

`security:acl-prune` Artisan command, scheduled hourly:
- Deletes ACL entries where `expires_at < now()`
- Audit logs each deletion (kind=acl.expired)
- Replaces the old `security:unblock-ips` command

### Auto-block flow

Each firewall middleware retains its `auto_block` config (`attempts`, `frequency`, `period`):

```php
'middleware' => [
    'xss' => [
        'auto_block' => [
            'attempts' => 3,
            'frequency' => 300, // 5 minutes
            'period' => 1800,   // 30 minutes
        ],
    ],
],
```

On Nth attack from same IP within `frequency` seconds:
- `BlockAclEntryListener` (renamed from `BlockIpListener`) creates ACL entry: `kind=ip, value=<ip>, action=block, source=auto_block, expires_at=now()+period, log_id=<trigger>, reason='Auto-blocked after N <middleware> attacks'`
- Audit log entry fired with the new ACL row as subject

---

## 13. WAF Rules System

### DB-backed

Rules live in `ls_waf_rules` (see §11.4). Admin panel manages enabled/disabled, custom rules, syncs from feeds. Built-in patterns from current `config/security.php` are extracted into a migration seeder.

### Sources

| source | meaning |
|---|---|
| `builtin` | Seeded by package migrations, read-only display, can be disabled |
| `user` | Created via dashboard, fully editable |
| `wf_free` | Synced from `ozankurt/laravel-security-signatures` (Wordfence-free regex patterns) |
| `owasp_crs` | Synced from OWASP ModSecurity Core Rule Set (subset) |
| `custom_feed` | User-configured third-party feed |

### Sync command

```bash
php artisan security:rules-sync [--source=wf_free]
```

- Pulls latest from configured remote
- Upserts by `(source, source_ref)` — bumps `version` on pattern/metadata change
- Audit-logged per change
- Scheduled daily by default

### Actions

| action | v1.0 behavior |
|---|---|
| `block` | Active — request denied immediately |
| `log` | Active — log + continue |
| `score` | Active in v1.0 (per Ozan reversal) — contributes to per-IP score; threshold-based block |

### Caching

`ls.waf.rules.{category}` cached forever, invalidated on any rule write.

### Suspicion scoring (in v1.0)

- Per-IP cumulative score in Redis (TTL window, default 3600s)
- Each `action=score` rule contributes its `score` integer
- When score crosses configured threshold → auto-block via `ls_acl`
- Threshold, decay window, score-to-action mapping all configurable
- Dashboard shows current scores per IP (sortable)

```php
'scoring' => [
    'enabled' => true,
    'threshold' => 100,
    'window' => 3600,
    'block_duration' => 1800,
],
```

---

## 14. Firewall Middleware System

### Existing middlewares (15) — preserved with refactor

`firewall.ip`, `firewall.agent`, `firewall.bot`, `firewall.geo`, `firewall.lfi`, `firewall.rfi`, `firewall.php`, `firewall.referrer`, `firewall.session`, `firewall.sqli`, `firewall.swear`, `firewall.url`, `firewall.whitelist`, `firewall.xss`, `firewall.keyword`.

Each is refactored to:
- Pull patterns from `ls_waf_rules` instead of config arrays
- Skip when ACL whitelist already matched (handled by separate ACL middleware at higher priority)
- Share a common `MatchedRuleEvent` to drive logging + scoring + notifications

### New middlewares in v1.0

| Middleware | Purpose |
|---|---|
| `firewall.acl` | Evaluates ACL (allow/blacklist/block) before any other firewall middleware. Highest priority. |
| `firewall.headers` | Adds security headers (HSTS, CSP, X-Frame, etc.) to responses |
| `firewall.honeypot` | Marks honeypot routes — paired with route registration helper |
| `firewall.disabled_routes` | Unconditional 404 + log + block for configured route patterns |
| `firewall.https` | Redirects HTTP → HTTPS in production |
| `firewall.av_uploads` | Streams `$request->allFiles()` through ClamAV via INSTREAM (opt-in per route) |
| `firewall.score_threshold` | Checks current per-IP suspicion score; blocks if >= threshold |

All new middlewares are aliased in `SecurityServiceProvider::registerMiddleware()` and added to the `firewall.all` group conditionally based on config.

### Middleware group `firewall.all`

```php
$router->middlewareGroup('firewall.all', [
    'firewall.acl',                  // first — short-circuit on whitelist/blacklist
    'firewall.headers',              // adds headers to response (also runs after)
    'firewall.https',
    'firewall.score_threshold',
    'firewall.honeypot',
    'firewall.disabled_routes',
    // domain-specific:
    'firewall.ip', 'firewall.agent', 'firewall.bot', 'firewall.geo',
    'firewall.lfi', 'firewall.rfi', 'firewall.php',
    'firewall.referrer', 'firewall.session',
    'firewall.sqli', 'firewall.swear', 'firewall.url',
    'firewall.xss', 'firewall.keyword',
]);
```

Order matters — `firewall.acl` first so blocked IPs short-circuit; `firewall.headers` ideally first too for response phase; domain middlewares last so they only run on requests that survive earlier checks.

---

## 15. Audit Log

### Schema

See §11.1, `ls_audit_log`.

### Event categories (all configurable on/off)

- `auth.*` — login, logout, failed login, password reset (from Laravel Auth events)
- `2fa.*` — challenge issued, verified, recovery code used (only if app's auth stack emits these)
- `user.*` — created, edited, deleted (opt-in observer)
- `role.*` — permission/role changes (opt-in observer)
- `model.*` — any model with `HasAuditLog` trait emits CRUD events
- `config.drift` — `config/*.php` checksum changes (scanner-driven)
- `file.drift` — files in `app/`, `routes/`, `config/`, `.env` modified outside expected window
- `composer.changed` — `composer.json` / `composer.lock` modified
- `acl.*` — added, removed, modified (self-observer)
- `scanner.*` — started, finished, finding, quarantine action
- `threat_feed.*` — sync started, completed, failed
- `notification.sent` — which alert went where
- `dashboard.action` — manual operations via dashboard
- `http.outbound` — opt-in, off by default (noisy)
- `bypass.used` — when bypass key / config IP / Artisan recovery is exercised

### Tamper evidence

- **HMAC chain**: each record stores `prev_hash` = previous record's HMAC. Mutating or deleting any record breaks the chain.
- Secret: `LS_AUDIT_HMAC_SECRET` env (separate from `APP_KEY` so leaked DB ≠ forgable).
- `php artisan security:audit-verify` walks chain, reports first broken link.
- Rotation: `php artisan security:audit-rotate-secret` re-HMACs the chain with a new secret (audit-logged).

### Optional remote sinks (v1.0)

`AuditSink` contract. Multiple sinks can run in parallel:
- File append: `storage/security/audit/audit.log.YYYY-MM-DD` (rotated daily)
- Push to S3 / R2 / generic webhook
- Syslog (UDP/TCP)
- Logtail / Datadog / generic HTTP+JSON

Configurable via:
```php
'audit_log' => [
    'sinks' => [
        'file' => ['enabled' => true, 'path' => 'storage/security/audit', 'rotation' => 'daily'],
        'webhook' => ['enabled' => false, 'url' => env('LS_AUDIT_WEBHOOK_URL'), 'headers' => [...]],
        's3' => ['enabled' => false, 'bucket' => env('LS_AUDIT_S3_BUCKET'), 'prefix' => 'audit/'],
        'syslog' => ['enabled' => false, 'host' => env('LS_AUDIT_SYSLOG_HOST')],
    ],
],
```

### Retention

Default 365 days. Per-`kind_id` overrides configurable:
```php
'retention_days' => [
    'default' => 365,
    'http.outbound' => 30,        // noisy, short retention
    'auth.failed_login' => 730,   // keep failed logins longer
    'acl.*' => 1095,              // 3 years for ACL change history
],
```

`security:audit-prune` Artisan command, scheduled daily.

---

## 16. Scanner

### Backends (independent, runnable solo or combined)

1. **Native engine** — always available. Patterns from `ls_signatures` (source=builtin_native or wf_free). Regex + file-hash matching. Custom + ported WF free signatures.
2. **ClamAV** — opt-in via composer suggest `xenolope/quahog`. Talks to local `clamd` via Unix socket or TCP.
3. **Composer audit** — wraps `composer audit` CLI output, surfaces CVEs in installed packages.

Findings deduplicated by `(file_path, signature_ref)` when multiple backends match the same thing.

### Scan targets (each is a `ScannerTarget`)

| target | default | quarantine policy |
|---|---|---|
| `vendor` | on | log-only (moving breaks app) |
| `app_files` | on | log-only |
| `public_uploads` | on | **move + stub** (in execution path) |
| `recently_modified` | on | per-finding decision |
| `config_drift` | on | log-only |
| `env_audit` | on | log-only |
| `dotfiles` | on | log-only |
| `db_content` | off | log-only |
| `unknown_files` (in `public/`) | off | **move + stub** |

Each policy overridable in `config/security.php`. Paranoid users can set "log-only everywhere"; aggressive users can set "move everywhere."

### Upload scanning (3 layers, all use same `Scanner::scanFile()` core)

1. **`firewall.av_uploads` middleware** — scans `$request->allFiles()` recursively. Opt-in per route. Default: reject 415 + audit + notify.
2. **Spatie Media Library auto-integration** — detected via `class_exists(\Spatie\MediaLibrary\Models\Media::class)`. Hooks Media Library events so files are rejected before save. Zero-config when Spatie present.
3. **On-demand API** — `app('security')->scanUploadedFile($uploadedFile): ScanResult` for any code path.

### Signatures (hosting + sync)

- Hosted in GitHub repo: **`ozankurt/laravel-security-signatures`**
- Each release is a tagged signature bundle: `signatures.json` + `checksum.sha256`
- `php artisan security:signatures-sync` does: GitHub API → download latest → verify checksum → upsert into `ls_signatures` by `(source, source_ref)` → bump `version` on change → audit log
- Pin via `config('security.scanner.signatures.pin' => 'v2026.05')` or always-latest
- Scheduled daily

### ClamAV operations

```bash
php artisan security:clamav-update    # wraps freshclam if available
php artisan security:clamav-status    # daemon + signature DB info (also shown in dashboard)
```

Dashboard shows daemon running/version + signature DB last-update timestamp.

### Scan triggers

1. **Manual** — `php artisan security:scan [--target=...] [--backend=...]` + dashboard "Start scan"
2. **Scheduled** — quick scan (recently-modified + signatures) daily; full scan weekly
3. **File-change watcher** (`security:watch`) — long-running command using `spatie/file-system-watcher`, requires `chokidar` npm pkg at runtime, falls back to polling if unavailable. Composer suggest. Watches `app/`, `routes/`, `config/`, `.env` + user extras. On change → audit log + optional focused scan. Supervisor/systemd unit examples in docs.

### Quarantine

- Move-and-stub by default for `public_uploads` and `unknown_files`
- File moved to `storage/security/quarantine/<finding_uuid>.bin`, original replaced with empty stub
- `php artisan security:quarantine-restore <finding_uuid>` + dashboard button to restore
- All quarantine operations audit-logged
- Per-target policy override in config

### Finding lifecycle

`open` → `quarantined` | `resolved` | `ignored` | `false_positive`. Resolved findings auto-reopen if the same file still matches on next scan. False positives feed back into signature quality data (we can downvote noisy signatures over time).

---

## 17. Notifications

### Channels in v1.0

| Channel | Native or new |
|---|---|
| Mail | existing |
| Slack | existing |
| Discord | existing (custom channel) |
| **Telegram** | new — bot API, MarkdownV2 |
| **Generic webhook** | new — user supplies URL + headers; **stable contract for future Central/SaaS app** |
| ~~SMS~~ | skipped |

Generic webhook payload contract (versioned for future Central):
```json
{
  "_meta": { "schema_version": "1.0", "site_id": "<config-ured site identifier>" },
  "event": "attack_detected",
  "severity": "high",
  "correlation_id": "uuid7-...",
  "ts": "2026-05-26T14:32:11Z",
  "summary": "Auto-blocked IP 1.2.3.4 after 3 SQLi attempts",
  "links": {
    "dashboard": "https://app.example.com/security/logs?correlation_id=..."
  },
  "data": { /* event-specific payload */ }
}
```

### Routing matrix

Per event kind × severity → channels[]:
```php
'routing' => [
    'attack_detected' => [
        'critical' => ['mail', 'discord', 'webhook'],
        'high'     => ['mail', 'slack', 'discord'],
        'medium'   => ['slack', 'discord'],
        'low'      => [], // digest only
    ],
    'auth_failed' => [ /* ... */ ],
    'scanner_finding' => [ /* ... */ ],
    'audit_event' => [ /* ... */ ],
    'acl_change' => [ /* ... */ ],
    // ... per kind
],
```

### Throttling

```php
'throttle' => [
    'attack_detected' => [
        'window' => 300,
        'group_by' => ['ip', 'middleware'],
        'max_per_window' => 1,
        'continuation_message' => 'N additional similar attacks suppressed in last 5min',
    ],
],
```

Throttle state lives in Redis (cache). **Cache dashboard exposes per-key clear** so admins can manually unblock alerting after fixing an issue.

### Multi-cadence reports

The current weekly `SecurityReportNotification` becomes one of many:

```php
'reports' => [
    'daily_digest' => [
        'enabled' => true,
        'cron_expression' => '0 8 * * *',
        'channels' => ['mail'],
        'include_severities' => ['low', 'medium'],
        'group_by' => 'kind',
    ],
    '3_day' => ['enabled' => false, 'cron_expression' => '0 8 */3 * *', 'channels' => ['mail']],
    '7_day' => ['enabled' => true, 'cron_expression' => '0 8 * * 1', 'channels' => ['mail']],
    '14_day' => ['enabled' => false, 'cron_expression' => '0 8 1,15 * *', 'channels' => ['mail']],
    '30_day' => ['enabled' => false, 'cron_expression' => '0 8 1 * *', 'channels' => ['mail']],
],
```

### Report sections (Wordfence-style executive email)

Each toggleable + configurable top-N limit:
- Header (site URL + date range + logo)
- Top N blocked IPs (IP, country, block count) — buttons link to dashboard
- Top N blocked countries (country, distinct IP count, total blocks)
- Top N failed logins (username, attempt count, whether user exists)
- Recent blocked attacks (time, IP, action) — links to live traffic
- Recently modified files
- Required updates — outdated composer packages with security advisories (feeds in from `composer audit` integration in 1.2)
- CTAs per section linking to relevant dashboard pages

---

## 18. Dashboard

### Stack

- Bootstrap 5 + Blade (current, keep)
- `datatables.net` JS direct (keep — mobile responsive mode is battle-tested)
- **Drop `yajra/laravel-datatables`** — rewrite as thin resource controllers returning JSON in DataTables shape
- jQuery (required by DataTables, OK)
- Alpine.js for interactive sprinkles (modals, toggles)
- All assets compiled

### Page IA

```
/security/
├── /                       Dashboard (stats + recent activity + system health)
├── /acl                    Unified ACL list (kind filter)
├── /logs                   Attack hits
├── /auth-logs              Login attempts
├── /live-traffic           Real-time stream (beta.4)
├── /audit-log              Audit trail (beta.1)
├── /scanner                Scanner home
│   ├── /scanner/findings   All findings, filterable
│   ├── /scanner/runs       Run history
│   └── /scanner/signatures Manage signatures
├── /rules                  WAF rules management
├── /notifications          Routing matrix UI + channel test buttons
├── /cache                  Cache inspection + clear
├── /diagnostics            Sysinfo + OWASP report (1.2)
└── /settings               Read-only config visualization
```

### AJAX action protocol (existing — keep)

Server returns `{ actions: [{ type, data }] }`. Current types: `toastr`, `reloadDataTable`. New types: `quarantineRestore`, `scanProgress`, `cacheCleared`, `confirmDialog`.

### Mobile constraint

Every page tested at 360px viewport. DataTables responsive mode handles column hiding + expand-to-detail row. No horizontal scrolling acceptable.

### Cache dashboard (Laravel Debugbar-inspired)

Page `/security/cache` shows:
- All cache keys with prefix `ls.*`
- Per key: TTL, last refresh, approximate size, hit/miss counters
- "Clear" button per key + "Clear all `ls.*`" button
- Useful for: stuck blocks, stale ACL, stale signatures, manually unblocking after fix

### ClamAV dashboard surface

Inside `/security/scanner`:
- Daemon status card (running? version? socket reachable?)
- Signature DB last `freshclam` update
- "Start scan" button (target picker + backend toggles)
- "Update virus signatures" button
- Recent runs + findings table

---

## 19. Bypass Mechanism (CRITICAL safety primitive)

Building a security system means you can lock yourself out. Three independent mechanisms — all must be supported.

### 1. Bypass key header

- `LS_BYPASS_KEY` env var (random 32+ char string)
- Requests with header `X-Security-Bypass: <key>` skip ALL ACL / firewall / throttle checks
- Logged in audit log when used (kind=bypass.used)
- Empty/unset = disabled
- Lifesaving when admins are locked out from a strange IP

### 2. Config IP whitelist (always-on, immutable from UI)

- `config('security.bypass.ips')` from `LS_BYPASS_IPS` env (comma-separated)
- Listed IPs/CIDRs always bypass everything
- Can never be auto-blocked
- Removal requires `.env` edit (no UI path)
- Recommended for: dev IPs, office static IPs, infrastructure admin IPs

### 3. Recovery Artisan command

```bash
php artisan security:bypass-add <ip>    # adds ACL entry kind=ip, action=allow, source=bypass
php artisan security:bypass-remove <ip> # removes it
php artisan security:bypass-list        # lists active bypass entries
```

Requires shell access. For one-off "stuck, let me in" without editing `.env`.

### Documentation

README + dashboard onboarding clearly explain which mechanism for which scenario. All three audit-log when used so abuse is detectable.

---

## 20. Beyond-Wordfence Extras (all in v1.0)

Each ships as an independent middleware/service, fully configurable.

### 20.1 Security headers middleware (`firewall.headers`)

```php
'headers' => [
    'enabled' => true,
    'hsts' => ['enabled' => true, 'max_age' => 31536000, 'include_subdomains' => true, 'preload' => false],
    'csp' => [
        'enabled' => true,
        'policy' => "default-src 'self'; ...",
        'use_nonce' => true,
        'report_uri' => null,
        'report_only' => false,
    ],
    'x_frame_options' => ['enabled' => true, 'value' => 'SAMEORIGIN'],
    'x_content_type_options' => true,
    'referrer_policy' => 'strict-origin-when-cross-origin',
    'permissions_policy' => 'camera=(), microphone=(), geolocation=()',
],
```

### 20.2 Honeypot routes

- Configurable list of paths (defaults: `wp-admin`, `wp-login.php`, `.env`, `phpmyadmin`, `wordpress`, `.git/config`, `xmlrpc.php`)
- Each hit → 404 response + log + audit + auto-block ACL entry (`kind=ip, action=block, source=honeypot, expires_at=now()+24h`)
- Routes auto-registered by service provider when enabled

```php
'honeypot' => [
    'enabled' => true,
    'paths' => ['wp-admin', '.env', 'phpmyadmin', /* ... */],
    'block_duration' => 86400, // 24h
],
```

### 20.3 Sensitive-field redaction (generalized)

Applied globally to all `request_data` captures (logs, auth_logs, audit_log, live_traffic):

```php
'redaction' => [
    'keys' => ['password', 'password_confirmation', 'token', 'api_key', 'secret', '*_token', 'credit_card', 'cvv', 'ssn'],
    'placeholder' => '[redacted]',
    'use_regex' => true, // patterns with * are treated as regex (* = any chars)
],
```

`RequestDataRedactor` service applied at every capture site.

### 20.4 Disable risky endpoints (`firewall.disabled_routes`)

```php
'disabled_routes' => [
    'enabled' => true,
    'patterns' => [
        // unconditional 404 + audit log
        'install.php',
        'wp-config.php',
        '_ignition/*', // disable Ignition in production
    ],
],
```

### 20.5 Pre-configured rate limiters (uses Laravel-native)

```php
'rate_limiters' => [
    'login' => ['enabled' => true, 'attempts' => 5, 'decay' => 60, 'by' => 'ip|email'],
    'password_reset' => ['enabled' => true, 'attempts' => 3, 'decay' => 60, 'by' => 'ip|email'],
    'api' => ['enabled' => false, 'attempts' => 60, 'decay' => 60, 'by' => 'user|ip'],
    'signup' => ['enabled' => true, 'attempts' => 3, 'decay' => 600, 'by' => 'ip'],
],
```

`SecurityServiceProvider` registers each enabled limiter via `RateLimiter::for()`. Users apply via `throttle:login` middleware. We don't reinvent rate-limit code.

### 20.6 Suspicious activity scoring

See §13. Per-IP cumulative score, configurable threshold, auto-block via ACL.

### 20.7 CSP nonce helper

- `@cspNonce` Blade directive — outputs per-request UUID7 nonce
- `firewall.headers` middleware sets matching nonce in CSP header
- Makes strict CSP usable without rewriting inline scripts

### 20.8 HTTPS enforcement (`firewall.https`)

- Redirects HTTP → HTTPS in production
- Audit-logs the redirect (severity=low)
- Configurable to be middleware-only or also set HSTS preload

### 20.9 Cookie security audit

Inside `env_audit` scanner target:
- Flag missing/wrong `SESSION_SECURE_COOKIE`, `SESSION_HTTP_ONLY`, `SESSION_SAME_SITE`
- Flag missing `APP_URL` or default value in production
- Flag `APP_DEBUG=true` in production
- Flag weak `APP_KEY`

### 20.10 OWASP security audit report (1.2)

Diagnostics page section. Scores config against OWASP cheatsheet:
- HTTPS enforced?
- Strong session cookies?
- CSRF protection on all stateful endpoints?
- Default error pages not leaking debug info?
- Trusted proxies correctly set?
- Outputs grade A–F + per-item remediation

### 20.11 Trusted proxies auto-detect

Extends Cloudflare-aware IP handling. Auto-include known proxy ranges:
- Cloudflare IPv4 + IPv6
- AWS ELB
- GCP Load Balancer
- Refreshed daily, cached
- User can override/disable

---

## 21. Threat Feed Providers (1.1)

### Free defaults

| Provider | What it gives us | API key needed? |
|---|---|---|
| **AbuseIPDB** | IP reputation + reports | Yes (free tier 1000 reports/day) |
| **Spamhaus DROP/EDROP** | Top-tier malicious IP blocklist | No |
| **MaxMind GeoLite2** | GeoIP country + ASN local DB | License key (free) |
| **OWASP ModSecurity CRS** | WAF rule patterns | No (git pull) |

### Pluggable contract

```php
namespace OzanKurt\Security\Contracts;

interface ThreatFeedProvider
{
    public function name(): string;
    public function sync(): SyncResult; // upserts into ls_acl and/or ls_waf_rules
}
```

Users register custom providers via service container.

### Premium-tier providers (in `-premium`)

- Real-time sync (vs daily/weekly for free)
- Larger / curated rule sets
- IP reputation scoring
- Wordfence-equivalent threat feed (via partnership or self-curated)

### Sync schedule

```bash
php artisan security:feed-sync [--source=spamhaus]
```

- Daily by default for free providers
- Premium: real-time push or shorter polling intervals

---

## 22. Composer Vuln Scanner (1.2)

Wraps `composer audit --format=json`:
- Surfaces CVEs in installed packages
- Findings land in `ls_scanner_findings` with `backend=composer_audit`
- Dashboard page shows: package, current version, advisory ID, severity, fix version
- Included in weekly report's "Required updates" section
- Scheduled daily

---

## 23. Diagnostics Page (1.2)

`/security/diagnostics` shows:
- PHP version + extensions
- Laravel version
- Memory limit + max execution time
- Queue connection + driver
- Cache driver
- Session driver
- DB connection + version
- Last scan timestamp + status
- Last signature DB update
- Last threat feed sync
- Scheduler last run
- List of enabled middlewares
- OWASP score card
- Composer suggestions detection (which optional packages installed)
- `LS_AUDIT_HMAC_SECRET` set? `LS_BYPASS_KEY` set?

---

## 24. Configurability

Every parameter that affects behavior is a config key in `config/security.php`. Hard-coded values are a bug.

### Top-level structure (final shape)

```php
return [
    'enabled' => env('LS_ENABLED', true),

    'database' => [...],         // connection, prefix, model bindings
    'storage' => [...],          // driver, sampling, queue
    'cache' => [...],            // backend, TTLs
    'dashboard' => [...],        // route prefix, middleware, gate, user_name_field
    'bypass' => [...],           // key, IPs, command-allow

    'acl' => [
        'evaluation_cache_ttl' => 60,
        'geo' => ['enabled' => false],
        'asn' => ['enabled' => false],
        'hostname' => ['enabled' => false],
    ],

    'waf' => [
        'rules' => [
            'always_on_patterns' => [...],
            'sync_cron' => '0 3 * * *',
        ],
        'scoring' => [...],
    ],

    'audit_log' => [
        'enabled' => true,
        'kinds' => [...],
        'retention_days' => [...],
        'hmac_secret' => env('LS_AUDIT_HMAC_SECRET'),
        'sinks' => [...],
    ],

    'scanner' => [
        'targets' => [...],
        'backends' => [...],
        'quarantine' => [...],
        'signatures' => ['pin' => null, 'sync_cron' => '0 4 * * *'],
        'clamav' => [...],
        'watcher' => [...],
    ],

    'notifications' => [
        'routing' => [...],
        'channels' => [...],
        'throttle' => [...],
        'reports' => [...],
    ],

    'middleware' => [/* per-middleware config — preserved structure from current */],

    'headers' => [...],          // §20.1
    'honeypot' => [...],         // §20.2
    'redaction' => [...],        // §20.3
    'disabled_routes' => [...],  // §20.4
    'rate_limiters' => [...],    // §20.5
    'trusted_proxies' => [...],  // §20.11

    'responses' => [...],        // blocked response config (existing)
];
```

---

## 25. Migration Story (0.x → 1.0)

### Strategy

**Full breaking changes** — per Ozan, no production users to preserve.

- Old tables (`security_logs`, `security_ips`, `security_auth_logs`) get **dropped** in fresh migrations. No data migration logic.
- New schema with `ls_` prefix is the only schema.
- Old config keys are not honored — users re-publish `config/security.php`.

### Upgrade command

```bash
php artisan security:upgrade
```

Wraps:
1. `vendor:publish` for new config (renamed from `security.php` to `security.php` — same name, new content)
2. `migrate` for new tables
3. Seeds for lookup tables + builtin rules + builtin signatures
4. Sets `LS_AUDIT_HMAC_SECRET` if missing (generates one and writes to `.env`)
5. Shows post-install instructions

### `UPGRADING.md`

Documented step-by-step for users moving from 0.x. Explicit list of what changed.

---

## 26. Cross-Cutting Concerns

### Caching strategy

| Cache key | TTL | Invalidation |
|---|---|---|
| `ls.acl.live` | forever | On any `ls_acl` write (observer) |
| `ls.acl.decision.{ip}` | 60s (configurable) | TTL only |
| `ls.waf.rules.{category}` | forever | On any rule write |
| `ls.lookups.{table}` | forever | Manual via dashboard or rare migration |
| `ls.scoring.{ip}` | 3600s (window) | TTL + sliding window |
| `ls.throttle.{key}` | per-event window | TTL only |
| `ls.geo.{ip}` | request lifetime | n/a |
| `ls.clamav.status` | 60s | TTL only |
| `ls.signatures.sync_last` | forever | On sync command |
| `ls.trusted_proxies` | 24h | TTL only |

### Queue strategy

- Notifications: queued (per existing channel queue config)
- Storage writes (when driver=queue or redis_batch): queued
- Scanner runs: queued (single job orchestrates multiple subjobs per target)
- Signature sync / feed sync / freshclam: queued
- File watcher: not queued (long-running process)

Configurable queue connection + name.

### Performance posture

- Hot path (middleware) hits Redis cache, never DB except for fresh ACL lookup or rare cache miss
- DB writes happen async via queue/batch in default config
- Sampling reduces live_traffic write volume by 10x default
- No N+1 queries in middleware path — ACL evaluation is one cached `GET`

---

## 27. Compatibility Matrix

| Concern | Supported | Notes |
|---|---|---|
| PHP | 8.0 – 8.4 | Some features may require 8.1+ (gated via runtime check) |
| Laravel | 9, 10, 11, 12 | Compatible across all; feature gates if needed |
| DB | MySQL 5.7+, PostgreSQL 12+, SQLite 3.30+ | Tested in CI on all three |
| Cache | Redis (recommended), File | Memcached works but untested |
| Queue | Any Laravel-supported driver | Database driver works for low-volume |

### Composer suggests (optional deps)

```json
{
  "suggest": {
    "geoip2/geoip2": "Required for ASN and country-level ACL kinds (^3.0)",
    "xenolope/quahog": "Required for ClamAV scanner backend (^3.0)",
    "spatie/file-system-watcher": "Required for security:watch live file monitoring (^1.0)",
    "predis/predis": "Strongly recommended for cache+queue backend (^2.0)",
    "spatie/laravel-medialibrary": "Auto-integration for media library upload scanning"
  }
}
```

---

## 28. Testing Strategy

### Framework

- **Orchestra Testbench** (already in use) for package testing
- PHPUnit 10+ (Laravel 10+) / PHPUnit 9 (Laravel 9 compat)

### Coverage targets

| Module | Goal | Why |
|---|---|---|
| ACL evaluation | 95%+ | Security-critical hot path |
| HMAC chain | 95%+ | Tamper evidence integrity |
| Scanner core | 80%+ | Many edge cases (filesystem) |
| WAF rule evaluation | 90%+ | Hot path |
| Middlewares | 80%+ | User-facing behavior |
| Notifications | 70%+ | Mostly format-checks |
| Dashboard / controllers | 60%+ | Integration tests |
| Helpers / utility | 70%+ | |

### Test categories

- **Unit** — `Tests\Unit\` — pure logic, no Laravel boot
- **Feature** — `Tests\Feature\` — Orchestra Testbench, full app boot, HTTP requests
- **Integration** — `Tests\Integration\` — multi-component flows (scanner → finding → quarantine → audit)
- **Mobile snapshot** — automated snapshot of every dashboard page at 360px (using a headless browser tool — Pest browser plugin or similar)

### Critical test paths (TDD required)

- ACL evaluation with all kinds
- HMAC chain construction + verification
- Bypass key acceptance
- Quarantine move + restore
- Sensitive field redaction
- Signature sync upsert behavior
- Honeypot route hit → auto-block flow
- Rate limiter integration
- Multi-cadence report generation

### Test data

- Factories for all models with `HasUuid`, `HasUserstamps` traits exercised
- Seeders for lookup tables shared between migrations and tests
- Fixture files for signature sync JSON, threat feed payloads, ClamAV mock responses

### CI

- GitHub Actions matrix: PHP 8.0/8.1/8.2/8.3/8.4 × Laravel 9/10/11/12
- DB matrix: MySQL 8, PostgreSQL 15, SQLite
- Static analysis: PHPStan level 5 on new code, level 6 by 1.0 final
- Code style: pre-existing StyleCI config

---

## 29. Premium Features (in the single `ozankurt/laravel-security` package)

Premium features live in the same package as free features, gated at runtime by `Security::isPremium()` / `Security::isFeatureAvailable($feature)`. No separate package, no Satis, no Composer auth — just Packagist + a license key.

### Distribution: Packagist + runtime license key

Both free-tier and premium-tier code ships in the same `ozankurt/laravel-security` package on Packagist. Anyone can `composer require` it. **Premium features stay dormant until a valid `LS_PREMIUM_LICENSE_KEY` is present and validated by the license-check API.** No license key → free behavior, identical to a free-only install.

### Buyer flow (much simpler than two-package design)

1. Buyer pays via the Laravel Shield site → receives license key string (e.g. `ls-prem-xxxxxxxxxxxx`)
2. Buyer already has the package installed (`composer require ozankurt/laravel-security` — or already had it from when they were a free user)
3. Buyer adds `LS_PREMIUM_LICENSE_KEY=ls-prem-xxxxxxxxxxxx` to `.env`
4. Premium features activate on next request (after license check call, cached 24h)

No `auth.json`, no extra repositories in `composer.json`, no private repo SSH keys.

### License-check API contract

Hosted by Ozan at `https://api.ozankurt.com/laravel-security/license/check` (or equivalent):

```
POST /laravel-security/license/check
Content-Type: application/json

{
  "key": "ls-prem-xxxxxxxxxxxx",
  "site_url": "https://buyer-app.example.com",
  "package_version": "1.0.0",
  "php_version": "8.3.1",
  "laravel_version": "12.0.0"
}

→ 200 OK
{
  "valid": true,
  "expires_at": "2027-05-26T00:00:00Z",
  "plan": "pro",
  "features": ["realtime_feed", "premium_audit", "remote_sink", "central_integration"],
  "domain_limit": 5,
  "domains_used": 2,
  "grace_period_days": 7
}

→ 200 OK (invalid key)
{
  "valid": false,
  "reason": "expired" | "revoked" | "domain_limit_exceeded" | "unknown_key",
  "message": "Human-readable status"
}
```

### Modelled on Wordfence's `wfLicense.php`

Wordfence's actual model:
1. User buys → license key
2. Plugin sends key + site URL to `noc1.wordfence.com` API
3. API returns JSON with validity, expiry, plan, features
4. Plugin caches ~24h in DB
5. Premium features check `wfLicense::isPremium()` before activating
6. On expiry, features gracefully degrade → free behavior + UI banner

We mirror this exactly, just with our own API endpoint.

### `LicenseChecker` service (in the same single package, under `OzanKurt\Security\Premium\`)

```php
namespace OzanKurt\Security\Premium;

final class LicenseChecker
{
    public function status(): LicenseStatus;  // cached, ~24h
    public function refresh(): LicenseStatus;  // force re-check
    public function isActive(): bool;          // valid + within grace period
    public function features(): array;
    public function isFeatureAvailable(string $feature): bool;
}
```

Accessed via the `Security` facade: `Security::isPremium()`, `Security::isFeatureAvailable('realtime_feed')`.

### Caching + grace period

- License-check result cached 24h in Redis/file (key: `ls.premium.license`)
- On 24h expiry, premium package re-checks
- If API unreachable for >24h: enter 7-day grace period (configurable) — features stay active, dashboard shows "License check unreachable" warning
- After grace period expires: features deactivate, dashboard shows "License expired" banner
- Prevents an Ozan-side API outage from killing buyer sites

### Premium feature gating in code

Each premium feature checks `LicenseChecker::isFeatureAvailable($feature)` before activating. Examples:

```php
if (app(LicenseChecker::class)->isFeatureAvailable('realtime_feed')) {
    // pull threat feed every 5min instead of daily
}

if (app(LicenseChecker::class)->isFeatureAvailable('remote_sink')) {
    // enable S3 / webhook audit sinks
}
```

When unavailable, the feature falls back to the free-package behavior (daily feed sync, file sink only, etc.).

### Dashboard surface

The dashboard's "License" page is always present (premium UI code is in the package whether activated or not):
- Current license status + plan + expiry + domains used
- Without a key: "Buy a license" CTA → Laravel Shield site
- With a valid key: shows plan, expiry, "Force re-check" button → `LicenseChecker::refresh()`
- Available features list
- Last check timestamp + next scheduled check

### How the binding works (single-package, runtime branching)

1. `SecurityServiceProvider::register()` binds each contract to a factory closure
2. The closure checks `Security::isPremium()` (or `isFeatureAvailable($feature)`) AT RESOLUTION TIME — not registration time
3. Returns the premium implementation when license valid, the free implementation otherwise
4. Result is per-request — license can be revoked mid-runtime and the next request gets the free impl

```php
// Example binding pattern
$this->app->bind(\OzanKurt\Security\Contracts\ThreatFeedProvider::class, function ($app) {
    return $app->make(\OzanKurt\Security\Facades\Security::class)->isFeatureAvailable('realtime_feed')
        ? new \OzanKurt\Security\Premium\RealtimeThreatFeedProvider()
        : new \OzanKurt\Security\Services\DailyThreatFeedProvider();
});
```

Both implementations live in the same package's `src/` tree — no separate package boundary.

### Composer requirement on buyer side (free OR premium — same install)

```json
{
    "require": {
        "ozankurt/laravel-security": "^1.0"
    }
}
```

That's it. No extra repositories, no auth files. Premium features just activate when `LS_PREMIUM_LICENSE_KEY` is present and valid.

### Anti-abuse considerations

- License check API includes `site_url` — same key from many sites = visible to Ozan
- `domain_limit` enforced by API (returns `domain_limit_exceeded` past N domains)
- If a customer shares their key publicly, Ozan can revoke + reissue
- Key revocation is immediate (cached 24h max — accept this lag)
- All key abuse audit-logged at API server
- License key never logged client-side in `ls_audit_log` (sensitive — redacted via the standard redaction list)

### Honest threat model (read this before designing premium features)

The runtime license check is **soft enforcement, not hard DRM.** Any determined buyer can open `vendor/ozankurt/laravel-security/src/Premium/LicenseChecker.php` and patch `isFeatureAvailable()` to always return true. Same for every open-source-distributed premium plugin (Wordfence, Yoast, ACF Pro, etc.). Don't ship this expecting it to defeat crackers — it's not designed to.

**What the license check IS for:**
- Clean "license expired, please renew" UI signal for honest buyers
- Make key sharing between companies slightly inconvenient (`domain_limit` tracking)
- Create a billing/activation audit trail
- Provide a revocation channel for leaked keys
- Threshold: 95% of professional buyers pay rather than patch — their time is worth more than the license cost. Plus, `vendor/` resets on every `composer update`, so patching becomes maintenance overhead.

**Where the actual enforcement lives: the SERVER side.**

The real premium *value* sits on Ozan's API services, not in the buyer's `vendor/` directory:

| Premium feature | Where the value physically lives | Crack-able locally? |
|---|---|---|
| Real-time threat feed sync | `api.ozankurt.com` gates feed access by license key | No — patcher gets stale free signatures |
| Real-time IP blocklist | Same API endpoint | No |
| Premium audit log remote sink (hosted version) | Ozan's hosted ingestion endpoint | No |
| Future Central integration | Ozan's SaaS aggregator | No |
| Local-only features (advanced report templates, premium UI, etc.) | Buyer's disk | Yes — accept this |

**Design rule:** when adding any premium feature, ask "is the value local or on the server?" If local, it's patchable — that's fine for cosmetic stuff (fancy report templates, advanced dashboard pages). If the value is server-side data/services, route it through the license-gated API and the local code is just a client.

**This is exactly how Wordfence operates.** Their entire PHP source is readable on WordPress.org. Premium value is the Threat Defense Feed, gated at their API. Local checks can be patched, but the patched plugin gets stale signatures — defeating the point.

**We do NOT implement:**
- Encrypted code blobs (IonCube / SourceGuardian style) — hostile to buyers, easy to bypass
- Composer post-install validators — patcher disables the script
- Runtime file checksums — patcher modifies the checksums too

These are arms-race tactics that buy 30 seconds of cracker time and a lot of buyer friction. Not worth it.

---

## 30. Filament Adapter (`ozankurt/laravel-security-filament`)

Ships later (post-1.0). Mirrors the Bootstrap dashboard as Filament resources. Filament has different major versions (3, 4, 5) with breaking API changes — we ship the adapter as two parallel packages by version:

| Package version | Filament versions supported |
|---|---|
| `ozankurt/laravel-security-filament` **v1.x** | Filament 3 and 4 |
| `ozankurt/laravel-security-filament` **v2.x** | Filament 5+ |

Same convention as `livewire/livewire` major-version splits. Users pin the major version matching their Filament.

```json
// v1.x composer.json
{
  "require": {
    "ozankurt/laravel-security": "^1.0",
    "filament/filament": "^3.0|^4.0"
  }
}
```

```json
// v2.x composer.json
{
  "require": {
    "ozankurt/laravel-security": "^1.0",
    "filament/filament": "^5.0"
  }
}
```

Out of scope for v1.0 design; gets its own spec when started. When we start v2.x of the adapter, we'll load the `filament-v5` skill for Filament-specific patterns.

---

## 31. Open Questions / Deferrals

These don't block v1.0 but should be revisited:

1. **Package rename** — `ozankurt/laravel-security` → `ozankurt/laravel-shield`? Deferred. Revisit after v1.0 lands.
2. **Wordfence Central analogue** — Separate Laravel app, separate brainstorm. Plugin emits webhook events on the stable contract from §17 to enable it.
3. **Compliance frameworks** — Audit log retention tuning, encryption-at-rest, signature-based audit verification, framework-specific certifications. Patterns documented; specific HIPAA/PCI/GDPR/SOC2 certifications NOT built into v1. Default 365-day retention + HMAC chain + per-kind retention overrides cover ~95% of users; regulated industries extend on top.

Explicitly NOT deferred (rejected from scope):
- Multi-tenant support (stancl/tenancy etc.) — out of scope, not planned

---

## Appendix A — Composer Suggests Summary

```json
{
  "suggest": {
    "geoip2/geoip2": "^3.0 — ASN and country-level ACL kinds",
    "xenolope/quahog": "^3.0 — ClamAV scanner backend",
    "spatie/file-system-watcher": "^1.0 — live file monitoring via security:watch",
    "predis/predis": "^2.0 — Redis cache + queue (strongly recommended)",
    "spatie/laravel-medialibrary": "* — auto-integration for media library upload scanning",
    "laravel/reverb": "^1.0 — self-hosted broadcasting for live traffic real-time mode",
    "filament/filament": "^3.0|^4.0|^5.0 — install ozankurt/laravel-security-filament for Filament panel (match Filament version to the adapter package major version)"
  }
}
```

---

## Appendix B — Environment Variables (complete inventory)

```bash
# Core toggle
LS_ENABLED=true

# Database
LS_DB_CONNECTION="${DB_CONNECTION}"
LS_DB_PREFIX=ls_

# Storage strategy
LS_STORAGE_DRIVER=sync  # sync | queue | redis_batch
LS_QUEUE_CONNECTION=default
LS_QUEUE_NAME=security

# Cache
LS_CACHE_STORE=redis  # redis | file | array

# Dashboard
LS_DASHBOARD_ENABLED=true
LS_DASHBOARD_ROUTE_PREFIX=security

# Bypass mechanism (CRITICAL)
LS_BYPASS_KEY=                          # 32+ char random string
LS_BYPASS_IPS=                          # comma-separated IP/CIDR list

# Audit log
LS_AUDIT_HMAC_SECRET=                   # required for tamper evidence
LS_AUDIT_FILE_SINK_ENABLED=true
LS_AUDIT_WEBHOOK_URL=
LS_AUDIT_S3_BUCKET=
LS_AUDIT_SYSLOG_HOST=

# Scanner / ClamAV
LS_SCANNER_ENABLED=true
LS_CLAMAV_ENABLED=false
LS_CLAMAV_SOCKET=/var/run/clamav/clamd.ctl
LS_SIGNATURE_PIN=                       # pin specific signature version

# Live traffic
LS_LIVE_TRAFFIC_ENABLED=true
LS_LIVE_TRAFFIC_REALTIME_ENABLED=false       # opt-in for socket-based real-time stream
LS_LIVE_TRAFFIC_BROADCAST_DRIVER=             # null = Laravel default

# Notification channels
LS_NOTIFY_MAIL_ENABLED=false
LS_NOTIFY_SLACK_ENABLED=false
LS_NOTIFY_DISCORD_ENABLED=false
LS_NOTIFY_TELEGRAM_ENABLED=false
LS_NOTIFY_TELEGRAM_BOT_TOKEN=
LS_NOTIFY_TELEGRAM_CHAT_ID=
LS_NOTIFY_WEBHOOK_ENABLED=false
LS_NOTIFY_WEBHOOK_URL=
LS_NOTIFY_WEBHOOK_SITE_ID=              # for Central aggregator identification

# Geo / ASN (opt-in)
LS_GEO_ENABLED=false
LS_ASN_ENABLED=false
LS_MAXMIND_LICENSE_KEY=
LS_GEO_DB_PATH=storage/security/geo/GeoLite2-Country.mmdb

# Threat feeds (1.1)
LS_ABUSEIPDB_KEY=
LS_SPAMHAUS_ENABLED=true
LS_OWASP_CRS_ENABLED=true

# Premium tier (activates premium-gated features in the single ozankurt/laravel-security package)
LS_PREMIUM_LICENSE_KEY=                       # license key purchased at laravel-shield.ozankurt.com
LS_PREMIUM_LICENSE_CHECK_URL=https://laravel-shield.ozankurt.com/api/license/check  # default
LS_PREMIUM_LICENSE_CACHE_TTL=86400            # 24h
LS_PREMIUM_LICENSE_GRACE_DAYS=7               # grace period if check API unreachable
```

---

## Appendix C — Artisan Commands (complete inventory)

```bash
# Lifecycle
php artisan security:upgrade            # Run after composer update — migrate, seed, publish
php artisan security:install            # First-time setup helper

# ACL
php artisan security:acl-prune          # Sweep expired entries (auto-scheduled)
php artisan security:acl-list           # CLI list of current entries
php artisan security:acl-add <kind> <value> <action> [--reason=] [--expires=]
php artisan security:acl-remove <id>

# Bypass (recovery)
php artisan security:bypass-add <ip>
php artisan security:bypass-remove <ip>
php artisan security:bypass-list

# Scanner
php artisan security:scan [--target=] [--backend=]
php artisan security:scan-status <run-id>
php artisan security:scan-cancel <run-id>
php artisan security:signatures-sync    # Pull latest signatures (auto-scheduled daily)
php artisan security:quarantine-list
php artisan security:quarantine-restore <finding-uuid>
php artisan security:watch              # Long-running file watcher (supervisor)

# ClamAV
php artisan security:clamav-update      # Wraps freshclam
php artisan security:clamav-status

# Audit log
php artisan security:audit-verify       # Walk HMAC chain
php artisan security:audit-prune        # Remove records past retention
php artisan security:audit-rotate-secret

# Threat feeds (1.1)
php artisan security:feed-sync [--source=]

# Reports
php artisan security:report-send <cadence>  # daily-digest | 3-day | 7-day | 14-day | 30-day
php artisan security:report-test            # Render report HTML for inspection

# Diagnostics
php artisan security:diag               # Print sysinfo to stdout
php artisan security:audit-env          # Check .env for security issues

# Cache
php artisan security:cache-clear [--key=]
php artisan security:cache-warm

# Composer vuln (1.2)
php artisan security:composer-audit
```

---

## Appendix D — High-Level Implementation Order

This is the rough order of work within each beta — phase-by-phase specs will detail further.

### `1.0.0-beta.1` — Foundation

1. New migrations: all `ls_*` tables + lookup tables
2. Models with `HasUuid` + `HasUserstamps` traits
3. Lookup table seeders (kinds, actions, levels, categories, statuses, targets, backends)
4. Service provider refactor: new bindings, new middleware aliases
5. Existing middlewares ported to read rules from DB (vs config)
6. ACL evaluation service + middleware + cache observer
7. Audit log core service with HMAC chain
8. Bypass mechanism: key check, config IPs, recovery commands
9. Severity routing in notifications
10. Cache dashboard page
11. Tests: ACL evaluation, HMAC chain, bypass

### `1.0.0-beta.2` — Audit expansion + dashboard polish

1. File drift / config drift / composer drift scanner targets
2. `HasAuditLog` trait for user models
3. Audit log dashboard page (filters by kind/severity/actor/correlation_id)
4. Drop Yajra: rewrite each datatable as resource controller + datatables.net JSON
5. Tests: audit categories, datatable JSON shape

### `1.0.0-beta.3` — Scanner + ClamAV + watcher

1. Native scanner engine with signature evaluation
2. ClamAV backend via `xenolope/quahog`
3. Signature sync command + GitHub release format
4. Composer audit backend
5. Quarantine service (move/restore)
6. Scanner dashboard pages (runs, findings, signatures, ClamAV status)
7. `security:watch` long-running command
8. Tests: scanner each backend, quarantine, watcher fallback

### `1.0.0-beta.4` — Live traffic + uploads

1. Live traffic capture middleware (with sampling)
2. Live traffic dashboard with filters
3. `firewall.av_uploads` middleware
4. Spatie Media Library integration
5. On-demand scan API
6. WAF rules management UI
7. Tests: sampling, upload rejection, media library hook

### `1.0.0-beta.5` — Beyond-WF extras

1. `firewall.headers` middleware + CSP nonce
2. Honeypot routes + auto-block
3. Sensitive-field redaction generalized
4. `firewall.disabled_routes`
5. `firewall.https`
6. Suspicious activity scoring engine
7. Cookie security audit (in env_audit target)
8. Trusted proxies auto-detect
9. Pre-configured rate limiters (config + service provider registration)
10. Tests: each extra middleware, scoring threshold flow

### `1.0.0` — Polish + docs

1. README rewrite
2. Per-feature docs in `docs/`
3. `UPGRADING.md`
4. Migration guide for 0.x users
5. Multi-cadence reports + Wordfence-style email layout
6. Final test sweep + coverage report

### `1.1.0` — Threat feeds

1. `ThreatFeedProvider` contract
2. AbuseIPDB, Spamhaus, MaxMind, OWASP CRS providers
3. Feed sync command + scheduler
4. Dashboard page for feed status

### `1.2.0` — Composer audit + diagnostics

1. Composer audit backend integration
2. Diagnostics page (sysinfo + OWASP score)
3. Import/export config + ACL

### `2.0.0` — Premium tier activation

1. Activate premium binding factories in `SecurityServiceProvider` (premium implementation classes already live in the single package's `src/Premium/`)
2. License-check API live at `laravel-shield.ozankurt.com/api/license/check`
3. Customer purchase / account flow on the marketing site
4. Premium dashboard pages activated when license valid

(Implementation note: no separate package, no Satis. The "2.0.0 launch" is mostly about the *service side* being live — the license API, customer accounts, billing — not the plugin code itself, which already shipped premium-code-gated since v1.0.)

---

## End of Spec

After Ozan reviews, next steps:
1. Address any feedback inline (this doc evolves until locked)
2. Create phase-by-phase implementation specs under `docs/superpowers/specs/phases/`
3. Invoke `superpowers:writing-plans` to produce the executable plan for `1.0.0-beta.1`
4. Begin implementation

**Status: DRAFT — awaiting Ozan's review.**
