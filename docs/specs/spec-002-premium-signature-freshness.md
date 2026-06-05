# Spec 002: Premium malware signature freshness

- Status: Draft
- Target version: v2.2.0
- Wordfence parity: Real-time malware signatures (Wordfence free waits 30 days for
  new malware signatures, Premium gets them immediately). This is Wordfence's single
  biggest stated premium differentiator.
- Premium feature flag: `premium_signatures`

## 1. Problem / current state

`shield:signatures-sync` ([src/Console/Commands/SignaturesSyncCommand.php](../../src/Console/Commands/SignaturesSyncCommand.php))
pulls malware signatures from a GitHub Releases feed:

```php
'url' => env('LS_SIGNATURE_URL', 'https://api.github.com/repos/OzanKurt/laravel-shield-signatures/releases/latest'),
'pin' => env('LS_SIGNATURE_PIN'),
```

It fetches `releases/latest` for **every** install, free or premium, and falls back to
embedded signatures when offline. There is **no premium gate** on signature freshness.
A `scanner.pro` feature flag is referenced only in tests and never wired into the
scanner. So today a free site gets the newest signatures at the same moment a premium
site does, which is the opposite of the Wordfence model and removes a core reason to
buy premium.

## 2. Goal

Introduce two signature release channels:

- **Premium channel**: newest signatures immediately (`releases/latest`).
- **Free channel**: the same signatures on a deliberate lag (default 30 days),
  published under a moving `free` release tag by the signatures repo / Central.

A licensed install resolves to the premium channel; everyone else resolves to the free
channel. Offline behaviour (embedded fallback) is unchanged.

## 3. Non-goals

- Changing signature format, the scanner engine, or `ls_signatures` schema.
- Encrypting or obfuscating signatures. Soft enforcement only, consistent with
  [docs/premium.md](../premium.md). The real lag is enforced by what the free tag
  contains server-side, not by client code.
- Building the lag-publishing automation (that is a signatures-repo / Central concern;
  this spec defines the client contract: a `free` tag that lags `latest` by N days).

## 4. Design

### 4.1 Channel resolution

In `SignaturesSyncCommand::handle()`, before building the URL, resolve the channel:

```php
$premium = Shield::isFeatureAvailable('premium_signatures');
$channel = $premium ? 'premium' : 'free';
$url = $premium
    ? config('shield.scanner.signatures.premium_url')   // releases/latest
    : config('shield.scanner.signatures.free_url');      // releases/tags/free
```

- An explicit `LS_SIGNATURE_PIN` still overrides both (pins to a specific tag) via the
  existing `pinnedUrl()` path. Useful for reproducible deployments and tests.
- The audit-log meta records `channel` so operators can see which track applied.

### 4.2 Why a `free` tag, not a date filter

GitHub `releases/latest` is a single pointer. Rather than have the client list all
releases and pick "newest older than 30 days" (fragile, rate-limited, needs pagination),
the signatures repo publishes a moving tag `free` that always points at the release
from ~30 days ago. The client just fetches `releases/tags/free`. The lag policy lives in
one place (the publisher), and the free URL is a plain, cacheable request.

### 4.3 Dashboard / diagnostics

`docs/diagnostics.md` already surfaces "Last signature DB update" from the most recent
`threat_feed.sync_completed`. Extend that row to also show the resolved channel
(`free` vs `premium`) and, for free installs, a subtle "Premium unlocks real-time
signatures" hint (consistent with other premium upsell hints, no nagging).

### 4.4 Free fallback / offline

Unchanged: if the resolved channel URL is unreachable or empty, fall back to embedded
signatures exactly as today. A premium site that loses Central/GitHub access still gets
embedded coverage.

## 5. Files to add / change

| File | Change |
|------|--------|
| `src/Console/Commands/SignaturesSyncCommand.php` | resolve channel via license; pick free vs premium URL; record `channel` in audit meta |
| `config/shield.php` | split `scanner.signatures.url` into `free_url` + `premium_url` (keep `url` as a back-compat alias mapping to free); keep `pin` |
| `src/Http/Controllers/DiagnosticsController.php` | surface resolved channel + last-update age |
| `docs/scanner` docs + `docs/diagnostics.md` | document the two channels and the 30-day free lag |

## 6. Config & env

```php
// config/shield.php -> scanner.signatures
'signatures' => [
    // Premium: newest signatures immediately.
    'premium_url' => env('LS_SIGNATURE_PREMIUM_URL', 'https://api.github.com/repos/OzanKurt/laravel-shield-signatures/releases/latest'),
    // Free: same signatures on a ~30 day lag (moving "free" tag).
    'free_url'    => env('LS_SIGNATURE_FREE_URL', 'https://api.github.com/repos/OzanKurt/laravel-shield-signatures/releases/tags/free'),
    // Optional hard pin (overrides channel selection) -> releases/tags/<pin>.
    'pin'         => env('LS_SIGNATURE_PIN'),
    'sync_cron'   => '0 5 * * *',
],
```

Back-compat: if a deployment still sets `LS_SIGNATURE_URL`, treat it as the free URL so
existing installs are unaffected.

## 7. Data / schema impact

None. Same `ls_signatures` table and `source` values (`wf_free`, `builtin_native`).
A premium-sourced signature could optionally carry `source='shield_premium'` if we want
to distinguish provenance, but that is optional and additive.

## 8. Premium gating & free fallback

- Gate: `Shield::isFeatureAvailable('premium_signatures')` selects the URL.
- Patchability: flipping the local flag only changes which URL is requested. The free
  tag still contains lagged content; only a valid license unlocks the always-fresh
  `latest` on the server side (if Central proxies it) or the documented premium GitHub
  release track. This is the same honest soft-enforcement posture as the feeds.
- Offline: embedded fallback guarantees the scanner always has a baseline ruleset.

## 9. Acceptance criteria

1. Premium license active -> sync hits `premium_url` (`releases/latest`).
2. No / invalid license -> sync hits `free_url` (`releases/tags/free`).
3. `LS_SIGNATURE_PIN` set -> both ignore channel and pin to `releases/tags/<pin>`.
4. Legacy `LS_SIGNATURE_URL` still works (maps to free channel), no breakage on upgrade.
5. Remote unreachable -> embedded fallback applies for both channels.
6. Audit meta on `threat_feed.sync_completed` includes the resolved `channel`.

## 10. Test plan

- `SignaturesSyncCommandTest`:
  - premium flag true -> `Http::fake()` asserts request to premium URL.
  - premium flag false -> asserts request to free URL.
  - pin set -> asserts `releases/tags/<pin>` regardless of flag.
  - legacy `LS_SIGNATURE_URL` env -> resolves to free URL.
  - remote 404 -> embedded fallback invoked, command still SUCCESS.
- Diagnostics test: channel string rendered for both license states.

## 11. Rollout notes

- Requires the signatures repo to start publishing a moving `free` tag lagging `latest`
  by ~30 days. Until that tag exists, free installs fall back to embedded signatures
  (safe, just not the lagged remote set). Ship the client change behind that publisher
  change, or seed the `free` tag at release time.
- Document clearly that free signatures lag premium by ~30 days, mirroring Wordfence,
  so the value proposition is explicit and honest.
