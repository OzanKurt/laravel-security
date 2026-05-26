<?php

namespace OzanKurt\Shield\Tests\Feature;

use OzanKurt\Shield\Middleware\Ip;
use OzanKurt\Shield\Models\Ip as IpModel;
use OzanKurt\Shield\Tests\TestCase;

class IpTest extends TestCase
{
    public function testShouldAllow()
    {
        $this->assertEquals('next', (new Ip())->handle($this->app->request, $this->getNextClosure()));
    }

    public function testShouldBlock()
    {
        IpModel::create(['ip' => '127.0.0.1', 'is_blocked' => 1]);

        $response = (new Ip())->handle($this->app->request, $this->getNextClosure());

        $this->assertEquals('403', $response->getStatusCode());
    }
}
