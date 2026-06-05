<?php

namespace OzanKurt\Shield\Tests\Feature\ThreatFeed;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use OzanKurt\Shield\Services\ThreatFeed\FeedRunner;
use OzanKurt\Shield\Services\ThreatFeed\Providers\EmergingThreatsProvider;
use OzanKurt\Shield\Tests\TestCase;

class EmergingThreatsProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['shield.threat_feed.emerging_threats' => [
            'enabled' => true, 'token' => 'oink', 'min_score' => 70, 'max_import' => 50000,
            'url' => 'https://et.test/rep.txt',
        ]]);
    }

    private function provider(): EmergingThreatsProvider
    {
        return app(EmergingThreatsProvider::class);
    }

    public function testName(): void
    {
        $this->assertSame('emerging_threats', $this->provider()->name());
    }

    public function testNotAvailableWithoutToken(): void
    {
        config(['shield.threat_feed.emerging_threats.token' => null]);
        $this->assertFalse($this->provider()->isAvailable());
    }

    public function testSyncImportsBlockRowsFilteredByScore(): void
    {
        Http::fake(['et.test/*' => Http::response("1.1.1.1,CnC,100\n2.2.2.2,Spam,50\n3.3.3.3,CnC,80\n# comment line\n\n", 200)]);

        $result = $this->provider()->sync();

        $this->assertTrue($result->success());
        $this->assertSame(2, $result->imported);
        $this->assertTrue(Acl::where(['source' => 'emerging_threats', 'value' => '1.1.1.1'])->exists());
        $this->assertTrue(Acl::where(['source' => 'emerging_threats', 'value' => '3.3.3.3'])->exists());
        $this->assertFalse(Acl::where(['source' => 'emerging_threats', 'value' => '2.2.2.2'])->exists());
    }

    public function testSyncSendsTokenAuthorization(): void
    {
        Http::fake(['et.test/*' => Http::response('1.1.1.1,CnC,100', 200)]);

        $this->provider()->sync();

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer oink'));
    }

    public function testSyncRespectsMaxImportCap(): void
    {
        config(['shield.threat_feed.emerging_threats.max_import' => 1]);
        Http::fake(['et.test/*' => Http::response("1.1.1.1,CnC,100\n3.3.3.3,CnC,90\n", 200)]);

        $result = $this->provider()->sync();

        $this->assertSame(1, $result->imported);
    }

    public function testSyncReturnsErrorOnHttpFailure(): void
    {
        Http::fake(['et.test/*' => Http::response('forbidden', 403)]);

        $result = $this->provider()->sync();

        $this->assertFalse($result->success());
    }

    public function testFeedRunnerSkipsWithoutPremiumLicense(): void
    {
        config(['shield.premium.license_key' => null]);
        Cache::forget('shield.premium.license');
        Http::fake();

        $runner = new FeedRunner([$this->provider()], app(AuditLogger::class));
        $results = $runner->runAll('emerging_threats');

        Http::assertNothingSent();
        $this->assertSame([], $results);
    }
}
