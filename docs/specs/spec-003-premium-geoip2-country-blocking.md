# Spec 003: Premium GeoIP2 + country/city precision (`maxmind_geoip2_premium`)

- Status: Draft
- Target version: v2.2.0
- Wordfence parity: Country Blocking (a hard Wordfence Premium gate). Our position is
  stronger: we keep country/ASN blocking FREE and sell higher-precision (city/region,
  paid-DB accuracy) as the premium tier.
- Premium feature flag: `premium_geoip2`
- Provider slug: `maxmind_geoip2_premium` (already in `FeedRunner::PREMIUM_ONLY_PROVIDERS`)

## 1. Problem / current state

Country and ASN blocking already work for free: `MaxMindGeoLite2Provider`
([src/Services/ThreatFeed/Providers/MaxMindGeoLite2Provider.php](../../src/Services/ThreatFeed/Providers/MaxMindGeoLite2Provider.php))
downloads the free GeoLite2 Country + ASN databases, and the `CountryMatcher` /
`AsnMatcher` activate once the MMDBs exist. ACL rows of kind `country` / `region` /
`city` / `asn` are supported by the evaluator.

But `maxmind_geoip2_premium` is listed as a premium-only provider with no class, so:

- `city` and `region` matchers have no high-accuracy data source (GeoLite2 City is
  coarse / not wired; the precise GeoIP2-City edition is paid).
- There is no premium upsell tied to geo at all, even though geo is exactly what
  Wordfence charges for.

## 2. Goal

Implement a premium provider that downloads the paid MaxMind GeoIP2 databases
(GeoIP2-City, GeoIP2-Country, optionally GeoIP2-ISP/ASN) using a paid MaxMind account,
and make the geo matchers prefer the premium MMDBs when present. Country/ASN stays free
via GeoLite2; premium buys city/region precision and better accuracy.

## 3. Non-goals

- Removing or degrading the free GeoLite2 path. Free country/ASN blocking stays.
- Writing ACL rows. Like the GeoLite2 provider, this provider only manages MMDB files;
  matchers consume them.
- Shipping MaxMind databases in the package (license forbids redistribution; we
  download with the operator's key, same as the free provider).

## 4. Design

### 4.1 New provider class

`src/Services/ThreatFeed/Providers/MaxMindGeoIp2PremiumProvider.php`, structurally a
sibling of the GeoLite2 provider:

- `name(): 'maxmind_geoip2_premium'`
- `label(): 'MaxMind GeoIP2 City/Country (Premium)'`
- `isAvailable()`: paid account id + key present in config AND `GeoIp2\Reader` exists.
- `sync()`: download editions `GeoIP2-City` and `GeoIP2-Country` (and `GeoIP2-ISP` if
  enabled) to `storage/shield/geo/premium/`, extract `.mmdb`, return a `SyncResult`.
  Reuse the tar.gz extraction logic from the GeoLite2 provider (factor the shared
  `extractMmdb()` into a trait or small helper to avoid duplication).

MaxMind paid downloads use the same `geoip_download` endpoint with the account's
license key; the edition ids differ (`GeoIP2-City` vs `GeoLite2-Country`).

### 4.2 Matcher database preference

`CountryMatcher`, `RegionMatcher`/`CityMatcher`, `AsnMatcher` resolve their MMDB path
through a single helper, e.g. `GeoDatabaseResolver::path(string $kind)`:

- Prefer `storage/shield/geo/premium/GeoIP2-City.mmdb` when it exists (covers country,
  region, city in one DB).
- Fall back to `storage/shield/geo/GeoLite2-Country.mmdb` (free, country only).
- `city` / `region` ACL kinds simply do not match when only the free DB is present
  (current behaviour), so unlicensed installs are unchanged.

The per-IP geo cache keys (`shield.geo.country.*`, `shield.geo.asn.*`) are unchanged;
add `shield.geo.city.*` / `shield.geo.region.*` with the same 24h TTL.

### 4.3 Scheduling

Premium GeoIP2 updates twice weekly (MaxMind publishes GeoIP2 updates a couple of times
per week). Add `maxmind_geoip2_premium` to the daily feed run; `FeedRunner` skips it
when unlicensed. Optionally gate the download frequency with a "last downloaded" cache
stamp to avoid re-pulling a multi-hundred-MB DB daily.

## 5. Files to add / change

| File | Change |
|------|--------|
| `src/Services/ThreatFeed/Providers/MaxMindGeoIp2PremiumProvider.php` | NEW provider |
| `src/Services/ThreatFeed/Support/MmdbExtractor.php` (or trait) | factor shared tar.gz extraction out of GeoLite2 provider |
| `src/Services/Acl/Matchers/CountryMatcher.php` (+ Region/City/Asn matchers) | resolve MMDB path via `GeoDatabaseResolver`, prefer premium |
| `src/Services/Acl/GeoDatabaseResolver.php` | NEW path resolver (premium-then-free) |
| `config/shield.php` | add `threat_feed.maxmind_premium` block |
| `FeedRunner::PREMIUM_ONLY_PROVIDERS` | already contains `maxmind_geoip2_premium`, no change |
| `composer.json` suggest | note GeoIP2-City benefits from `geoip2/geoip2` (already suggested) |
| `docs/threat-feeds.md`, `docs/acl.md` | document premium geo precision |

(Matcher class paths above are indicative; confirm actual matcher locations during
implementation. The current code references `CountryMatcher` / `AsnMatcher` in
[docs/acl.md](../acl.md) and [docs/threat-feeds.md](../threat-feeds.md).)

## 6. Config & env

```php
// config/shield.php -> threat_feed
'maxmind_premium' => [
    'enabled'     => env('LS_MAXMIND_PREMIUM_ENABLED', false),
    'account_id'  => env('LS_MAXMIND_PREMIUM_ACCOUNT_ID'),
    'license_key' => env('LS_MAXMIND_PREMIUM_LICENSE_KEY'),
    'editions'    => ['GeoIP2-Country', 'GeoIP2-City'],   // add 'GeoIP2-ISP' if desired
],
```

Env additions: `LS_MAXMIND_PREMIUM_ENABLED`, `LS_MAXMIND_PREMIUM_ACCOUNT_ID`,
`LS_MAXMIND_PREMIUM_LICENSE_KEY`.

## 7. Data / schema impact

None. ACL kinds `country` / `region` / `city` / `asn` already exist. Only the backing
MMDB files and cache keys change.

## 8. Premium gating & free fallback

- Gate: `FeedRunner::isProviderUnlocked()` -> `isFeatureAvailable('premium_threat_feeds')`.
  (Geo precision rides the same threat-feed entitlement; `premium_geoip2` may be used as
  a finer-grained flag if the license plan distinguishes it.)
- Fall-back: without the premium MMDB, country/ASN still work via GeoLite2; city/region
  ACL rules simply do not match. No errors, no behaviour change for free installs.
- The paid MaxMind key is a secret: redacted, never logged.

## 9. Acceptance criteria

1. With premium config + license, `shield:feed-sync` downloads GeoIP2-City/Country to
   `storage/shield/geo/premium/` and matchers resolve to the premium DB.
2. A `city` ACL rule matches when the premium DB is present and does not match when only
   the free DB is present.
3. Free country/ASN blocking is byte-for-byte unchanged when premium is off.
4. Provider skipped (not failed) when unlicensed.
5. A failed/partial download returns `SyncResult::error` and leaves any existing DB
   intact (atomic replace).

## 10. Test plan

- `MaxMindGeoIp2PremiumProviderTest`: fake the download, assert extraction to the
  premium dir, `SyncResult` counts, atomic replace on partial failure.
- `GeoDatabaseResolverTest`: premium path preferred when present, free path otherwise.
- `CountryMatcherTest` / `CityMatcherTest`: city matches only with premium DB; country
  matches with either; unlicensed install unchanged.
- `FeedRunnerTest`: provider gated off without license.

## 11. Rollout notes

- Default `enabled=false` (needs a paid MaxMind account). Document the upsell: "country
  and ASN blocking are free; city/region precision is premium".
- Premium GeoIP2 DBs are large. Use a last-downloaded stamp to avoid daily re-pulls and
  document the disk footprint under `storage/shield/geo/premium/`.
