<?php

namespace OzanKurt\Shield\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\AuthLog;
use OzanKurt\Shield\Models\Log;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Shield;

/**
 * Builds the Wordfence-style executive-summary data structure for a given
 * time window. Sections are configurable on/off + top_n via shield.reports.<cadence>.sections.
 */
class CadenceReportGenerator
{
    public function __construct(
        private LookupResolver $lookups,
        private Shield $shield,
    ) {}

    /** @return array<string, mixed> */
    public function build(string $cadence): array
    {
        $window = $this->cadenceToDays($cadence);
        $start = CarbonImmutable::now()->subDays($window);
        $end = CarbonImmutable::now();
        $topN = (int) config("shield.reports.{$cadence}.top_n", 10);

        return [
            'cadence' => $cadence,
            'window_days' => $window,
            'start' => $start->toIso8601String(),
            'end' => $end->toIso8601String(),
            'site_url' => config('app.url'),
            'sections' => [
                'top_blocked_ips' => $this->topBlockedIps($start, $end, $topN),
                'top_blocked_countries' => $this->topBlockedCountries($start, $end, $topN),
                'top_failed_logins' => $this->topFailedLogins($start, $end, $topN),
                'recent_blocked_attacks' => $this->recentBlockedAttacks($start, $end, $topN),
                'recently_modified_files' => $this->recentlyModifiedFiles($start, 15),
                'required_updates' => $this->requiredUpdates(),
            ],
        ];
    }

    private function cadenceToDays(string $cadence): int
    {
        return match ($cadence) {
            'daily_digest' => 1,
            '3_day' => 3,
            '7_day' => 7,
            '14_day' => 14,
            '30_day' => 30,
            default => 7,
        };
    }

    private function topBlockedIps(CarbonImmutable $start, CarbonImmutable $end, int $topN): Collection
    {
        $blockId = $this->lookups->id(AclAction::class, 'block');
        $blacklistId = $this->lookups->id(AclAction::class, 'blacklist');

        return Acl::query()
            ->whereIn('action_id', array_filter([$blockId, $blacklistId]))
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('hit_count')
            ->limit($topN)
            ->get(['value', 'hit_count', 'reason', 'meta'])
            ->map(fn ($a) => [
                'ip' => $a->value,
                'country' => $a->meta['country'] ?? null,
                'hit_count' => $a->hit_count,
                'reason' => $a->reason,
            ]);
    }

    private function topBlockedCountries(CarbonImmutable $start, CarbonImmutable $end, int $topN): Collection
    {
        // Aggregates Log records with country info; falls back to ACL meta when country is set there
        return Log::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('meta_data')
            ->get(['meta_data'])
            ->map(fn ($l) => is_array($l->meta_data) ? ($l->meta_data['country'] ?? null) : null)
            ->filter()
            ->countBy()
            ->sortDesc()
            ->take($topN)
            ->map(fn ($count, $country) => ['country' => $country, 'count' => $count])
            ->values();
    }

    private function topFailedLogins(CarbonImmutable $start, CarbonImmutable $end, int $topN): Collection
    {
        return AuthLog::query()
            ->whereBetween('created_at', [$start, $end])
            ->where('is_successful', false)
            ->selectRaw('email, COUNT(*) as attempts, MAX(user_id) as user_id')
            ->groupBy('email')
            ->orderByDesc('attempts')
            ->limit($topN)
            ->get()
            ->map(fn ($r) => [
                'email' => $r->email,
                'attempts' => (int) $r->attempts,
                'existing_user' => $r->user_id !== null,
            ]);
    }

    private function recentBlockedAttacks(CarbonImmutable $start, CarbonImmutable $end, int $topN): Collection
    {
        return Log::query()
            ->whereBetween('created_at', [$start, $end])
            ->latest('id')
            ->limit($topN)
            ->get(['created_at', 'ip', 'middleware', 'url'])
            ->map(fn ($l) => [
                'time' => (string) $l->created_at,
                'ip' => $l->ip,
                'action' => $l->middleware,
                'url' => $l->url,
            ]);
    }

    private function recentlyModifiedFiles(CarbonImmutable $start, int $limit): array
    {
        $files = $this->shield->getRecentlyModifiedFiles($start->toDateTime(), $limit);
        return is_array($files) ? $files : [];
    }

    /**
     * Composer audit findings. Populated by composer audit backend in 1.2.0;
     * for 1.0.0 we return a stub.
     */
    private function requiredUpdates(): array
    {
        return [
            // Will be populated once composer audit backend's findings are merged in 1.2.0
            // For 1.0.0: empty list with a hint key
            '_hint' => 'Run shield:composer-audit (available in 1.2.0) to populate this section.',
        ];
    }
}
