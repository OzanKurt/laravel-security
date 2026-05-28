<?php

namespace OzanKurt\Shield\Services\Audit;

use Illuminate\Support\Facades\Auth;
use OzanKurt\Shield\Facades\Shield;
use OzanKurt\Shield\Jobs\ForwardAuditToCentralJob;
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

        $created = AuditLog::create(array_merge($record, [
            'correlation_id' => $this->correlation->get(),
            'prev_hash' => $prevHash,
            'hmac' => $hmac,
        ]));

        $this->maybeForwardToCentral($created, $kindName, $severity);

        return $created;
    }

    /**
     * Best-effort forward to the Central app's webhook ingest endpoint.
     * Only fires when:
     *   - shield.premium.webhook_ingest_url is configured (non-null), AND
     *   - the site holds an active premium license, AND
     *   - the local Laravel queue connection is set up (no sync queues
     *     in production, please — we DON'T want each audit row to make
     *     a synchronous HTTP call to Central).
     *
     * Job is queued on the configured queue with retry/backoff. Failures
     * are best-effort and never bubble up to break the audit-log write —
     * the local row is the source of truth.
     */
    private function maybeForwardToCentral(AuditLog $entry, string $kindName, string $severity): void
    {
        $ingestUrl = config('shield.premium.webhook_ingest_url');
        if (empty($ingestUrl)) {
            return;
        }

        try {
            if (! Shield::isPremium()) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $payload = [
            'kind' => $kindName,
            'severity' => $severity,
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
            'correlation_id' => $entry->correlation_id,
            'occurred_at' => $entry->created_at?->toIso8601String(),
            'audit_log_id' => $entry->id,
            'audit_log_uuid' => $entry->uuid ?? null,
        ];

        try {
            ForwardAuditToCentralJob::dispatch($payload)
                ->onQueue((string) config('shield.premium.queue', 'default'));
        } catch (\Throwable $e) {
            // Queue not configured / table missing — degrade silently.
            // The local row already exists and is the source of truth.
            // Log at info level so operators can find it if they're
            // wondering why nothing reaches Central.
            \Illuminate\Support\Facades\Log::info('Shield: failed to dispatch Central forward job', [
                'error' => $e->getMessage(),
                'audit_log_id' => $entry->id,
            ]);
        }
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
