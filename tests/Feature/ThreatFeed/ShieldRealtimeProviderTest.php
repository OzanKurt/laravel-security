<?php

namespace OzanKurt\Shield\Tests\Feature\ThreatFeed;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Models\Lookups\LogLevel;
use OzanKurt\Shield\Models\Lookups\WafRuleAction;
use OzanKurt\Shield\Models\Lookups\WafRuleCategory;
use OzanKurt\Shield\Models\Lookups\WafRuleKind;
use OzanKurt\Shield\Models\Lookups\WafRuleTarget;
use OzanKurt\Shield\Models\WafRule;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Services\ThreatFeed\FeedRunner;
use OzanKurt\Shield\Services\ThreatFeed\Providers\ShieldRealtimeProvider;
use OzanKurt\Shield\Tests\TestCase;

class ShieldRealtimeProviderTest extends TestCase
{
    private const CURSOR_KEY = 'shield.threat_feed.shield_realtime.cursor';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shield.premium.license_key' => 'rt-key',
            'shield.premium.feed_pull_url' => 'https://central.test/api/feeds/pull',
            'shield.threat_feed.shield_realtime.enabled' => true,
        ]);
        Cache::forget(self::CURSOR_KEY);
    }

    private function provider(): ShieldRealtimeProvider
    {
        return app(ShieldRealtimeProvider::class);
    }

    private function fakePull(array $payload): void
    {
        Http::fake(['central.test/*' => Http::response($payload, 200)]);
    }

    public function testNameAndAvailability(): void
    {
        $this->assertSame('shield_realtime', $this->provider()->name());
        $this->assertTrue($this->provider()->isAvailable());

        config(['shield.threat_feed.shield_realtime.enabled' => false]);
        $this->assertFalse($this->provider()->isAvailable());
    }

    public function testSyncImportsWafRulesAndAclFromCentral(): void
    {
        $this->fakePull([
            'cursor' => 'cursor-2',
            'waf_rules' => [[
                'ref' => 'rt-1', 'name' => 'RT XSS', 'category' => 'xss', 'kind' => 'regex',
                'target' => 'request_input', 'pattern' => '/<script/i', 'action' => 'block',
                'severity' => 'high', 'score' => 10, 'version' => 1,
            ]],
            'acl' => [[
                'value' => '203.0.113.5', 'kind' => 'ip', 'action' => 'block', 'note' => 'RT bad ip',
            ]],
        ]);

        $result = $this->provider()->sync();

        $this->assertTrue($result->success());
        $this->assertSame(2, $result->imported);

        $rule = WafRule::where(['source' => 'shield_realtime', 'source_ref' => 'rt-1'])->first();
        $this->assertNotNull($rule);
        $this->assertSame('/<script/i', $rule->pattern);

        $acl = Acl::where(['source' => 'shield_realtime', 'value' => '203.0.113.5'])->first();
        $this->assertNotNull($acl);

        $this->assertSame('cursor-2', Cache::get(self::CURSOR_KEY));
    }

    public function testSyncResendsStoredCursorAsSince(): void
    {
        Cache::put(self::CURSOR_KEY, 'cursor-1', 3600);
        $this->fakePull(['cursor' => 'cursor-2', 'waf_rules' => [], 'acl' => []]);

        $this->provider()->sync();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'since=cursor-1'));
    }

    private function seedRealtimeWafRule(int $version, string $name = 'old'): void
    {
        $lookups = app(LookupResolver::class);
        WafRule::create([
            'source' => 'shield_realtime', 'source_ref' => 'rt-1', 'name' => $name, 'pattern' => '/old/',
            'category_id' => $lookups->id(WafRuleCategory::class, 'xss'),
            'kind_id' => $lookups->id(WafRuleKind::class, 'regex'),
            'target_id' => $lookups->id(WafRuleTarget::class, 'request_input'),
            'action_id' => $lookups->id(WafRuleAction::class, 'block'),
            'severity_id' => $lookups->id(LogLevel::class, 'high'),
            'score' => 1, 'version' => $version, 'is_enabled' => true,
        ]);
    }

    public function testSyncIgnoresWafRuleWhenVersionNotHigher(): void
    {
        $this->seedRealtimeWafRule(version: 2);
        $this->fakePull(['cursor' => 'c', 'waf_rules' => [[
            'ref' => 'rt-1', 'name' => 'same-version', 'category' => 'xss', 'kind' => 'regex',
            'target' => 'request_input', 'pattern' => '/new/', 'action' => 'block', 'severity' => 'high', 'version' => 2,
        ]], 'acl' => []]);

        $result = $this->provider()->sync();

        $this->assertSame(0, $result->updated);
        $this->assertSame('old', WafRule::where('source_ref', 'rt-1')->first()->name);
    }

    public function testSyncUpdatesWafRuleOnHigherVersion(): void
    {
        $this->seedRealtimeWafRule(version: 2);
        $this->fakePull(['cursor' => 'c', 'waf_rules' => [[
            'ref' => 'rt-1', 'name' => 'newer', 'category' => 'xss', 'kind' => 'regex',
            'target' => 'request_input', 'pattern' => '/newer/', 'action' => 'block', 'severity' => 'high', 'version' => 3,
        ]], 'acl' => []]);

        $result = $this->provider()->sync();

        $this->assertSame(1, $result->updated);
        $this->assertSame('newer', WafRule::where('source_ref', 'rt-1')->first()->name);
    }

    public function testSyncRevokesWafRulesAndAclByRef(): void
    {
        $lookups = app(LookupResolver::class);
        WafRule::create([
            'source' => 'shield_realtime', 'source_ref' => 'rt-x', 'name' => 'doomed', 'pattern' => '/x/',
            'category_id' => $lookups->id(WafRuleCategory::class, 'xss'),
            'kind_id' => $lookups->id(WafRuleKind::class, 'regex'),
            'target_id' => $lookups->id(WafRuleTarget::class, 'request_input'),
            'action_id' => $lookups->id(WafRuleAction::class, 'block'),
            'severity_id' => $lookups->id(LogLevel::class, 'high'),
            'is_enabled' => true, 'version' => 1,
        ]);
        Acl::create([
            'kind_id' => $lookups->id(AclKind::class, 'ip'),
            'action_id' => $lookups->id(AclAction::class, 'block'),
            'value' => '10.0.0.9', 'source' => 'shield_realtime',
        ]);

        $this->fakePull([
            'cursor' => 'c', 'waf_rules' => [], 'acl' => [],
            'revoked' => ['waf_rules' => ['rt-x'], 'acl' => ['10.0.0.9']],
        ]);

        $result = $this->provider()->sync();

        $this->assertSame(2, $result->deleted);
        $this->assertFalse((bool) WafRule::where('source_ref', 'rt-x')->first()->is_enabled);
        $this->assertFalse(Acl::query()->active()->where('value', '10.0.0.9')->exists());
    }

    public function testSyncReturnsErrorResultWhenPullFails(): void
    {
        Http::fake(['central.test/*' => Http::response('nope', 402)]);

        $result = $this->provider()->sync();

        $this->assertFalse($result->success());
        $this->assertNotNull($result->error);
    }

    public function testFeedRunnerSkipsRealtimeWithoutPremiumLicense(): void
    {
        config(['shield.premium.license_key' => null]);
        Cache::forget('shield.premium.license');
        Http::fake();

        $runner = new FeedRunner([$this->provider()], app(AuditLogger::class));
        $results = $runner->runAll('shield_realtime');

        Http::assertNothingSent();
        $this->assertSame([], $results);
    }

    public function testFeedRunnerRunsRealtimeWithPremiumLicense(): void
    {
        Cache::put('shield.premium.license', [
            'state' => 'valid', 'valid' => true, 'features' => [],
            'last_checked_at' => now()->toIso8601String(),
        ], 3600);
        $this->fakePull(['cursor' => 'c1', 'waf_rules' => [], 'acl' => []]);

        $runner = new FeedRunner([$this->provider()], app(AuditLogger::class));
        $results = $runner->runAll('shield_realtime');

        $this->assertCount(1, $results);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://central.test/api/feeds/pull'));
    }
}
