<?php

namespace OzanKurt\Shield\Services\ThreatFeed\Support;

use Illuminate\Support\Facades\Log;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

/**
 * Shared IP-blocklist import for commercial feed providers (Emerging Threats,
 * CrowdStrike). Upserts rows into ls_acl as kind=ip, action=block, keyed by
 * (source, kind, value). Enforces a max_import cap and logs (never silently
 * truncates) when the cap is hit.
 */
trait ImportsAclBlocklist
{
    /**
     * @param array<int, array{value:string, reason?:string, meta?:array<string,mixed>}> $rows
     * @return array{imported:int, updated:int, truncated:int}
     */
    protected function importIpBlocklist(LookupResolver $lookups, string $source, array $rows, int $maxImport): array
    {
        $kindId = $lookups->id(AclKind::class, 'ip');
        $actionId = $lookups->id(AclAction::class, 'block');

        $imported = 0;
        $updated = 0;
        $truncated = 0;
        $processed = 0;

        foreach ($rows as $row) {
            $value = trim((string) ($row['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            if ($maxImport > 0 && $processed >= $maxImport) {
                $truncated++;
                continue;
            }
            $processed++;

            $payload = [
                'kind_id' => $kindId,
                'action_id' => $actionId,
                'value' => $value,
                'source' => $source,
                'reason' => $row['reason'] ?? null,
                'meta' => $row['meta'] ?? null,
            ];

            $existing = Acl::where(['source' => $source, 'kind_id' => $kindId, 'value' => $value])->first();
            if ($existing) {
                $existing->update($payload);
                $updated++;
            } else {
                Acl::create($payload);
                $imported++;
            }
        }

        if ($truncated > 0) {
            Log::warning("Shield: {$source} feed truncated at max_import", [
                'source' => $source,
                'max_import' => $maxImport,
                'dropped' => $truncated,
            ]);
        }

        return ['imported' => $imported, 'updated' => $updated, 'truncated' => $truncated];
    }
}
