<?php

namespace OzanKurt\Security\Tests\Feature;

use OzanKurt\Security\Middleware\Ip;
use OzanKurt\Security\Models\Ip as Model;
use OzanKurt\Security\Tests\TestCase;

class IpTest extends TestCase
{
    public function testShouldAllow()
    {
        $this->assertEquals('next', (new Ip())->handle($this->app->request, $this->getNextClosure()));
    }

    public function testShouldBlock()
    {
        Model::create(['ip' => '127.0.0.1', 'is_blocked' => 1]);

        $response = (new Ip())->handle($this->app->request, $this->getNextClosure());

        $this->assertEquals('403', $response->getStatusCode());
    }
}
