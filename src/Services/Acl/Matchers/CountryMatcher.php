<?php

namespace OzanKurt\Shield\Services\Acl\Matchers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use OzanKurt\Shield\Services\Acl\GeoDatabaseResolver;
use Throwable;

/**
 * Resolves the request IP to an ISO 3166-1 alpha-2 country code via MaxMind.
 *
 * The database path comes from GeoDatabaseResolver, which prefers the premium
 * GeoIP2 City/Country DB when present and falls back to the free GeoLite2
 * Country DB. Country blocking therefore stays free; premium buyers simply get
 * higher-accuracy data automatically.
 *
 * Falls back gracefully (returns false) when no DB is present or geoip2/geoip2
 * isn't installed.
 */
class CountryMatcher implements AclMatcher
{
    public function __construct(private GeoDatabaseResolver $geo) {}

    public function matches(Request $request, string $value): bool
    {
        $code = $this->lookupCountryCode($this->resolveClientIp($request));
        if (! $code) return false;
        return strcasecmp($code, $value) === 0;
    }

    private function resolveClientIp(Request $request): string
    {
        return $request->header('CF-Connecting-IP') ?? $request->ip() ?? '';
    }

    private function lookupCountryCode(string $ip): ?string
    {
        if ($ip === '' || ! class_exists(\GeoIp2\Database\Reader::class)) {
            return null;
        }

        $dbPath = $this->geo->countryDbPath();
        if ($dbPath === null) {
            return null;
        }

        return Cache::remember('shield.geo.country.' . md5($ip), 86400, function () use ($ip, $dbPath) {
            try {
                $reader = new \GeoIp2\Database\Reader($dbPath);
                return $reader->country($ip)->country->isoCode;
            } catch (Throwable) {
                return null;
            }
        });
    }
}
