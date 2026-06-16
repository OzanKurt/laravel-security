<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Database\Seeders\EmbeddedSignatureSeeder;
use OzanKurt\Shield\Models\Lookups\LogLevel;
use OzanKurt\Shield\Models\Lookups\SignatureCategory;
use OzanKurt\Shield\Facades\Shield;
use OzanKurt\Shield\Models\Signature;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use Throwable;

class SignaturesSyncCommand extends Command
{
    protected $signature = 'shield:signatures-sync {--force : Re-apply all signatures even if version matches} {--embedded : Use embedded fallback signatures only, skip remote}';

    protected $description = 'Sync malware signatures from the GitHub Releases feed, falling back to embedded signatures when remote is unreachable.';

    /**
     * Resolved signature channel for the current run, recorded in audit meta.
     * One of: free, premium, pinned, embedded.
     */
    private string $channel = 'embedded';

    public function handle(LookupResolver $lookups, AuditLogger $audit): int
    {
        $useEmbedded = (bool) $this->option('embedded');
        $force = (bool) $this->option('force');

        if ($useEmbedded) {
            return $this->applyEmbedded($audit);
        }

        $url = $this->resolveSignatureUrl();

        $this->line("Fetching {$url} (channel: {$this->channel})");

        try {
            $response = Http::timeout(15)
                ->withHeaders($this->requestHeaders())
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
        $signatures = $this->extractSignatures($payload);

        if (empty($signatures)) {
            $this->warn('No signatures found in remote payload. Falling back to embedded signatures.');
            return $this->applyEmbedded($audit);
        }

        $applied = $this->applyRemote($signatures, $lookups, $force);

        $audit->log('threat_feed.sync_completed', "Synced {$applied} remote signatures", [
            'severity' => 'low',
            'meta' => ['source' => 'remote', 'channel' => $this->channel, 'count' => $applied, 'release_tag' => $payload['tag_name'] ?? null],
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
            'meta' => ['source' => 'embedded', 'channel' => $this->channel, 'added' => $added, 'total' => $afterCount],
        ]);

        $this->info("Applied embedded signatures: {$afterCount} total, {$added} new.");

        return self::SUCCESS;
    }

    /**
     * Resolve which signature release to fetch and record the channel.
     *
     * Precedence:
     *   1. An explicit pin (LS_SIGNATURE_PIN) overrides everything -> releases/tags/<pin>.
     *   2. Premium license active -> premium_url (releases/latest, always fresh).
     *   3. Otherwise -> free_url (a moving "free" tag lagging latest by ~30 days),
     *      matching Wordfence's free-tier signature delay.
     *
     * Back-compat: a deployment that only set the legacy single `url` falls back
     * to it for either channel, so existing installs keep working on upgrade.
     */
    private function resolveSignatureUrl(): string
    {
        $cfg = (array) config('shield.scanner.signatures');
        $legacy = $cfg['url'] ?? null;
        $pin = $cfg['pin'] ?? null;

        if ($pin) {
            $this->channel = 'pinned';
            $base = (string) ($cfg['premium_url'] ?? $legacy ?? '');

            return $this->pinnedUrl($base, (string) $pin);
        }

        if (Shield::isFeatureAvailable('premium_signatures')) {
            $this->channel = 'premium';

            return (string) ($cfg['premium_url'] ?? $legacy ?? '');
        }

        $this->channel = 'free';

        return (string) ($cfg['free_url'] ?? $legacy ?? '');
    }

    private function pinnedUrl(string $url, string $pin): string
    {
        // /releases/latest → /releases/tags/<pin>
        return preg_replace('#/releases/latest$#', '/releases/tags/' . rawurlencode($pin), $url);
    }

    /**
     * Headers for the signature request. Sends the premium license key as a
     * bearer token so the Central app's /api/signatures/premium endpoint can
     * authorize the fresh channel server-side. The public free channel ignores
     * it. Harmless to always send when a key is configured.
     *
     * @return array<string,string>
     */
    private function requestHeaders(): array
    {
        $headers = ['Accept' => 'application/json'];

        $key = (string) config('shield.premium.license_key', '');
        if ($key !== '') {
            $headers['Authorization'] = 'Bearer ' . $key;
        }

        return $headers;
    }

    /**
     * Normalize a signature list from a response payload. The Central app's
     * /api/signatures/* endpoints return a direct JSON array of signatures;
     * legacy GitHub Releases payloads (signatures.json asset, or fenced JSON in
     * the release body) are still supported for back-compat with a pinned tag.
     *
     * @param  mixed  $payload
     */
    private function extractSignatures($payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        // Direct signature array, the Central app channel response.
        if ($this->looksLikeSignatureList($payload)) {
            return $payload;
        }

        return $this->extractSignaturesFromRelease($payload);
    }

    /**
     * Whether the payload is already a flat list of signature objects
     * (each carrying a `ref`), as returned by the Central app.
     *
     * @param  array<mixed>  $payload
     */
    private function looksLikeSignatureList(array $payload): bool
    {
        if ($payload === [] || ! array_is_list($payload)) {
            return false;
        }

        $first = $payload[0];

        return is_array($first) && isset($first['ref']);
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

        // Strategy 2: inline JSON in the release body, look for a fenced ```json block
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
