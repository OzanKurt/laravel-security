<?php

namespace OzanKurt\Shield\Services\Premium;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OzanKurt\Shield\Models\WebhookDelivery;
use OzanKurt\Shield\Support\CorrelationId;

/**
 * HTTP client for outbound calls from a Shield-protected site to the
 * Central app (laravel-shield.ozankurt.com). Handles webhook ingest,
 * heartbeat ping, and the test/ping connectivity check.
 *
 * Every request is signed via WebhookSigner so Central can prove
 * authenticity + integrity + freshness. Bearer auth = license key is
 * still sent for license attribution; the HMAC signature is the
 * cryptographic check that matters.
 *
 * Failures are logged + swallowed: a Central outage MUST NOT cascade
 * into 500s on the customer's app.
 */
class CentralClient
{
    public function __construct(
        private LicenseChecker $checker,
        private WebhookSigner $signer,
    ) {}

    /**
     * Push an audit event to Central. Returns a structured result so
     * the caller (or the queued job wrapper) can write a delivery log
     * row capturing http_status + response_excerpt for diagnostics.
     *
     * @param array<string,mixed> $event
     */
    public function pushEvent(array $event, array $context = []): DeliveryResult
    {
        $url = (string) config('shield.premium.webhook_ingest_url', '');
        if ($url === '') {
            return DeliveryResult::skipped('webhook_ingest_url_not_configured');
        }
        if (! $this->checker->hasKey()) {
            return DeliveryResult::skipped('no_license_key');
        }

        $auditLogId = isset($event['audit_log_id']) ? (int) $event['audit_log_id'] : null;

        return $this->postSigned($url, [
            'event' => $event,
            '_meta' => $this->meta(),
        ], 'webhook_ingest', array_merge(['audit_log_id' => $auditLogId], $context));
    }

    /**
     * Push a batch of audit events in a single call. Used when an
     * outbox-style flush needs to drain queued events to Central in
     * one round-trip.
     *
     * @param array<int, array<string,mixed>> $events
     */
    public function pushEvents(array $events): DeliveryResult
    {
        $url = (string) config('shield.premium.webhook_ingest_url', '');
        if ($url === '') {
            return DeliveryResult::skipped('webhook_ingest_url_not_configured');
        }
        if (empty($events)) {
            return DeliveryResult::skipped('empty_batch');
        }
        if (! $this->checker->hasKey()) {
            return DeliveryResult::skipped('no_license_key');
        }

        $payload = [
            'events' => $events,
            '_meta' => array_merge($this->meta(), ['batch_size' => count($events)]),
        ];

        return $this->postSigned($url, $payload, 'webhook_ingest_batch', [
            'batch_size' => count($events),
        ]);
    }

    /**
     * Heartbeat ping — summary stats so Central can show "last seen" +
     * per-site activity for license holders.
     *
     * @param array<string,mixed> $stats
     */
    public function heartbeat(array $stats): DeliveryResult
    {
        $url = (string) config('shield.premium.heartbeat.url', '');
        if ($url === '') {
            return DeliveryResult::skipped('heartbeat_url_not_configured');
        }
        if (! $this->checker->hasKey()) {
            return DeliveryResult::skipped('no_license_key');
        }

        return $this->postSigned($url, [
            'stats' => $stats,
            '_meta' => $this->meta(),
        ], 'heartbeat');
    }

    /**
     * Connectivity test — Central echoes the timestamp back signed
     * with the same secret. Lets the operator confirm both directions
     * of the signing scheme are wired correctly.
     */
    public function ping(): DeliveryResult
    {
        $url = (string) config('shield.premium.test_ping_url', '');
        if ($url === '') {
            // Derive from heartbeat URL by default — same host, /api/test/ping.
            $heartbeat = (string) config('shield.premium.heartbeat.url', '');
            if ($heartbeat === '') {
                return DeliveryResult::skipped('no_ping_url_configured');
            }
            $url = preg_replace('#/api/heartbeat$#', '/api/test/ping', $heartbeat);
        }

        if (! $this->checker->hasKey()) {
            return DeliveryResult::skipped('no_license_key');
        }

        return $this->postSigned($url, [
            'nonce' => bin2hex(random_bytes(8)),
            '_meta' => $this->meta(),
        ], 'test_ping');
    }

    /**
     * Encode + sign + POST. Captures HTTP status and a bounded response
     * body excerpt for the delivery log. Connection errors return a
     * result with http_status = 0 + a populated error string.
     *
     * Writes a WebhookDelivery row before + after so operators can see
     * EVERY attempt in /shield/webhook-deliveries, including ones that
     * crashed mid-request.
     *
     * @param array<string,mixed> $payload
     * @param array{audit_log_id?: int|null, batch_size?: int} $context
     */
    public function postSigned(string $url, array $payload, string $operation, array $context = []): DeliveryResult
    {
        // Encode once — both the signature and the actual request body
        // hash THIS exact byte sequence. Re-encoding later (even with
        // the same flags) can produce different bytes for floats/etc.
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return $this->finalize(
                null,
                DeliveryResult::failure(0, 'json_encode_failure: ' . json_last_error_msg(), $operation, $url),
            );
        }

        $delivery = $this->openDelivery($operation, $url, $body, $context);

        $headers = array_merge(
            [
                'Authorization' => 'Bearer ' . (string) config('shield.premium.license_key'),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Shield-Site' => (string) config('app.url'),
                'X-Shield-Operation' => $operation,
            ],
            $this->signer->sign($body),
        );

        try {
            $response = Http::timeout((int) config('shield.premium.http_timeout', 10))
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (\Throwable $e) {
            Log::warning("Shield: Central {$operation} connection failure", [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);
            return $this->finalize($delivery, DeliveryResult::failure(0, $e->getMessage(), $operation, $url));
        }

        return $this->finalize($delivery, $this->resultFromResponse($response, $operation, $url));
    }

    /**
     * Open a WebhookDelivery row in pending status BEFORE the HTTP call
     * fires. If the process crashes mid-request (timeout, OOM, etc),
     * the row stays in 'pending' as a permanent record that something
     * went wrong — invisible failures are debugging hell.
     *
     * Falls back to null silently if the table doesn't exist yet
     * (fresh install pre-migration) so we don't block the actual POST.
     *
     * @param array<string,mixed> $context
     */
    private function openDelivery(string $operation, string $url, string $body, array $context): ?WebhookDelivery
    {
        try {
            return WebhookDelivery::create([
                'correlation_id' => app(CorrelationId::class)->get(),
                'operation' => $operation,
                'target_url' => substr($url, 0, 255),
                'payload_hash' => hash('sha256', $body),
                'payload_bytes' => strlen($body),
                'batch_size' => max(1, (int) ($context['batch_size'] ?? 1)),
                'status' => WebhookDelivery::STATUS_PENDING,
                'attempt_number' => max(1, (int) ($context['attempt_number'] ?? 1)),
                'max_attempts' => max(1, (int) ($context['max_attempts'] ?? 3)),
                'audit_log_id' => $context['audit_log_id'] ?? null,
                'dispatched_at' => now(),
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Update the pending row with the final outcome. Best-effort —
     * if the row is missing we still return the result unchanged.
     */
    private function finalize(?WebhookDelivery $delivery, DeliveryResult $result): DeliveryResult
    {
        if ($delivery === null) {
            return $result;
        }

        try {
            $delivery->markCompleted(
                match ($result->outcome) {
                    'success' => WebhookDelivery::STATUS_SUCCESS,
                    'skipped' => WebhookDelivery::STATUS_SKIPPED,
                    default => WebhookDelivery::STATUS_FAILURE,
                },
                $result->httpStatus,
                $result->error,
                $result->responseExcerpt,
            );
        } catch (\Throwable) {
            // Cosmetic — don't break the actual delivery on a logging miss.
        }

        return $result;
    }

    private function resultFromResponse(Response $response, string $operation, string $url): DeliveryResult
    {
        $excerpt = substr((string) $response->body(), 0, 512);

        if ($response->successful()) {
            return DeliveryResult::success($response->status(), $excerpt, $operation, $url);
        }

        Log::info("Shield: Central {$operation} non-2xx response", [
            'status' => $response->status(),
            'body' => $excerpt,
        ]);

        return DeliveryResult::failure(
            $response->status(),
            "http_{$response->status()}",
            $operation,
            $url,
            $excerpt,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function meta(): array
    {
        return [
            'schema_version' => 1,
            'site_url' => (string) config('app.url'),
            'sent_at' => now()->toIso8601String(),
        ];
    }
}
