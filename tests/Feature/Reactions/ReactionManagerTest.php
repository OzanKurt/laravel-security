<?php

namespace OzanKurt\Shield\Tests\Feature\Reactions;

use Illuminate\Support\Facades\Bus;
use OzanKurt\Shield\Jobs\RunAclReactionJob;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Services\Reactions\AbuseIpDbReportReaction;
use OzanKurt\Shield\Services\Reactions\CloudflareReaction;
use OzanKurt\Shield\Services\Reactions\ReactionManager;
use OzanKurt\Shield\Tests\TestCase;

class ReactionManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The provider binding for ReactionManager is added in a later task
        // (Task 6). Bind it here with the real reactions so this test verifies
        // genuine manager behaviour (allowlist filtering, enabled filtering,
        // dispatch) rather than the empty autowired default.
        $this->app->singleton(ReactionManager::class, function ($app) {
            return new ReactionManager([
                $app->make(CloudflareReaction::class),
                $app->make(AbuseIpDbReportReaction::class),
            ]);
        });
    }

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
