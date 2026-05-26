<?php

namespace OzanKurt\Shield\Tests\Feature;

use OzanKurt\Shield\Firewall\Middleware\Xss;
use OzanKurt\Shield\Tests\TestCase;

class XssTest extends TestCase
{
    public function testShouldAllow()
    {
        $this->assertEquals('next', $this->app->make(Xss::class)->handle($this->app->request, $this->getNextClosure()));
    }

    public function testShouldBlock()
    {
        $this->app->request->query->set('foo', '<script>alert(123)</script>');

        $this->assertEquals('403', $this->app->make(Xss::class)->handle($this->app->request, $this->getNextClosure())->getStatusCode());
    }
}
