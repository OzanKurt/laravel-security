# Spec 001: Real-time threat-feed push (`shield_realtime` provider)

- Status: Draft
- Target version: v2.2.0
- Wordfence parity: Real-time firewall rules + Real-time IP blocklist (Wordfence
  free waits 30 days, Premium gets them immediately)
- Premium feature flag: `premium_threat_feeds`
- Provider slug: `shield_realtime` (already listed in `FeedRunner::PREMIUM_ONLY_PROVIDERS`)

## 1. Problem / current state

`FeedRunner` gates a provider named `shield_realtime` behind
`Shield::isFeatureAvailable('premium_threat_feeds')`
([src/Services/ThreatFeed/FeedRunner.php:18](../../src/Services/ThreatFeed/FeedRunner.php)),
but **no provider class with that slug exists**. The registered providers
([config/shield.php](../../config/shield.php) `threat_feed.providers`) are only the
free ones: Spamhaus, AbuseIPDB, OWASP CRS, MaxMind GeoLite2.

Free providers run once daily (`shield.threat_feed.sync_cron` = `0 3 * * *`). There
is no near-real-time path. So today free and premium installs receive WAF rules and
blocklist entries on the same daily cadence: the central Wordfence-style differentiator
is missing.

## 2. Goal

Implement a premium provider that pulls fresh WAF rules and ACL blocklist deltas from
the Laravel Shield Central API on a short interval (default every 5 minutes), so a
licensed site receives new rules and malicious IPs within minutes of Ozan publishing
them, while unlicensed sites keep the daily free cadence.

## 3. Non-goals

- Server push / websockets. We poll Central on a short interval. "Real-time" here
  means minutes, matching how Wordfence Premium actually behaves.
- Building the publishing side on Central (that lives in the separate
  `laravel-shield-app` project). This spec defines the client contract only.
- Changing free provider behaviour.

## 4. Design

### 4.1 New provider class

`src/Services/ThreatFeed/Providers/ShieldRealtimeProvider.php` implementing
`ThreatFeedProvider`:

- `name(): 'shield_realtime'`
- `label(): 'Laravel Shield Realtime (Premium)'`
- `isAvailable()`: true when a license key is configured AND
  `shield.threat_feed.shield_realtime.enabled` is true. (The premium license check
  itself is enforced by `FeedRunner::isProviderUnlocked()`, so `isAvailable()` only
  checks local config, mirroring the other providers.)
- `sync()`: pull deltas since the last cursor, upsert into `ls_waf_rules` and
  `ls_acl`, persist the new cursor, return a `SyncResult`.

### 4.2 Pull protocol

Add a pull method to `CentralClient`
([src/Services/Premium/CentralClient.php](../../src/Services/Premium/CentralClient.php)),
reusing its existing signed-request plumbing:

```
GET {shield.premium.feed_pull_url}?since=<cursor>
Authorization: Bearer <license_key>
X-Shield-Site: <app.url>
```

Response (JSON):

```jsonc
{
  "cursor": "2026-06-05T10:32:00Z#1837",   // opaque, echoed back next pull
  "waf_rules": [ { ref, name, category, kind, target, pattern, action, severity, score, version, is_enabled } ],
  "acl":       [ { ref, tier, kind, value, note, version, is_enabled } ],
  "revoked":   { "waf_rules": ["ref1"], "acl": ["ref9"] }   // soft-disable on the client
}
```

- `waf_rules` upsert by `(source='shield_realtime', source_ref=ref)`, bumping on
  `version`. Identical pattern to `OwaspCrsProvider::sync()`.
- `acl` upsert by `(source='shield_realtime', source_ref=ref)` into `ls_acl`, same
  pattern Spamhaus / AbuseIPDB providers use for blocklist rows.
- `revoked` refs set `is_enabled = false` (we soft-disable, never hard-delete feed
  rows, so an operator can audit what changed).
- Cursor stored in cache key `shield.threat_feed.shield_realtime.cursor`. First run
  with no cursor pulls a bounded backfill (server caps page size).

### 4.3 Scheduling

`FeedRunner` already no-ops premium providers when unlicensed (falls closed in
`isProviderUnlocked()`), so the realtime pull can be scheduled unconditionally and
stays inert on free installs.

In the package scheduler registration (where `shield.threat_feed.sync_cron` is wired),
add a second entry:

- Free daily sync: unchanged (`0 3 * * *`, all providers).
- Realtime pull: `php artisan shield:feed-sync --source=shield_realtime`
  every `shield.threat_feed.shield_realtime.interval_minutes` (default 5),
  `withoutOverlapping()`.

`FeedSyncCommand` already supports `--source=` filtering. Confirm it filters by
`provider->name()`; if not, add that filter (small change in
[src/Console/Commands/FeedSyncCommand.php](../../src/Console/Commands/FeedSyncCommand.php)).

### 4.4 Free fallback

When the license is absent / expired / revoked, `isProviderUnlocked()` returns false,
the provider is skipped, and the site keeps receiving the free daily feeds. No error,
no banner beyond the existing premium-inactive dashboard notice.

## 5. Files to add / change

| File | Change |
|------|--------|
| `src/Services/ThreatFeed/Providers/ShieldRealtimeProvider.php` | NEW provider class |
| `src/Services/Premium/CentralClient.php` | add `pullFeed(?string $cursor): array` (signed GET helper) |
| `config/shield.php` | add `threat_feed.shield_realtime` block; add `premium.feed_pull_url` |
| `src/Providers/...` scheduler registration | add the 5-minute realtime schedule entry |
| `src/Console/Commands/FeedSyncCommand.php` | ensure `--source` filters by provider name |
| `FeedRunner::PREMIUM_ONLY_PROVIDERS` | already contains `shield_realtime`, no change |

## 6. Config & env

```php
// config/shield.php  -> threat_feed
'shield_realtime' => [
    'enabled'          => env('LS_REALTIME_FEED_ENABLED', true),
    'interval_minutes' => (int) env('LS_REALTIME_FEED_INTERVAL', 5),
],

// config/shield.php  -> premium
'feed_pull_url' => env(
    'LS_PREMIUM_FEED_PULL_URL',
    'https://laravel-shield.ozankurt.com/api/feeds/pull'
),
```

Env additions documented in `docs/threat-feeds.md` and `.env` example:
`LS_REALTIME_FEED_ENABLED`, `LS_REALTIME_FEED_INTERVAL`, `LS_PREMIUM_FEED_PULL_URL`.

## 7. Data / schema impact

None. Reuses `ls_waf_rules` and `ls_acl` with a new `source` value `shield_realtime`.
No migration required.

## 8. Premium gating & free fallback

- Gate: `FeedRunner::isProviderUnlocked()` -> `isFeatureAvailable('premium_threat_feeds')`.
- Fall-closed: any throw in the gate skips the provider (already implemented).
- Server-side moat: Central returns 402/403 for an invalid key, so even a patched
  `isFeatureAvailable()` cannot pull premium feeds without a valid key. This matches
  the documented soft-enforcement model in [docs/premium.md](../premium.md).

## 9. Acceptance criteria

1. With a valid premium license, `shield:feed-sync --source=shield_realtime` pulls
   deltas, upserts WAF + ACL rows tagged `source=shield_realtime`, persists a cursor,
   and a second run sends that cursor as `since`.
2. `revoked` refs become `is_enabled=false` locally.
3. Without a license, the provider is skipped and the run reports it as gated, not
   failed. The daily free sync still imports free providers.
4. A Central outage during pull yields `SyncResult::error` (logged, audit
   `threat_feed.sync_failed`), never an uncaught exception.
5. The license key is never written to logs or the `ls_audit_log` meta.

## 10. Test plan

- `ShieldRealtimeProviderTest`: `Http::fake()` a feed payload, assert WAF/ACL upserts,
  cursor persistence, second-run `since` param, revoked soft-disable.
- `FeedRunnerTest`: provider skipped when `isFeatureAvailable` is false; included when true.
- `CentralClientTest`: `pullFeed()` sends bearer + site headers, parses cursor, swallows
  connection failure into a structured result.
- `FeedSyncCommandTest`: `--source=shield_realtime` runs only that provider.

## 11. Rollout notes

- Ship `enabled=true` by default; inert on free installs because the gate falls closed.
- Operators self-hosting without Central can set `LS_REALTIME_FEED_ENABLED=false`.
- Document the 5-minute cadence and that it is a no-op without a license.
