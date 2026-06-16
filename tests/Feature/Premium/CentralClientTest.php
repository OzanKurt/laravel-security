<?php

namespace OzanKurt\Shield\Tests\Feature\Premium;

use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Models\WebhookDelivery;
use OzanKurt\Shield\Services\Premium\CentralClient;
use OzanKurt\Shield\Services\Premium\LicenseChecker;
use OzanKurt\Shield\Services\Premium\WebhookSigner;
use OzanKurt\Shield\Tests\TestCase;

class CentralClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Build a CentralClient with a stub LicenseChecker that returns the
     * given license-key state. Avoids hitting the cache + HTTP for the
     * license check itself when exercising CentralClient behavior.
     */
    private function makeClient(bool $hasKey, bool $canSign = true): CentralClient
    {
        $checker = new class($hasKey) extends LicenseChecker {
            private bool $hasKey;
            public function __construct(bool $hasKey)
            {
                // Skip parent constructor, we don't need a cache repo.
                $this->hasKey = $hasKey;
            }
            public function hasKey(): bool
            {
                return $this->hasKey;
            }
        };

        $signer = new class($canSign) extends WebhookSigner {
            private bool $canSign;
            public function __construct(bool $canSign)
            {
                $this->canSign = $canSign;
            }
            public function canSign(): bool
            {
                return $this->canSign;
            }
            public function sign(string $body): array
            {
                if (! $this->canSign) {
                    return [];
                }
                return [
                    'X-Shield-Signature' => 'v1=deadbeef',
                    'X-Shield-Timestamp' => '1700000000',
                    'X-Shield-Nonce' => 'nonce-fixture',
                ];
            }
        };

        return new CentralClient($checker, $signer);
    }

    public function testPostSignedShortCircuitsSkippedWhenCanSignFalse(): void
    {
        $client = $this->makeClient(hasKey: true, canSign: false);
        // Configure a webhook URL so we hit postSigned with real intent.
        config(['shield.premium.webhook_ingest_url' => 'https://central.test/api/webhooks/ingest']);

        $result = $client->postSigned('https://central.test/api/x', ['a' => 1], 'webhook_ingest');

        $this->assertSame('skipped', $result->outcome);
        $this->assertSame('no_signing_secret', $result->error);
        Http::assertNothingSent();
    }

    public function testPushEventSkippedWhenWebhookIngestUrlUnset(): void
    {
        config(['shield.premium.webhook_ingest_url' => '']);
        $client = $this->makeClient(hasKey: true);

        $result = $client->pushEvent(['audit_log_id' => 1, 'kind' => 'auth.login']);

        $this->assertSame('skipped', $result->outcome);
        $this->assertSame('webhook_ingest_url_not_configured', $result->error);
    }

    public function testPushEventSkippedWhenNoLicenseKey(): void
    {
        config(['shield.premium.webhook_ingest_url' => 'https://central.test/api/webhooks/ingest']);
        $client = $this->makeClient(hasKey: false);

        $result = $client->pushEvent(['audit_log_id' => 1]);

        $this->assertSame('skipped', $result->outcome);
        $this->assertSame('no_license_key', $result->error);
    }

    public function testHeartbeatSkippedWhenHeartbeatUrlUnset(): void
    {
        config(['shield.premium.heartbeat.url' => '']);
        $client = $this->makeClient(hasKey: true);

        $result = $client->heartbeat(['requests' => 1]);

        $this->assertSame('skipped', $result->outcome);
        $this->assertSame('heartbeat_url_not_configured', $result->error);
    }

    public function testPingUrlDerivedFromHeartbeatUrl(): void
    {
        config([
            'shield.premium.license_key' => 'abcd1234ef567890ghijklmn',
            'shield.premium.test_ping_url' => '',
            'shield.premium.heartbeat.url' => 'https://central.test/api/heartbeat',
            'shield.premium.check_url' => 'https://central.test/api/license/check',
        ]);
        Http::fake([
            'central.test/api/test/ping' => Http::response([], 200),
        ]);

        $client = $this->makeClient(hasKey: true);
        $result = $client->ping();

        $this->assertSame('success', $result->outcome);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://central.test/api/test/ping';
        });
    }

    public function testPingUrlDerivedFromCheckUrlWhenHeartbeatUnset(): void
    {
        config([
            'shield.premium.license_key' => 'abcd1234ef567890ghijklmn',
            'shield.premium.test_ping_url' => '',
            'shield.premium.heartbeat.url' => '',
            'shield.premium.check_url' => 'https://central-fallback.test/api/license/check',
        ]);
        Http::fake([
            'central-fallback.test/api/test/ping' => Http::response([], 200),
        ]);

        $client = $this->makeClient(hasKey: true);
        $result = $client->ping();

        $this->assertSame('success', $result->outcome);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://central-fallback.test/api/test/ping';
        });
    }

    public function testPingUrlUsesExplicitTestPingUrlWhenSet(): void
    {
        config([
            'shield.premium.license_key' => 'abcd1234ef567890ghijklmn',
            'shield.premium.test_ping_url' => 'https://custom-test.test/api/whatever',
            'shield.premium.heartbeat.url' => 'https://central.test/api/heartbeat',
            'shield.premium.check_url' => 'https://central.test/api/license/check',
        ]);
        Http::fake([
            'custom-test.test/api/whatever' => Http::response([], 200),
        ]);

        $client = $this->makeClient(hasKey: true);
        $result = $client->ping();

        $this->assertSame('success', $result->outcome);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://custom-test.test/api/whatever';
        });
    }

    public function testSuccessfulPostWritesDeliveryRowWithStatusSuccess(): void
    {
        config([
            'shield.premium.license_key' => 'abcd1234ef567890ghijklmn',
            'shield.premium.webhook_ingest_url' => 'https://central.test/api/webhooks/ingest',
        ]);
        Http::fake([
            'central.test/*' => Http::response(['ok' => true], 200),
        ]);

        $client = $this->makeClient(hasKey: true);
        $result = $client->pushEvent(['audit_log_id' => 42, 'kind' => 'auth.login']);

        $this->assertSame('success', $result->outcome);
        $this->assertSame(200, $result->httpStatus);

        $delivery = WebhookDelivery::where('audit_log_id', 42)->first();
        $this->assertNotNull($delivery);
        $this->assertSame(WebhookDelivery::STATUS_SUCCESS, $delivery->status);
        $this->assertSame(200, $delivery->http_status);
    }

    public function testFourXxResponseWritesDeliveryFailureNoRetry(): void
    {
        config([
            'shield.premium.license_key' => 'abcd1234ef567890ghijklmn',
            'shield.premium.webhook_ingest_url' => 'https://central.test/api/webhooks/ingest',
        ]);
        Http::fake([
            'central.test/*' => Http::response(['error' => 'bad signature'], 401),
        ]);

        $client = $this->makeClient(hasKey: true);
        $result = $client->pushEvent(['audit_log_id' => 43]);

        $this->assertSame('failure', $result->outcome);
        $this->assertSame(401, $result->httpStatus);
        $this->assertFalse($result->shouldRetry());

        $delivery = WebhookDelivery::where('audit_log_id', 43)->first();
        $this->assertSame(WebhookDelivery::STATUS_FAILURE, $delivery->status);
    }

    public function testFiveXxResponseWritesDeliveryFailureShouldRetry(): void
    {
        config([
            'shield.premium.license_key' => 'abcd1234ef567890ghijklmn',
            'shield.premium.webhook_ingest_url' => 'https://central.test/api/webhooks/ingest',
        ]);
        Http::fake([
            'central.test/*' => Http::response(['error' => 'overloaded'], 503),
        ]);

        $client = $this->makeClient(hasKey: true);
        $result = $client->pushEvent(['audit_log_id' => 44]);

        $this->assertSame('failure', $result->outcome);
        $this->assertSame(503, $result->httpStatus);
        $this->assertTrue($result->shouldRetry());

        $delivery = WebhookDelivery::where('audit_log_id', 44)->first();
        $this->assertSame(WebhookDelivery::STATUS_FAILURE, $delivery->status);
    }

    public function testConnectionErrorWritesDeliveryWithHttpStatusZero(): void
    {
        config([
            'shield.premium.license_key' => 'abcd1234ef567890ghijklmn',
            'shield.premium.webhook_ingest_url' => 'https://central.test/api/webhooks/ingest',
        ]);
        Http::fake([
            'central.test/*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('refused');
            },
        ]);

        $client = $this->makeClient(hasKey: true);
        $result = $client->pushEvent(['audit_log_id' => 45]);

        $this->assertSame('failure', $result->outcome);
        $this->assertSame(0, $result->httpStatus);
        $this->assertNotNull($result->error);

        $delivery = WebhookDelivery::where('audit_log_id', 45)->first();
        $this->assertSame(WebhookDelivery::STATUS_FAILURE, $delivery->status);
    }

    public function testAuthorizationHeaderCarriesBearerLicenseKey(): void
    {
        config([
            'shield.premium.license_key' => 'my-license-key-99999',
            'shield.premium.webhook_ingest_url' => 'https://central.test/api/webhooks/ingest',
        ]);
        Http::fake([
            'central.test/*' => Http::response([], 200),
        ]);

        $client = $this->makeClient(hasKey: true);
        $client->pushEvent(['audit_log_id' => 50]);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer my-license-key-99999');
        });
    }

    public function testSigningHeadersPresentOnSuccessfulSign(): void
    {
        config([
            'shield.premium.license_key' => 'abcd1234ef567890ghijklmn',
            'shield.premium.webhook_ingest_url' => 'https://central.test/api/webhooks/ingest',
        ]);
        Http::fake([
            'central.test/*' => Http::response([], 200),
        ]);

        $client = $this->makeClient(hasKey: true);
        $client->pushEvent(['audit_log_id' => 51]);

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Shield-Signature')
                && $request->hasHeader('X-Shield-Timestamp')
                && $request->hasHeader('X-Shield-Nonce');
        });
    }

    public function testPullFeedSendsBearerSiteHeaderAndSinceCursor(): void
    {
        config([
            'shield.premium.license_key' => 'realtime-key-123',
            'shield.premium.feed_pull_url' => 'https://central.test/api/feeds/pull',
            'app.url' => 'https://my-site.test',
        ]);
        Http::fake([
            'central.test/api/feeds/pull*' => Http::response(['cursor' => 'c2', 'waf_rules' => [], 'acl' => []], 200),
        ]);

        $client = $this->makeClient(hasKey: true);
        $payload = $client->pullFeed('c1');

        $this->assertSame('c2', $payload['cursor']);
        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://central.test/api/feeds/pull')
                && str_contains($request->url(), 'since=c1')
                && $request->hasHeader('Authorization', 'Bearer realtime-key-123')
                && $request->hasHeader('X-Shield-Site', 'https://my-site.test');
        });
    }

    public function testPullFeedOmitsSinceWhenCursorNull(): void
    {
        config([
            'shield.premium.license_key' => 'k',
            'shield.premium.feed_pull_url' => 'https://central.test/api/feeds/pull',
        ]);
        Http::fake(['central.test/*' => Http::response(['cursor' => 'c1'], 200)]);

        $client = $this->makeClient(hasKey: true);
        $client->pullFeed(null);

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'since='));
    }

    public function testPullFeedThrowsWhenUrlNotConfigured(): void
    {
        config(['shield.premium.feed_pull_url' => '']);
        $client = $this->makeClient(hasKey: true);

        $this->expectException(\RuntimeException::class);
        $client->pullFeed(null);
    }

    public function testPullFeedThrowsWhenNoLicenseKey(): void
    {
        config(['shield.premium.feed_pull_url' => 'https://central.test/api/feeds/pull']);
        $client = $this->makeClient(hasKey: false);

        $this->expectException(\RuntimeException::class);
        $client->pullFeed(null);
    }

    public function testPullFeedThrowsOnNon2xx(): void
    {
        config([
            'shield.premium.license_key' => 'k',
            'shield.premium.feed_pull_url' => 'https://central.test/api/feeds/pull',
        ]);
        Http::fake(['central.test/*' => Http::response('payment required', 402)]);
        $client = $this->makeClient(hasKey: true);

        $this->expectException(\RuntimeException::class);
        $client->pullFeed(null);
    }
}
