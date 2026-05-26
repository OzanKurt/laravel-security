<?php

namespace OzanKurt\Shield\Services\ThreatFeed\Providers;

use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Contracts\ThreatFeedProvider;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Services\ThreatFeed\SyncResult;

/**
 * Pulls AbuseIPDB blacklist (free tier: up to 10000 entries with confidence
 * threshold 90, refreshable every 24h). Upserts into ls_acl as kind=ip,
 * action=block, source=abuseipdb, expires_at=now()+24h.
 *
 * Requires LS_ABUSEIPDB_KEY in .env.
 */
class AbuseIpDbProvider implements ThreatFeedProvider
{
    private const ENDPOINT = 'https://api.abuseipdb.com/api/v2/blacklist';

    public function __construct(private LookupResolver $lookups) {}

    public function name(): string { return 'abuseipdb'; }
    public function label(): string { return 'AbuseIPDB'; }

    public function isAvailable(): bool
    {
        return ! empty(config('shield.threat_feed.abuseipdb.key'))
            && (bool) config('shield.threat_feed.abuseipdb.enabled', true);
    }

    public function sync(): SyncResult
    {
        $kindId = $this->lookups->id(AclKind::class, 'ip');
        $actionId = $this->lookups->id(AclAction::class, 'block');
        $key = (string) config('shield.threat_feed.abuseipdb.key');
        $threshold = (int) config('shield.threat_feed.abuseipdb.confidence_minimum', 90);

        $response = Http::timeout(20)
            ->withHeaders(['Key' => $key, 'Accept' => 'application/json'])
            ->get(self::ENDPOINT, ['confidenceMinimum' => $threshold]);

        if (! $response->successful()) {
            return new SyncResult($this->name(), error: "API returned {$response->status()}");
        }

        $data = $response->json('data') ?? [];
        $imported = 0;
        $updated = 0;

        foreach ($data as $entry) {
            $ip = $entry['ipAddress'] ?? null;
            if (! $ip) continue;

            $existing = Acl::query()->where(['kind_id' => $kindId, 'value' => $ip, 'source' => 'abuseipdb'])->first();
            $payload = [
                'kind_id' => $kindId,
                'action_id' => $actionId,
                'value' => $ip,
                'source' => 'abuseipdb',
                'reason' => 'AbuseIPDB confidence ' . ($entry['abuseConfidenceScore'] ?? '?'),
                'expires_at' => now()->addDay(),
                'meta' => [
                    'confidence' => $entry['abuseConfidenceScore'] ?? null,
                    'last_reported_at' => $entry['lastReportedAt'] ?? null,
                ],
            ];

            if ($existing) {
                $existing->update($payload);
                $updated++;
            } else {
                Acl::create($payload);
                $imported++;
            }
        }

        return new SyncResult($this->name(), imported: $imported, updated: $updated);
    }
}
