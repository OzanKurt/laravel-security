<?php

namespace OzanKurt\Shield\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use OzanKurt\Shield\Tests\TestCase;

class CacheDashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Override the gate after boot so it takes precedence over the service
        // provider's default (which registers a deferred false-returning callback).
        Gate::define('viewShieldDashboard', fn () => true);
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('app.key', 'base64:Lf+1x2r3feOZ2hfF6Ksn6JSwbR4yGJ3vYri6EGr/EuA=');
        $app['config']->set('shield.dashboard.enabled', true);
        // Remove auth middleware so unauthenticated test requests pass through.
        $app['config']->set('shield.dashboard.middleware', []);
    }

    public function test_cache_index_renders(): void
    {
        $this->get(route('shield.cache.index'))
            ->assertOk()
            ->assertSee('Shield cache keys');
    }

    public function test_cache_clear_single_key_returns_json(): void
    {
        Cache::forever('shield.acl.live', ['x']);

        $this->post(route('shield.cache.clear'), ['key' => 'shield.acl.live'])
            ->assertOk()
            ->assertJson(['ok' => true, 'cleared' => 'shield.acl.live']);

        $this->assertNull(Cache::get('shield.acl.live'));
    }

    public function test_cache_clear_all_returns_json(): void
    {
        Cache::forever('shield.lookups.AclKind', ['ip' => 1]);

        $this->post(route('shield.cache.clear'), ['key' => '*'])
            ->assertOk()
            ->assertJson(['ok' => true, 'cleared' => 'all']);
    }
}
