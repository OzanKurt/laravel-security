<?php

namespace OzanKurt\Shield\Services\Premium;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for outbound calls from a Shield-protected site to the
 * Central app (laravel-shield.ozankurt.com). Handles webhook ingest and
 * heartbeat ping.
 *
 * All requests carry the LS_PREMIUM_LICENSE_KEY as a bearer token so
 * Central can attribute the payload to the right license + customer.
 * The license key is otherwise treated as a secret — it is NEVER
 * included in the request body or logged on failure.
 *
 * Failures are logged + swallowed: a Central outage MUST NOT cascade
 * into 500s on the customer's app.
 */
class CentralClient
{
    public function __construct(private LicenseChecker $checker)
    {
    }

    /**
     * Push an audit event to Central. Returns true on 2xx; false on any
     * non-2xx or connection error. Skips silently when no license is
     * configured or webhook ingest is disabled.
     *
     * @param array<string,mixed> $event
     */
    public function pushEvent(array $event): bool
    {
        $url = config('shield.premium.webhook_ingest_url');
        if (empty($url)) {
            return false;
        }

        if (! $this->checker->hasKey()) {
            return false;
        }

        try {
            $response = $this->authedRequest()->post((string) $url, [
                'event' => $event,
                '_meta' => [
                    'schema_version' => 1,
                    'site_url' => (string) config('app.url'),
                    'sent_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Throwable $e) {
            // Connection errors must not bubble — log + move on.
            Log::warning('Shield: Central webhook ingest connection failure', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        return $this->handleResponse('webhook_ingest', $response);
    }

    /**
     * Push a batch of audit events in a single call. Used by the cron
     * heartbeat to forward queued events that piled up between heartbeats
     * (e.g. when Central was temporarily unreachable).
     *
     * @param array<int, array<string,mixed>> $events
     */
    public function pushEvents(array $events): bool
    {
        $url = config('shield.premium.webhook_ingest_url');
        if (empty($url) || empty($events) || ! $this->checker->hasKey()) {
            return false;
        }

        try {
            $response = $this->authedRequest()->post((string) $url, [
                'events' => $events,
                '_meta' => [
                    'schema_version' => 1,
                    'site_url' => (string) config('app.url'),
                    'sent_at' => now()->toIso8601String(),
                    'batch_size' => count($events),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Shield: Central webhook batch connection failure', [
                'error' => $e->getMessage(),
                'batch_size' => count($events),
            ]);
            return false;
        }

        return $this->handleResponse('webhook_ingest_batch', $response);
    }

    /**
     * Heartbeat ping — summary stats so Central can show "last seen" +
     * per-site activity for license holders. Sent at most once per
     * shield.premium.heartbeat.interval_minutes.
     *
     * @param array<string,mixed> $stats
     */
    public function heartbeat(array $stats): bool
    {
        $url = config('shield.premium.heartbeat.url');
        if (empty($url) || ! $this->checker->hasKey()) {
            return false;
        }

        try {
            $response = $this->authedRequest()->post((string) $url, [
                'stats' => $stats,
                '_meta' => [
                    'schema_version' => 1,
                    'site_url' => (string) config('app.url'),
                    'sent_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Shield: Central heartbeat connection failure', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        return $this->handleResponse('heartbeat', $response);
    }

    /**
     * Build the authed HTTP client. License key goes in Authorization
     * header — never in the body where it might be logged accidentally.
     */
    private function authedRequest()
    {
        $key = (string) config('shield.premium.license_key');

        return Http::timeout((int) config('shield.premium.http_timeout', 10))
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'Authorization' => 'Bearer ' . $key,
                'X-Shield-Site' => (string) config('app.url'),
            ]);
    }

    private function handleResponse(string $operation, Response $response): bool
    {
        if ($response->successful()) {
            return true;
        }

        Log::info("Shield: Central {$operation} non-2xx response", [
            'status' => $response->status(),
            // Body is bounded — Central's API errors are small JSON blobs.
            'body' => substr((string) $response->body(), 0, 512),
        ]);

        return false;
    }
}
