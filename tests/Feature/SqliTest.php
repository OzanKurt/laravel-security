<?php

namespace OzanKurt\Security\Tests\Feature;

use OzanKurt\Security\Middleware\Sqli;
use OzanKurt\Security\Tests\TestCase;

class SqliTest extends TestCase
{
    public function testShouldAllow()
    {
        $this->assertEquals('next', (new Sqli())->handle($this->app->request, $this->getNextClosure()));
    }

    public function testShouldBlock()
    {
        $this->app->request->query->set('foo', '-1+union+select+1,2,3,4,5,6,7,8,9,(SELECT+password+FROM+users+WHERE+ID=1)');

        $this->assertEquals('403', (new Sqli())->handle($this->app->request, $this->getNextClosure())->getStatusCode());
    }
}
