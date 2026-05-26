<?php

namespace OzanKurt\Shield\Tests\Feature;

use OzanKurt\Shield\Middleware\Lfi;
use OzanKurt\Shield\Tests\TestCase;

class LfiTest extends TestCase
{
    public function testShouldAllow()
    {
        $this->assertEquals('next', (new Lfi())->handle($this->app->request, $this->getNextClosure()));
    }

    public function testShouldBlock()
    {
        $this->app->request->query->set('foo', '../../../../etc/passwd');

        $this->assertEquals('403', (new Lfi())->handle($this->app->request, $this->getNextClosure())->getStatusCode());
    }
}
