# Laravel Shield Central — Brainstorm Doc

**Document:** `docs/superpowers/specs/2026-05-26-laravel-shield-central-design.md`
**Status:** Brainstorm (companion doc; lives in plugin repo for now, will move to `laravel-shield-app` repo when that's created)
**Branch:** (will be `feat/initial-central` in `OzanKurt/laravel-shield-app`)
**Date:** 2026-05-26
**Sibling repo path:** `D:\Code\Projects\laravel-shield-app\`

---

## 1. Purpose

A separate standalone Laravel application at **`laravel-shield.ozankurt.com`** that does four things, in order of dependency / time-to-build:

1. **Marketing + sales site** — landing page, pricing, docs, signup
2. **License-check API** — what the `ozankurt/laravel-shield` package's premium features call at runtime
3. **Customer accounts + billing** — purchase, manage licenses, generate keys, view invoices
4. **SIEM-aggregator dashboard** — consume webhook events from many plugin installs (Wordfence Central analogue, the eventual flagship feature)

This document is a **brainstorm scaffold**, not a finished spec. Once we start active work on this, it gets a proper brainstorming session and its own design doc.

---

## 2. Why a separate Laravel app, not part of the plugin

- **Different deployment** — a public SaaS, not a library. Different infra (DB, queues, public URL).
- **Different audience** — customers, not developers running `composer require`.
- **Different release cadence** — site updates daily/weekly without forcing customers to `composer update`.
- **Different stack flexibility** — uses whatever Laravel features make sense (Filament admin, Stripe, Inertia, etc.) without leaking those into the plugin.
- **Different code lifecycle** — fewer breaking-changes concerns; the API contract with the plugin is what we keep stable.

The plugin's webhook channel (§17 of the plugin spec) emits versioned payloads designed for consumption by this Central app.

---

## 3. Phased roadmap

| Phase | Scope | Effort estimate |
|---|---|---|
| **v0.1 — minimum viable** | Laravel app scaffold + marketing landing + pricing page + license-check API endpoint stub (hardcoded keys for testing) | 2-3 days |
| **v0.2 — customer accounts** | Signup/login (Fortify or Breeze) + customer model + license generation + Stripe Checkout integration | ~1 week |
| **v0.3 — license management** | Admin (Filament) for managing customers + licenses + manual key generation + revocation + telemetry view | ~1 week |
| **v0.5 — purchase flow polish** | Buyer self-serve license dashboard, renewal reminders, invoice download, transactional emails | ~3-4 days |
| **v1.0 — SIEM aggregator** | Webhook ingestion endpoint, organization/site model, multi-site dashboard, cross-site alerts, search/filter across sites | ~3-4 weeks |
| **v1.5+** | API for third-party access, mobile-friendly dashboard, alert templates, audit-log centralization | ongoing |

Total to v1.0 SIEM: ~6-8 weeks of focused engineering.

**Parallel to plugin work:** v0.1 + v0.2 can happen alongside plugin beta.1-beta.3 (~3 weeks total). The license API needs to exist before premium features can be sold (which is plugin 2.0.0 → realistically 2 months out anyway).

---

## 4. Tech stack (proposal — confirm during brainstorm)

- **Laravel 12.x** (latest stable)
- **PHP 8.3+** (no need to support old PHP for a SaaS we control)
- **MySQL or PostgreSQL** (PostgreSQL preferred for jsonb + better long-term)
- **Redis** for cache + queue
- **Filament v5** for the admin panel (we already plan to support v5 via the plugin's Filament adapter v2.x)
- **Inertia + Vue OR Livewire** for the customer-facing dashboard (decide during brainstorm)
- **Stripe** for billing (Checkout + Customer Portal — out-of-box, minimal code)
- **Mailgun or Postmark** for transactional emails
- **Cloudflare** in front for CDN + DDoS protection (irony: a security suite needs WAF too)
- **GitHub Actions** for CI/CD
- **Fly.io / Forge / Vapor** for hosting (decide based on cost + ops)
- **Sentry** for error tracking
- **Pest** for testing

---

## 5. Data model (initial sketch)

Following the same conventions as the plugin (uuid7, userstamps, soft-deletes, lookup tables instead of enums):

```sql
organizations                          customers                          licenses
├── id, uuid                          ├── id, uuid                       ├── id, uuid
├── name                              ├── organization_id  FK            ├── customer_id    FK
├── slug                              ├── email           unique         ├── plan_id        FK → license_plans
├── (Stripe customer_id)              ├── name                           ├── key            unique
├── timestamps + softdeletes          ├── stripe_customer_id             ├── status_id      FK → license_statuses
                                       ├── timestamps + softdeletes       ├── starts_at, expires_at
                                                                          ├── domain_limit
                                                                          ├── features        json
                                                                          ├── meta            json
                                                                          ├── timestamps + softdeletes + userstamps

license_check_logs                    license_plans                      license_statuses (lookup)
├── id, uuid, correlation_id          ├── id, uuid                       ├── id, name, label, description
├── license_id      FK                ├── name (solo, pro, enterprise)   │   (active, trialing, expired, revoked, suspended)
├── site_url                          ├── price_cents
├── ip                                ├── domain_limit_default
├── user_agent                        ├── features              json
├── checked_at                        ├── stripe_price_id
├── result_id       FK → license_check_results
├── meta             json
├── timestamps

license_check_results (lookup)        webhooks_received                  organization_sites (when SIEM lives)
├── valid                              ├── id, uuid, correlation_id       ├── id, uuid
├── expired                            ├── organization_id   FK           ├── organization_id    FK
├── revoked                            ├── source_site_url                ├── url                unique
├── domain_limit_exceeded              ├── event_kind                     ├── verified_at        nullable
├── unknown_key                        ├── severity                       ├── webhook_secret      (HMAC verification)
├── network_error (only client-logged) ├── payload          json          ├── last_event_at
                                       ├── ingested_at                    ├── status_id          FK
                                       ├── timestamps                     ├── timestamps + softdeletes
```

(The webhooks_received → aggregated dashboards / search is v1.0 SIEM scope.)

---

## 6. Key endpoints

### License-check API (v0.1)

```
POST /api/license/check
Body: { key, site_url, package_version, php_version, laravel_version }
Response: { valid, expires_at, plan, features, domain_limit, domains_used, grace_period_days }
```

Rate-limited per key (e.g. 60/hr — way more than the 24h check cadence allows). Returns 200 on both valid and invalid (with details in body) so it never appears to the plugin as a connection problem when it's really an invalid key.

### Webhook ingestion (v1.0 SIEM)

```
POST /api/webhooks/ingest
Headers: X-Shield-Signature: <hmac-sha256-of-body-with-site's-webhook-secret>
Body: <plugin webhook payload, see plugin spec §17>
```

HMAC signature verification by per-site secret. Payloads dropped into a queue → processed into `webhooks_received` + cross-referenced to organization/site.

### Customer dashboard

```
GET  /                    landing
GET  /pricing             pricing
GET  /docs                docs index
POST /signup              create account
POST /login
GET  /dashboard           customer's licenses overview
GET  /dashboard/licenses
POST /dashboard/licenses  create new license (within plan limits)
GET  /dashboard/billing   Stripe portal redirect
```

### Admin (Filament panel)

```
/admin
  /admin/customers
  /admin/licenses (manual issue / revoke / extend)
  /admin/plans
  /admin/license-check-logs (telemetry, abuse detection)
  /admin/webhooks-received (SIEM v1.0+)
```

---

## 7. Integration contract with the plugin

This is what stays stable across the plugin's lifetime — breaking this contract would break every customer install.

### Plugin → Central

- `POST /api/license/check` — at most 1× per 24h per site, schema versioned via `package_version` field
- `POST /api/webhooks/ingest` — every event the plugin's webhook channel fires (configurable)

### Central → Plugin (none for v1.0)

No reverse calls. The plugin polls / push only. Future versions might add command-and-control (e.g., "force this site to refresh its threat feed") but that's well beyond v1.

### Payload schema versioning

Both API endpoints include `_meta.schema_version` in the payload. Central handles N-1 versions gracefully. Plugin updates bump schema_version when payloads change.

---

## 8. Onboarding flow for a new buyer

1. Buyer hits `laravel-shield.ozankurt.com` → marketing page
2. Clicks Pricing → picks plan (Solo / Pro / Enterprise)
3. Signs up + Stripe Checkout for selected plan
4. After payment → license key auto-generated, emailed + shown in dashboard
5. Buyer copies key → adds to `.env` in their Laravel app (`LS_PREMIUM_LICENSE_KEY=ls-prem-xxx`)
6. Next request to that Laravel app → plugin's `LicenseChecker` calls Central → premium activates
7. Buyer can view license details + add more sites (up to `domain_limit`) in dashboard
8. Pre-expiry email reminder (30d, 7d, 1d) → renewal via Stripe Customer Portal

---

## 9. Pricing model placeholders

Pin during brainstorm; ballpark for reference:

| Plan | Price/month | Domain limit | Features |
|---|---|---|---|
| Solo | $9 | 1 | Real-time feed (daily), basic premium audit, no SIEM |
| Pro | $29 | 5 | Real-time feed (hourly), full premium audit, remote sinks, SIEM dashboard |
| Enterprise | $99 | 25 | Real-time feed (5min), all features, SLA, priority support |

(Numbers TBD — pricing-strategy brainstorm separate.)

---

## 10. Open questions for the proper Central brainstorm

When we start the Central app brainstorm in earnest, decide:

1. Hosting platform (Fly / Forge / Vapor / DIY)
2. Frontend approach (Inertia+Vue vs Livewire+Alpine vs separate SPA)
3. Domain limit enforcement granularity (per-site URL vs per-app-instance)
4. Self-hosted Central — should we ship the Central app as a deployable package for users who want to run their own?
5. Free tier — does Central have a free tier? (e.g., 1 site, no premium plugin features)
6. Stripe vs Paddle vs LemonSqueezy
7. Self-serve license refund / trial duration
8. Multi-currency
9. Affiliate / partner program
10. Pricing tiers (above sketch may be wrong)
11. SLAs + uptime commitments
12. Branding: Laravel Shield Central? Shield Central? Just Central?

---

## 11. Folder structure (when work starts)

```
D:\Code\Projects\
├── laravel-shield\              (this repo after rename — the plugin)
├── laravel-shield-app\          (the Central app)
│   ├── app\
│   ├── config\
│   ├── routes\
│   ├── ...
│   └── docs/superpowers/specs/  (Central's own brainstorms)
├── laravel-shield-signatures\   (signature bundles repo for the plugin)
│   ├── releases/
│   └── README.md
└── (future) laravel-shield-filament\
```

---

## 12. Next actions for Central

This doc is a brainstorm scaffold — not a finished spec. Before serious work starts:

1. Run a proper brainstorming session (the `superpowers:brainstorming` skill) for Central with full Q&A
2. Produce a complete design spec at `D:\Code\Projects\laravel-shield-app\docs\superpowers\specs\YYYY-MM-DD-central-design.md`
3. Pick the tech stack details (hosting, frontend, pricing)
4. Build phase v0.1 in parallel with plugin beta.2-beta.3 work
5. Iterate through v0.2, v0.3, v0.5 as plugin work progresses
6. SIEM v1.0 lands after plugin v1.0 is shipped

For now: this scaffold ensures the plugin spec doesn't paint itself into a corner that the Central app would need to undo.

---

**Status: BRAINSTORM SCAFFOLD — needs proper brainstorming session before implementation.**
