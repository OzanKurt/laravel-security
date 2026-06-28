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

    public function testCreateReturnsNullWhenNotConfigured()
    {
        config(['shield.reactions.cloudflare.api_token' => null]);
        config(['shield.reactions.cloudflare.zone_id' => null]);
        config(['shield.reactions.cloudflare.account_id' => null]);

        Http::fake();

        $this->assertNull(app(CloudflareClient::class)->createBlockRule('203.0.113.7', 'x'));
        Http::assertNothingSent();
    }

    public function testCreateReturnsNullOnConnectionException()
    {
        config(['shield.reactions.cloudflare.api_token' => 'tok']);
        config(['shield.reactions.cloudflare.zone_id' => 'zone123']);

        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('refused');
        });

        $this->assertNull(app(CloudflareClient::class)->createBlockRule('203.0.113.7', 'x'));
    }
}
