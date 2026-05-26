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
 * Pulls Spamhaus DROP + EDROP CIDR lists (no API key needed) and upserts
 * them into ls_acl as kind=cidr, action=blacklist, source=spamhaus.
 */
class SpamhausProvider implements ThreatFeedProvider
{
    private const URLS = [
        'drop' => 'https://www.spamhaus.org/drop/drop.txt',
        'edrop' => 'https://www.spamhaus.org/drop/edrop.txt',
    ];

    public function __construct(private LookupResolver $lookups) {}

    public function name(): string { return 'spamhaus'; }
    public function label(): string { return 'Spamhaus DROP/EDROP'; }

    public function isAvailable(): bool
    {
        return (bool) config('shield.threat_feed.spamhaus.enabled', true);
    }

    public function sync(): SyncResult
    {
        $kindId = $this->lookups->id(AclKind::class, 'cidr');
        $actionId = $this->lookups->id(AclAction::class, 'blacklist');

        $imported = 0;
        $updated = 0;

        foreach (self::URLS as $listName => $url) {
            try {
                $response = Http::timeout(20)->get($url);
                if (! $response->successful()) continue;
            } catch (\Throwable) {
                continue;
            }

            foreach ($this->parseCidrList($response->body()) as $cidr) {
                $existing = Acl::query()->where(['kind_id' => $kindId, 'value' => $cidr])->first();

                if ($existing) {
                    $updated++;
                    continue;
                }

                Acl::create([
                    'kind_id' => $kindId,
                    'action_id' => $actionId,
                    'value' => $cidr,
                    'source' => 'spamhaus',
                    'reason' => "Spamhaus {$listName}",
                    'meta' => ['list' => $listName],
                ]);
                $imported++;
            }
        }

        return new SyncResult($this->name(), imported: $imported, updated: $updated);
    }

    /** @return string[] */
    private function parseCidrList(string $body): array
    {
        $cidrs = [];
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, ';')) continue;
            // Format: 1.2.3.0/24 ; SBL12345
            if (preg_match('#^([0-9a-f.:]+/\d+)#i', $line, $m)) {
                $cidrs[] = $m[1];
            }
        }
        return array_unique($cidrs);
    }
}
