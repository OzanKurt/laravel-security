<?php

namespace OzanKurt\Shield\Tests\Unit\Services\Acl\Matchers;

use Illuminate\Http\Request;
use OzanKurt\Shield\Services\Acl\Matchers\CidrMatcher;
use OzanKurt\Shield\Tests\TestCase;

class CidrMatcherTest extends TestCase
{
    public function test_ip_in_cidr_range_matches(): void
    {
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.42']);
        $m = new CidrMatcher();

        $this->assertTrue($m->matches($request, '10.0.0.0/24'));
    }

    public function test_ip_outside_cidr_does_not_match(): void
    {
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '11.0.0.1']);
        $m = new CidrMatcher();

        $this->assertFalse($m->matches($request, '10.0.0.0/24'));
    }

    public function test_handles_ipv6_cidr(): void
    {
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '2001:db8::1']);
        $m = new CidrMatcher();

        $this->assertTrue($m->matches($request, '2001:db8::/32'));
        $this->assertFalse($m->matches($request, '2001:db9::/32'));
    }
}
