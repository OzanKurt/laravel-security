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
        config(['queue.default' => 'sync']);
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
