<?php

namespace OzanKurt\Shield\Services\ThreatFeed\Providers;

use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Contracts\ThreatFeedProvider;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Services\ThreatFeed\Support\ImportsAclBlocklist;
use OzanKurt\Shield\Services\ThreatFeed\SyncResult;

/**
 * Premium-only provider for the Proofpoint Emerging Threats IP reputation feed
 * (ET Pro / Intelligence). The operator supplies their own ET token; we consume
 * it and import the IP reputation list into ls_acl as block-tier rows.
 *
 * Feed format (detailed-iprepdata): one CSV line per IP, "ip,category,score"
 * where score is 0-127. Only entries at or above min_score are imported.
 *
 * FeedRunner gates this behind a valid premium license; it never runs on a free
 * install. Note: this gates the integration, not the data, the buyer still needs
 * their own ET subscription.
 */
class EmergingThreatsProvider implements ThreatFeedProvider
{
    use ImportsAclBlocklist;

    private const DEFAULT_URL = 'https://rules.emergingthreats.net/eval/reputation/detailed-iprepdata.txt';

    public function __construct(private LookupResolver $lookups) {}

    public function name(): string { return 'emerging_threats'; }
    public function label(): string { return 'Proofpoint Emerging Threats (Premium)'; }

    public function isAvailable(): bool
    {
        $cfg = (array) config('shield.threat_feed.emerging_threats', []);

        return (bool) ($cfg['enabled'] ?? false) && ! empty($cfg['token']);
    }

    public function sync(): SyncResult
    {
        $cfg = (array) config('shield.threat_feed.emerging_threats', []);
        $token = (string) ($cfg['token'] ?? '');
        $minScore = (int) ($cfg['min_score'] ?? 70);
        $maxImport = (int) ($cfg['max_import'] ?? 50000);
        $url = (string) ($cfg['url'] ?? self::DEFAULT_URL);

        try {
            $response = Http::timeout(60)
                ->withHeaders(['Authorization' => 'Bearer ' . $token])
                ->get($url);
        } catch (\Throwable $e) {
            return new SyncResult($this->name(), error: $e->getMessage());
        }

        if (! $response->successful()) {
            return new SyncResult($this->name(), error: "API returned {$response->status()}");
        }

        $rows = $this->parseReputation($response->body(), $minScore);
        $stats = $this->importIpBlocklist($this->lookups, $this->name(), $rows, $maxImport);

        return new SyncResult($this->name(), imported: $stats['imported'], updated: $stats['updated']);
    }

    /**
     * @return array<int, array{value:string, reason:string, meta:array<string,mixed>}>
     */
    private function parseReputation(string $body, int $minScore): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode(',', $line));
            if (count($parts) < 3) {
                continue;
            }

            [$ip, $category, $score] = $parts;
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }
            if ((int) $score < $minScore) {
                continue;
            }

            $rows[] = [
                'value' => $ip,
                'reason' => "Emerging Threats {$category} (score {$score})",
                'meta' => ['category' => $category, 'score' => (int) $score],
            ];
        }

        return $rows;
    }
}
