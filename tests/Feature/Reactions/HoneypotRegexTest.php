<?php

namespace OzanKurt\Shield\Tests\Feature\Reactions;

use Illuminate\Http\Request;
use OzanKurt\Shield\Firewall\Middleware\HoneypotRegex;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class HoneypotRegexTest extends TestCase
{
    public function testMatchingPathTrapsAndBlocks()
    {
        config(['shield.honeypot.enabled' => true]);
        config(['shield.honeypot.regex_paths' => ['#^\.env#i']]);

        $request = Request::create('/.env', 'GET');
        $request->server->set('REMOTE_ADDR', '203.0.113.60');

        $threw = false;
        try {
            (new HoneypotRegex())->handle($request, fn ($r) => 'next');
        } catch (HttpException $e) {
            $threw = true;
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertTrue($threw, 'Expected a 404 HttpException');
        $this->assertTrue(Acl::query()->where('value', '203.0.113.60')->where('source', 'honeypot')->exists());
    }

    public function testNonMatchingPathPassesThrough()
    {
        config(['shield.honeypot.enabled' => true]);
        config(['shield.honeypot.regex_paths' => ['#^\.env#i']]);

        $request = Request::create('/home', 'GET');
        $this->assertSame('next', (new HoneypotRegex())->handle($request, fn ($r) => 'next'));
    }

    public function testInvalidPatternIsSkippedNotFatal()
    {
        config(['shield.honeypot.enabled' => true]);
        config(['shield.honeypot.regex_paths' => ['#(unclosed']]); // invalid PCRE

        $request = Request::create('/home', 'GET');
        $this->assertSame('next', (new HoneypotRegex())->handle($request, fn ($r) => 'next'));
    }
}
