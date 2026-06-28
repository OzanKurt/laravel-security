# Spec 006: HoneypotPlus parity - edge reactions, AbuseIPDB reporting, form input-trap

- Status: Draft
- Track: Core (firewall reactions + honeypot). New track, separate from the 001-005 specs.
- Tier: All FREE. `laravel-honeypotplus` is MIT; per the port-WF-free-code rule, everything here ships in the free package.
- Premium flag: none.

This spec absorbs every capability of the `laravel-honeypotplus-main` package that
Shield does not already provide, identified by a full feature diff. Shield already
has a stronger honeypot core (route-level traps for ~80 paths, ACL auto-block, audit
log, notifications, dashboard, suspicion scoring). The net-new value in HoneypotPlus
is its **outbound reaction layer** (push blocks to the Cloudflare edge, report
attackers to AbuseIPDB) plus three smaller items.

The five gaps closed here:

1. Cloudflare edge ban/unban (create/delete Zone IP Access Rules).
2. AbuseIPDB **outbound reporting** (we currently only consume the blacklist).
3. Regex honeypot path patterns.
4. Form-field input-trap (Blade component + middleware + validation rule).
5. Interactive ban-management CLI.

Items 1 and 2 are built as a **generic ACL reaction layer**, not bolted onto
honeypots, so they fire for every locally-detected block source.

---

## 1. Problem / current state

Confirmed against source:

- **Blocking is centralised in `ls_acl`.** The `Acl` model
  ([src/Models/Acl.php](../../src/Models/Acl.php)) is the canonical blocklist:
  columns `kind_id`, `action_id`, `value`, `source` (free-text string), `reason`,
  `expires_at`, `hit_count`, and a JSON `meta` column. Honeypot hits, suspicion
  scoring, WAF auto-block, and threat-feed imports all `Acl::create(...)` with a
  distinguishing `source`.
- **`AclObserver`** ([src/Observers/AclObserver.php](../../src/Observers/AclObserver.php))
  already observes the model but only clears the evaluator cache on
  `saved`/`deleted`/`restored`. It is the natural single choke point for reactions.
- **AbuseIPDB is consume-only.** `AbuseIpDbProvider`
  ([src/Services/ThreatFeed/Providers/AbuseIpDbProvider.php](../../src/Services/ThreatFeed/Providers/AbuseIpDbProvider.php))
  pulls the `/blacklist` endpoint into `ls_acl` (source=`abuseipdb`). There is no
  call to the `/report` endpoint anywhere.
- **No Cloudflare API integration exists.** `TrustedProxiesService` only detects
  Cloudflare proxy ranges; it never touches the firewall API.
- **Honeypot paths are route-registration based.** `registerHoneypotRoutes()`
  ([src/ShieldServiceProvider.php:415](../../src/ShieldServiceProvider.php#L415))
  registers two explicit routes per configured path (exact + wildcard subpath), all
  pointing at `HoneypotController::trap`
  ([src/Http/Controllers/HoneypotController.php](../../src/Http/Controllers/HoneypotController.php)).
  There is no way to match a path by regex.
- **No form-field honeypot.** Shield protects against scanner reconnaissance, not
  spam-bot form submissions. There is no Blade component, middleware, or rule for a
  hidden-field trap.
- **CLI is flag-based only.** ACL management is done through `Acl::create` /
  dashboard; there is no interactive `shield:acl` console UI. `shield:unblock-ips`
  ([src/Commands/UnblockIpsCommand.php](../../src/Commands/UnblockIpsCommand.php))
  exists for the legacy `Ip` model.
- **`SuspicionScorer::bump($ip, $by)`**
  ([src/Services/Scoring/SuspicionScorer.php](../../src/Services/Scoring/SuspicionScorer.php))
  is the existing escalation primitive: it accumulates a per-IP score and auto-blocks
  (source=`scoring`) once `shield.scoring.threshold` is crossed. The form trap reuses
  this rather than inventing new escalation logic.

---

## 2. Goal

1. When an IP is blocked by a **locally-detected** source, asynchronously push it to
   the Cloudflare edge (Zone IP Access Rule, mode=block) and/or report it to
   AbuseIPDB, each independently toggleable.
2. When such a block expires or is removed, remove the matching Cloudflare rule.
3. Add regex honeypot path matching alongside the existing exact/wildcard routes.
4. Ship a `<x-shield-honeypot />` Blade component + `ProtectAgainstSpam` middleware +
   `ShieldHoneypot` validation rule that silently discards bot submissions and
   escalates the source IP through `SuspicionScorer`.
5. Ship an interactive `php artisan shield:acl` management command.

Everything degrades gracefully: missing Cloudflare token / AbuseIPDB key means the
reaction is skipped (logged once), never throws, never 500s a protected site.

---

## 3. Non-goals

- WAF IP Lists / account-level Cloudflare rules (we ship Zone Access Rules only; the
  reaction is written behind an interface so a list-based driver can be added later
  without touching callers).
- Reporting or edge-pushing **feed-sourced** IPs (explicitly excluded to prevent
  report loops and edge flooding).
- A new notification class for "IP pushed to Cloudflare" (audit-log only; YAGNI).
- Livewire-specific honeypot component (the validation rule covers Livewire forms).
- New database tables (all reaction state lives in `ls_acl.meta`).

---

## 4. Design

### 4.1 Outbound reaction layer (gaps 1 + 2)

**Contract.** `src/Contracts/AclReaction.php`:

```php
interface AclReaction
{
    public function name(): string;        // 'cloudflare' | 'abuseipdb_report'
    public function isEnabled(): bool;      // config + credentials present
    public function appliesTo(Acl $acl): bool; // action=block, source allowed, public IP, kind=ip
    public function ban(Acl $acl): void;    // perform the outbound side effect
    public function unban(Acl $acl): void;  // reverse it (no-op for one-shot reactions)
}
```

**Reactions.**

- `src/Services/Reactions/CloudflareReaction.php`
  - `ban`: `POST /client/v4/zones/{zone}/firewall/access_rules/rules` with
    `{mode:'block', configuration:{target:'ip', value:<ip>}, notes:<category + reason>}`.
    Stores the returned rule id at `acl.meta.reactions.cloudflare.rule_id` and
    `created_at`.
  - `unban`: `DELETE .../access_rules/rules/{rule_id}` using the stored id, then
    clears the meta marker.
  - Talks to a thin `src/Services/Reactions/CloudflareClient.php` wrapper around
    `Http::` (timeout, bearer token, JSON). Account-level scope is used when
    `zone_id` is empty and `account_id` is set (same endpoints under `/accounts/...`).
- `src/Services/Reactions/AbuseIpDbReportReaction.php`
  - `ban`: `POST https://api.abuseipdb.com/api/v2/report` with `ip`, `categories`
    (config, default `[21,19]`), `comment` (the ACL reason, redacted of secrets).
    Dedupe: skip when `acl.meta.reactions.abuseipdb.reported_at` is already set or the
    ACL `created_at` is older than `max_age_days`. Records `reported_at` on success.
  - `unban`: no-op (community reports are permanent).

**Manager + dispatch.** `src/Services/Reactions/ReactionManager.php`:

- Holds the registered reactions (bound in the service provider).
- `onBlock(Acl $acl)` / `onUnblock(Acl $acl)`: for each reaction where
  `isEnabled() && appliesTo($acl)`, dispatch `RunAclReactionJob::dispatch($reaction->name(), $acl->id, 'ban'|'unban')->afterCommit()`.
- The **source allowlist** lives in config:
  `shield.reactions.self_detected_sources = ['honeypot','honeypot_form','waf','scoring','auth','manual']`.
  `appliesTo` returns false for any other source (so `abuseipdb`, `spamhaus`,
  `crowdstrike`, `emerging_threats`, `shield_realtime`, `maxmind_*` never react).

**Job.** `src/Jobs/RunAclReactionJob.php` (queued, `tries=3`,
`backoff=[10,30,60]`, `timeout=30`): resolves the named reaction, reloads the `Acl`,
re-checks `appliesTo`, calls `ban`/`unban`. Wraps the HTTP call; on permanent failure
(4xx other than 429) it logs and stops, on transient failure it throws to retry.
Audit-logs `reaction.cloudflare` / `reaction.abuseipdb` with the outcome.

**Trigger.** Extend `AclObserver`:

- `created($acl)`: `app(ReactionManager::class)->onBlock($acl)`.
- (Cache clearing stays as-is.)

**Unban reconciliation.** A scheduled command
`src/Console/Commands/ReactionsReconcileCommand.php` (`shield:reactions-reconcile`),
scheduled every minute next to the existing `crons.unblock_ips`:

- Finds ACL rows where `meta.reactions.cloudflare.rule_id` is set AND the row is no
  longer an active block (`expires_at <= now()` OR soft-deleted).
- Calls `ReactionManager->onUnblock($acl)` for each, which dispatches the Cloudflare
  delete and clears the marker.
- Bounded batch size per run (config `shield.reactions.reconcile_batch`, default 200).

This is the only safe way to reverse edge state, because ACL expiry is passive (the
evaluator just ignores expired rows; nothing deletes them at the instant they expire).

### 4.2 Form input-trap (gap 4)

Mirrors the proven Spatie laravel-honeypot shape, integrated with Shield's scoring.

- **Blade component** `src/View/Components/Honeypot.php` + view
  `resources/views/components/honeypot.blade.php`, registered as `x-shield-honeypot`.
  Plus a `@shieldHoneypot` Blade directive shortcut. Renders:
  - a visually-hidden wrapper (`<div style="display:none">`) containing a text input
    named `shield.honeypot.name_field` (default `shield_hp`, optionally randomised
    per-render with the real name stored in an encrypted companion field);
  - an encrypted-timestamp field named `shield.honeypot.valid_from_field` (default
    `shield_hp_time`) carrying `Crypt::encryptString((string) now()->timestamp)`.
- **Middleware** `src/Http/Middleware/ProtectAgainstSpam.php`, alias
  `shield.honeypot-form`. On non-GET requests it trips when:
  - the name field is present and non-empty, OR
  - the decrypted timestamp is younger than `shield.honeypot.form.min_time_seconds`
    (default 1) or older than `max_time_seconds` (default 3600, stale form), OR
  - the timestamp field is missing/undecryptable (when `require_timestamp` is true).
- **On trip** (per design decision: silent discard + escalate):
  - Do not process the request. Respond per `shield.honeypot.form.response`:
    `redirect_back` (default, 302 back with old input dropped) or `ok` (200 empty) or
    a configurable status.
  - `app(SuspicionScorer::class)->bump($request->ip(), config('shield.honeypot.form.score'))`
    (default 50). Repeat trips cross the scoring threshold and auto-block via the
    existing path (source=`scoring`), which then drives the reaction layer.
  - `AuditLogger->log('honeypot.form_trap', ...)` with severity `medium`, the IP, the
    matched reason (`filled` | `too_fast` | `stale` | `tampered`), redacted.
- **Validation rule** `src/Rules/ShieldHoneypot.php` (`implements ValidationRule`) so
  Livewire/manual forms can `'shield_hp' => new ShieldHoneypot` without the middleware.
  Same checks, same escalation, returns a generic failure message.

Real-user safety: the trap only ever escalates the score; it never hard-blocks on a
single trip, so a password-manager autofill of a hidden field cannot instantly ban a
legitimate visitor.

### 4.3 Regex honeypot paths (gap 3)

- Extract the trap body of `HoneypotController::trap` into a shared action
  `src/Services/Honeypot/HoneypotTrap.php` (`handle(Request, string $matchedPath): never`)
  that does the audit log + ACL block + `abort(404)`. The controller becomes a thin
  caller; behaviour is unchanged.
- Add `src/Firewall/Middleware/HoneypotRegex.php`, registered in the firewall
  middleware group when `shield.honeypot.enabled`. It matches `request()->path()`
  against each pattern in `shield.honeypot.regex_paths` (full PCRE strings, e.g.
  `'#^\.env#i'`, `'#wp-config\.php$#i'`). First match calls `HoneypotTrap->handle()`.
- Block source for regex hits is `honeypot` (same as route hits), so reactions apply.
- Invalid patterns are validated at boot (skipped + logged once) so a bad config entry
  cannot 500 every request.

### 4.4 Interactive CLI (gap 5)

- `src/Console/Commands/AclManageCommand.php` (`shield:acl`), menu-driven with
  `laravel/prompts` (already a Laravel 11+ dependency):
  - **List** active blocks (paginated table: ip, source, reason, expires_at,
    cf_rule_id present?).
  - **Ban** an IP: prompt value + duration (1h / 24h / 7d / 30d / permanent) ->
    `Acl::create(source='manual')`. Because `manual` is in `self_detected_sources`,
    the observer fires the reactions automatically (no special-casing).
  - **Unban** an IP: soft-delete matching active ACL rows -> reconcile/onUnblock
    removes the Cloudflare rule.
  - **Search** by IP. **Stats** (totals by source, active vs expired, reported count).
- Non-interactive flags retained for scripting: `--list`, `--ban=IP`, `--unban=IP`,
  `--hours=N`.

---

## 5. Files to touch

**New**

- `src/Contracts/AclReaction.php`
- `src/Services/Reactions/ReactionManager.php`
- `src/Services/Reactions/CloudflareClient.php`
- `src/Services/Reactions/CloudflareReaction.php`
- `src/Services/Reactions/AbuseIpDbReportReaction.php`
- `src/Jobs/RunAclReactionJob.php`
- `src/Console/Commands/ReactionsReconcileCommand.php`
- `src/Console/Commands/AclManageCommand.php`
- `src/Services/Honeypot/HoneypotTrap.php`
- `src/Firewall/Middleware/HoneypotRegex.php`
- `src/Http/Middleware/ProtectAgainstSpam.php`
- `src/View/Components/Honeypot.php`
- `resources/views/components/honeypot.blade.php`
- `src/Rules/ShieldHoneypot.php`

**Modified**

- `src/Observers/AclObserver.php` - dispatch reactions on `created`.
- `src/Http/Controllers/HoneypotController.php` - delegate to `HoneypotTrap`.
- `src/ShieldServiceProvider.php` - bind `ReactionManager` + reactions, register the
  regex middleware in the firewall group, register `x-shield-honeypot` component,
  `@shieldHoneypot` directive, the two new commands, schedule
  `shield:reactions-reconcile`, add reaction status to `php artisan about`.
- `config/shield.php` - new `reactions` block + `honeypot.form` + `honeypot.regex_paths`.
- `database/seeders/LookupTableSeeder.php` - add audit kinds `honeypot.form_trap`,
  `reaction.cloudflare`, `reaction.abuseipdb`.

---

## 6. Config / env

```php
// config/shield.php

'reactions' => [
    'self_detected_sources' => ['honeypot', 'honeypot_form', 'waf', 'scoring', 'auth', 'manual'],
    'reconcile_batch' => 200,

    'cloudflare' => [
        'enabled'    => env('LS_CLOUDFLARE_ENABLED', false),
        'api_token'  => env('LS_CLOUDFLARE_API_TOKEN'),
        'zone_id'    => env('LS_CLOUDFLARE_ZONE_ID'),
        'account_id' => env('LS_CLOUDFLARE_ACCOUNT_ID'), // used when zone_id is empty
        'note_category' => env('LS_CLOUDFLARE_NOTE_CATEGORY', 'shield-block'),
    ],

    'abuseipdb_report' => [
        'enabled'      => env('LS_ABUSEIPDB_REPORT_ENABLED', false),
        'api_key'      => env('LS_ABUSEIPDB_KEY'), // shared with the existing consumer
        'categories'   => [21, 19],                // Web App Attack, Bad Web Bot
        'max_age_days' => 30,
    ],
],

'honeypot' => [
    // ...existing keys...
    'regex_paths' => [
        // '#^\.env#i', '#wp-config\.php$#i', '#\.git(/|$)#i',
    ],
    'form' => [
        'enabled'          => env('LS_HONEYPOT_FORM_ENABLED', false),
        'name_field'       => 'shield_hp',
        'valid_from_field' => 'shield_hp_time',
        'randomize'        => false,
        'require_timestamp'=> true,
        'min_time_seconds' => 1,
        'max_time_seconds' => 3600,
        'response'         => 'redirect_back', // redirect_back | ok | <int status>
        'score'            => 50,
    ],
],
```

Secrets (`LS_CLOUDFLARE_API_TOKEN`, `LS_ABUSEIPDB_KEY`) are never logged and are
covered by the standard sensitive-field redaction list. The AbuseIPDB key is reused
from the existing consumer config to avoid two keys.

---

## 7. Acceptance criteria

1. Creating an `Acl` block with source `honeypot` and Cloudflare enabled dispatches a
   `RunAclReactionJob` that POSTs one access rule and stores the rule id in `meta`.
2. The same with a feed source (`abuseipdb`, `spamhaus`, ...) dispatches **nothing**.
3. When that block expires, `shield:reactions-reconcile` dispatches exactly one
   Cloudflare DELETE for the stored rule id and clears the marker.
4. AbuseIPDB reporting POSTs `/report` once per IP, skips already-reported IPs and IPs
   older than `max_age_days`, and never reports private/reserved IPs.
5. With all credentials absent, blocking an IP produces no HTTP calls, no exceptions,
   and the local block still works.
6. `<x-shield-honeypot />` renders a hidden named field + an encrypted timestamp field.
7. A POST with the hidden field filled, or submitted under `min_time_seconds`, is
   discarded per `response`, bumps the IP score, and audit-logs `honeypot.form_trap`;
   a clean POST passes through untouched.
8. Crossing the scoring threshold via repeated form trips auto-blocks the IP
   (source=`scoring`) and that block drives the reaction layer.
9. A request to a path matching a `regex_paths` entry is 404'd, audit-logged, and
   ACL-blocked identically to a route honeypot hit.
10. `shield:acl` lists/bans/unbans interactively; a manual ban triggers reactions and a
    manual unban removes the Cloudflare rule.

---

## 8. Test plan (Pest)

- `ReactionManager`: source allowlist (self-detected react, feed sources do not);
  disabled/missing-credential reactions are skipped.
- `CloudflareReaction` ban/unban with `Http::fake()`: rule id persisted, DELETE uses
  the stored id, account-level fallback path, 429 retries vs 4xx stops.
- `AbuseIpDbReportReaction` with `Http::fake()`: report once, dedupe, max-age skip,
  private-IP skip.
- `RunAclReactionJob`: re-checks `appliesTo` on run, retry/backoff config.
- `AclObserver`: `created` block dispatches; non-block / feed-source does not
  (`Bus::fake()`).
- `ReactionsReconcileCommand`: only expired/deleted rows with a rule id reconcile;
  batch bound respected.
- `ProtectAgainstSpam` middleware + `ShieldHoneypot` rule: filled/too-fast/stale/clean
  cases, response modes, score bump (`SuspicionScorer` spy), audit entry.
- `HoneypotRegex` middleware: match -> 404 + block; invalid pattern skipped, not fatal.
- `HoneypotTrap` shared action: controller and middleware produce identical effects.
- `AclManageCommand`: list/ban/unban/search via prompts test helpers; manual ban
  reaction dispatch.

---

## 9. Open risks / notes

- Cloudflare's legacy IP Access Rules API is still supported but is the older surface;
  the `AclReaction` interface isolates it so a WAF-IP-List driver can be added later
  without touching the observer, manager, or callers.
- The reconcile cron is the failure-tolerant path for edge cleanup; if a DELETE fails
  permanently the marker is retained so the next run retries (no silent orphan).
- Form-trap randomised field names require storing the real name in an encrypted
  companion field so the middleware can recover it; default ships randomisation OFF
  for the simplest correct behaviour, ON is opt-in.
