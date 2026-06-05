# Threat feeds (1.1+)

Pull-based providers that import IP blocklists + WAF rules + GeoIP databases from public feeds. Runs on a daily schedule by default; trigger ad-hoc via `php artisan shield:feed-sync`.

## Providers shipped

| Name | Source | Imports into | Requires |
|---|---|---|---|
| `spamhaus` | Spamhaus DROP + EDROP lists (free, public) | `ls_acl` as CIDR ranges, blacklist action | none |
| `abuseipdb` | AbuseIPDB blacklist API (free tier 1k requests/day) | `ls_acl` as IPs, block action, 24h expiry | `LS_ABUSEIPDB_KEY` |
| `maxmind_geolite2` | MaxMind GeoLite2 Country + ASN DBs | `storage/shield/geo/*.mmdb` — activates country/ASN ACL matchers | `LS_MAXMIND_LICENSE_KEY` + `composer require geoip2/geoip2` |
| `maxmind_geoip2_premium` | MaxMind **paid** GeoIP2 Country/City/ISP DBs (**Premium**) | `storage/shield/geo/premium/*.mmdb` — preferred over GeoLite2, adds city/region precision | valid premium license + paid MaxMind account |
| `owasp_crs` | OWASP ModSecurity CRS rule subset (via our curated JSON mirror) | `ls_waf_rules` with source=owasp_crs | none |
| `shield_realtime` | Laravel Shield Central realtime delta feed (**Premium**) | `ls_waf_rules` + `ls_acl` with source=shield_realtime | valid premium license |

## Enable a provider

```env
# AbuseIPDB
LS_ABUSEIPDB_ENABLED=true
LS_ABUSEIPDB_KEY=your-abuseipdb-api-key

# MaxMind GeoLite2 (signup free at maxmind.com)
LS_MAXMIND_ENABLED=true
LS_MAXMIND_LICENSE_KEY=your-maxmind-license-key
```

Spamhaus + OWASP CRS are on by default — no API key required.

## Premium realtime feed (`shield_realtime`)

Free providers sync once daily. The premium `shield_realtime` provider pulls WAF-rule
and ACL deltas from the Laravel Shield Central app every few minutes, so a licensed
site gets new firewall rules and malicious IPs within minutes instead of the next day.
This is the Laravel Shield equivalent of Wordfence Premium's real-time rules + blocklist.

```env
# On by default; pulls every 5 minutes. No-op without a premium license.
LS_REALTIME_FEED_ENABLED=true
LS_REALTIME_FEED_INTERVAL=5
# Override only for staging/self-hosted Central:
# LS_PREMIUM_FEED_PULL_URL=https://laravel-shield.ozankurt.com/api/feeds/pull
```

`FeedRunner` skips `shield_realtime` unless a valid premium license is active
(`Shield::isFeatureAvailable('premium_threat_feeds')`), and Central gates the pull
endpoint on the license key server-side, so the feature is inert on free installs even
if the local license check is patched. Deltas upsert by `source_ref` (WAF) and
`(kind, value)` (ACL), bump on `version`, and revocations soft-disable rather than
hard-delete. The pull cursor is cached at `shield.threat_feed.shield_realtime.cursor`.

## Premium GeoIP2 (`maxmind_geoip2_premium`)

Country and ASN blocking are **free** (GeoLite2). Premium adds the paid MaxMind GeoIP2
databases for higher accuracy and city/region precision. `GeoDatabaseResolver` makes the
geo ACL matchers prefer `storage/shield/geo/premium/*.mmdb` over the free GeoLite2 DBs
automatically once present, so country/region/city ACL rules use the best available data.

```env
LS_MAXMIND_PREMIUM_ENABLED=true
LS_MAXMIND_PREMIUM_ACCOUNT_ID=your-account-id
LS_MAXMIND_PREMIUM_LICENSE_KEY=your-paid-license-key
```

Requires `composer require geoip2/geoip2` and a paid MaxMind subscription. `FeedRunner`
skips this provider without a valid premium license, so it is inert on free installs.

## Sync schedule

```bash
# Default: 3am daily (configurable via shield.threat_feed.sync_cron)
php artisan shield:feed-sync

# Run a specific provider only
php artisan shield:feed-sync --source=spamhaus

# All providers
php artisan shield:feed-sync
```

The command audit-logs each sync run with `threat_feed.sync_started` / `threat_feed.sync_completed` / `threat_feed.sync_failed`. View on `/shield/threat-feed` for the latest run status per provider.

## What each provider does

### Spamhaus DROP / EDROP

Pulls two text lists from Spamhaus:
- `https://www.spamhaus.org/drop/drop.txt` — confirmed-bad CIDR ranges
- `https://www.spamhaus.org/drop/edrop.txt` — confirmed-bad CIDR ranges, extended

Each CIDR becomes a row in `ls_acl` with:
- `kind = cidr`
- `action = blacklist`
- `source = spamhaus`
- `reason = "Spamhaus drop"` (or `edrop`)
- `meta.list` = `drop` or `edrop`

Permanent — no expiry. Re-running doesn't duplicate rows (upsert on `(kind, value)`).

### AbuseIPDB

Pulls `/api/v2/blacklist` with `confidenceMinimum=90` (configurable).

Each IP becomes a row in `ls_acl` with:
- `kind = ip`
- `action = block`
- `source = abuseipdb`
- `reason = "AbuseIPDB confidence 95"` (varies)
- `expires_at = now()+1d` (rolling — refreshed each sync)
- `meta.confidence`, `meta.last_reported_at`

Rate limit: free tier is 5 requests/day for blacklist endpoint. Daily cron stays well under.

### MaxMind GeoLite2

Downloads `GeoLite2-Country.mmdb` + `GeoLite2-ASN.mmdb` from MaxMind's CDN to `storage/shield/geo/`. Does NOT write any ACL/WAF rows.

Once the MMDBs exist, the `CountryMatcher` + `AsnMatcher` activate automatically. ACL rows of kind `country` / `region` / `city` / `asn` start matching:

```php
// Block all of Russia
Acl::create([
    'kind_id'   => $resolver->id(AclKind::class, 'country'),
    'action_id' => $resolver->id(AclAction::class, 'blacklist'),
    'value'     => 'RU',
    'source'    => 'manual',
]);
```

The matchers cache resolved country/ASN per IP for 24h in `shield.geo.*` cache keys.

### OWASP CRS

Pulls a curated subset of OWASP ModSecurity CRS rules (currently ~200 patterns; mirrored at `raw.githubusercontent.com/OzanKurt/laravel-shield-signatures/main/owasp-crs/rules.json`).

Each rule lands in `ls_waf_rules` with `source = owasp_crs`. Versioned via `version` column — bumps when the upstream pattern changes.

When the mirror is unreachable, an embedded fallback of ~5 critical CRS rules ships in `OwaspCrsProvider`.

## Premium tier (paid)

The free providers are pull-based on a daily schedule. Premium activates real-time push:

| Provider | Free tier | Premium |
|---|---|---|
| Spamhaus | daily | streaming (push) |
| AbuseIPDB | daily, 1k requests/day | unlimited, real-time blocklist |
| Custom Wordfence-equivalent feed | unavailable | ✓ via Shield Central API |

See [premium.md](premium.md) for activation.

## Custom providers

See [architecture.md#adding-a-custom-threat-feed-provider](architecture.md#adding-a-custom-threat-feed-provider).

## Dashboard

`/shield/threat-feed` shows every configured provider with:
- Whether it's available (env vars set + dependencies installed)
- Last run timestamp
- Last run status (`sync_completed` / `sync_failed`)

The page reads the most-recent audit log entry per provider — no separate state table.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `feed-sync` exits successfully but no rows imported | Provider rate-limited or returned empty list (Spamhaus DROP can be tiny) | Check `last_run_status` in `/shield/threat-feed` |
| `MaxMind GeoLite2` provider unavailable | `geoip2/geoip2` not installed OR `LS_MAXMIND_LICENSE_KEY` blank | `composer require geoip2/geoip2`; sign up free at maxmind.com |
| Country ACL rule doesn't match | MaxMind MMDB not downloaded yet | Run `php artisan shield:feed-sync --source=maxmind_geolite2`; check `storage/shield/geo/` |
| AbuseIPDB returns 429 | Daily quota exceeded | Reduce sync frequency in `shield.threat_feed.sync_cron` or upgrade your AbuseIPDB plan |
