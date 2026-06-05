<?php

namespace OzanKurt\Shield\Services\ThreatFeed\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Contracts\ThreatFeedProvider;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Services\ThreatFeed\Support\ImportsAclBlocklist;
use OzanKurt\Shield\Services\ThreatFeed\SyncResult;

/**
 * Premium-only provider for CrowdStrike Falcon Intelligence indicators. Uses the
 * operator's own OAuth2 client credentials to fetch IP-address IOCs and imports
 * them into ls_acl as block-tier rows, filtered by malicious_confidence.
 *
 * The OAuth token is cached until shortly before it expires so repeat syncs do
 * not re-authenticate. FeedRunner gates this behind a valid premium license; it
 * gates the integration, not the data (the buyer needs their own Falcon plan).
 */
class CrowdStrikeProvider implements ThreatFeedProvider
{
    use ImportsAclBlocklist;

    private const TOKEN_CACHE_KEY = 'shield.threat_feed.crowdstrike.token';
    private const CONFIDENCE_RANK = ['low' => 1, 'medium' => 2, 'high' => 3];

    public function __construct(private LookupResolver $lookups) {}

    public function name(): string { return 'crowdstrike'; }
    public function label(): string { return 'CrowdStrike Falcon Intelligence (Premium)'; }

    public function isAvailable(): bool
    {
        $cfg = (array) config('shield.threat_feed.crowdstrike', []);

        return (bool) ($cfg['enabled'] ?? false)
            && ! empty($cfg['client_id'])
            && ! empty($cfg['client_secret']);
    }

    public function sync(): SyncResult
    {
        $cfg = (array) config('shield.threat_feed.crowdstrike', []);
        $maxImport = (int) ($cfg['max_import'] ?? 50000);
        $minRank = self::CONFIDENCE_RANK[strtolower((string) ($cfg['min_confidence'] ?? 'high'))] ?? 3;

        try {
            $token = $this->token($cfg);
            $resources = $this->fetchIndicators($cfg, $token);
        } catch (\Throwable $e) {
            return new SyncResult($this->name(), error: $e->getMessage());
        }

        $rows = [];
        foreach ($resources as $resource) {
            if (($resource['type'] ?? null) !== 'ip_address') {
                continue;
            }
            $confidence = strtolower((string) ($resource['malicious_confidence'] ?? ''));
            if ((self::CONFIDENCE_RANK[$confidence] ?? 0) < $minRank) {
                continue;
            }
            $ip = (string) ($resource['indicator'] ?? '');
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            $rows[] = [
                'value' => $ip,
                'reason' => "CrowdStrike Falcon ({$confidence} confidence)",
                'meta' => ['confidence' => $confidence],
            ];
        }

        $stats = $this->importIpBlocklist($this->lookups, $this->name(), $rows, $maxImport);

        return new SyncResult($this->name(), imported: $stats['imported'], updated: $stats['updated']);
    }

    /**
     * @param array<string,mixed> $cfg
     */
    private function token(array $cfg): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $base = rtrim((string) ($cfg['base_url'] ?? 'https://api.crowdstrike.com'), '/');
        $response = Http::asForm()->timeout(30)->post($base . '/oauth2/token', [
            'client_id' => (string) ($cfg['client_id'] ?? ''),
            'client_secret' => (string) ($cfg['client_secret'] ?? ''),
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException("crowdstrike_auth_http_{$response->status()}");
        }

        $token = (string) $response->json('access_token', '');
        if ($token === '') {
            throw new \RuntimeException('crowdstrike_auth_no_token');
        }

        $ttl = (int) $response->json('expires_in', 1800);
        Cache::put(self::TOKEN_CACHE_KEY, $token, max(60, $ttl - 60));

        return $token;
    }

    /**
     * @param array<string,mixed> $cfg
     * @return array<int, array<string,mixed>>
     */
    private function fetchIndicators(array $cfg, string $token): array
    {
        $base = rtrim((string) ($cfg['base_url'] ?? 'https://api.crowdstrike.com'), '/');
        $response = Http::withToken($token)->timeout(60)->get($base . '/intel/combined/indicators', [
            'filter' => "type:'ip_address'",
            'limit' => (int) ($cfg['page_limit'] ?? 1000),
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException("crowdstrike_indicators_http_{$response->status()}");
        }

        return (array) ($response->json('resources') ?? []);
    }
}
