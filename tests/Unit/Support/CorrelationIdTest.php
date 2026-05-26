<?php

namespace OzanKurt\Shield\Tests\Unit\Support;

use OzanKurt\Shield\Support\CorrelationId;
use OzanKurt\Shield\Tests\TestCase;
use Ramsey\Uuid\Uuid;

class CorrelationIdTest extends TestCase
{
    public function test_get_generates_one_uuid_per_request_lifetime(): void
    {
        $svc = $this->app->make(CorrelationId::class);

        $first = $svc->get();
        $second = $svc->get();

        $this->assertSame($first, $second);
        $this->assertSame(7, Uuid::fromString($first)->getVersion());
    }

    public function test_reset_generates_new_uuid(): void
    {
        $svc = $this->app->make(CorrelationId::class);

        $first = $svc->get();
        $svc->reset();
        $second = $svc->get();

        $this->assertNotSame($first, $second);
    }

    public function test_set_overrides_the_current_uuid(): void
    {
        $svc = $this->app->make(CorrelationId::class);

        $explicit = Uuid::uuid7()->toString();
        $svc->set($explicit);

        $this->assertSame($explicit, $svc->get());
    }
}
