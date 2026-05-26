<?php

namespace OzanKurt\Shield\Tests\Unit\Services\Acl;

use Illuminate\Http\Request;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Acl\AclEvaluator;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Tests\TestCase;

class AclEvaluatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear live entries cache between tests
        app(AclEvaluator::class)->clearCache();
    }

    public function test_whitelist_short_circuits_to_allow(): void
    {
        $resolver = app(LookupResolver::class);
        Acl::create([
            'kind_id' => $resolver->id(AclKind::class, 'ip'),
            'action_id' => $resolver->id(AclAction::class, 'allow'),
            'value' => '1.2.3.4',
            'source' => 'manual',
        ]);
        Acl::create([
            'kind_id' => $resolver->id(AclKind::class, 'ip'),
            'action_id' => $resolver->id(AclAction::class, 'block'),
            'value' => '1.2.3.4',
            'source' => 'auto_block',
        ]);

        $req = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '1.2.3.4']);
        $decision = app(AclEvaluator::class)->evaluate($req);

        $this->assertSame('allow', $decision->action);
    }

    public function test_block_returns_deny(): void
    {
        $resolver = app(LookupResolver::class);
        Acl::create([
            'kind_id' => $resolver->id(AclKind::class, 'ip'),
            'action_id' => $resolver->id(AclAction::class, 'block'),
            'value' => '5.5.5.5',
            'source' => 'manual',
        ]);

        $req = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '5.5.5.5']);
        $decision = app(AclEvaluator::class)->evaluate($req);

        $this->assertSame('block', $decision->action);
        $this->assertNotNull($decision->matchedEntry);
    }

    public function test_no_match_returns_pass(): void
    {
        $req = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '99.99.99.99']);
        $decision = app(AclEvaluator::class)->evaluate($req);

        $this->assertSame('pass', $decision->action);
    }

    public function test_expired_block_does_not_match(): void
    {
        $resolver = app(LookupResolver::class);
        Acl::create([
            'kind_id' => $resolver->id(AclKind::class, 'ip'),
            'action_id' => $resolver->id(AclAction::class, 'block'),
            'value' => '7.7.7.7',
            'source' => 'auto_block',
            'expires_at' => now()->subMinute(),
        ]);

        $req = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '7.7.7.7']);
        $decision = app(AclEvaluator::class)->evaluate($req);

        $this->assertSame('pass', $decision->action);
    }
}
