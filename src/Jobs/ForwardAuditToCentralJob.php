<?php

namespace OzanKurt\Shield\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use OzanKurt\Shield\Services\Premium\CentralClient;

/**
 * Forwards a single audit-log entry to the Central app's webhook ingest
 * endpoint. Dispatched from AuditLogger::log() when the site has an
 * active premium license + webhook ingest URL configured.
 *
 * Failures retry up to 3 times with exponential backoff. After that
 * the event is dropped — Central is best-effort, NOT the source of
 * truth (the local ls_audit_log row is authoritative).
 */
class ForwardAuditToCentralJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30; // seconds — 30s, 60s, 90s

    /**
     * @param array<string,mixed> $event
     */
    public function __construct(public array $event)
    {
    }

    public function handle(CentralClient $client): void
    {
        // pushEvent already returns false silently when Central is
        // unreachable / not configured — only treat true 4xx/5xx as
        // job failure worth retrying.
        $ok = $client->pushEvent($this->event);

        if (! $ok) {
            // Re-throwing triggers retry per $tries/$backoff. CentralClient
            // logs the underlying reason; no need to log again here.
            throw new \RuntimeException('Central event ingest failed');
        }
    }
}
