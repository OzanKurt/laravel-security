<?php

namespace OzanKurt\Shield\Services\ThreatFeed\Providers;

use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Contracts\ThreatFeedProvider;
use OzanKurt\Shield\Services\ThreatFeed\Support\ExtractsMmdb;
use OzanKurt\Shield\Services\ThreatFeed\SyncResult;

/**
 * Downloads + extracts MaxMind GeoLite2 Country + ASN MMDBs to
 * storage/shield/geo/. Requires LS_MAXMIND_LICENSE_KEY (free signup
 * required at maxmind.com).
 *
 * Doesn't write any ACL/WAF rows itself — once the DBs are in place,
 * the CountryMatcher / AsnMatcher stubs from beta.1 swap to real
 * implementations driven by geoip2/geoip2 reader.
 */
class MaxMindGeoLite2Provider implements ThreatFeedProvider
{
    use ExtractsMmdb;

    public function __construct() {}

    public function name(): string { return 'maxmind_geolite2'; }
    public function label(): string { return 'MaxMind GeoLite2 (Country + ASN)'; }

    public function isAvailable(): bool
    {
        return ! empty(config('shield.threat_feed.maxmind.license_key'))
            && class_exists(\GeoIp2\Reader::class);
    }

    public function sync(): SyncResult
    {
        $licenseKey = (string) config('shield.threat_feed.maxmind.license_key');
        $targetDir = storage_path('shield/geo');
        if (! is_dir($targetDir)) {
            @mkdir($targetDir, 0700, true);
        }

        $editions = [
            'GeoLite2-Country' => 'GeoLite2-Country.mmdb',
            'GeoLite2-ASN' => 'GeoLite2-ASN.mmdb',
        ];

        $imported = 0;
        foreach ($editions as $edition => $filename) {
            $url = "https://download.maxmind.com/app/geoip_download"
                . "?edition_id={$edition}&license_key={$licenseKey}&suffix=tar.gz";

            try {
                $tarPath = $targetDir . "/{$edition}.tar.gz";
                $contents = Http::timeout(120)->get($url)->body();
                file_put_contents($tarPath, $contents);

                if (! $this->extractMmdb($tarPath, $targetDir, $filename)) {
                    continue;
                }

                $imported++;
            } catch (\Throwable $e) {
                return new SyncResult($this->name(), imported: $imported, error: $e->getMessage());
            }
        }

        return new SyncResult($this->name(), imported: $imported);
    }
}
