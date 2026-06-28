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
