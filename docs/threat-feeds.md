# Threat feeds (1.1+)

Pull-based providers that import IP blocklists + WAF rules + GeoIP databases from public feeds. Runs on a daily schedule by default; trigger ad-hoc via `php artisan shield:feed-sync`.

## Providers shipped

| Name | Source | Imports into | Requires |
|---|---|---|---|
| `spamhaus` | Spamhaus DROP + EDROP lists (free, public) | `ls_acl` as CIDR ranges, blacklist action | none |
| `abuseipdb` | AbuseIPDB blacklist API (free tier 1k requests/day) | `ls_acl` as IPs, block action, 24h expiry | `LS_ABUSEIPDB_KEY` |
| `maxmind_geolite2` | MaxMind GeoLite2 Country + ASN DBs | `storage/shield/geo/*.mmdb` — activates country/ASN ACL matchers | `LS_MAXMIND_LICENSE_KEY` + `composer require geoip2/geoip2` |
| `owasp_crs` | OWASP ModSecurity CRS rule subset (via our curated JSON mirror) | `ls_waf_rules` with source=owasp_crs | none |
| `emerging_threats` | Proofpoint Emerging Threats IP reputation (**Premium**) | `ls_acl` as IPs, block action, source=emerging_threats | premium license + ET token |
| `crowdstrike` | CrowdStrike Falcon Intelligence IP IOCs (**Premium**) | `ls_acl` as IPs, block action, source=crowdstrike | premium license + Falcon OAuth creds |

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

## Commercial threat-intel feeds (`emerging_threats`, `crowdstrike`)

Premium buyers who already license commercial threat intel can plug it in. Both feeds
import IP reputation into `ls_acl` as block-tier rows and are gated by a valid premium
license. Important: the license gates the *integration*; the *data* still requires the
operator's own vendor subscription.

```env
# Proofpoint Emerging Threats (ET Pro / Intelligence)
LS_ET_ENABLED=true
LS_ET_TOKEN=your-et-token
LS_ET_MIN_SCORE=70          # 0-127 reputation threshold

# CrowdStrike Falcon Intelligence
LS_CROWDSTRIKE_ENABLED=true
LS_CROWDSTRIKE_CLIENT_ID=your-client-id
LS_CROWDSTRIKE_CLIENT_SECRET=your-client-secret
LS_CROWDSTRIKE_MIN_CONFIDENCE=high   # high | medium | low
```

CrowdStrike authenticates via OAuth2 client credentials; the token is cached until just
before it expires so repeat syncs do not re-authenticate. Both feeds enforce a
`max_import` cap and log (never silently drop) when the cap truncates a feed. Vendor
tokens/secrets are treated as secrets and are never logged.

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
