<?php

namespace OzanKurt\Shield\Tests\Feature\Scanner;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Models\AuditLog;
use OzanKurt\Shield\Models\Lookups\AuditLogKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Tests\TestCase;

class SignaturesSyncChannelTest extends TestCase
{
    private string $premiumUrl = 'https://api.github.test/repos/x/sigs/releases/latest';
    private string $freeUrl = 'https://api.github.test/repos/x/sigs/releases/tags/free';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shield.scanner.signatures.premium_url' => $this->premiumUrl,
            'shield.scanner.signatures.free_url' => $this->freeUrl,
            'shield.scanner.signatures.pin' => null,
        ]);
        Cache::forget('shield.premium.license');
        Http::fake(['*' => Http::response(['tag_name' => 't', 'body' => ''], 200)]);
    }

    private function licensed(): void
    {
        config(['shield.premium.license_key' => 'k']);
        Cache::put('shield.premium.license', [
            'state' => 'valid', 'valid' => true, 'features' => [],
            'last_checked_at' => now()->toIso8601String(),
        ], 3600);
    }

    public function testUsesPremiumUrlWhenLicensed(): void
    {
        $this->licensed();

        $this->artisan('shield:signatures-sync')->assertSuccessful();

        Http::assertSent(fn ($r) => $r->url() === $this->premiumUrl);
    }

    public function testUsesFreeUrlWhenNotLicensed(): void
    {
        $this->artisan('shield:signatures-sync')->assertSuccessful();

        Http::assertSent(fn ($r) => $r->url() === $this->freeUrl);
    }

    public function testPinOverridesChannelSelection(): void
    {
        $this->licensed();
        config(['shield.scanner.signatures.pin' => 'v9.9.9']);

        $this->artisan('shield:signatures-sync')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/releases/tags/v9.9.9'));
    }

    public function testLegacyUrlMapsToFreeChannelWhenChannelsUnset(): void
    {
        config([
            'shield.scanner.signatures.premium_url' => null,
            'shield.scanner.signatures.free_url' => null,
            'shield.scanner.signatures.url' => 'https://api.github.test/repos/x/sigs/releases/legacy',
        ]);

        $this->artisan('shield:signatures-sync')->assertSuccessful();

        Http::assertSent(fn ($r) => $r->url() === 'https://api.github.test/repos/x/sigs/releases/legacy');
    }

    public function testRecordsResolvedChannelInAuditMeta(): void
    {
        $this->artisan('shield:signatures-sync')->assertSuccessful();

        $kindId = app(LookupResolver::class)->id(AuditLogKind::class, 'threat_feed.sync_completed');
        $row = AuditLog::where('kind_id', $kindId)->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertSame('free', $row->meta['channel'] ?? null);
    }
}
