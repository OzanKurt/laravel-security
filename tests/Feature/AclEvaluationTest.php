<?php

namespace OzanKurt\Shield\Tests\Feature;

use OzanKurt\Shield\Database\Seeders\LookupTableSeeder;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Acl\AclEvaluator;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Tests\TestCase;

class AclEvaluationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear the evaluator's live entries cache before each test
        app(AclEvaluator::class)->clearCache();

        $this->app['router']->get('/_test/acl-target', function () {
            return 'ok';
        })->middleware('firewall.acl');
    }

    public function test_unmatched_ip_passes_through(): void
    {
        $this->get('/_test/acl-target', ['REMOTE_ADDR' => '8.8.8.8'])
            ->assertOk();
    }

    public function test_blocked_ip_gets_403(): void
    {
        $resolver = app(LookupResolver::class);
        Acl::create([
            'kind_id' => $resolver->id(AclKind::class, 'ip'),
            'action_id' => $resolver->id(AclAction::class, 'block'),
            'value' => '6.6.6.6',
            'source' => 'manual',
        ]);

        // Clear cache so the new entry is picked up
        app(AclEvaluator::class)->clearCache();

        $this->call('GET', '/_test/acl-target', [], [], [], ['REMOTE_ADDR' => '6.6.6.6'])
            ->assertStatus(403);
    }

    public function test_whitelisted_ip_bypasses_even_if_also_blocked(): void
    {
        $resolver = app(LookupResolver::class);
        Acl::create([
            'kind_id' => $resolver->id(AclKind::class, 'ip'),
            'action_id' => $resolver->id(AclAction::class, 'allow'),
            'value' => '4.4.4.4',
            'source' => 'manual',
        ]);
        Acl::create([
            'kind_id' => $resolver->id(AclKind::class, 'ip'),
            'action_id' => $resolver->id(AclAction::class, 'block'),
            'value' => '4.4.4.4',
            'source' => 'auto_block',
        ]);

        // Clear cache so the new entries are picked up
        app(AclEvaluator::class)->clearCache();

        $this->call('GET', '/_test/acl-target', [], [], [], ['REMOTE_ADDR' => '4.4.4.4'])
            ->assertOk();
    }
}
