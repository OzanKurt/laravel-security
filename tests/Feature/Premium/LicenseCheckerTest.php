<?php

namespace OzanKurt\Shield\Tests\Feature\Premium;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Services\Premium\LicenseChecker;
use OzanKurt\Shield\Tests\TestCase;

class LicenseCheckerTest extends TestCase
{
    private function makeChecker(): LicenseChecker
    {
        return new LicenseChecker(app(CacheRepository::class));
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Reset cache between tests so each starts from a clean state.
        Cache::forget(config('shield.premium.cache_key', 'shield.premium.license'));
    }

    public function testStateNoKeyWhenNoLicenseConfigured(): void
    {
        config(['shield.premium.license_key' => null]);
        $checker = $this->makeChecker();

        $state = $checker->state();

        $this->assertSame('no_key', $state['state']);
        $this->assertFalse($checker->isPremium());
    }

    public function testCentralReturnsValidPersistsStateValid(): void
    {
        config([
            'shield.premium.license_key' => 'abcd1234ef567890ghijklmn',
            'shield.premium.check_url' => 'https://central.test/api/license/check',
        ]);
        Http::fake([
            'central.test/api/license/check' => Http::response([
                'valid' => true,
                'plan' => 'pro',
                'features' => ['scanner.pro', 'central.sync'],
                'expires_at' => '2099-01-01T00:00:00Z',
                'domain_limit' => 5,
                'domains_used' => 1,
            ], 200),
        ]);
        $checker = $this->makeChecker();

        $state = $checker->state();

        $this->assertSame('valid', $state['state']);
        $this->assertTrue($state['valid']);
        $this->assertSame('pro', $state['plan']);
        $this->assertTrue($checker->isPremium());
    }

    public function testCentralReturnsValidFalsePersistsStateInvalid(): void
    {
        config([
            'shield.premium.license_key' => 'abcd1234ef567890ghijklmn',
            'shield.premium.check_url' => 'https://central.test/api/license/check',
        ]);
        Http::fake([
            'central.test/api/license/check' => Http::response([
                'valid' => false,
                'reason' => 'revoked',
            ], 200),
        ]);
        $checker = $this->makeChecker();

        $state = $checker->state();

        $this->assertSame('invalid', $state['state']);
        $this->assertFalse($checker->isPremium());
        $this->assertSame('revoked', $state['reason']);
    }

    public function testCentralUnreachableWithPriorValidEntersGrace(): void
    {
        config([
            'shield.premium.license_key' => 'abcd1234ef567890ghijklmn',
            'shield.premium.check_url' => 'https://central.test/api/license/check',
            'shield.premium.grace_period_days' => 7,
        ]);
        $cacheKey = config('shield.premium.cache_key');

        // Seed cache with prior valid state from earlier today
        Cache::put($cacheKey, [
            'state' => 'valid',
            'valid' => true,
            'plan' => 'pro',
            'features' => ['scanner.pro'],
            'last_checked_at' => now()->subDays(2)->toIso8601String(),
        ], 86400);

        // Now Central goes down (HTTP 500)
        Http::fake([
            'central.test/api/license/check' => Http::response([], 500),
        ]);

        $checker = $this->makeChecker();
        $state = $checker->refresh();

        $this->assertSame('grace', $state['state']);
        $this->assertTrue($state['valid']);
        $this->assertArrayHasKey('grace_until', $state);
        $this->assertTrue($checker->isPremium());
    }

    public function testCentralUnreachableWithNoPriorValidIsInvalidShortTtl(): void
    {
        config([
            'shield.premium.license_key' => 'abcd1234ef567890ghijklmn',
            'shield.premium.check_url' => 'https://central.test/api/license/check',
        ]);

        Http::fake([
            'central.test/api/license/check' => Http::response([], 500),
        ]);

        $checker = $this->makeChecker();
        $state = $checker->state();

        $this->assertSame('invalid', $state['state']);
        $this->assertSame('api_unreachable_no_prior_valid', $state['reason']);
        $this->assertFalse($checker->isPremium());
    }

    public function testGraceExpiresAfterGracePeriodDays(): void
    {
        config([
            'shield.premium.license_key' => 'abcd1234ef567890ghijklmn',
            'shield.premium.check_url' => 'https://central.test/api/license/check',
            'shield.premium.grace_period_days' => 7,
        ]);
        $cacheKey = config('shield.premium.cache_key');

        // Seed cache with prior valid state from 10 days ago (beyond 7-day grace)
        Cache::put($cacheKey, [
            'state' => 'valid',
            'valid' => true,
            'last_checked_at' => now()->subDays(10)->toIso8601String(),
        ], 86400);

        Http::fake([
            'central.test/api/license/check' => Http::response([], 500),
        ]);

        $checker = $this->makeChecker();
        $state = $checker->refresh();

        // Grace would have expired 3 days ago, fallback to invalid
        $this->assertSame('invalid', $state['state']);
        $this->assertSame('api_unreachable_no_prior_valid', $state['reason']);
    }

    public function testIsFeatureAvailableReturnsFalseWhenNotPremium(): void
    {
        config(['shield.premium.license_key' => null]);
        $checker = $this->makeChecker();

        $this->assertFalse($checker->isFeatureAvailable('scanner.pro'));
    }

    public function testIsFeatureAvailableReturnsTrueWhenFeatureInList(): void
    {
        $cacheKey = config('shield.premium.cache_key');
        Cache::put($cacheKey, [
            'state' => 'valid',
            'valid' => true,
            'features' => ['scanner.pro', 'central.sync'],
            'last_checked_at' => now()->toIso8601String(),
        ], 86400);
        config(['shield.premium.license_key' => 'abcd1234ef567890ghijklmn']);

        $checker = $this->makeChecker();

        $this->assertTrue($checker->isFeatureAvailable('scanner.pro'));
        $this->assertFalse($checker->isFeatureAvailable('nope.missing'));
    }

    public function testIsFeatureAvailableReturnsTrueForAnyFeatureWhenFeaturesEmpty(): void
    {
        $cacheKey = config('shield.premium.cache_key');
        Cache::put($cacheKey, [
            'state' => 'valid',
            'valid' => true,
            'features' => [],
            'last_checked_at' => now()->toIso8601String(),
        ], 86400);
        config(['shield.premium.license_key' => 'abcd1234ef567890ghijklmn']);

        $checker = $this->makeChecker();

        $this->assertTrue($checker->isFeatureAvailable('anything-goes'));
    }

    public function testMaskedKeyShowsFirstEightAndLastFour(): void
    {
        config(['shield.premium.license_key' => 'abcdefgh' . 'XXXXXXXXX' . 'wxyz']);
        $checker = $this->makeChecker();

        $masked = $checker->maskedKey();

        $this->assertSame('abcdefgh' . str_repeat('*', 9) . 'wxyz', $masked);
    }

    public function testMaskedKeyReturnsNullWhenNoKey(): void
    {
        config(['shield.premium.license_key' => null]);
        $checker = $this->makeChecker();

        $this->assertNull($checker->maskedKey());
    }

    public function testCachedStateReturnsNullWhenNoCache(): void
    {
        $checker = $this->makeChecker();

        $this->assertNull($checker->cachedState());
    }

    public function testCachedStateReturnsArrayWhenCached(): void
    {
        $cacheKey = config('shield.premium.cache_key');
        $payload = [
            'state' => 'valid',
            'valid' => true,
            'last_checked_at' => now()->toIso8601String(),
        ];
        Cache::put($cacheKey, $payload, 86400);

        $checker = $this->makeChecker();

        $cached = $checker->cachedState();
        $this->assertIsArray($cached);
        $this->assertSame('valid', $cached['state']);
    }

    public function testIsPremiumCachedDoesNotTriggerHttp(): void
    {
        config([
            'shield.premium.license_key' => 'abcd1234ef567890ghijklmn',
            'shield.premium.check_url' => 'https://central.test/api/license/check',
        ]);

        // Fake any HTTP call as failing so we know HTTP wasn't called
        Http::fake();
        Http::preventStrayRequests();

        $checker = $this->makeChecker();

        $this->assertFalse($checker->isPremiumCached());
        // No exception thrown means no HTTP was attempted
        Http::assertNothingSent();
    }

    public function testClearCacheForgetsTheCacheEntry(): void
    {
        $cacheKey = config('shield.premium.cache_key');
        Cache::put($cacheKey, ['state' => 'valid', 'valid' => true, 'last_checked_at' => now()->toIso8601String()], 86400);

        $this->assertNotNull(Cache::get($cacheKey));

        $checker = $this->makeChecker();
        $checker->clearCache();

        $this->assertNull(Cache::get($cacheKey));
    }

    public function testRefreshBypassesCacheFreshness(): void
    {
        config([
            'shield.premium.license_key' => 'abcd1234ef567890ghijklmn',
            'shield.premium.check_url' => 'https://central.test/api/license/check',
        ]);
        $cacheKey = config('shield.premium.cache_key');

        // Seed a "fresh" cache that state() would respect
        Cache::put($cacheKey, [
            'state' => 'invalid',
            'valid' => false,
            'reason' => 'revoked',
            'last_checked_at' => now()->toIso8601String(),
        ], 86400);

        Http::fake([
            'central.test/api/license/check' => Http::response([
                'valid' => true,
                'plan' => 'pro',
                'features' => ['scanner.pro'],
            ], 200),
        ]);

        $checker = $this->makeChecker();
        $state = $checker->refresh();

        // refresh() must hit Central even though cache was fresh
        $this->assertSame('valid', $state['state']);
        $this->assertTrue($state['valid']);
        Http::assertSentCount(1);
    }
}
