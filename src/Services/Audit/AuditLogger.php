<?php

namespace OzanKurt\Shield\Services\Audit;

use Illuminate\Support\Facades\Auth;
use OzanKurt\Shield\Models\AuditLog;
use OzanKurt\Shield\Models\Lookups\AuditLogKind;
use OzanKurt\Shield\Models\Lookups\LogLevel;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Support\CorrelationId;

class AuditLogger
{
    public function __construct(
        private HmacChain $chain,
        private LookupResolver $lookups,
        private CorrelationId $correlation,
    ) {}

    public function log(string $kindName, string $description, array $opts = []): AuditLog
    {
        $severity = $opts['severity'] ?? 'medium';
        $kindId = $this->lookups->id(AuditLogKind::class, $kindName)
            ?? throw new \InvalidArgumentException("Unknown audit kind: $kindName");
        $severityId = $this->lookups->id(LogLevel::class, $severity);

        $prev = AuditLog::query()->orderByDesc('id')->first();

        $record = [
            'kind_id' => $kindId,
            'severity_id' => $severityId,
            'description' => $description,
            'actor_type' => $opts['actor_type'] ?? (Auth::id() ? 'user' : 'system'),
            'actor_id' => $opts['actor_id'] ?? Auth::id(),
            'subject_type' => $opts['subject_type'] ?? null,
            'subject_id' => $opts['subject_id'] ?? null,
            'ip' => $opts['ip'] ?? request()?->ip(),
            'user_agent' => $opts['user_agent'] ?? request()?->userAgent(),
            'url' => $opts['url'] ?? request()?->fullUrl(),
            'changes' => $opts['changes'] ?? null,
            'meta' => $opts['meta'] ?? null,
        ];

        $prevHash = $prev?->hmac;
        $hmac = $this->chain->compute($prevHash, $record);

        return AuditLog::create(array_merge($record, [
            'correlation_id' => $this->correlation->get(),
            'prev_hash' => $prevHash,
            'hmac' => $hmac,
        ]));
    }

    public function verify(): array
    {
        $entries = AuditLog::query()->orderBy('id')->cursor();
        $issues = [];
        $expectedPrev = null;

        foreach ($entries as $entry) {
            $expected = $this->chain->compute($expectedPrev, [
                'kind_id' => $entry->kind_id,
                'severity_id' => $entry->severity_id,
                'description' => $entry->description,
                'actor_type' => $entry->actor_type,
                'actor_id' => $entry->actor_id,
                'subject_type' => $entry->subject_type,
                'subject_id' => $entry->subject_id,
                'ip' => $entry->ip,
                'user_agent' => $entry->user_agent,
                'url' => $entry->url,
                'changes' => $entry->changes,
                'meta' => $entry->meta,
            ]);

            if ($expected !== $entry->hmac) {
                $issues[] = ['id' => $entry->id, 'reason' => 'hmac mismatch'];
            }
            $expectedPrev = $entry->hmac;
        }

        return $issues;
    }
}
