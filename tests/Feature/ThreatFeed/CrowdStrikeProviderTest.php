<?php

namespace OzanKurt\Shield\Tests\Feature\ThreatFeed;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Services\ThreatFeed\Providers\CrowdStrikeProvider;
use OzanKurt\Shield\Tests\TestCase;

class CrowdStrikeProviderTest extends TestCase
{
    private const TOKEN_CACHE_KEY = 'shield.threat_feed.crowdstrike.token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['shield.threat_feed.crowdstrike' => [
            'enabled' => true, 'client_id' => 'id', 'client_secret' => 'sec',
            'base_url' => 'https://cs.test', 'min_confidence' => 'high', 'max_import' => 50000,
        ]]);
        Cache::forget(self::TOKEN_CACHE_KEY);
    }

    private function provider(): CrowdStrikeProvider
    {
        return app(CrowdStrikeProvider::class);
    }

    private function fakeOauthAndIndicators(array $resources): void
    {
        Http::fake([
            'cs.test/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 1799], 201),
            'cs.test/intel/combined/indicators*' => Http::response(['resources' => $resources], 200),
        ]);
    }

    public function testName(): void
    {
        $this->assertSame('crowdstrike', $this->provider()->name());
    }

    public function testNotAvailableWithoutCredentials(): void
    {
        config(['shield.threat_feed.crowdstrike.client_secret' => null]);
        $this->assertFalse($this->provider()->isAvailable());
    }

    public function testSyncAuthenticatesThenImportsFilteredByConfidence(): void
    {
        $this->fakeOauthAndIndicators([
            ['indicator' => '9.9.9.9', 'type' => 'ip_address', 'malicious_confidence' => 'high'],
            ['indicator' => '8.8.8.8', 'type' => 'ip_address', 'malicious_confidence' => 'low'],
        ]);

        $result = $this->provider()->sync();

        $this->assertTrue($result->success());
        $this->assertSame(1, $result->imported);
        $this->assertTrue(Acl::where(['source' => 'crowdstrike', 'value' => '9.9.9.9'])->exists());
        $this->assertFalse(Acl::where(['source' => 'crowdstrike', 'value' => '8.8.8.8'])->exists());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2/token'));
    }

    public function testSyncReusesCachedTokenWithoutReauthenticating(): void
    {
        Cache::put(self::TOKEN_CACHE_KEY, 'cached-tok', 1000);
        Http::fake([
            'cs.test/intel/combined/indicators*' => Http::response(['resources' => [
                ['indicator' => '9.9.9.9', 'type' => 'ip_address', 'malicious_confidence' => 'high'],
            ]], 200),
        ]);

        $this->provider()->sync();

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'oauth2/token'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'indicators') && $r->hasHeader('Authorization', 'Bearer cached-tok'));
    }

    public function testSyncReturnsErrorWhenAuthFails(): void
    {
        Http::fake(['cs.test/oauth2/token' => Http::response(['error' => 'invalid_client'], 401)]);

        $result = $this->provider()->sync();

        $this->assertFalse($result->success());
    }
}
