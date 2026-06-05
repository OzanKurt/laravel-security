<?php

namespace OzanKurt\Shield\Services\Acl\Matchers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use OzanKurt\Shield\Services\Acl\GeoDatabaseResolver;
use Throwable;

/**
 * Matches the request IP against an Autonomous System Number, e.g. "AS12345".
 *
 * The database path comes from GeoDatabaseResolver, which prefers the premium
 * GeoIP2 ISP DB when present and falls back to the free GeoLite2 ASN DB.
 * Returns false gracefully when no DB or the geoip2 library is missing.
 */
class AsnMatcher implements AclMatcher
{
    public function __construct(private GeoDatabaseResolver $geo) {}

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

        $dbPath = $this->geo->asnDbPath();
        if ($dbPath === null) {
            return null;
        }

        return Cache::remember('shield.geo.asn.' . md5($ip), 86400, function () use ($ip, $dbPath) {
            try {
                $reader = new \GeoIp2\Database\Reader($dbPath);
                return $reader->asn($ip)->autonomousSystemNumber;
            } catch (Throwable) {
                return null;
            }
        });
    }
}
