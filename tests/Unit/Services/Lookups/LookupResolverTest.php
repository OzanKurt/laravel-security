<?php

namespace OzanKurt\Shield\Tests\Unit\Services\Lookups;

use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Tests\TestCase;

class LookupResolverTest extends TestCase
{
    public function test_resolves_known_name_to_id(): void
    {
        // Lookups are seeded in TestCase::setUpDatabase via LookupTableSeeder
        $resolver = $this->app->make(LookupResolver::class);

        $this->assertIsInt($resolver->id(AclKind::class, 'ip'));
        $this->assertIsInt($resolver->id(AclKind::class, 'cidr'));
        $this->assertNotSame($resolver->id(AclKind::class, 'ip'), $resolver->id(AclKind::class, 'cidr'));
    }

    public function test_returns_null_for_unknown_name(): void
    {
        $resolver = $this->app->make(LookupResolver::class);
        $this->assertNull($resolver->id(AclKind::class, 'nonsense'));
    }

    public function test_resolves_id_back_to_name(): void
    {
        $resolver = $this->app->make(LookupResolver::class);
        $ipId = $resolver->id(AclKind::class, 'ip');

        $this->assertSame('ip', $resolver->name(AclKind::class, $ipId));
    }
}
