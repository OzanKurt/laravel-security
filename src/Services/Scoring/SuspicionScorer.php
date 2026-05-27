<?php

namespace OzanKurt\Shield\Services\Scoring;

use Illuminate\Support\Facades\Cache;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

/**
 * Per-IP cumulative suspicion score with a sliding TTL window.
 *
 * When score crosses shield.scoring.threshold, auto-blocks the IP in the ACL
 * with action=block, source=scoring, expires_at=now()+block_duration.
 *
 * Backed by the Laravel cache (Redis recommended, file falls back).
 */
class SuspicionScorer
{
    public function __construct(
        private LookupResolver $lookups,
        private AuditLogger $audit,
    ) {}

    public function get(string $ip): int
    {
        return (int) Cache::get($this->key($ip), 0);
    }

    public function bump(string $ip, int $by): int
    {
        if (! config('shield.scoring.enabled', false) || $by <= 0) {
            return $this->get($ip);
        }

        $key = $this->key($ip);
        $window = (int) config('shield.scoring.window', 3600);

        // Cache::increment doesn't honour TTL on first insert across all stores;
        // bootstrap with put() then increment when present.
        if (Cache::has($key)) {
            $score = (int) Cache::increment($key, $by);
        } else {
            $score = $by;
            Cache::put($key, $score, $window);
        }

        $threshold = (int) config('shield.scoring.threshold', 100);
        if ($score >= $threshold) {
            $this->autoBlock($ip, $score);
            Cache::forget($key);
        }

        return $score;
    }

    public function reset(string $ip): void
    {
        Cache::forget($this->key($ip));
    }

    private function autoBlock(string $ip, int $score): void
    {
        $duration = (int) config('shield.scoring.block_duration', 1800);

        $exists = Acl::query()
            ->where('value', $ip)
            ->where('kind_id', $this->lookups->id(AclKind::class, 'ip'))
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->exists();

        if ($exists) {
            return;
        }

        Acl::create([
            'kind_id' => $this->lookups->id(AclKind::class, 'ip'),
            'action_id' => $this->lookups->id(AclAction::class, 'block'),
            'value' => $ip,
            'source' => 'scoring',
            'reason' => "Auto-blocked: suspicion score reached {$score}",
            'expires_at' => now()->addSeconds($duration),
        ]);

        $this->audit->log('acl.added', "Suspicion-score auto-block: {$ip} reached {$score}", [
            'severity' => 'high',
            'ip' => $ip,
            'meta' => ['score' => $score, 'block_duration' => $duration],
        ]);
    }

    private function key(string $ip): string
    {
        return 'shield.scoring.' . md5($ip);
    }
}
