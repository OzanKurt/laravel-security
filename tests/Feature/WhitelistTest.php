<?php

namespace OzanKurt\Security\Tests\Feature;

use OzanKurt\Security\Middleware\Whitelist;
use OzanKurt\Security\Tests\TestCase;

class WhitelistTest extends TestCase
{
    public function testShouldAllow()
    {
        config(['security.whitelist' => ['127.0.0.0/24']]);

        $this->assertEquals('next', (new Whitelist())->handle($this->app->request, $this->getNextClosure()));
    }

    public function testShouldAllowMultiple()
    {
        config(['security.whitelist' => ['127.0.0.0/24', '127.0.0.1']]);

        $this->assertEquals('next', (new Whitelist())->handle($this->app->request, $this->getNextClosure()));
    }

    public function testShouldBlock()
    {
        $this->assertEquals('403', (new Whitelist())->handle($this->app->request, $this->getNextClosure())->getStatusCode());
    }
}
