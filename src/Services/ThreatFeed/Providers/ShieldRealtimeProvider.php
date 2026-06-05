<?php

namespace OzanKurt\Shield\Services\ThreatFeed\Providers;

use Illuminate\Support\Facades\Cache;
use OzanKurt\Shield\Contracts\ThreatFeedProvider;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Models\Lookups\LogLevel;
use OzanKurt\Shield\Models\Lookups\WafRuleAction;
use OzanKurt\Shield\Models\Lookups\WafRuleCategory;
use OzanKurt\Shield\Models\Lookups\WafRuleKind;
use OzanKurt\Shield\Models\Lookups\WafRuleTarget;
use OzanKurt\Shield\Models\WafRule;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Services\Premium\CentralClient;
use OzanKurt\Shield\Services\ThreatFeed\SyncResult;

/**
 * Premium realtime feed. Pulls WAF-rule + ACL deltas published by the Laravel
 * Shield Central app since the last cursor and applies them locally. Scheduled
 * on a short interval (default 5 min) so a licensed site receives new rules and
 * malicious IPs within minutes, instead of the daily free cadence.
 *
 * Premium-only: FeedRunner gates this provider behind a valid license via
 * isProviderUnlocked() -> isFeatureAvailable('premium_threat_feeds'). Central
 * also gates the pull endpoint on the license key server-side, so a locally
 * patched feature check still gets nothing.
 *
 * WAF rows are keyed by (source='shield_realtime', source_ref) and bumped on
 * version. ACL rows are keyed by (source='shield_realtime', kind_id, value).
 * Revocations soft-disable WAF rules (is_enabled=false) and expire ACL rows
 * (expires_at=now), never hard-deleting feed history.
 */
class ShieldRealtimeProvider implements ThreatFeedProvider
{
    private const CURSOR_KEY = 'shield.threat_feed.shield_realtime.cursor';

    public function __construct(
        private LookupResolver $lookups,
        private CentralClient $central,
    ) {}

    public function name(): string { return 'shield_realtime'; }
    public function label(): string { return 'Laravel Shield Realtime (Premium)'; }

    public function isAvailable(): bool
    {
        return (bool) config('shield.threat_feed.shield_realtime.enabled', true);
    }

    public function sync(): SyncResult
    {
        $cursor = Cache::get(self::CURSOR_KEY);

        try {
            $payload = $this->central->pullFeed(is_string($cursor) ? $cursor : null);
        } catch (\Throwable $e) {
            return new SyncResult($this->name(), error: $e->getMessage());
        }

        $imported = 0;
        $updated = 0;

        foreach ((array) ($payload['waf_rules'] ?? []) as $rule) {
            [$new, $changed] = $this->upsertWafRule((array) $rule);
            $imported += $new;
            $updated += $changed;
        }

        foreach ((array) ($payload['acl'] ?? []) as $entry) {
            [$new, $changed] = $this->upsertAcl((array) $entry);
            $imported += $new;
            $updated += $changed;
        }

        $deleted = $this->revoke((array) ($payload['revoked'] ?? []));

        if (isset($payload['cursor']) && is_string($payload['cursor'])) {
            Cache::forever(self::CURSOR_KEY, $payload['cursor']);
        }

        return new SyncResult($this->name(), imported: $imported, updated: $updated, deleted: $deleted);
    }

    /**
     * @param array<string,mixed> $rule
     * @return array{0:int,1:int} [newCount, updatedCount]
     */
    private function upsertWafRule(array $rule): array
    {
        $ref = $rule['ref'] ?? null;
        if (! $ref) {
            return [0, 0];
        }

        $remoteVersion = (int) ($rule['version'] ?? 1);
        $existing = WafRule::where(['source' => 'shield_realtime', 'source_ref' => $ref])->first();

        $payload = [
            'source' => 'shield_realtime',
            'source_ref' => $ref,
            'name' => $rule['name'] ?? 'Realtime rule',
            'description' => $rule['description'] ?? null,
            'category_id' => $this->lookups->id(WafRuleCategory::class, $rule['category'] ?? 'custom'),
            'kind_id' => $this->lookups->id(WafRuleKind::class, $rule['kind'] ?? 'regex'),
            'target_id' => $this->lookups->id(WafRuleTarget::class, $rule['target'] ?? 'request_input'),
            'pattern' => $rule['pattern'] ?? '',
            'action_id' => $this->lookups->id(WafRuleAction::class, $rule['action'] ?? 'block'),
            'severity_id' => $this->lookups->id(LogLevel::class, $rule['severity'] ?? 'high'),
            'score' => (int) ($rule['score'] ?? 0),
            'is_enabled' => $rule['is_enabled'] ?? true,
            'version' => $remoteVersion,
        ];

        if ($existing) {
            if ($existing->version < $remoteVersion) {
                $existing->update($payload);
                return [0, 1];
            }
            return [0, 0];
        }

        WafRule::create($payload);
        return [1, 0];
    }

    /**
     * @param array<string,mixed> $entry
     * @return array{0:int,1:int} [newCount, updatedCount]
     */
    private function upsertAcl(array $entry): array
    {
        $value = $entry['value'] ?? null;
        if (! $value) {
            return [0, 0];
        }

        $kindId = $this->lookups->id(AclKind::class, $entry['kind'] ?? 'ip');
        $payload = [
            'kind_id' => $kindId,
            'action_id' => $this->lookups->id(AclAction::class, $entry['action'] ?? 'block'),
            'value' => $value,
            'source' => 'shield_realtime',
            'reason' => $entry['note'] ?? 'Shield realtime feed',
            'expires_at' => null,
            'meta' => isset($entry['ref']) ? ['ref' => $entry['ref']] : null,
        ];

        $existing = Acl::where(['source' => 'shield_realtime', 'kind_id' => $kindId, 'value' => $value])->first();
        if ($existing) {
            $existing->update($payload);
            return [0, 1];
        }

        Acl::create($payload);
        return [1, 0];
    }

    /**
     * Soft-disable revoked WAF rules and expire revoked ACL entries.
     *
     * @param array<string,mixed> $revoked
     */
    private function revoke(array $revoked): int
    {
        $count = 0;

        foreach ((array) ($revoked['waf_rules'] ?? []) as $ref) {
            $count += WafRule::where(['source' => 'shield_realtime', 'source_ref' => $ref])
                ->update(['is_enabled' => false]);
        }

        foreach ((array) ($revoked['acl'] ?? []) as $value) {
            $count += Acl::where(['source' => 'shield_realtime', 'value' => $value])
                ->whereNull('expires_at')
                ->update(['expires_at' => now()]);
        }

        return $count;
    }
}
