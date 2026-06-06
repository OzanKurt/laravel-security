<?php

namespace OzanKurt\Shield\Tests\Feature\Scanner;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Models\Signature;
use OzanKurt\Shield\Tests\TestCase;

/**
 * Signature sync against the Central app channels (which replaced the GitHub
 * laravel-shield-signatures repo): the app returns a direct JSON array of
 * signatures, and the premium channel is authorized with the license bearer.
 */
class SignaturesSyncAppTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shield.scanner.signatures.premium_url' => 'https://central.test/api/signatures/premium',
            'shield.scanner.signatures.free_url' => 'https://central.test/api/signatures/free',
            'shield.scanner.signatures.pin' => null,
            'shield.premium.license_key' => 'lic-key-1',
        ]);

        // Active premium license so the premium channel is selected.
        Cache::put('shield.premium.license', [
            'state' => 'valid', 'valid' => true, 'features' => [],
            'last_checked_at' => now()->toIso8601String(),
        ], 3600);
    }

    public function test_consumes_direct_signature_array_from_app(): void
    {
        Http::fake(['central.test/*' => Http::response([
            ['ref' => 'app.sig.1', 'name' => 'App sig', 'category' => 'heuristic', 'kind' => 'regex', 'pattern' => '/x/i', 'severity' => 'high', 'version' => 1, 'is_enabled' => true],
        ], 200)]);

        $this->artisan('shield:signatures-sync')->assertSuccessful();

        $this->assertTrue(Signature::where(['source' => 'wf_free', 'source_ref' => 'app.sig.1'])->exists());
    }

    public function test_sends_bearer_license_key_on_premium_channel(): void
    {
        Http::fake(['central.test/*' => Http::response([
            ['ref' => 'app.sig.2', 'name' => 'x', 'category' => 'heuristic', 'kind' => 'regex', 'pattern' => '/x/', 'severity' => 'low', 'version' => 1],
        ], 200)]);

        $this->artisan('shield:signatures-sync')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/signatures/premium')
            && $r->hasHeader('Authorization', 'Bearer lic-key-1'));
    }
}
