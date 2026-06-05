# Premium parity specs (v2.2.0)

This folder holds one implementation spec per premium feature needed to reach
Wordfence-Premium parity. Each spec is self-contained: problem, design, files to
touch, config/env, gating, acceptance criteria, and test plan.

## Background

A feature comparison against [Wordfence pricing](https://www.wordfence.com/products/pricing/)
showed the real Wordfence Premium moat is three things gated behind "real-time vs
30-day-delayed", plus country blocking:

| Wordfence Premium feature | Our status before this milestone |
|---|---|
| Real-time firewall rules | Gate exists (`premium_threat_feeds`) but the `shield_realtime` provider class does NOT exist |
| Real-time IP blocklist | Same: gated by name, no provider class |
| Real-time malware signatures | No premium gate at all. `shield:signatures-sync` pulls the same GitHub Releases feed for free and premium |
| Country blocking | Shipped FREE via MaxMind GeoLite2. No premium tier |
| Audit log | Intentionally excluded from this milestone (see below) |
| Premium support | Not a software feature, N/A to a single-maintainer model |

`FeedRunner::PREMIUM_ONLY_PROVIDERS` already lists four provider slugs
(`shield_realtime`, `maxmind_geoip2_premium`, `emerging_threats`, `crowdstrike`)
that are gated but unimplemented. These specs implement them and close the
signature-freshness gap.

## The specs

| # | Spec | Closes Wordfence parity for | Premium flag |
|---|------|------------------------------|--------------|
| 001 | [Real-time threat-feed push](spec-001-realtime-threat-feed-push.md) | Real-time firewall rules + real-time IP blocklist | `premium_threat_feeds` |
| 002 | [Premium malware signature freshness](spec-002-premium-signature-freshness.md) | Real-time malware signatures | `premium_signatures` |
| 003 | [Premium GeoIP2 + country/city precision](spec-003-premium-geoip2-country-blocking.md) | Country blocking (we keep country free, sell city precision) | `premium_geoip2` |
| 004 | [Commercial threat-intel feeds](spec-004-commercial-threat-feeds.md) | IP blocklist enrichment beyond Wordfence | `premium_threat_feeds` |

## Explicit exclusions (not specced, on purpose)

- **Audit Log / Audit Log History** - excluded by request.
- **Leaked / breached password protection** - out of plugin scope (HIBP/credential
  features are deliberately not part of this package; scope is WAF / analyze / log /
  scan / alert).
- **Premium support, Care, Response (managed services)** - human-service offerings,
  not software features. Different business model.
- **Central SIEM dashboard** - lives in the separate Laravel Shield Central app, not
  in this package. Webhook forwarding to Central already exists here
  (`ForwardAuditToCentralJob`); the dashboard itself is out of scope for this repo.

## Shared conventions used by all specs

- Premium activation is runtime-gated through `Shield::isFeatureAvailable($flag)`
  ([src/Shield.php](../../src/Shield.php)), which delegates to
  `LicenseChecker` ([src/Services/Premium/LicenseChecker.php](../../src/Services/Premium/LicenseChecker.php)).
- Every premium code path MUST degrade to the existing free behaviour when the
  license is missing, expired, revoked, or the Central API is unreachable past the
  grace period. No premium feature may throw or 500 a protected site.
- New threat-feed providers implement
  `OzanKurt\Shield\Contracts\ThreatFeedProvider`
  ([src/Contracts/ThreatFeedProvider.php](../../src/Contracts/ThreatFeedProvider.php))
  and return a `SyncResult`.
- New providers are registered in `config('shield.threat_feed.providers')` and, when
  premium-only, added to `FeedRunner::PREMIUM_ONLY_PROVIDERS`.
- All sync runs audit-log `threat_feed.sync_started` / `sync_completed` /
  `sync_failed` (already wired in `FeedRunner`).
- The license key and all vendor API keys are secrets: never logged, never rendered
  in clear text, redacted by the standard sensitive-field list.
