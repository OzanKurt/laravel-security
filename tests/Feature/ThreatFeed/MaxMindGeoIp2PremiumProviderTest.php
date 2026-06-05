<?php

namespace OzanKurt\Shield\Tests\Feature\ThreatFeed;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use OzanKurt\Shield\Services\ThreatFeed\FeedRunner;
use OzanKurt\Shield\Services\ThreatFeed\Providers\MaxMindGeoIp2PremiumProvider;
use OzanKurt\Shield\Services\ThreatFeed\SyncResult;
use OzanKurt\Shield\Tests\TestCase;

class MaxMindGeoIp2PremiumProviderTest extends TestCase
{
    private function provider(): MaxMindGeoIp2PremiumProvider
    {
        return app(MaxMindGeoIp2PremiumProvider::class);
    }

    public function testName(): void
    {
        $this->assertSame('maxmind_geoip2_premium', $this->provider()->name());
    }

    public function testNotAvailableWhenDisabled(): void
    {
        config(['shield.threat_feed.maxmind_premium' => ['enabled' => false, 'account_id' => '1', 'license_key' => 'k']]);
        $this->assertFalse($this->provider()->isAvailable());
    }

    public function testNotAvailableWithoutCredentials(): void
    {
        config(['shield.threat_feed.maxmind_premium' => ['enabled' => true, 'account_id' => null, 'license_key' => null]]);
        $this->assertFalse($this->provider()->isAvailable());
    }

    public function testSyncReturnsErrorResultOnBadDownloadWithoutThrowing(): void
    {
        config(['shield.threat_feed.maxmind_premium' => [
            'enabled' => true, 'account_id' => '1', 'license_key' => 'k', 'editions' => ['GeoIP2-Country'],
        ]]);
        Http::fake(['download.maxmind.com/*' => Http::response('not-a-valid-tarball', 200)]);

        $result = $this->provider()->sync();

        $this->assertInstanceOf(SyncResult::class, $result);
        $this->assertSame('maxmind_geoip2_premium', $result->provider);
        $this->assertFalse($result->success());
    }

    public function testFeedRunnerSkipsPremiumGeoWithoutLicense(): void
    {
        config([
            'shield.premium.license_key' => null,
            'shield.threat_feed.maxmind_premium' => ['enabled' => true, 'account_id' => '1', 'license_key' => 'k'],
        ]);
        Cache::forget('shield.premium.license');
        Http::fake();

        $runner = new FeedRunner([$this->provider()], app(AuditLogger::class));
        $results = $runner->runAll('maxmind_geoip2_premium');

        Http::assertNothingSent();
        $this->assertSame([], $results);
    }
}
