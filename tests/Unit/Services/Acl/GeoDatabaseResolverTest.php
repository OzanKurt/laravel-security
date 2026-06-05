<?php

namespace OzanKurt\Shield\Tests\Unit\Services\Acl;

use OzanKurt\Shield\Services\Acl\GeoDatabaseResolver;
use OzanKurt\Shield\Tests\TestCase;

class GeoDatabaseResolverTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/shield-geo-' . uniqid();
        @mkdir($this->dir . '/premium', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $path = $dir . '/' . $f;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function touchDb(string $relative): void
    {
        $path = $this->dir . '/' . $relative;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, 'fake-mmdb');
    }

    private function resolver(): GeoDatabaseResolver
    {
        return new GeoDatabaseResolver($this->dir);
    }

    private function norm(?string $path): ?string
    {
        return $path === null ? null : str_replace('\\', '/', $path);
    }

    public function testCountryPrefersPremiumCityOverFree(): void
    {
        $this->touchDb('GeoLite2-Country.mmdb');
        $this->touchDb('premium/GeoIP2-City.mmdb');

        $this->assertStringEndsWith('premium/GeoIP2-City.mmdb', $this->norm($this->resolver()->countryDbPath()));
    }

    public function testCountryFallsBackToFreeWhenNoPremium(): void
    {
        $this->touchDb('GeoLite2-Country.mmdb');

        $this->assertStringEndsWith('GeoLite2-Country.mmdb', $this->norm($this->resolver()->countryDbPath()));
    }

    public function testCountryNullWhenNoDatabases(): void
    {
        $this->assertNull($this->resolver()->countryDbPath());
    }

    public function testCityRequiresPremiumDatabase(): void
    {
        $this->touchDb('GeoLite2-Country.mmdb');
        $this->assertNull($this->resolver()->cityDbPath());

        $this->touchDb('premium/GeoIP2-City.mmdb');
        $this->assertNotNull($this->resolver()->cityDbPath());
    }

    public function testAsnPrefersPremiumIspOverFree(): void
    {
        $this->touchDb('GeoLite2-ASN.mmdb');
        $this->touchDb('premium/GeoIP2-ISP.mmdb');

        $this->assertStringEndsWith('premium/GeoIP2-ISP.mmdb', $this->norm($this->resolver()->asnDbPath()));
    }

    public function testHasPremiumDatabase(): void
    {
        $this->assertFalse($this->resolver()->hasPremiumDatabase());

        $this->touchDb('premium/GeoIP2-Country.mmdb');
        $this->assertTrue($this->resolver()->hasPremiumDatabase());
    }
}
