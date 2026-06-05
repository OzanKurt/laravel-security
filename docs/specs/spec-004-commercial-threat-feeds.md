# Spec 004: Commercial threat-intel feeds (`emerging_threats`, `crowdstrike`)

- Status: Draft
- Target version: v2.2.0
- Wordfence parity: extends "real-time IP blocklist". Wordfence ships its own
  proprietary blocklist (40k+ IPs). We match that with our `shield_realtime` feed
  (Spec 001) and exceed it by letting premium customers plug in commercial threat-intel
  feeds they may already pay for (Proofpoint Emerging Threats, CrowdStrike Falcon
  Intelligence).
- Premium feature flag: `premium_threat_feeds`
- Provider slugs: `emerging_threats`, `crowdstrike` (both already in
  `FeedRunner::PREMIUM_ONLY_PROVIDERS`)

## 1. Problem / current state

Both slugs are gated premium in
[src/Services/ThreatFeed/FeedRunner.php:18](../../src/Services/ThreatFeed/FeedRunner.php)
but neither provider class exists. Free installs get Spamhaus DROP/EDROP + AbuseIPDB
(free tier). There is no path to ingest the higher-quality commercial feeds that
security-conscious teams already license.

## 2. Goal

Implement two premium `ThreatFeedProvider`s that pull IP / network indicators from
commercial APIs and import them into `ls_acl` as block-tier rows, on the standard feed
schedule, gated by premium license.

## 3. Non-goals

- Reselling commercial data. The operator supplies their own ET Pro / CrowdStrike API
  key; we only consume it. (Emerging Threats also has an open ruleset; the Pro IP
  reputation feed is the paid one we target for premium.)
- WAF rule import from these vendors in v1 of this spec. Start with IP/network
  reputation -> `ls_acl`. ET Suricata rule translation is a later, separate effort.

## 4. Design

Both providers follow the established provider pattern (see `SpamhausProvider` /
`AbuseIpDbProvider` for the "pull list -> upsert ls_acl block rows" shape). Each:

- implements `ThreatFeedProvider`
- `isAvailable()`: vendor enabled in config AND API key present
- `sync()`: fetch indicators, upsert into `ls_acl` (tier=block) with
  `source=<slug>`, `source_ref=<indicator id or ip>`, bump on `version`, soft-disable
  on removal, return `SyncResult`.

### 4.1 `EmergingThreatsProvider`

- `name(): 'emerging_threats'`, `label(): 'Proofpoint Emerging Threats (Premium)'`
- Source: ET Pro reputation feed (CSV/JSON of IPs + category + score) fetched with the
  operator's ET OINKCODE / API token.
- Map ET category + score to ACL note + (optional) score; import IPs/CIDRs as block-tier
  ACL rows. Respect a configurable `min_score` to avoid importing low-confidence noise.

### 4.2 `CrowdStrikeProvider`

- `name(): 'crowdstrike'`, `label(): 'CrowdStrike Falcon Intelligence (Premium)'`
- Auth: OAuth2 client-credentials against the Falcon API (`client_id` + `client_secret`
  -> bearer token, cached until expiry).
- Pull IOC indicators of type `ip_address` / network, filter by `min_confidence`,
  import as block-tier ACL rows. Page through results; respect rate limits with
  bounded ret//backoff.

### 4.3 Shared concerns

- Both honour a per-provider `max_import` cap to protect `ls_acl` size, and `log()` when
  the cap truncates a feed (no silent truncation).
- Both write the same `threat_feed.sync_*` audit events via `FeedRunner` (automatic).
- Vendor keys/secrets are secrets: redacted, never logged, never in audit meta.

## 5. Files to add / change

| File | Change |
|------|--------|
| `src/Services/ThreatFeed/Providers/EmergingThreatsProvider.php` | NEW provider |
| `src/Services/ThreatFeed/Providers/CrowdStrikeProvider.php` | NEW provider |
| `config/shield.php` | add `threat_feed.emerging_threats` + `threat_feed.crowdstrike` blocks; register both classes in `threat_feed.providers` |
| `FeedRunner::PREMIUM_ONLY_PROVIDERS` | already contains both slugs, no change |
| `docs/threat-feeds.md` | document setup, keys, categories, min-score/confidence |

## 6. Config & env

```php
// config/shield.php -> threat_feed
'emerging_threats' => [
    'enabled'   => env('LS_ET_ENABLED', false),
    'token'     => env('LS_ET_TOKEN'),           // OINKCODE / Pro token
    'min_score' => (int) env('LS_ET_MIN_SCORE', 70),
    'max_import'=> (int) env('LS_ET_MAX_IMPORT', 50000),
],
'crowdstrike' => [
    'enabled'        => env('LS_CROWDSTRIKE_ENABLED', false),
    'client_id'      => env('LS_CROWDSTRIKE_CLIENT_ID'),
    'client_secret'  => env('LS_CROWDSTRIKE_CLIENT_SECRET'),
    'base_url'       => env('LS_CROWDSTRIKE_BASE_URL', 'https://api.crowdstrike.com'),
    'min_confidence' => env('LS_CROWDSTRIKE_MIN_CONFIDENCE', 'high'),
    'max_import'     => (int) env('LS_CROWDSTRIKE_MAX_IMPORT', 50000),
],
```

Register both provider classes in `threat_feed.providers` alongside the existing four.

## 7. Data / schema impact

None. Reuses `ls_acl` with new `source` values `emerging_threats` and `crowdstrike`.
No migration.

## 8. Premium gating & free fallback

- Gate: `FeedRunner::isProviderUnlocked()` -> `isFeatureAvailable('premium_threat_feeds')`.
- Fall-closed on any gate error (already implemented).
- Free installs are unaffected: providers default `enabled=false` and are skipped
  without a license regardless.
- Note: these feeds require the operator's own paid vendor subscription. Our license
  gates the *integration*; the vendor gates the *data*. Document this clearly so buyers
  understand they still need an ET/CrowdStrike account.

## 9. Acceptance criteria

1. With premium license + vendor key, each provider imports block-tier `ls_acl` rows
   tagged with its source, respecting `min_score` / `min_confidence` and `max_import`.
2. Removed indicators are soft-disabled on the next sync.
3. `max_import` truncation emits a `log()`/audit note (no silent cap).
4. Without a license, both providers are skipped (gated, not failed).
5. Vendor auth failure or rate-limit yields `SyncResult::error`, logged, no exception.
6. CrowdStrike OAuth token is cached and reused until expiry.

## 10. Test plan

- `EmergingThreatsProviderTest`: `Http::fake()` a reputation payload, assert ACL upserts,
  `min_score` filtering, `max_import` cap + log, soft-disable on removal.
- `CrowdStrikeProviderTest`: fake OAuth token + IOC pages, assert token caching,
  pagination, `min_confidence` filter, ACL upserts.
- `FeedRunnerTest`: both providers gated off without license, on with license.
- Secret-redaction test: tokens never appear in logs / audit meta.

## 11. Rollout notes

- Default both `enabled=false`. They are opt-in integrations for buyers who already hold
  the vendor subscriptions.
- Ship ET first (simpler list pull); CrowdStrike second (OAuth + pagination).
- Document in the premium feature matrix that commercial feeds are "bring your own
  vendor key", gated by Shield premium.
