<?php

namespace OzanKurt\Shield\Tests\Feature\Reactions;

use Illuminate\Support\Facades\Bus;
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
        // AclObserver now fires onBlock on create; fake the bus so the
        // incidental queued reaction job does not run during this unit test.
        Bus::fake();
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
        // AclObserver now fires onBlock on create; fake the bus so the
        // incidental queued reaction job does not run during this unit test.
        Bus::fake();
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
        // AclObserver now fires onBlock on create; fake the bus so the
        // incidental queued reaction job does not run during this unit test.
        Bus::fake();
        config(['shield.reactions.cloudflare.enabled' => true]);
        $acl = $this->block('10.0.0.1');
        $this->assertFalse(app(CloudflareReaction::class)->appliesTo($acl));
    }
}
