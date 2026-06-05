# Premium Tier

Premium features ship in the **same package** as free features. There's no separate Composer repo, no Satis, no auth tokens. Just paste your license key in `.env` and premium activates on next request.

## What premium unlocks

- **Real-time threat feed sync** — free tier syncs daily; premium polls every few minutes
- **Real-time IP blocklist subscription** — same idea for blocklists
- **Real-time malware signatures** — premium fetches `releases/latest`; free uses a moving `free` tag lagging ~30 days (matches Wordfence's free-tier signature delay). Resolved per-run via `premium_signatures`; the channel is recorded in the `threat_feed.sync_completed` audit meta
- **Hosted audit-log sink** — forward audit events to the Shield Central app for cross-site SIEM aggregation
- **Future SIEM dashboard integration** — first-class consumer of the webhook channel

Local-only features (dashboard polish, advanced report templates, extra middlewares) are NOT premium-gated — those ship for everyone in the free release.

## Setup

1. Buy a license at [laravel-shield.ozankurt.com](https://laravel-shield.ozankurt.com)
2. Receive your key — `ls-prem-xxxxxxxxxxxx`
3. Add to `.env`:

```env
LS_PREMIUM_LICENSE_KEY=ls-prem-xxxxxxxxxxxx
```

That's it. Premium features activate after the next license check (cached 24h).

## How it works under the hood

Modelled on Wordfence's `wfLicense.php`:

1. On first feature request (or on the daily check cron), the package POSTs to `https://laravel-shield.ozankurt.com/api/license/check` with your key + site URL + package version
2. The API returns `{ valid, expires_at, plan, features: [...], domain_limit, domains_used }`
3. Result cached in your local cache for 24h (key `shield.premium.license`)
4. Premium feature code paths check `Shield::isFeatureAvailable($feature)` before activating; fall back to free behaviour when the check fails

### Grace period

If the license API is unreachable for >24h, premium stays active for an additional 7-day grace period (configurable via `LS_PREMIUM_LICENSE_GRACE_DAYS`). After the grace period expires, features deactivate and the dashboard shows a banner.

This prevents an outage on Ozan's side from killing buyer sites.

## Honest threat model — soft enforcement, not DRM

The local `LicenseChecker::isFeatureAvailable()` check is **patchable**. Any buyer can open `vendor/ozankurt/laravel-shield/src/Premium/LicenseChecker.php` and change `isFeatureAvailable()` to always return true. This is true for every open-source-distributed premium plugin — Wordfence, Yoast, ACF Pro, EDD, etc.

The license check is NOT designed to defeat crackers. It's designed to:
- Give honest buyers a clean "license expired, please renew" UI signal
- Make key sharing between companies slightly inconvenient (`domain_limit` tracking on the API side)
- Create a billing/activation audit trail
- Provide a revocation channel for leaked keys

**Where the actual enforcement lives: Ozan's API server**, not your `vendor/` directory.

The real premium *value* lives server-side:

| Premium feature | Where the value lives | Crack-able locally? |
|---|---|---|
| Real-time threat feed | `laravel-shield.ozankurt.com` API gates feed access by license key | No — patcher gets stale free signatures |
| Real-time IP blocklist | Same API | No |
| Hosted audit-log sink | Hosted ingestion endpoint | No |
| SIEM dashboard | The Shield Central app | No |

Patching `isFeatureAvailable()` doesn't unlock the API. The API still demands a valid key. Without one, the patched plugin works the same as the free package.

**We do NOT implement:** encrypted code blobs (IonCube / SourceGuardian), Composer post-install validators, runtime file checksums. These hurt honest buyers and don't defeat crackers — not worth it.

## Anti-abuse

- API knows `site_url` per check → same key from many sites is visible to Ozan
- `domain_limit` enforced API-side (returns `domain_limit_exceeded` past N domains)
- Revocation is immediate (cached up to 24h on buyer side)
- License key is treated as a secret — never logged, redacted via the standard sensitive-field redaction list

## Configuration

```php
// config/shield.php
'premium' => [
    'license_key' => env('LS_PREMIUM_LICENSE_KEY'),
    'check_url' => env('LS_PREMIUM_LICENSE_CHECK_URL', 'https://laravel-shield.ozankurt.com/api/license/check'),
    'cache_ttl' => env('LS_PREMIUM_LICENSE_CACHE_TTL', 86400),  // 24h
    'grace_days' => env('LS_PREMIUM_LICENSE_GRACE_DAYS', 7),
],
```
