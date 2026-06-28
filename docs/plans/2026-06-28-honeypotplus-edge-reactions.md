# HoneypotPlus Edge Reactions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Absorb every feature of the MIT `laravel-honeypotplus` package that Shield lacks: Cloudflare edge ban/unban and AbuseIPDB outbound reporting (as a generic ACL reaction layer), regex honeypot paths, a form-field input-trap, and an interactive `shield:acl` CLI.

**Architecture:** A pluggable `AclReaction` layer fires from `AclObserver::created` whenever a *self-detected* IP block lands in `ls_acl`, dispatching queued jobs that push the IP to Cloudflare and/or report it to AbuseIPDB. Edge unban happens via a scheduled reconcile command (ACL expiry is passive). The form trap reuses the existing `SuspicionScorer` for escalation; regex paths reuse a shared `HoneypotTrap` action.

**Tech Stack:** PHP 8.3+, Laravel 11/12, Eloquent, queued jobs, `Illuminate\Support\Facades\Http`, PHPUnit + Orchestra Testbench (NOT Pest), sqlite in-memory for tests.

---

## Conventions (read before starting)

- **Tests are PHPUnit**, class-based, extending `OzanKurt\Shield\Tests\TestCase` (`tests/TestCase.php`). Methods are `public function testXxx()`. The base `TestCase` boots Testbench, sqlite `:memory:`, runs `LookupTableSeeder` + `BuiltinWafRuleSeeder`, and loads `config/shield.php`. Run a single test with: `vendor/bin/phpunit --filter testName`.
- **Audit kinds must exist before use.** `AuditLogger::log($kind, ...)` throws `InvalidArgumentException` for unknown kinds. New kinds are added to `database/seeders/LookupTableSeeder.php` (the `$kinds` array around line 171).
- **ACL block source is a free-text string** on `ls_acl.source` (no lookup). Reaction state is stored in the JSON `meta` column. No migrations in this plan.
- **Reactions never throw to the request path.** All outbound HTTP happens inside queued jobs; missing credentials means the reaction reports `isEnabled() === false` and is skipped.
- Namespaces: app code `OzanKurt\Shield\...`, tests `OzanKurt\Shield\Tests\...`.
- Commit after every task with the message shown in the task's final step.

---

## File Structure

**New files**

| File | Responsibility |
|---|---|
| `src/Contracts/AclReaction.php` | Interface for an outbound reaction (ban/unban an ACL entry). |
| `src/Services/Reactions/CloudflareClient.php` | Thin `Http::` wrapper for Cloudflare IP Access Rules. |
| `src/Services/Reactions/CloudflareReaction.php` | `AclReaction` that creates/deletes a Cloudflare block rule. |
| `src/Services/Reactions/AbuseIpDbReportReaction.php` | `AclReaction` that reports the IP to AbuseIPDB `/report`. |
| `src/Services/Reactions/ReactionManager.php` | Holds reactions, decides which fire, dispatches jobs. |
| `src/Jobs/RunAclReactionJob.php` | Queued runner for one reaction × one ACL × ban/unban. |
| `src/Console/Commands/ReactionsReconcileCommand.php` | `shield:reactions-reconcile` removes edge rules for expired blocks. |
| `src/Console/Commands/AclManageCommand.php` | `shield:acl` interactive ban/unban/list/search/stats. |
| `src/Services/Honeypot/HoneypotTrap.php` | Shared audit+block+404 action for honeypot hits. |
| `src/Firewall/Middleware/HoneypotRegex.php` | Matches request path against regex honeypot patterns. |
| `src/Http/Middleware/ProtectAgainstSpam.php` | Form input-trap enforcement middleware. |
| `src/View/Components/Honeypot.php` | `<x-shield-honeypot />` component (renders inline Blade). |
| `src/Rules/ShieldHoneypot.php` | Validation rule equivalent of the trap (for Livewire/manual forms). |

**Modified files**

| File | Change |
|---|---|
| `config/shield.php` | Add `reactions` block; add `honeypot.form` + `honeypot.regex_paths`. |
| `database/seeders/LookupTableSeeder.php` | Add audit kinds `honeypot.form_trap`, `reaction.cloudflare`, `reaction.abuseipdb`. |
| `src/Observers/AclObserver.php` | `created()` → `ReactionManager::onBlock`. |
| `src/Http/Controllers/HoneypotController.php` | Delegate to `HoneypotTrap`. |
| `src/ShieldServiceProvider.php` | Bind reactions; register regex middleware, component, directive, commands; schedule reconcile; add `about` line. |

---

## Task 1: Config + audit-kind seeding (foundation)

**Files:**
- Modify: `config/shield.php` (honeypot block ~line 736; append a new top-level `reactions` block)
- Modify: `database/seeders/LookupTableSeeder.php:171` (the `$kinds` array)
- Test: `tests/Feature/Reactions/ConfigAndSeedTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace OzanKurt\Shield\Tests\Feature\Reactions;

use OzanKurt\Shield\Models\Lookups\AuditLogKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Tests\TestCase;

class ConfigAndSeedTest extends TestCase
{
    public function testReactionConfigDefaultsExist()
    {
        $this->assertIsArray(config('shield.reactions.self_detected_sources'));
        $this->assertContains('honeypot', config('shield.reactions.self_detected_sources'));
        $this->assertFalse(config('shield.reactions.cloudflare.enabled'));
        $this->assertFalse(config('shield.reactions.abuseipdb_report.enabled'));
        $this->assertIsArray(config('shield.honeypot.regex_paths'));
        $this->assertSame('redirect_back', config('shield.honeypot.form.response'));
    }

    public function testNewAuditKindsSeeded()
    {
        $resolver = app(LookupResolver::class);
        $this->assertNotNull($resolver->id(AuditLogKind::class, 'honeypot.form_trap'));
        $this->assertNotNull($resolver->id(AuditLogKind::class, 'reaction.cloudflare'));
        $this->assertNotNull($resolver->id(AuditLogKind::class, 'reaction.abuseipdb'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ConfigAndSeedTest`
Expected: FAIL (config keys null, audit kinds not found).

- [ ] **Step 3: Add the `reactions` config block**

In `config/shield.php`, add this as a new top-level array entry (e.g. immediately before the closing `];` of the returned array):

```php
    'reactions' => [
        'self_detected_sources' => ['honeypot', 'honeypot_form', 'waf', 'scoring', 'auth', 'manual'],
        'reconcile_batch' => 200,

        'cloudflare' => [
            'enabled' => env('LS_CLOUDFLARE_ENABLED', false),
            'api_token' => env('LS_CLOUDFLARE_API_TOKEN'),
            'zone_id' => env('LS_CLOUDFLARE_ZONE_ID'),
            'account_id' => env('LS_CLOUDFLARE_ACCOUNT_ID'),
            'note_category' => env('LS_CLOUDFLARE_NOTE_CATEGORY', 'shield-block'),
        ],

        'abuseipdb_report' => [
            'enabled' => env('LS_ABUSEIPDB_REPORT_ENABLED', false),
            'api_key' => env('LS_ABUSEIPDB_KEY'),
            'categories' => [21, 19],
            'max_age_days' => 30,
        ],
    ],
```

- [ ] **Step 4: Add `form` + `regex_paths` to the existing `honeypot` block**

Inside the existing `'honeypot' => [ ... ]` array in `config/shield.php`, add these two keys (alongside `enabled`, `paths`, `block_duration`):

```php
        'regex_paths' => [
            // Full PCRE patterns, matched against request()->path():
            // '#^\.env#i', '#wp-config\.php$#i', '#\.git(/|$)#i',
        ],

        'form' => [
            'enabled' => env('LS_HONEYPOT_FORM_ENABLED', false),
            'name_field' => 'shield_hp',
            'valid_from_field' => 'shield_hp_time',
            'randomize' => false,
            'require_timestamp' => true,
            'min_time_seconds' => 1,
            'max_time_seconds' => 3600,
            'response' => 'redirect_back', // redirect_back | ok | <int status>
            'score' => 50,
        ],
```

- [ ] **Step 5: Seed the new audit kinds**

In `database/seeders/LookupTableSeeder.php`, add these three strings to the `$kinds` array (after the existing `'acl.added', 'acl.updated', 'acl.deleted', 'acl.expired',` line):

```php
            'honeypot.form_trap',
            'reaction.cloudflare', 'reaction.abuseipdb',
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter ConfigAndSeedTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add config/shield.php database/seeders/LookupTableSeeder.php tests/Feature/Reactions/ConfigAndSeedTest.php
git commit -m "feat(reactions): add reaction + form-trap config and audit kinds"
```

---

## Task 2: AclReaction contract + CloudflareClient

**Files:**
- Create: `src/Contracts/AclReaction.php`
- Create: `src/Services/Reactions/CloudflareClient.php`
- Test: `tests/Feature/Reactions/CloudflareClientTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace OzanKurt\Shield\Tests\Feature\Reactions;

use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Services\Reactions\CloudflareClient;
use OzanKurt\Shield\Tests\TestCase;

class CloudflareClientTest extends TestCase
{
    public function testCreateBlockRuleReturnsRuleId()
    {
        config(['shield.reactions.cloudflare.api_token' => 'tok']);
        config(['shield.reactions.cloudflare.zone_id' => 'zone123']);

        Http::fake([
            '*/zones/zone123/firewall/access_rules/rules' => Http::response([
                'success' => true,
                'result' => ['id' => 'rule_abc'],
            ], 200),
        ]);

        $id = app(CloudflareClient::class)->createBlockRule('203.0.113.7', 'shield-block: test');

        $this->assertSame('rule_abc', $id);
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/zones/zone123/firewall/access_rules/rules')
            && $r['mode'] === 'block'
            && $r['configuration']['value'] === '203.0.113.7');
    }

    public function testDeleteRuleReturnsTrueOnSuccess()
    {
        config(['shield.reactions.cloudflare.api_token' => 'tok']);
        config(['shield.reactions.cloudflare.zone_id' => 'zone123']);

        Http::fake([
            '*/access_rules/rules/rule_abc' => Http::response(['success' => true], 200),
        ]);

        $this->assertTrue(app(CloudflareClient::class)->deleteRule('rule_abc'));
    }

    public function testFailedCreateReturnsNull()
    {
        config(['shield.reactions.cloudflare.api_token' => 'tok']);
        config(['shield.reactions.cloudflare.zone_id' => 'zone123']);

        Http::fake(['*' => Http::response(['success' => false], 403)]);

        $this->assertNull(app(CloudflareClient::class)->createBlockRule('203.0.113.7', 'x'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter CloudflareClientTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Create the contract**

`src/Contracts/AclReaction.php`:

```php
<?php

namespace OzanKurt\Shield\Contracts;

use OzanKurt\Shield\Models\Acl;

interface AclReaction
{
    /** Stable machine name, e.g. 'cloudflare' or 'abuseipdb_report'. */
    public function name(): string;

    /** True when configured + credentials present. */
    public function isEnabled(): bool;

    /** Reaction-specific applicability (kind=ip, public IP, not already done...). */
    public function appliesTo(Acl $acl): bool;

    /** Perform the outbound side effect for a new block. */
    public function ban(Acl $acl): void;

    /** Reverse the side effect (no-op for one-shot reactions). */
    public function unban(Acl $acl): void;
}
```

- [ ] **Step 4: Create the CloudflareClient**

`src/Services/Reactions/CloudflareClient.php`:

```php
<?php

namespace OzanKurt\Shield\Services\Reactions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over the Cloudflare IP Access Rules API. Zone-scoped when a
 * zone_id is configured, otherwise account-scoped. Returns null/false on any
 * non-success so callers never see exceptions on the happy path; transient
 * failures are surfaced by returning null so the queued job can retry.
 */
class CloudflareClient
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    public function isConfigured(): bool
    {
        $token = (string) config('shield.reactions.cloudflare.api_token');
        $scope = $this->scopePath();

        return $token !== '' && $scope !== null;
    }

    /** @return string|null the created rule id, or null on failure */
    public function createBlockRule(string $ip, string $note): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $response = $this->http()->post($this->scopePath() . '/firewall/access_rules/rules', [
            'mode' => 'block',
            'configuration' => ['target' => 'ip', 'value' => $ip],
            'notes' => $note,
        ]);

        if (! $response->successful() || ! $response->json('success')) {
            Log::warning('Cloudflare access rule create failed', [
                'ip' => $ip, 'status' => $response->status(),
            ]);

            return null;
        }

        return $response->json('result.id');
    }

    public function deleteRule(string $ruleId): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $response = $this->http()->delete($this->scopePath() . '/firewall/access_rules/rules/' . $ruleId);

        return $response->successful() && (bool) $response->json('success');
    }

    private function http()
    {
        return Http::timeout(20)
            ->withToken((string) config('shield.reactions.cloudflare.api_token'))
            ->acceptJson();
    }

    /** Returns the base path segment for zone- or account-scoped rules, or null. */
    private function scopePath(): ?string
    {
        $zone = (string) config('shield.reactions.cloudflare.zone_id');
        if ($zone !== '') {
            return self::BASE . '/zones/' . $zone;
        }

        $account = (string) config('shield.reactions.cloudflare.account_id');
        if ($account !== '') {
            return self::BASE . '/accounts/' . $account;
        }

        return null;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter CloudflareClientTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Contracts/AclReaction.php src/Services/Reactions/CloudflareClient.php tests/Feature/Reactions/CloudflareClientTest.php
git commit -m "feat(reactions): add AclReaction contract + Cloudflare API client"
```

---

## Task 3: CloudflareReaction

**Files:**
- Create: `src/Services/Reactions/CloudflareReaction.php`
- Test: `tests/Feature/Reactions/CloudflareReactionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace OzanKurt\Shield\Tests\Feature\Reactions;

use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Services\Reactions\CloudflareClient;
use OzanKurt\Shield\Services\Reactions\CloudflareReaction;
use OzanKurt\Shield\Tests\TestCase;

class CloudflareReactionTest extends TestCase
{
    private function block(string $ip): Acl
    {
        $lookups = app(LookupResolver::class);

        return Acl::create([
            'kind_id' => $lookups->id(AclKind::class, 'ip'),
            'action_id' => $lookups->id(AclAction::class, 'block'),
            'value' => $ip,
            'source' => 'honeypot',
            'reason' => 'test',
        ]);
    }

    public function testBanStoresRuleIdInMeta()
    {
        config(['shield.reactions.cloudflare.enabled' => true]);

        $this->mock(CloudflareClient::class, function ($m) {
            $m->shouldReceive('isConfigured')->andReturn(true);
            $m->shouldReceive('createBlockRule')->once()->andReturn('rule_xyz');
        });

        $acl = $this->block('203.0.113.9');
        app(CloudflareReaction::class)->ban($acl->fresh());

        $this->assertSame('rule_xyz', $acl->fresh()->meta['reactions']['cloudflare']['rule_id']);
    }

    public function testUnbanDeletesStoredRuleAndClearsMarker()
    {
        config(['shield.reactions.cloudflare.enabled' => true]);

        $this->mock(CloudflareClient::class, function ($m) {
            $m->shouldReceive('isConfigured')->andReturn(true);
            $m->shouldReceive('deleteRule')->once()->with('rule_xyz')->andReturn(true);
        });

        $acl = $this->block('203.0.113.9');
        $acl->update(['meta' => ['reactions' => ['cloudflare' => ['rule_id' => 'rule_xyz']]]]);

        app(CloudflareReaction::class)->unban($acl->fresh());

        $this->assertArrayNotHasKey('cloudflare', $acl->fresh()->meta['reactions'] ?? []);
    }

    public function testDoesNotApplyToPrivateIp()
    {
        config(['shield.reactions.cloudflare.enabled' => true]);
        $acl = $this->block('10.0.0.1');
        $this->assertFalse(app(CloudflareReaction::class)->appliesTo($acl));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter CloudflareReactionTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Create the reaction**

`src/Services/Reactions/CloudflareReaction.php`:

```php
<?php

namespace OzanKurt\Shield\Services\Reactions;

use OzanKurt\Shield\Contracts\AclReaction;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

class CloudflareReaction implements AclReaction
{
    public function __construct(
        private CloudflareClient $client,
        private LookupResolver $lookups,
    ) {}

    public function name(): string
    {
        return 'cloudflare';
    }

    public function isEnabled(): bool
    {
        return (bool) config('shield.reactions.cloudflare.enabled', false)
            && $this->client->isConfigured();
    }

    public function appliesTo(Acl $acl): bool
    {
        if ($acl->kind_id !== $this->lookups->id(AclKind::class, 'ip')) {
            return false;
        }
        if ($acl->action_id !== $this->lookups->id(AclAction::class, 'block')) {
            return false;
        }

        return $this->isPublicIp((string) $acl->value);
    }

    public function ban(Acl $acl): void
    {
        if (! empty($acl->meta['reactions']['cloudflare']['rule_id'])) {
            return; // already pushed
        }

        $note = config('shield.reactions.cloudflare.note_category', 'shield-block')
            . ': ' . (string) $acl->reason;

        $ruleId = $this->client->createBlockRule((string) $acl->value, $note);

        if ($ruleId === null) {
            throw new \RuntimeException('Cloudflare rule create failed (will retry)');
        }

        $this->setMeta($acl, ['rule_id' => $ruleId, 'created_at' => now()->toIso8601String()]);
    }

    public function unban(Acl $acl): void
    {
        $ruleId = $acl->meta['reactions']['cloudflare']['rule_id'] ?? null;
        if ($ruleId === null) {
            return;
        }

        if (! $this->client->deleteRule((string) $ruleId)) {
            throw new \RuntimeException('Cloudflare rule delete failed (will retry)');
        }

        $this->clearMeta($acl);
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function setMeta(Acl $acl, array $data): void
    {
        $meta = $acl->meta ?? [];
        $meta['reactions']['cloudflare'] = $data;
        $acl->update(['meta' => $meta]);
    }

    private function clearMeta(Acl $acl): void
    {
        $meta = $acl->meta ?? [];
        unset($meta['reactions']['cloudflare']);
        $acl->update(['meta' => $meta]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter CloudflareReactionTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Reactions/CloudflareReaction.php tests/Feature/Reactions/CloudflareReactionTest.php
git commit -m "feat(reactions): add Cloudflare edge ban/unban reaction"
```

---

## Task 4: AbuseIpDbReportReaction

**Files:**
- Create: `src/Services/Reactions/AbuseIpDbReportReaction.php`
- Test: `tests/Feature/Reactions/AbuseIpDbReportReactionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace OzanKurt\Shield\Tests\Feature\Reactions;

use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Services\Reactions\AbuseIpDbReportReaction;
use OzanKurt\Shield\Tests\TestCase;

class AbuseIpDbReportReactionTest extends TestCase
{
    private function block(string $ip, array $meta = []): Acl
    {
        $lookups = app(LookupResolver::class);

        return Acl::create([
            'kind_id' => $lookups->id(AclKind::class, 'ip'),
            'action_id' => $lookups->id(AclAction::class, 'block'),
            'value' => $ip,
            'source' => 'honeypot',
            'reason' => 'Hit honeypot path: /.env',
            'meta' => $meta,
        ]);
    }

    public function testBanReportsAndRecordsTimestamp()
    {
        config(['shield.reactions.abuseipdb_report.enabled' => true]);
        config(['shield.reactions.abuseipdb_report.api_key' => 'k']);
        Http::fake(['*/report' => Http::response(['data' => ['abuseConfidenceScore' => 100]], 200)]);

        $acl = $this->block('203.0.113.20');
        app(AbuseIpDbReportReaction::class)->ban($acl->fresh());

        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/v2/report')
            && $r['ip'] === '203.0.113.20');
        $this->assertNotNull($acl->fresh()->meta['reactions']['abuseipdb']['reported_at']);
    }

    public function testAlreadyReportedDoesNotApply()
    {
        config(['shield.reactions.abuseipdb_report.enabled' => true]);
        config(['shield.reactions.abuseipdb_report.api_key' => 'k']);

        $acl = $this->block('203.0.113.20', ['reactions' => ['abuseipdb' => ['reported_at' => '2026-01-01T00:00:00+00:00']]]);
        $this->assertFalse(app(AbuseIpDbReportReaction::class)->appliesTo($acl));
    }

    public function testPrivateIpDoesNotApply()
    {
        config(['shield.reactions.abuseipdb_report.enabled' => true]);
        config(['shield.reactions.abuseipdb_report.api_key' => 'k']);
        $this->assertFalse(app(AbuseIpDbReportReaction::class)->appliesTo($this->block('192.168.1.5')));
    }

    public function testStaleBlockDoesNotApply()
    {
        config(['shield.reactions.abuseipdb_report.enabled' => true]);
        config(['shield.reactions.abuseipdb_report.api_key' => 'k']);
        config(['shield.reactions.abuseipdb_report.max_age_days' => 30]);

        $acl = $this->block('203.0.113.21');
        $acl->forceFill(['created_at' => now()->subDays(45)])->save();

        $this->assertFalse(app(AbuseIpDbReportReaction::class)->appliesTo($acl->fresh()));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter AbuseIpDbReportReactionTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Create the reaction**

`src/Services/Reactions/AbuseIpDbReportReaction.php`:

```php
<?php

namespace OzanKurt\Shield\Services\Reactions;

use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Contracts\AclReaction;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

class AbuseIpDbReportReaction implements AclReaction
{
    private const ENDPOINT = 'https://api.abuseipdb.com/api/v2/report';

    public function __construct(private LookupResolver $lookups) {}

    public function name(): string
    {
        return 'abuseipdb_report';
    }

    public function isEnabled(): bool
    {
        return (bool) config('shield.reactions.abuseipdb_report.enabled', false)
            && ! empty(config('shield.reactions.abuseipdb_report.api_key'));
    }

    public function appliesTo(Acl $acl): bool
    {
        if ($acl->kind_id !== $this->lookups->id(AclKind::class, 'ip')) {
            return false;
        }
        if (! empty($acl->meta['reactions']['abuseipdb']['reported_at'])) {
            return false; // already reported (dedupe)
        }
        if (! $this->isPublicIp((string) $acl->value)) {
            return false;
        }

        $maxAge = (int) config('shield.reactions.abuseipdb_report.max_age_days', 30);

        return $acl->created_at === null || $acl->created_at->gt(now()->subDays($maxAge));
    }

    public function ban(Acl $acl): void
    {
        $response = Http::timeout(20)
            ->withHeaders(['Key' => (string) config('shield.reactions.abuseipdb_report.api_key'), 'Accept' => 'application/json'])
            ->asForm()
            ->post(self::ENDPOINT, [
                'ip' => (string) $acl->value,
                'categories' => implode(',', (array) config('shield.reactions.abuseipdb_report.categories', [21, 19])),
                'comment' => $this->comment($acl),
            ]);

        if ($response->status() === 429) {
            throw new \RuntimeException('AbuseIPDB rate-limited (will retry)');
        }
        if (! $response->successful()) {
            // 4xx (e.g. duplicate within 15 min, bad key) → permanent; mark + stop.
            $this->mark($acl);

            return;
        }

        $this->mark($acl);
    }

    public function unban(Acl $acl): void
    {
        // Community reports are permanent; nothing to reverse.
    }

    private function comment(Acl $acl): string
    {
        // Reason only, no secrets. Keep it generic + short.
        return 'Laravel Shield: ' . substr((string) $acl->reason, 0, 200);
    }

    private function mark(Acl $acl): void
    {
        $meta = $acl->meta ?? [];
        $meta['reactions']['abuseipdb'] = ['reported_at' => now()->toIso8601String()];
        $acl->update(['meta' => $meta]);
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter AbuseIpDbReportReactionTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Reactions/AbuseIpDbReportReaction.php tests/Feature/Reactions/AbuseIpDbReportReactionTest.php
git commit -m "feat(reactions): add AbuseIPDB outbound reporting reaction"
```

---

## Task 5: ReactionManager + RunAclReactionJob

**Files:**
- Create: `src/Services/Reactions/ReactionManager.php`
- Create: `src/Jobs/RunAclReactionJob.php`
- Test: `tests/Feature/Reactions/ReactionManagerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace OzanKurt\Shield\Tests\Feature\Reactions;

use Illuminate\Support\Facades\Bus;
use OzanKurt\Shield\Jobs\RunAclReactionJob;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Services\Reactions\ReactionManager;
use OzanKurt\Shield\Tests\TestCase;

class ReactionManagerTest extends TestCase
{
    private function block(string $source): Acl
    {
        $lookups = app(LookupResolver::class);

        return Acl::create([
            'kind_id' => $lookups->id(AclKind::class, 'ip'),
            'action_id' => $lookups->id(AclAction::class, 'block'),
            'value' => '203.0.113.30',
            'source' => $source,
            'reason' => 'test',
        ]);
    }

    public function testSelfDetectedSourceDispatchesEnabledReactions()
    {
        Bus::fake();
        config(['shield.reactions.cloudflare.enabled' => true]);
        config(['shield.reactions.cloudflare.api_token' => 'tok']);
        config(['shield.reactions.cloudflare.zone_id' => 'z']);

        app(ReactionManager::class)->onBlock($this->block('honeypot'));

        Bus::assertDispatched(RunAclReactionJob::class, fn ($j) => $j->reactionName === 'cloudflare' && $j->op === 'ban');
    }

    public function testFeedSourceDispatchesNothing()
    {
        Bus::fake();
        config(['shield.reactions.cloudflare.enabled' => true]);
        config(['shield.reactions.cloudflare.api_token' => 'tok']);
        config(['shield.reactions.cloudflare.zone_id' => 'z']);

        app(ReactionManager::class)->onBlock($this->block('abuseipdb'));

        Bus::assertNotDispatched(RunAclReactionJob::class);
    }

    public function testDisabledReactionNotDispatched()
    {
        Bus::fake();
        config(['shield.reactions.cloudflare.enabled' => false]);

        app(ReactionManager::class)->onBlock($this->block('honeypot'));

        Bus::assertNotDispatched(RunAclReactionJob::class);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ReactionManagerTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Create the ReactionManager**

`src/Services/Reactions/ReactionManager.php`:

```php
<?php

namespace OzanKurt\Shield\Services\Reactions;

use OzanKurt\Shield\Contracts\AclReaction;
use OzanKurt\Shield\Jobs\RunAclReactionJob;
use OzanKurt\Shield\Models\Acl;

class ReactionManager
{
    /** @param array<int,AclReaction> $reactions */
    public function __construct(private array $reactions = []) {}

    /** @return array<int,AclReaction> */
    public function reactions(): array
    {
        return $this->reactions;
    }

    public function get(string $name): ?AclReaction
    {
        foreach ($this->reactions as $reaction) {
            if ($reaction->name() === $name) {
                return $reaction;
            }
        }

        return null;
    }

    public function onBlock(Acl $acl): void
    {
        if (! $this->sourceAllowed($acl)) {
            return;
        }

        foreach ($this->reactions as $reaction) {
            if ($reaction->isEnabled() && $reaction->appliesTo($acl)) {
                RunAclReactionJob::dispatch($reaction->name(), $acl->getKey(), 'ban')->afterCommit();
            }
        }
    }

    public function onUnblock(Acl $acl): void
    {
        foreach ($this->reactions as $reaction) {
            if ($reaction->isEnabled()) {
                RunAclReactionJob::dispatch($reaction->name(), $acl->getKey(), 'unban')->afterCommit();
            }
        }
    }

    public function sourceAllowed(Acl $acl): bool
    {
        $allowed = (array) config('shield.reactions.self_detected_sources', []);

        return in_array($acl->source, $allowed, true);
    }
}
```

- [ ] **Step 4: Create the job**

`src/Jobs/RunAclReactionJob.php`:

```php
<?php

namespace OzanKurt\Shield\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use OzanKurt\Shield\Services\Reactions\ReactionManager;

class RunAclReactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

    /** @return array<int,int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(
        public string $reactionName,
        public int $aclId,
        public string $op, // 'ban' | 'unban'
    ) {}

    public function handle(ReactionManager $manager, AuditLogger $audit): void
    {
        $reaction = $manager->get($this->reactionName);
        $acl = Acl::find($this->aclId);

        if ($reaction === null || $acl === null || ! $reaction->isEnabled()) {
            return;
        }

        if ($this->op === 'ban') {
            if (! $manager->sourceAllowed($acl) || ! $reaction->appliesTo($acl)) {
                return;
            }
            $reaction->ban($acl);
        } else {
            $reaction->unban($acl);
        }

        $kind = $this->reactionName === 'cloudflare' ? 'reaction.cloudflare' : 'reaction.abuseipdb';
        $audit->log($kind, ucfirst($this->op) . " via {$this->reactionName}: {$acl->value}", [
            'severity' => 'low',
            'ip' => (string) $acl->value,
            'meta' => ['op' => $this->op, 'acl_id' => $acl->getKey()],
        ]);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter ReactionManagerTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Services/Reactions/ReactionManager.php src/Jobs/RunAclReactionJob.php tests/Feature/Reactions/ReactionManagerTest.php
git commit -m "feat(reactions): add ReactionManager + queued RunAclReactionJob"
```

---

## Task 6: Wire reactions into AclObserver + bind in provider

**Files:**
- Modify: `src/Observers/AclObserver.php`
- Modify: `src/ShieldServiceProvider.php` (register block ~line 42-85: bind `ReactionManager`)
- Test: `tests/Feature/Reactions/AclObserverReactionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace OzanKurt\Shield\Tests\Feature\Reactions;

use Illuminate\Support\Facades\Bus;
use OzanKurt\Shield\Jobs\RunAclReactionJob;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Tests\TestCase;

class AclObserverReactionTest extends TestCase
{
    public function testCreatingHoneypotBlockDispatchesReaction()
    {
        Bus::fake();
        config(['shield.reactions.cloudflare.enabled' => true]);
        config(['shield.reactions.cloudflare.api_token' => 'tok']);
        config(['shield.reactions.cloudflare.zone_id' => 'z']);

        $lookups = app(LookupResolver::class);
        Acl::create([
            'kind_id' => $lookups->id(AclKind::class, 'ip'),
            'action_id' => $lookups->id(AclAction::class, 'block'),
            'value' => '203.0.113.40',
            'source' => 'honeypot',
            'reason' => 'test',
        ]);

        Bus::assertDispatched(RunAclReactionJob::class);
    }

    public function testCreatingFeedBlockDispatchesNothing()
    {
        Bus::fake();
        config(['shield.reactions.cloudflare.enabled' => true]);
        config(['shield.reactions.cloudflare.api_token' => 'tok']);
        config(['shield.reactions.cloudflare.zone_id' => 'z']);

        $lookups = app(LookupResolver::class);
        Acl::create([
            'kind_id' => $lookups->id(AclKind::class, 'ip'),
            'action_id' => $lookups->id(AclAction::class, 'block'),
            'value' => '203.0.113.41',
            'source' => 'spamhaus',
            'reason' => 'test',
        ]);

        Bus::assertNotDispatched(RunAclReactionJob::class);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter AclObserverReactionTest`
Expected: FAIL (no reaction dispatched; `ReactionManager` not bound / observer not wired).

- [ ] **Step 3: Bind ReactionManager in the provider**

In `src/ShieldServiceProvider.php`, inside `register()` near the other singletons (around line 53), add:

```php
        $this->app->singleton(\OzanKurt\Shield\Services\Reactions\CloudflareClient::class);

        $this->app->singleton(\OzanKurt\Shield\Services\Reactions\ReactionManager::class, function ($app) {
            return new \OzanKurt\Shield\Services\Reactions\ReactionManager([
                $app->make(\OzanKurt\Shield\Services\Reactions\CloudflareReaction::class),
                $app->make(\OzanKurt\Shield\Services\Reactions\AbuseIpDbReportReaction::class),
            ]);
        });
```

- [ ] **Step 4: Wire the observer**

Replace the body of `src/Observers/AclObserver.php` with:

```php
<?php

namespace OzanKurt\Shield\Observers;

use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Services\Acl\AclEvaluator;
use OzanKurt\Shield\Services\Reactions\ReactionManager;

class AclObserver
{
    public function __construct(
        private AclEvaluator $evaluator,
        private ReactionManager $reactions,
    ) {}

    public function created(Acl $acl): void
    {
        $this->reactions->onBlock($acl);
    }

    public function saved(Acl $acl): void
    {
        $this->evaluator->clearCache();
    }

    public function deleted(Acl $acl): void
    {
        $this->evaluator->clearCache();
    }

    public function restored(Acl $acl): void
    {
        $this->evaluator->clearCache();
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter AclObserverReactionTest`
Expected: PASS.

- [ ] **Step 6: Run the full reactions suite to check nothing regressed**

Run: `vendor/bin/phpunit --filter Reaction`
Expected: PASS (all reaction tests green).

- [ ] **Step 7: Commit**

```bash
git add src/Observers/AclObserver.php src/ShieldServiceProvider.php tests/Feature/Reactions/AclObserverReactionTest.php
git commit -m "feat(reactions): fire reactions from AclObserver on block creation"
```

---

## Task 7: ReactionsReconcileCommand (edge unban) + schedule

**Files:**
- Create: `src/Console/Commands/ReactionsReconcileCommand.php`
- Modify: `src/ShieldServiceProvider.php` (`registerCommands()` ~line 277; `booted()` schedule block ~line 307)
- Test: `tests/Feature/Reactions/ReactionsReconcileCommandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace OzanKurt\Shield\Tests\Feature\Reactions;

use Illuminate\Support\Facades\Bus;
use OzanKurt\Shield\Jobs\RunAclReactionJob;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Tests\TestCase;

class ReactionsReconcileCommandTest extends TestCase
{
    private function block(string $ip, ?string $ruleId, $expiresAt): Acl
    {
        $lookups = app(LookupResolver::class);

        return Acl::create([
            'kind_id' => $lookups->id(AclKind::class, 'ip'),
            'action_id' => $lookups->id(AclAction::class, 'block'),
            'value' => $ip,
            'source' => 'honeypot',
            'reason' => 'test',
            'expires_at' => $expiresAt,
            'meta' => $ruleId ? ['reactions' => ['cloudflare' => ['rule_id' => $ruleId]]] : [],
        ]);
    }

    public function testExpiredBlockWithRuleIdReconciles()
    {
        Bus::fake();
        $this->block('203.0.113.50', 'rule_1', now()->subMinute()); // expired + has rule

        $this->artisan('shield:reactions-reconcile')->assertExitCode(0);

        Bus::assertDispatched(RunAclReactionJob::class, fn ($j) => $j->op === 'unban');
    }

    public function testActiveBlockWithRuleIdDoesNotReconcile()
    {
        Bus::fake();
        $this->block('203.0.113.51', 'rule_2', now()->addHour()); // active

        $this->artisan('shield:reactions-reconcile')->assertExitCode(0);

        Bus::assertNotDispatched(RunAclReactionJob::class);
    }

    public function testExpiredBlockWithoutRuleIdDoesNotReconcile()
    {
        Bus::fake();
        $this->block('203.0.113.52', null, now()->subMinute()); // expired, no edge rule

        $this->artisan('shield:reactions-reconcile')->assertExitCode(0);

        Bus::assertNotDispatched(RunAclReactionJob::class);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ReactionsReconcileCommandTest`
Expected: FAIL (command not registered).

- [ ] **Step 3: Create the command**

`src/Console/Commands/ReactionsReconcileCommand.php`:

```php
<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Services\Reactions\ReactionManager;

/**
 * Removes Cloudflare edge rules for ACL blocks that are no longer active.
 * ACL expiry is passive (the evaluator just ignores expired rows), so this
 * scheduled command is the only thing that reverses edge state. Bounded per
 * run by shield.reactions.reconcile_batch.
 */
class ReactionsReconcileCommand extends Command
{
    protected $signature = 'shield:reactions-reconcile';

    protected $description = 'Remove edge (Cloudflare) rules for expired/removed ACL blocks';

    public function handle(ReactionManager $manager): int
    {
        $batch = (int) config('shield.reactions.reconcile_batch', 200);

        // Rows that still carry a cloudflare rule id but are no longer an
        // active block: expired, OR soft-deleted (withTrashed catches those).
        $rows = Acl::withTrashed()
            ->whereNotNull('meta')
            ->where(function ($q) {
                $q->whereNotNull('deleted_at')
                  ->orWhere(function ($q2) {
                      $q2->whereNotNull('expires_at')->where('expires_at', '<=', now());
                  });
            })
            ->limit($batch)
            ->get()
            ->filter(fn (Acl $acl) => ! empty($acl->meta['reactions']['cloudflare']['rule_id']));

        foreach ($rows as $acl) {
            $manager->onUnblock($acl);
        }

        $this->info("Reconciled {$rows->count()} edge rule(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Register the command**

In `src/ShieldServiceProvider.php` `registerCommands()`, add:

```php
        $this->commands(\OzanKurt\Shield\Console\Commands\ReactionsReconcileCommand::class);
```

- [ ] **Step 5: Schedule it**

In `src/ShieldServiceProvider.php`, inside the `$this->app->booted(function () { ... })` block (alongside the `unblock_ips` schedule near line 308), add:

```php
            app(\Illuminate\Console\Scheduling\Schedule::class)
                ->command('shield:reactions-reconcile')
                ->everyMinute()
                ->withoutOverlapping();
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter ReactionsReconcileCommandTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Console/Commands/ReactionsReconcileCommand.php src/ShieldServiceProvider.php tests/Feature/Reactions/ReactionsReconcileCommandTest.php
git commit -m "feat(reactions): add shield:reactions-reconcile for edge unban"
```

---

## Task 8: HoneypotTrap action + refactor controller + regex middleware

**Files:**
- Create: `src/Services/Honeypot/HoneypotTrap.php`
- Modify: `src/Http/Controllers/HoneypotController.php`
- Create: `src/Firewall/Middleware/HoneypotRegex.php`
- Modify: `src/ShieldServiceProvider.php` (firewall group registration ~line 225; alias ~line 221)
- Test: `tests/Feature/Reactions/HoneypotRegexTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace OzanKurt\Shield\Tests\Feature\Reactions;

use Illuminate\Http\Request;
use OzanKurt\Shield\Firewall\Middleware\HoneypotRegex;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class HoneypotRegexTest extends TestCase
{
    public function testMatchingPathTrapsAndBlocks()
    {
        config(['shield.honeypot.enabled' => true]);
        config(['shield.honeypot.regex_paths' => ['#^\.env#i']]);

        $request = Request::create('/.env', 'GET');
        $request->server->set('REMOTE_ADDR', '203.0.113.60');

        $threw = false;
        try {
            (new HoneypotRegex())->handle($request, fn ($r) => 'next');
        } catch (HttpException $e) {
            $threw = true;
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertTrue($threw, 'Expected a 404 HttpException');
        $this->assertTrue(Acl::query()->where('value', '203.0.113.60')->where('source', 'honeypot')->exists());
    }

    public function testNonMatchingPathPassesThrough()
    {
        config(['shield.honeypot.enabled' => true]);
        config(['shield.honeypot.regex_paths' => ['#^\.env#i']]);

        $request = Request::create('/home', 'GET');
        $this->assertSame('next', (new HoneypotRegex())->handle($request, fn ($r) => 'next'));
    }

    public function testInvalidPatternIsSkippedNotFatal()
    {
        config(['shield.honeypot.enabled' => true]);
        config(['shield.honeypot.regex_paths' => ['#(unclosed']]); // invalid PCRE

        $request = Request::create('/home', 'GET');
        $this->assertSame('next', (new HoneypotRegex())->handle($request, fn ($r) => 'next'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter HoneypotRegexTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Create the shared HoneypotTrap action**

`src/Services/Honeypot/HoneypotTrap.php`:

```php
<?php

namespace OzanKurt\Shield\Services\Honeypot;

use Illuminate\Http\Request;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

/**
 * Shared honeypot trap: audit-log the hit, auto-block the source IP in the
 * ACL (source=honeypot), and 404. Used by both the route controller and the
 * regex middleware so behaviour is identical.
 */
class HoneypotTrap
{
    public function __construct(
        private LookupResolver $lookups,
        private AuditLogger $audit,
    ) {}

    public function handle(Request $request, string $matchedPath): void
    {
        $ip = $request->ip();
        $blockDuration = (int) config('shield.honeypot.block_duration', 86400);

        $this->audit->log('acl.added', "Honeypot hit: {$matchedPath}", [
            'severity' => 'high',
            'ip' => $ip,
            'url' => $request->fullUrl(),
            'meta' => ['honeypot_path' => $matchedPath, 'block_seconds' => $blockDuration],
        ]);

        $existing = Acl::query()
            ->where('value', $ip)
            ->where('kind_id', $this->lookups->id(AclKind::class, 'ip'))
            ->where('source', 'honeypot')
            ->first();

        if (! $existing) {
            Acl::create([
                'kind_id' => $this->lookups->id(AclKind::class, 'ip'),
                'action_id' => $this->lookups->id(AclAction::class, 'block'),
                'value' => $ip,
                'source' => 'honeypot',
                'reason' => "Hit honeypot path: {$matchedPath}",
                'expires_at' => now()->addSeconds($blockDuration),
            ]);
        }

        abort(404);
    }
}
```

- [ ] **Step 4: Refactor the controller to delegate**

Replace `src/Http/Controllers/HoneypotController.php` with:

```php
<?php

namespace OzanKurt\Shield\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OzanKurt\Shield\Services\Honeypot\HoneypotTrap;

class HoneypotController extends Controller
{
    public function trap(Request $request, HoneypotTrap $trap)
    {
        $trap->handle($request, $request->path());
    }
}
```

- [ ] **Step 5: Create the regex middleware**

`src/Firewall/Middleware/HoneypotRegex.php`:

```php
<?php

namespace OzanKurt\Shield\Firewall\Middleware;

use Closure;
use Illuminate\Http\Request;
use OzanKurt\Shield\Services\Honeypot\HoneypotTrap;

class HoneypotRegex
{
    public function handle(Request $request, Closure $next)
    {
        if (! config('shield.honeypot.enabled', false)) {
            return $next($request);
        }

        $path = $request->path(); // no leading slash
        $candidate = '/' . ltrim($path, '/');

        foreach ((array) config('shield.honeypot.regex_paths', []) as $pattern) {
            // Invalid patterns must never 500 the request; @preg_match returns
            // false on a bad pattern, which we treat as "no match".
            if (@preg_match($pattern, $path) === 1 || @preg_match($pattern, $candidate) === 1) {
                app(HoneypotTrap::class)->handle($request, $path);
            }
        }

        return $next($request);
    }
}
```

- [ ] **Step 6: Register the middleware in the firewall group**

In `src/ShieldServiceProvider.php`, add an alias near the other firewall aliases (~line 221):

```php
        $router->aliasMiddleware('firewall.honeypot_regex', \OzanKurt\Shield\Firewall\Middleware\HoneypotRegex::class);
```

Then add `'firewall.honeypot_regex'` to the static prefix list of the `firewall.all` group (the first array argument to `array_merge` at ~line 226), so it becomes:

```php
            ['firewall.correlation', 'firewall.bypass', 'firewall.acl', 'firewall.live_traffic', 'firewall.honeypot_regex'],
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter HoneypotRegexTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Services/Honeypot/HoneypotTrap.php src/Http/Controllers/HoneypotController.php src/Firewall/Middleware/HoneypotRegex.php src/ShieldServiceProvider.php tests/Feature/Reactions/HoneypotRegexTest.php
git commit -m "feat(honeypot): add regex path matching via shared HoneypotTrap action"
```

---

## Task 9: Form input-trap — component, middleware, validation rule

**Files:**
- Create: `src/View/Components/Honeypot.php`
- Create: `src/Http/Middleware/ProtectAgainstSpam.php`
- Create: `src/Rules/ShieldHoneypot.php`
- Modify: `src/ShieldServiceProvider.php` (boot: register component + directive ~line 115; alias the middleware ~line 221)
- Test: `tests/Feature/Reactions/FormHoneypotTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace OzanKurt\Shield\Tests\Feature\Reactions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use OzanKurt\Shield\Http\Middleware\ProtectAgainstSpam;
use OzanKurt\Shield\Services\Scoring\SuspicionScorer;
use OzanKurt\Shield\Tests\TestCase;

class FormHoneypotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['shield.honeypot.form.enabled' => true]);
        config(['shield.honeypot.form.response' => 'ok']);
        config(['shield.scoring.enabled' => true]);
    }

    public function testCleanSubmissionPassesThrough()
    {
        $request = Request::create('/contact', 'POST', [
            'shield_hp' => '',
            'shield_hp_time' => Crypt::encryptString((string) now()->subSeconds(5)->timestamp),
        ]);

        $result = (new ProtectAgainstSpam())->handle($request, fn ($r) => 'next');
        $this->assertSame('next', $result);
    }

    public function testFilledHoneypotIsDiscardedAndScored()
    {
        $scorer = $this->spy(SuspicionScorer::class);
        $this->app->instance(SuspicionScorer::class, $scorer);

        $request = Request::create('/contact', 'POST', [
            'shield_hp' => 'i-am-a-bot',
            'shield_hp_time' => Crypt::encryptString((string) now()->subSeconds(5)->timestamp),
        ]);
        $request->server->set('REMOTE_ADDR', '203.0.113.70');

        $response = (new ProtectAgainstSpam())->handle($request, fn ($r) => 'next');

        $this->assertNotSame('next', $response);
        $this->assertSame(200, $response->getStatusCode());
        $scorer->shouldHaveReceived('bump')->once();
    }

    public function testTooFastSubmissionIsDiscarded()
    {
        $request = Request::create('/contact', 'POST', [
            'shield_hp' => '',
            'shield_hp_time' => Crypt::encryptString((string) now()->timestamp), // 0s elapsed
        ]);
        $request->server->set('REMOTE_ADDR', '203.0.113.71');

        $response = (new ProtectAgainstSpam())->handle($request, fn ($r) => 'next');
        $this->assertNotSame('next', $response);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter FormHoneypotTest`
Expected: FAIL (middleware class not found).

- [ ] **Step 3: Create the middleware**

`src/Http/Middleware/ProtectAgainstSpam.php`:

```php
<?php

namespace OzanKurt\Shield\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use OzanKurt\Shield\Services\Scoring\SuspicionScorer;

/**
 * Form input-trap. Trips when a hidden honeypot field is filled, or the form
 * was submitted impossibly fast / too long ago. On a trip it does NOT process
 * the request (silent discard) and escalates the IP via SuspicionScorer, so
 * repeat offenders cross the scoring threshold and auto-block through the
 * normal path (which then drives the reaction layer).
 */
class ProtectAgainstSpam
{
    public function handle(Request $request, Closure $next)
    {
        if (! config('shield.honeypot.form.enabled', false) || $request->isMethod('GET')) {
            return $next($request);
        }

        $reason = $this->trippedReason($request);

        if ($reason === null) {
            return $next($request);
        }

        app(SuspicionScorer::class)->bump($request->ip(), (int) config('shield.honeypot.form.score', 50));

        app(AuditLogger::class)->log('honeypot.form_trap', "Form honeypot tripped ({$reason})", [
            'severity' => 'medium',
            'ip' => $request->ip(),
            'meta' => ['reason' => $reason, 'path' => $request->path()],
        ]);

        return $this->discardResponse();
    }

    /** @return string|null reason key, or null when the submission is clean */
    private function trippedReason(Request $request): ?string
    {
        $nameField = (string) config('shield.honeypot.form.name_field', 'shield_hp');
        if (filled($request->input($nameField))) {
            return 'filled';
        }

        if (! config('shield.honeypot.form.require_timestamp', true)) {
            return null;
        }

        $timeField = (string) config('shield.honeypot.form.valid_from_field', 'shield_hp_time');
        $raw = $request->input($timeField);
        if (! is_string($raw) || $raw === '') {
            return 'tampered';
        }

        try {
            $submittedAt = (int) Crypt::decryptString($raw);
        } catch (\Throwable) {
            return 'tampered';
        }

        $elapsed = now()->timestamp - $submittedAt;
        if ($elapsed < (int) config('shield.honeypot.form.min_time_seconds', 1)) {
            return 'too_fast';
        }
        if ($elapsed > (int) config('shield.honeypot.form.max_time_seconds', 3600)) {
            return 'stale';
        }

        return null;
    }

    private function discardResponse()
    {
        $response = config('shield.honeypot.form.response', 'redirect_back');

        if ($response === 'redirect_back') {
            return redirect()->back();
        }
        if ($response === 'ok') {
            return response('', 200);
        }

        return response('', (int) $response);
    }
}
```

- [ ] **Step 4: Create the Blade component**

`src/View/Components/Honeypot.php` (renders inline Blade, so no view namespace is needed):

```php
<?php

namespace OzanKurt\Shield\View\Components;

use Illuminate\Support\Facades\Crypt;
use Illuminate\View\Component;

class Honeypot extends Component
{
    public string $nameField;
    public string $timeField;
    public string $token;

    public function __construct()
    {
        $this->nameField = (string) config('shield.honeypot.form.name_field', 'shield_hp');
        $this->timeField = (string) config('shield.honeypot.form.valid_from_field', 'shield_hp_time');
        $this->token = Crypt::encryptString((string) now()->timestamp);
    }

    public function render()
    {
        return <<<'BLADE'
        <div style="display:none !important;" aria-hidden="true">
            <input type="text" name="{{ $nameField }}" value="" tabindex="-1" autocomplete="off" />
            <input type="text" name="{{ $timeField }}" value="{{ $token }}" tabindex="-1" autocomplete="off" />
        </div>
        BLADE;
    }
}
```

- [ ] **Step 5: Create the validation rule**

`src/Rules/ShieldHoneypot.php`:

```php
<?php

namespace OzanKurt\Shield\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Crypt;
use OzanKurt\Shield\Services\Scoring\SuspicionScorer;

/**
 * Validation-rule equivalent of ProtectAgainstSpam, for Livewire / manual
 * forms. Attach to the honeypot field: 'shield_hp' => [new ShieldHoneypot].
 * The field under validation must be empty; the companion timestamp field is
 * read from the current request.
 */
class ShieldHoneypot implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (filled($value)) {
            $this->escalateAndFail($fail);

            return;
        }

        if (! config('shield.honeypot.form.require_timestamp', true)) {
            return;
        }

        $timeField = (string) config('shield.honeypot.form.valid_from_field', 'shield_hp_time');
        $raw = request()->input($timeField);

        try {
            $submittedAt = (int) Crypt::decryptString((string) $raw);
        } catch (\Throwable) {
            $this->escalateAndFail($fail);

            return;
        }

        $elapsed = now()->timestamp - $submittedAt;
        $min = (int) config('shield.honeypot.form.min_time_seconds', 1);
        $max = (int) config('shield.honeypot.form.max_time_seconds', 3600);

        if ($elapsed < $min || $elapsed > $max) {
            $this->escalateAndFail($fail);
        }
    }

    private function escalateAndFail(Closure $fail): void
    {
        app(SuspicionScorer::class)->bump(request()->ip(), (int) config('shield.honeypot.form.score', 50));
        $fail('The given data was invalid.');
    }
}
```

- [ ] **Step 6: Register the middleware alias, component, and directive**

In `src/ShieldServiceProvider.php`:

Alias (near line 221, with the other aliases):

```php
        $router->aliasMiddleware('shield.honeypot-form', \OzanKurt\Shield\Http\Middleware\ProtectAgainstSpam::class);
```

Component + directive (in `boot()`, near `registerHoneypotRoutes()` at ~line 116):

```php
        \Illuminate\Support\Facades\Blade::component('shield-honeypot', \OzanKurt\Shield\View\Components\Honeypot::class);
        \Illuminate\Support\Facades\Blade::directive('shieldHoneypot', function () {
            return "<?php echo \\Illuminate\\Support\\Facades\\Blade::renderComponent(new \\OzanKurt\\Shield\\View\\Components\\Honeypot()); ?>";
        });
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter FormHoneypotTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/View/Components/Honeypot.php src/Http/Middleware/ProtectAgainstSpam.php src/Rules/ShieldHoneypot.php src/ShieldServiceProvider.php tests/Feature/Reactions/FormHoneypotTest.php
git commit -m "feat(honeypot): add form input-trap component, middleware, and rule"
```

---

## Task 10: Interactive shield:acl command

**Files:**
- Create: `src/Console/Commands/AclManageCommand.php`
- Modify: `src/ShieldServiceProvider.php` (`registerCommands()` ~line 277)
- Test: `tests/Feature/Reactions/AclManageCommandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace OzanKurt\Shield\Tests\Feature\Reactions;

use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Tests\TestCase;

class AclManageCommandTest extends TestCase
{
    public function testNonInteractiveBanCreatesManualAclEntry()
    {
        $this->artisan('shield:acl --ban=203.0.113.80 --hours=24')->assertExitCode(0);

        $this->assertTrue(
            Acl::query()->where('value', '203.0.113.80')->where('source', 'manual')->exists()
        );
    }

    public function testNonInteractiveUnbanSoftDeletesEntry()
    {
        $this->artisan('shield:acl --ban=203.0.113.81 --hours=1')->assertExitCode(0);
        $this->artisan('shield:acl --unban=203.0.113.81')->assertExitCode(0);

        $this->assertFalse(
            Acl::query()->where('value', '203.0.113.81')->exists() // soft-deleted → excluded
        );
    }

    public function testListRunsWithoutError()
    {
        $this->artisan('shield:acl --list')->assertExitCode(0);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter AclManageCommandTest`
Expected: FAIL (command not registered).

- [ ] **Step 3: Create the command**

`src/Console/Commands/AclManageCommand.php`:

```php
<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

/**
 * Interactive ACL management. Manual bans use source='manual', which is in
 * shield.reactions.self_detected_sources, so the AclObserver fires the
 * reaction layer (Cloudflare/AbuseIPDB) automatically. Unbans soft-delete the
 * row; shield:reactions-reconcile then removes any edge rule.
 */
class AclManageCommand extends Command
{
    protected $signature = 'shield:acl
        {--list : List active blocks and exit}
        {--ban= : Ban this IP non-interactively}
        {--unban= : Unban this IP non-interactively}
        {--hours=24 : Ban duration in hours (0 = permanent), used with --ban}';

    protected $description = 'Manage the Shield ACL (list / ban / unban / search / stats)';

    public function handle(LookupResolver $lookups): int
    {
        if ($ip = $this->option('ban')) {
            $this->ban($lookups, $ip, (int) $this->option('hours'));

            return self::SUCCESS;
        }

        if ($ip = $this->option('unban')) {
            $this->unban($ip);

            return self::SUCCESS;
        }

        if ($this->option('list')) {
            $this->listActive();

            return self::SUCCESS;
        }

        return $this->menu($lookups);
    }

    private function menu(LookupResolver $lookups): int
    {
        while (true) {
            $choice = $this->choice('Shield ACL', ['List', 'Ban', 'Unban', 'Search', 'Stats', 'Quit'], 0);

            match ($choice) {
                'List' => $this->listActive(),
                'Ban' => $this->ban($lookups, $this->ask('IP to ban'), $this->durationHours()),
                'Unban' => $this->unban($this->ask('IP to unban')),
                'Search' => $this->search($this->ask('IP to search')),
                'Stats' => $this->stats(),
                'Quit' => null,
            };

            if ($choice === 'Quit') {
                return self::SUCCESS;
            }
        }
    }

    private function durationHours(): int
    {
        return (int) $this->choice('Duration', ['1', '24', '168', '720', '0'], 1);
    }

    private function ban(LookupResolver $lookups, ?string $ip, int $hours): void
    {
        if (! $ip || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->error('Invalid IP.');

            return;
        }

        Acl::create([
            'kind_id' => $lookups->id(AclKind::class, 'ip'),
            'action_id' => $lookups->id(AclAction::class, 'block'),
            'value' => $ip,
            'source' => 'manual',
            'reason' => 'Manual ban via shield:acl',
            'expires_at' => $hours > 0 ? now()->addHours($hours) : null,
        ]);

        $this->info("Banned {$ip}" . ($hours > 0 ? " for {$hours}h." : ' permanently.'));
    }

    private function unban(?string $ip): void
    {
        if (! $ip) {
            $this->error('No IP given.');

            return;
        }

        $count = Acl::query()->where('value', $ip)->get()->each->delete()->count();
        $this->info("Removed {$count} ACL entr(ies) for {$ip}.");
    }

    private function search(?string $ip): void
    {
        $rows = Acl::query()->where('value', $ip)->get();
        $this->renderTable($rows);
    }

    private function listActive(): void
    {
        $rows = Acl::query()->active()->ofAction('block')->latest()->limit(50)->get();
        $this->renderTable($rows);
    }

    private function stats(): void
    {
        $bySource = Acl::query()->selectRaw('source, count(*) as c')->groupBy('source')->pluck('c', 'source');
        $this->table(['Source', 'Count'], $bySource->map(fn ($c, $s) => [$s, $c])->values()->all());
    }

    private function renderTable($rows): void
    {
        $this->table(
            ['IP', 'Source', 'Reason', 'Expires', 'Edge rule?'],
            $rows->map(fn (Acl $a) => [
                $a->value,
                $a->source,
                \Illuminate\Support\Str::limit((string) $a->reason, 40),
                $a->expires_at?->toDateTimeString() ?? 'never',
                empty($a->meta['reactions']['cloudflare']['rule_id']) ? 'no' : 'yes',
            ])->all()
        );
    }
}
```

- [ ] **Step 4: Register the command**

In `src/ShieldServiceProvider.php` `registerCommands()`, add:

```php
        $this->commands(\OzanKurt\Shield\Console\Commands\AclManageCommand::class);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter AclManageCommandTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Console/Commands/AclManageCommand.php src/ShieldServiceProvider.php tests/Feature/Reactions/AclManageCommandTest.php
git commit -m "feat(cli): add interactive shield:acl management command"
```

---

## Task 11: End-to-end integration test + `about` line + docs

**Files:**
- Modify: `src/ShieldServiceProvider.php` (the `AboutCommand::add` block; search for `'honeypot'`/`about` near line 76)
- Create: `tests/Feature/Reactions/EndToEndReactionTest.php`
- Modify: `docs/specs/spec-006-honeypotplus-edge-reactions.md` (flip Status to "Implemented")

- [ ] **Step 1: Write the end-to-end test**

```php
<?php

namespace OzanKurt\Shield\Tests\Feature\Reactions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use OzanKurt\Shield\Jobs\RunAclReactionJob;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Tests\TestCase;

class EndToEndReactionTest extends TestCase
{
    public function testHoneypotBlockPushesToCloudflareEndToEnd()
    {
        config(['shield.reactions.cloudflare.enabled' => true]);
        config(['shield.reactions.cloudflare.api_token' => 'tok']);
        config(['shield.reactions.cloudflare.zone_id' => 'zone123']);

        Http::fake([
            '*/firewall/access_rules/rules' => Http::response(['success' => true, 'result' => ['id' => 'rule_e2e']], 200),
        ]);

        $lookups = app(LookupResolver::class);

        // Creating the block fires the observer, which dispatches the job.
        // With the sync queue (test default), the job runs immediately.
        $acl = Acl::create([
            'kind_id' => $lookups->id(AclKind::class, 'ip'),
            'action_id' => $lookups->id(AclAction::class, 'block'),
            'value' => '203.0.113.90',
            'source' => 'honeypot',
            'reason' => 'Hit honeypot path: /.env',
        ]);

        $this->assertSame('rule_e2e', $acl->fresh()->meta['reactions']['cloudflare']['rule_id']);
    }

    public function testFeedBlockSkipsAllReactionsEndToEnd()
    {
        Queue::fake();
        config(['shield.reactions.cloudflare.enabled' => true]);
        config(['shield.reactions.cloudflare.api_token' => 'tok']);
        config(['shield.reactions.cloudflare.zone_id' => 'zone123']);

        $lookups = app(LookupResolver::class);
        Acl::create([
            'kind_id' => $lookups->id(AclKind::class, 'ip'),
            'action_id' => $lookups->id(AclAction::class, 'block'),
            'value' => '203.0.113.91',
            'source' => 'abuseipdb',
            'reason' => 'feed import',
        ]);

        Queue::assertNotPushed(RunAclReactionJob::class);
    }
}
```

- [ ] **Step 2: Run test to verify it fails or passes**

Run: `vendor/bin/phpunit --filter EndToEndReactionTest`
Expected: PASS already (the wiring exists from prior tasks). If `testHoneypotBlockPushesToCloudflareEndToEnd` fails because the test queue is not sync, set it explicitly at the top of the test: `config(['queue.default' => 'sync']);` and re-run.

- [ ] **Step 3: Add the `about` line**

In `src/ShieldServiceProvider.php`, find the `\Illuminate\Foundation\Console\AboutCommand::add('Shield', ...)` block (near line 76) and add reaction status entries, e.g.:

```php
            'Cloudflare Reaction' => config('shield.reactions.cloudflare.enabled') ? 'ENABLED' : 'OFF',
            'AbuseIPDB Reporting' => config('shield.reactions.abuseipdb_report.enabled') ? 'ENABLED' : 'OFF',
            'Form Honeypot' => config('shield.honeypot.form.enabled') ? 'ENABLED' : 'OFF',
```

- [ ] **Step 4: Run the entire test suite**

Run: `vendor/bin/phpunit`
Expected: PASS (no regressions across the whole suite).

- [ ] **Step 5: Flip the spec status**

In `docs/specs/spec-006-honeypotplus-edge-reactions.md`, change `- Status: Draft` to `- Status: Implemented`.

- [ ] **Step 6: Commit**

```bash
git add src/ShieldServiceProvider.php tests/Feature/Reactions/EndToEndReactionTest.php docs/specs/spec-006-honeypotplus-edge-reactions.md
git commit -m "feat(reactions): end-to-end test + about status, mark spec-006 implemented"
```

---

## Self-Review notes (for the implementer)

- **Spec coverage:** Task 2-7 = Cloudflare + AbuseIPDB reaction layer (spec §4.1); Task 8 = regex paths (§4.3); Task 9 = form trap (§4.2); Task 10 = interactive CLI (§4.4); Task 1 = config/env (§6). Acceptance criteria 1-10 map to tests in Tasks 3-10 + the E2E test in Task 11.
- **Source allowlist** (`self_detected_sources`) is enforced in `ReactionManager` and re-checked in the job (defence in depth), covering acceptance criteria 1-2.
- **No new migrations** — reaction state lives in `ls_acl.meta`. If your sqlite test DB lacks the `meta`/`expires_at` columns, confirm the existing ACL migration includes them before starting (it does in the current schema; the `Acl` model casts `meta => json`).
- **Queue in tests:** Testbench defaults the queue to `sync`, so observer-dispatched jobs run inline, which is why the E2E test asserts the final meta. Where a test asserts dispatch only, it uses `Bus::fake()`/`Queue::fake()`.
- If `Acl::query()->active()` / `->ofAction()` scopes are missing in your branch, they exist on `src/Models/Acl.php` (`scopeActive`, `scopeOfAction`).
