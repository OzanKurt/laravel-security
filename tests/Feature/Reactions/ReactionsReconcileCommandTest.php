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
