<?php

namespace OzanKurt\Shield\Tests\Unit\Services\Acl\Matchers;

use Illuminate\Http\Request;
use OzanKurt\Shield\Services\Acl\GeoDatabaseResolver;
use OzanKurt\Shield\Services\Acl\Matchers\AsnMatcher;
use OzanKurt\Shield\Services\Acl\Matchers\CountryMatcher;
use OzanKurt\Shield\Tests\TestCase;

/**
 * Without a MaxMind database (and/or geoip2/geoip2), the geo matchers must
 * degrade to false rather than throw. After the premium-GeoIP2 refactor they
 * resolve their DB path through GeoDatabaseResolver, so this also pins that
 * the matchers consult the resolver and accept it via the constructor.
 */
class GeoMatcherResolverTest extends TestCase
{
    private function emptyResolver(): GeoDatabaseResolver
    {
        return new GeoDatabaseResolver(sys_get_temp_dir() . '/shield-geo-empty-' . uniqid());
    }

    private function request(): Request
    {
        return Request::create('/', 'GET', server: ['REMOTE_ADDR' => '8.8.8.8']);
    }

    public function testCountryMatcherReturnsFalseWithoutDatabase(): void
    {
        $matcher = new CountryMatcher($this->emptyResolver());

        $this->assertFalse($matcher->matches($this->request(), 'US'));
    }

    public function testAsnMatcherReturnsFalseWithoutDatabase(): void
    {
        $matcher = new AsnMatcher($this->emptyResolver());

        $this->assertFalse($matcher->matches($this->request(), 'AS15169'));
    }
}
