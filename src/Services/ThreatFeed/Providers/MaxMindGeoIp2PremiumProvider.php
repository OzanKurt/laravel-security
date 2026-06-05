<?php

namespace OzanKurt\Shield\Services\ThreatFeed\Providers;

use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Contracts\ThreatFeedProvider;
use OzanKurt\Shield\Services\Acl\GeoDatabaseResolver;
use OzanKurt\Shield\Services\ThreatFeed\Support\ExtractsMmdb;
use OzanKurt\Shield\Services\ThreatFeed\SyncResult;

/**
 * Premium-only provider. Downloads the paid MaxMind GeoIP2 databases
 * (GeoIP2-Country, GeoIP2-City, optionally GeoIP2-ISP) to
 * storage/shield/geo/premium/ using a paid MaxMind account.
 *
 * Unlike the free GeoLite2 provider, these databases offer higher accuracy and
 * city/region precision. Once present, GeoDatabaseResolver makes the geo ACL
 * matchers prefer them automatically. FeedRunner gates this provider behind a
 * valid premium license; it never runs on a free install.
 *
 * Writes no ACL/WAF rows itself, it only manages the MMDB files.
 */
class MaxMindGeoIp2PremiumProvider implements ThreatFeedProvider
{
    use ExtractsMmdb;

    private const DOWNLOAD_ENDPOINT = 'https://download.maxmind.com/app/geoip_download';

    public function __construct(private GeoDatabaseResolver $geo) {}

    public function name(): string { return 'maxmind_geoip2_premium'; }
    public function label(): string { return 'MaxMind GeoIP2 City/Country (Premium)'; }

    public function isAvailable(): bool
    {
        $cfg = (array) config('shield.threat_feed.maxmind_premium', []);

        return (bool) ($cfg['enabled'] ?? false)
            && ! empty($cfg['account_id'])
            && ! empty($cfg['license_key'])
            && class_exists(\GeoIp2\Database\Reader::class);
    }

    public function sync(): SyncResult
    {
        $cfg = (array) config('shield.threat_feed.maxmind_premium', []);
        $licenseKey = (string) ($cfg['license_key'] ?? '');
        $editions = (array) ($cfg['editions'] ?? ['GeoIP2-Country', 'GeoIP2-City']);

        $targetDir = $this->geo->premiumDir();
        if (! is_dir($targetDir)) {
            @mkdir($targetDir, 0700, true);
        }

        $imported = 0;
        foreach ($editions as $edition) {
            $url = self::DOWNLOAD_ENDPOINT
                . "?edition_id={$edition}&license_key={$licenseKey}&suffix=tar.gz";

            try {
                $tarPath = $targetDir . "/{$edition}.tar.gz";
                $contents = Http::timeout(120)->get($url)->body();
                file_put_contents($tarPath, $contents);

                if (! $this->extractMmdb($tarPath, $targetDir, "{$edition}.mmdb")) {
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
