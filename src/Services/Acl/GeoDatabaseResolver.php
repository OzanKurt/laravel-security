<?php

namespace OzanKurt\Shield\Services\Acl;

/**
 * Resolves which MaxMind MMDB backs a geo lookup, preferring the premium
 * GeoIP2 databases (storage/shield/geo/premium/) over the free GeoLite2
 * databases (storage/shield/geo/).
 *
 * This is what lets premium buyers get higher-accuracy country/ASN data and
 * city/region precision, while free installs keep working on GeoLite2. Country
 * and ASN blocking stay free; city precision is premium-only because the free
 * GeoLite2 sync does not ship a city database.
 */
class GeoDatabaseResolver
{
    public function __construct(private ?string $baseDir = null) {}

    /**
     * Country DB: a premium City DB resolves country too, then a premium
     * Country DB, then the free GeoLite2 Country DB.
     */
    public function countryDbPath(): ?string
    {
        return $this->firstExisting([
            $this->premium('GeoIP2-City.mmdb'),
            $this->premium('GeoIP2-Country.mmdb'),
            $this->free('GeoLite2-Country.mmdb'),
        ]);
    }

    /**
     * City/region precision is premium-only: the free GeoLite2 sync does not
     * download a city database, so this returns null on free installs.
     */
    public function cityDbPath(): ?string
    {
        return $this->firstExisting([
            $this->premium('GeoIP2-City.mmdb'),
        ]);
    }

    /**
     * ASN DB: premium ISP DB first (richer org data), then free GeoLite2 ASN.
     */
    public function asnDbPath(): ?string
    {
        return $this->firstExisting([
            $this->premium('GeoIP2-ISP.mmdb'),
            $this->premium('GeoIP2-City.mmdb'),
            $this->free('GeoLite2-ASN.mmdb'),
        ]);
    }

    /** Whether any premium GeoIP2 database is present (for diagnostics/upsell). */
    public function hasPremiumDatabase(): bool
    {
        return $this->firstExisting([
            $this->premium('GeoIP2-City.mmdb'),
            $this->premium('GeoIP2-Country.mmdb'),
            $this->premium('GeoIP2-ISP.mmdb'),
        ]) !== null;
    }

    public function premiumDir(): string
    {
        return $this->base() . '/premium';
    }

    public function freeDir(): string
    {
        return $this->base();
    }

    private function base(): string
    {
        return $this->baseDir ?? storage_path('shield/geo');
    }

    private function premium(string $file): string
    {
        return $this->premiumDir() . '/' . $file;
    }

    private function free(string $file): string
    {
        return $this->base() . '/' . $file;
    }

    /**
     * @param array<int, string> $paths
     */
    private function firstExisting(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
