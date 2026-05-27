<?php

namespace OzanKurt\Shield\Services\Acl\Matchers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Resolves the request IP to an ISO 3166-1 alpha-2 country code via the
 * MaxMind GeoLite2-Country MMDB (synced by MaxMindGeoLite2Provider in 1.1.0).
 *
 * Falls back gracefully (returns false) when the DB isn't present or
 * geoip2/geoip2 isn't installed.
 */
class CountryMatcher implements AclMatcher
{
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

        return Cache::remember('shield.geo.country.' . md5($ip), 86400, function () use ($ip) {
            try {
                $dbPath = storage_path('shield/geo/GeoLite2-Country.mmdb');
                if (! is_file($dbPath)) return null;
                $reader = new \GeoIp2\Database\Reader($dbPath);
                return $reader->country($ip)->country->isoCode;
            } catch (Throwable) {
                return null;
            }
        });
    }
}
