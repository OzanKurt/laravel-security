<?php

namespace OzanKurt\Shield\Services\Acl\Matchers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Matches the request IP against an Autonomous System Number, e.g. "AS12345".
 * Backed by the MaxMind GeoLite2-ASN MMDB (synced via 1.1.0 threat feed
 * provider). Returns false gracefully when DB or geoip2 library missing.
 */
class AsnMatcher implements AclMatcher
{
    public function matches(Request $request, string $value): bool
    {
        $asn = $this->lookupAsn($this->resolveClientIp($request));
        if ($asn === null) return false;

        $needle = preg_replace('/^AS/i', '', $value);
        return (string) $asn === (string) $needle;
    }

    private function resolveClientIp(Request $request): string
    {
        return $request->header('CF-Connecting-IP') ?? $request->ip() ?? '';
    }

    private function lookupAsn(string $ip): ?int
    {
        if ($ip === '' || ! class_exists(\GeoIp2\Database\Reader::class)) {
            return null;
        }

        return Cache::remember('shield.geo.asn.' . md5($ip), 86400, function () use ($ip) {
            try {
                $dbPath = storage_path('shield/geo/GeoLite2-ASN.mmdb');
                if (! is_file($dbPath)) return null;
                $reader = new \GeoIp2\Database\Reader($dbPath);
                return $reader->asn($ip)->autonomousSystemNumber;
            } catch (Throwable) {
                return null;
            }
        });
    }
}
