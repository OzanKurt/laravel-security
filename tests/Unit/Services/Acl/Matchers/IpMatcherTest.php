<?php

namespace OzanKurt\Shield\Tests\Unit\Services\Acl\Matchers;

use Illuminate\Http\Request;
use OzanKurt\Shield\Services\Acl\Matchers\IpMatcher;
use OzanKurt\Shield\Tests\TestCase;

class IpMatcherTest extends TestCase
{
    public function test_matches_exact_ip(): void
    {
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '1.2.3.4']);
        $matcher = new IpMatcher();

        $this->assertTrue($matcher->matches($request, '1.2.3.4'));
        $this->assertFalse($matcher->matches($request, '5.6.7.8'));
    }

    public function test_handles_cloudflare_header(): void
    {
        $request = Request::create('/', 'GET', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_CF_CONNECTING_IP' => '9.9.9.9',
        ]);
        $matcher = new IpMatcher();

        $this->assertTrue($matcher->matches($request, '9.9.9.9'));
    }
}
