<?php

namespace OzanKurt\Shield\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use OzanKurt\Shield\Models\WebhookDelivery;
use OzanKurt\Shield\Services\Premium\CentralClient;

/**
 * Forwards a single audit-log entry to the Central app's webhook ingest
 * endpoint. Dispatched from AuditLogger::log() when the site has an
 * active premium license + webhook ingest URL configured.
 *
 * Retry policy:
 *   - 4xx (signature failed, license inactive, payload bad)  → no retry
 *   - 5xx (Central having a bad day)                          → retry
 *   - Connection failure (timeout, DNS, refused)              → retry
 *
 * Exponential backoff with jitter (configurable via $backoff()) — 30s,
 * 90s, 270s on default 3-attempt setting. Total max delay ~6 minutes
 * before exhaustion, well within reason for transient Central outages.
 *
 * On exhaustion, the most-recent WebhookDelivery row is bumped to
 * 'exhausted' so the dashboard surfaces the permanent failure.
 */
class ForwardAuditToCentralJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param array<string,mixed> $event
     */
    public function __construct(public array $event)
    {
    }

    /**
     * Backoff schedule with jitter. Jitter prevents synchronous retry
     * storms when many sites recover from the same Central outage and
     * all retry simultaneously.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        $base = [30, 90, 270];
        return array_map(fn (int $seconds) => $seconds + random_int(0, 15), $base);
    }

    public function handle(CentralClient $client): void
    {
        $result = $client->pushEvent($this->event, [
            'attempt_number' => $this->attempts(),
            'max_attempts' => $this->tries,
        ]);

        // 4xx → permanent (don't retry, don't re-throw). We've already
        // logged the WebhookDelivery row as failure; the queue worker
        // shouldn't burn retry budget on something it can never fix.
        if (! $result->ok() && ! $result->shouldRetry()) {
            return;
        }

        // 5xx + connection errors → throw to trigger the queue retry.
        // CentralClient already wrote a 'failure' row; the next attempt
        // creates a new 'pending' row, so the dashboard timeline is intact.
        if (! $result->ok()) {
            throw new \RuntimeException(
                "Central event ingest failed (status={$result->httpStatus}, reason={$result->error})"
            );
        }
    }

    /**
     * Final hook after all retries are exhausted. Mark the most-recent
     * delivery row as 'exhausted' so the dashboard makes it obvious that
     * this event never reached Central + further retries won't happen.
     */
    public function failed(\Throwable $exception): void
    {
        try {
            $auditLogId = isset($this->event['audit_log_id']) ? (int) $this->event['audit_log_id'] : null;
            if ($auditLogId === null) {
                return;
            }

            WebhookDelivery::query()
                ->where('audit_log_id', $auditLogId)
                ->where('operation', 'webhook_ingest')
                ->latest('id')
                ->limit(1)
                ->update([
                    'status' => WebhookDelivery::STATUS_EXHAUSTED,
                    'reason' => 'all_retries_failed: ' . substr($exception->getMessage(), 0, 240),
                ]);
        } catch (\Throwable) {
            // Already in a failed-job context; don't compound the failure.
        }
    }
}
