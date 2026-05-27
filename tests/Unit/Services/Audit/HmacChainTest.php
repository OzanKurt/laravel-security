<?php

namespace OzanKurt\Shield\Tests\Unit\Services\Audit;

use OzanKurt\Shield\Services\Audit\HmacChain;
use OzanKurt\Shield\Tests\TestCase;

class HmacChainTest extends TestCase
{
    public function test_first_record_hashes_with_null_prev(): void
    {
        $chain = new HmacChain('test-secret');
        $hash = $chain->compute(null, ['kind' => 'auth.login', 'description' => 'user 1']);

        $this->assertSame(64, strlen($hash));
    }

    public function test_chain_links_change_when_data_changes(): void
    {
        $chain = new HmacChain('test-secret');

        $h1 = $chain->compute(null, ['kind' => 'auth.login', 'description' => 'A']);
        $h2 = $chain->compute(null, ['kind' => 'auth.login', 'description' => 'B']);

        $this->assertNotSame($h1, $h2);
    }

    public function test_same_inputs_with_same_secret_produce_same_hash(): void
    {
        $chain = new HmacChain('test-secret');

        $h1 = $chain->compute('prev-hash-fake', ['kind' => 'x', 'description' => 'y']);
        $h2 = $chain->compute('prev-hash-fake', ['kind' => 'x', 'description' => 'y']);

        $this->assertSame($h1, $h2);
    }

    public function test_different_secret_produces_different_hash(): void
    {
        $c1 = new HmacChain('secret-a');
        $c2 = new HmacChain('secret-b');

        $h1 = $c1->compute(null, ['kind' => 'x']);
        $h2 = $c2->compute(null, ['kind' => 'x']);

        $this->assertNotSame($h1, $h2);
    }
}
