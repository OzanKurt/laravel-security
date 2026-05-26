<?php

namespace OzanKurt\Shield\Tests\Unit\Services\Lookups;

use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Tests\TestCase;

class LookupResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createLookupTable();
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\Schema::dropIfExists('ls_acl_kinds');
        parent::tearDown();
    }

    private function createLookupTable(): void
    {
        \Illuminate\Support\Facades\Schema::create('ls_acl_kinds', function ($table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('label', 128);
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function test_resolves_known_name_to_id(): void
    {
        AclKind::create(['name' => 'ip', 'label' => 'IP address', 'sort_order' => 1]);
        AclKind::create(['name' => 'cidr', 'label' => 'CIDR range', 'sort_order' => 2]);

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
        $kind = AclKind::create(['name' => 'ip', 'label' => 'IP']);
        $resolver = $this->app->make(LookupResolver::class);

        $this->assertSame('ip', $resolver->name(AclKind::class, $kind->id));
    }
}
