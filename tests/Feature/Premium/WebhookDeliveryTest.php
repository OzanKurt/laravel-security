<?php

namespace OzanKurt\Shield\Tests\Feature\Premium;

use OzanKurt\Shield\Models\WebhookDelivery;
use OzanKurt\Shield\Tests\TestCase;

class WebhookDeliveryTest extends TestCase
{
    private function makePending(?\DateTimeInterface $dispatchedAt = null): WebhookDelivery
    {
        return WebhookDelivery::create([
            'operation' => 'webhook_ingest',
            'target_url' => 'https://central.test/api/webhooks/ingest',
            'payload_hash' => str_repeat('a', 64),
            'payload_bytes' => 100,
            'batch_size' => 1,
            'status' => WebhookDelivery::STATUS_PENDING,
            'attempt_number' => 1,
            'max_attempts' => 3,
            'audit_log_id' => 1,
            'dispatched_at' => $dispatchedAt,
        ]);
    }

    public function testMarkCompletedSetsStatusHttpStatusReasonAndExcerpt(): void
    {
        $delivery = $this->makePending(now()->subSeconds(2));

        $delivery->markCompleted(WebhookDelivery::STATUS_SUCCESS, 200, null, 'OK body excerpt');

        $delivery->refresh();
        $this->assertSame(WebhookDelivery::STATUS_SUCCESS, $delivery->status);
        $this->assertSame(200, $delivery->http_status);
        $this->assertNull($delivery->reason);
        $this->assertSame('OK body excerpt', $delivery->response_excerpt);
    }

    public function testMarkCompletedSetsDurationMsPositiveWhenDispatchedAtIsPast(): void
    {
        // Dispatched 5 seconds ago, completed now → duration ~5000ms
        $delivery = $this->makePending(now()->subSeconds(5));

        $delivery->markCompleted(WebhookDelivery::STATUS_SUCCESS, 200, null, null);

        $delivery->refresh();
        $this->assertGreaterThan(0, $delivery->duration_ms);
        // Must be positive (the abs() fix), should be roughly 5000 (±tolerance for test exec time)
        $this->assertGreaterThanOrEqual(4900, $delivery->duration_ms);
    }

    public function testMarkCompletedHandlesNullDispatchedAtGracefully(): void
    {
        // dispatched_at is NOT NULL in the schema; simulate a persisted row
        // whose in-memory dispatched_at became null (e.g. attribute mutation
        // by upstream code). markCompleted must guard against null so it
        // doesn't crash on a diffInMilliseconds() call against null.
        $delivery = $this->makePending(now()->subSecond());

        // Force-set to null AFTER persistence so update() only writes the
        // markCompleted column set without touching dispatched_at. Use
        // setRawAttributes so the attribute is "original" (not dirty).
        $attrs = $delivery->getAttributes();
        $attrs['dispatched_at'] = null;
        $delivery->setRawAttributes($attrs, sync: true);

        $delivery->markCompleted(WebhookDelivery::STATUS_SUCCESS, 200, null, null);

        $this->assertSame(WebhookDelivery::STATUS_SUCCESS, $delivery->status);
        $this->assertNull($delivery->duration_ms);
    }

    public function testIsPendingReturnsTrueOnlyForPendingStatus(): void
    {
        $pending = $this->makePending(now());
        $this->assertTrue($pending->isPending());

        $pending->status = WebhookDelivery::STATUS_SUCCESS;
        $this->assertFalse($pending->isPending());
    }

    public function testIsFinalReturnsTrueForSuccessSkippedExhausted(): void
    {
        $delivery = $this->makePending(now());

        $delivery->status = WebhookDelivery::STATUS_SUCCESS;
        $this->assertTrue($delivery->isFinal());

        $delivery->status = WebhookDelivery::STATUS_SKIPPED;
        $this->assertTrue($delivery->isFinal());

        $delivery->status = WebhookDelivery::STATUS_EXHAUSTED;
        $this->assertTrue($delivery->isFinal());

        $delivery->status = WebhookDelivery::STATUS_PENDING;
        $this->assertFalse($delivery->isFinal());

        $delivery->status = WebhookDelivery::STATUS_FAILURE;
        $this->assertFalse($delivery->isFinal());
    }

    public function testHasUuidAutoFillsUuidOnCreate(): void
    {
        $delivery = $this->makePending(now());

        $this->assertNotEmpty($delivery->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $delivery->uuid,
        );
    }
}
