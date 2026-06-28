<?php

namespace OzanKurt\Shield\Services\Reactions;

use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Contracts\AclReaction;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

class AbuseIpDbReportReaction implements AclReaction
{
    private const ENDPOINT = 'https://api.abuseipdb.com/api/v2/report';

    public function __construct(private LookupResolver $lookups) {}

    public function name(): string
    {
        return 'abuseipdb_report';
    }

    public function isEnabled(): bool
    {
        return (bool) config('shield.reactions.abuseipdb_report.enabled', false)
            && ! empty(config('shield.reactions.abuseipdb_report.api_key'));
    }

    public function appliesTo(Acl $acl): bool
    {
        if ($acl->kind_id !== $this->lookups->id(AclKind::class, 'ip')) {
            return false;
        }
        if ($acl->action_id !== $this->lookups->id(AclAction::class, 'block')) {
            return false; // never report allow/whitelist entries
        }
        if (! empty($acl->meta['reactions']['abuseipdb']['reported_at'])) {
            return false; // already reported (dedupe)
        }
        if (! $this->isPublicIp((string) $acl->value)) {
            return false;
        }

        $maxAge = (int) config('shield.reactions.abuseipdb_report.max_age_days', 30);

        return $acl->created_at === null || $acl->created_at->gt(now()->subDays($maxAge));
    }

    public function ban(Acl $acl): void
    {
        $response = Http::timeout(20)
            ->withHeaders(['Key' => (string) config('shield.reactions.abuseipdb_report.api_key'), 'Accept' => 'application/json'])
            ->asForm()
            ->post(self::ENDPOINT, [
                'ip' => (string) $acl->value,
                'categories' => implode(',', (array) config('shield.reactions.abuseipdb_report.categories', [21, 19])),
                'comment' => $this->comment($acl),
            ]);

        if ($response->status() === 429) {
            throw new \RuntimeException('AbuseIPDB rate-limited (will retry)');
        }
        if (! $response->successful()) {
            // 4xx (e.g. duplicate within 15 min, bad key) => permanent; mark + stop.
            $this->mark($acl);

            return;
        }

        $this->mark($acl);
    }

    public function unban(Acl $acl): void
    {
        // Community reports are permanent; nothing to reverse.
    }

    public function reversible(): bool
    {
        return false;
    }

    private function comment(Acl $acl): string
    {
        // Reason only, no secrets. Keep it generic + short.
        return 'Laravel Shield: ' . substr((string) $acl->reason, 0, 200);
    }

    private function mark(Acl $acl): void
    {
        $meta = $acl->meta ?? [];
        $meta['reactions']['abuseipdb'] = ['reported_at' => now()->toIso8601String()];
        $acl->update(['meta' => $meta]);
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
