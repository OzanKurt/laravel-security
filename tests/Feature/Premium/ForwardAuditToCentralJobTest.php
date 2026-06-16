<?php

namespace OzanKurt\Shield\Tests\Feature\Premium;

use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Jobs\ForwardAuditToCentralJob;
use OzanKurt\Shield\Models\WebhookDelivery;
use OzanKurt\Shield\Services\Premium\CentralClient;
use OzanKurt\Shield\Services\Premium\LicenseChecker;
use OzanKurt\Shield\Services\Premium\WebhookSigner;
use OzanKurt\Shield\Tests\TestCase;

class ForwardAuditToCentralJobTest extends TestCase
{
    /**
     * Build a CentralClient backed by Http::fake() responses.
     */
    private function makeClient(): CentralClient
    {
        $checker = new class extends LicenseChecker {
            public function __construct() {}
            public function hasKey(): bool { return true; }
        };

        $signer = new class extends WebhookSigner {
            public function __construct() {}
            public function canSign(): bool { return true; }
            public function sign(string $body): array
            {
                return [
                    'X-Shield-Signature' => 'v1=deadbeef',
                    'X-Shield-Timestamp' => '1700000000',
                    'X-Shield-Nonce' => 'fixed-nonce',
                ];
            }
        };

        return new CentralClient($checker, $signer);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'shield.premium.license_key' => 'abcd1234ef567890ghijklmn',
            'shield.premium.webhook_ingest_url' => 'https://central.test/api/webhooks/ingest',
        ]);
    }

    public function testHandleWithSuccessfulPushEventDoesNotThrow(): void
    {
        Http::fake([
            'central.test/*' => Http::response(['ok' => true], 200),
        ]);
        $job = new ForwardAuditToCentralJob(['audit_log_id' => 1, 'kind' => 'auth.login']);

        $job->handle($this->makeClient());

        $this->assertTrue(true); // no throw
    }

    public function testHandleWithFourXxPushEventReturnsWithoutThrow(): void
    {
        Http::fake([
            'central.test/*' => Http::response(['err' => 'forbidden'], 403),
        ]);
        $job = new ForwardAuditToCentralJob(['audit_log_id' => 2]);

        $job->handle($this->makeClient());

        $this->assertTrue(true); // 4xx is permanent, no exception thrown
    }

    public function testHandleWithFiveXxPushEventThrowsToTriggerRetry(): void
    {
        Http::fake([
            'central.test/*' => Http::response(['err' => 'down'], 503),
        ]);
        $job = new ForwardAuditToCentralJob(['audit_log_id' => 3]);

        $this->expectException(\RuntimeException::class);
        $job->handle($this->makeClient());
    }

    public function testHandleWithConnectionErrorThrowsToTriggerRetry(): void
    {
        Http::fake([
            'central.test/*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('refused');
            },
        ]);
        $job = new ForwardAuditToCentralJob(['audit_log_id' => 4]);

        $this->expectException(\RuntimeException::class);
        $job->handle($this->makeClient());
    }

    public function testBackoffReturnsThreeValuesWithJitter(): void
    {
        $job = new ForwardAuditToCentralJob(['audit_log_id' => 99]);

        $b = $job->backoff();

        $this->assertCount(3, $b);

        // Each value should be in [base, base+15]
        $this->assertGreaterThanOrEqual(30, $b[0]);
        $this->assertLessThanOrEqual(45, $b[0]);

        $this->assertGreaterThanOrEqual(90, $b[1]);
        $this->assertLessThanOrEqual(105, $b[1]);

        $this->assertGreaterThanOrEqual(270, $b[2]);
        $this->assertLessThanOrEqual(285, $b[2]);
    }

    public function testFailedUpdatesOnlyLatestDeliveryForAuditLogId(): void
    {
        // Seed multiple WebhookDelivery rows for the same audit_log_id -
        // simulating retries that each opened a fresh "pending" row.
        // The previous bug rewrote ALL rows; failed() must only touch the
        // most-recent one so per-attempt history is preserved.
        $auditLogId = 555;

        $first = WebhookDelivery::create([
            'operation' => 'webhook_ingest',
            'target_url' => 'https://central.test/api/webhooks/ingest',
            'payload_hash' => str_repeat('a', 64),
            'payload_bytes' => 10,
            'batch_size' => 1,
            'status' => WebhookDelivery::STATUS_FAILURE,
            'http_status' => 500,
            'reason' => 'http_500',
            'attempt_number' => 1,
            'max_attempts' => 3,
            'audit_log_id' => $auditLogId,
            'dispatched_at' => now()->subSeconds(10),
            'completed_at' => now()->subSeconds(9),
        ]);

        $second = WebhookDelivery::create([
            'operation' => 'webhook_ingest',
            'target_url' => 'https://central.test/api/webhooks/ingest',
            'payload_hash' => str_repeat('b', 64),
            'payload_bytes' => 10,
            'batch_size' => 1,
            'status' => WebhookDelivery::STATUS_FAILURE,
            'http_status' => 500,
            'reason' => 'http_500',
            'attempt_number' => 2,
            'max_attempts' => 3,
            'audit_log_id' => $auditLogId,
            'dispatched_at' => now()->subSeconds(5),
            'completed_at' => now()->subSeconds(4),
        ]);

        $job = new ForwardAuditToCentralJob(['audit_log_id' => $auditLogId]);
        $job->failed(new \RuntimeException('all retries failed'));

        $first->refresh();
        $second->refresh();

        // First row UNCHANGED (status still 'failure', original reason)
        $this->assertSame(WebhookDelivery::STATUS_FAILURE, $first->status);
        $this->assertSame('http_500', $first->reason);

        // Second row updated to 'exhausted'
        $this->assertSame(WebhookDelivery::STATUS_EXHAUSTED, $second->status);
        $this->assertStringStartsWith('all_retries_failed:', (string) $second->reason);
    }

    public function testFailedWithNoAuditLogIdIsNoop(): void
    {
        $job = new ForwardAuditToCentralJob(['kind' => 'something', /* no audit_log_id */]);

        // Should not crash even when there's nothing to look up
        $job->failed(new \RuntimeException('boom'));

        $this->assertTrue(true);
    }

    public function testFailedWithNoMatchingDeliveryRowsIsNoop(): void
    {
        $job = new ForwardAuditToCentralJob(['audit_log_id' => 99999999]);

        $job->failed(new \RuntimeException('boom'));

        // No existing rows with that audit_log_id, so nothing to update.
        $this->assertSame(0, WebhookDelivery::where('audit_log_id', 99999999)->count());
    }
}
