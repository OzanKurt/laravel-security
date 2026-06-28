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
    protected function setUp(): void
    {
        parent::setUp();

        // Reconcile only matters when Cloudflare is enabled (the normal case);
        // onUnblock dispatches per-reaction only when isEnabled() is true.
        config(['shield.reactions.cloudflare.enabled' => true]);
        config(['shield.reactions.cloudflare.api_token' => 'tok']);
        config(['shield.reactions.cloudflare.zone_id' => 'z']);
    }

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

        // The block is still active, so reconcile must not unban it. (An
        // incidental ban job fires on creation via the observer; we only
        // assert no unban was dispatched.)
        Bus::assertNotDispatched(RunAclReactionJob::class, fn ($j) => $j->op === 'unban');
    }

    public function testExpiredBlockWithoutRuleIdDoesNotReconcile()
    {
        Bus::fake();
        $this->block('203.0.113.52', null, now()->subMinute()); // expired, no edge rule

        $this->artisan('shield:reactions-reconcile')->assertExitCode(0);

        // No cloudflare rule_id in meta, so there is nothing to reconcile; the
        // command must not unban it. (Incidental ban on creation is ignored.)
        Bus::assertNotDispatched(RunAclReactionJob::class, fn ($j) => $j->op === 'unban');
    }
}
