<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Database\Seeders\EmbeddedSignatureSeeder;
use OzanKurt\Shield\Models\Lookups\LogLevel;
use OzanKurt\Shield\Models\Lookups\SignatureCategory;
use OzanKurt\Shield\Models\Signature;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use Throwable;

class SignaturesSyncCommand extends Command
{
    protected $signature = 'shield:signatures-sync {--force : Re-apply all signatures even if version matches} {--embedded : Use embedded fallback signatures only, skip remote}';

    protected $description = 'Sync malware signatures from the GitHub Releases feed, falling back to embedded signatures when remote is unreachable.';

    public function handle(LookupResolver $lookups, AuditLogger $audit): int
    {
        $useEmbedded = (bool) $this->option('embedded');
        $force = (bool) $this->option('force');

        if ($useEmbedded) {
            return $this->applyEmbedded($audit);
        }

        $url = (string) config('shield.scanner.signatures.url');
        $pin = config('shield.scanner.signatures.pin');

        if ($pin) {
            $url = $this->pinnedUrl($url, (string) $pin);
        }

        $this->line("Fetching {$url}");

        try {
            $response = Http::timeout(15)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get($url);
        } catch (Throwable $e) {
            $this->warn("Remote unreachable ({$e->getMessage()}). Falling back to embedded signatures.");
            return $this->applyEmbedded($audit);
        }

        if (! $response->successful()) {
            $this->warn("Remote returned HTTP {$response->status()}. Falling back to embedded signatures.");
            return $this->applyEmbedded($audit);
        }

        $payload = $response->json();
        $signatures = $this->extractSignaturesFromRelease($payload);

        if (empty($signatures)) {
            $this->warn('No signatures found in remote payload. Falling back to embedded signatures.');
            return $this->applyEmbedded($audit);
        }

        $applied = $this->applyRemote($signatures, $lookups, $force);

        $audit->log('threat_feed.sync_completed', "Synced {$applied} remote signatures", [
            'severity' => 'low',
            'meta' => ['source' => 'remote', 'count' => $applied, 'release_tag' => $payload['tag_name'] ?? null],
        ]);

        $this->info("Synced {$applied} remote signatures.");

        return self::SUCCESS;
    }

    private function applyEmbedded(AuditLogger $audit): int
    {
        $beforeCount = Signature::where('source', 'builtin_native')->count();
        (new EmbeddedSignatureSeeder())->run();
        $afterCount = Signature::where('source', 'builtin_native')->count();
        $added = $afterCount - $beforeCount;

        $audit->log('threat_feed.sync_completed', "Applied embedded signatures (added {$added}, total {$afterCount})", [
            'severity' => 'low',
            'meta' => ['source' => 'embedded', 'added' => $added, 'total' => $afterCount],
        ]);

        $this->info("Applied embedded signatures: {$afterCount} total, {$added} new.");

        return self::SUCCESS;
    }

    private function pinnedUrl(string $url, string $pin): string
    {
        // /releases/latest → /releases/tags/<pin>
        return preg_replace('#/releases/latest$#', '/releases/tags/' . rawurlencode($pin), $url);
    }

    /**
     * Extract a normalized signature list from a GitHub Releases payload.
     *
     * The release is expected to expose a `signatures.json` asset whose `browser_download_url`
     * we fetch separately, OR a `body` field containing inline JSON.
     */
    private function extractSignaturesFromRelease(array $payload): array
    {
        // Strategy 1: a downloadable asset named signatures.json
        foreach ($payload['assets'] ?? [] as $asset) {
            if (($asset['name'] ?? null) === 'signatures.json') {
                try {
                    $assetResponse = Http::timeout(15)->get($asset['browser_download_url'] ?? '');
                    if ($assetResponse->successful()) {
                        $decoded = $assetResponse->json();
                        if (is_array($decoded) && ! empty($decoded)) {
                            return $decoded;
                        }
                    }
                } catch (Throwable) {
                    // Fall through to other strategies
                }
            }
        }

        // Strategy 2: inline JSON in the release body — look for a fenced ```json block
        $body = (string) ($payload['body'] ?? '');
        if (preg_match('/```json\s*([\s\S]+?)\s*```/', $body, $match)) {
            $decoded = json_decode($match[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function applyRemote(array $signatures, LookupResolver $lookups, bool $force): int
    {
        $applied = 0;

        foreach ($signatures as $sig) {
            // Expected shape (each item):
            //   { ref, name, category, kind (regex|file_hash|string_match),
            //     pattern, severity, version, description }
            $ref = $sig['ref'] ?? null;
            $name = $sig['name'] ?? null;

            if (! $ref || ! $name) {
                $this->warn('Skipping signature without ref/name');
                continue;
            }

            $existing = Signature::where(['source' => 'wf_free', 'source_ref' => $ref])->first();
            $remoteVersion = (int) ($sig['version'] ?? 1);

            if ($existing && $existing->version >= $remoteVersion && ! $force) {
                continue;
            }

            Signature::updateOrCreate(
                ['source' => 'wf_free', 'source_ref' => $ref],
                [
                    'name' => $name,
                    'description' => $sig['description'] ?? null,
                    'category_id' => $lookups->id(SignatureCategory::class, $sig['category'] ?? 'heuristic'),
                    'kind' => $sig['kind'] ?? 'regex',
                    'pattern' => $sig['pattern'] ?? '',
                    'severity_id' => $lookups->id(LogLevel::class, $sig['severity'] ?? 'medium'),
                    'is_enabled' => $sig['is_enabled'] ?? true,
                    'version' => $remoteVersion,
                ],
            );

            $applied++;
        }

        return $applied;
    }
}
