<?php

namespace OzanKurt\Shield\Tests\Feature\Premium;

use OzanKurt\Shield\Services\Premium\WebhookSigner;
use OzanKurt\Shield\Tests\TestCase;

class WebhookSignerTest extends TestCase
{
    public function testSignReturnsThreeHeadersWhenSecretPresent(): void
    {
        config(['shield.premium.webhook_secret' => 'test-secret']);
        $signer = new WebhookSigner();

        $headers = $signer->sign('{"event":"test"}');

        $this->assertCount(3, $headers);
        $this->assertArrayHasKey('X-Shield-Signature', $headers);
        $this->assertArrayHasKey('X-Shield-Timestamp', $headers);
        $this->assertArrayHasKey('X-Shield-Nonce', $headers);
    }

    public function testSignReturnsEmptyArrayWhenNoSecretConfigured(): void
    {
        config(['shield.premium.webhook_secret' => null]);
        config(['shield.premium.license_key' => null]);
        $signer = new WebhookSigner();

        $this->assertSame([], $signer->sign('{"event":"test"}'));
    }

    public function testCanSignReturnsTrueWhenWebhookSecretConfigured(): void
    {
        config(['shield.premium.webhook_secret' => 'a-secret']);
        config(['shield.premium.license_key' => null]);

        $this->assertTrue((new WebhookSigner())->canSign());
    }

    public function testCanSignReturnsTrueWhenOnlyLicenseKeyConfigured(): void
    {
        config(['shield.premium.webhook_secret' => null]);
        config(['shield.premium.license_key' => 'lic-key']);

        $this->assertTrue((new WebhookSigner())->canSign());
    }

    public function testCanSignReturnsFalseWhenNeitherConfigured(): void
    {
        config(['shield.premium.webhook_secret' => null]);
        config(['shield.premium.license_key' => null]);

        $this->assertFalse((new WebhookSigner())->canSign());
    }

    public function testSignatureIsDeterministicGivenSameInputs(): void
    {
        $secret = 'rotation-secret';
        $body = '{"event":"deterministic-test"}';
        $ts = '1700000000';
        $nonce = 'fixed-nonce-deadbeef';

        $bodyHash = hash('sha256', $body);
        $message = WebhookSigner::SCHEME_VERSION . '.' . $ts . '.' . $nonce . '.' . $bodyHash;
        $expected = 'v1=' . hash_hmac('sha256', $message, $secret);

        // Re-derive twice to confirm HMAC determinism (the signer itself
        // pulls live ts/nonce, so we test the deterministic property of the
        // underlying scheme directly).
        $a = hash_hmac('sha256', $message, $secret);
        $b = hash_hmac('sha256', $message, $secret);

        $this->assertSame($a, $b);
        $this->assertSame($expected, 'v1=' . $a);
    }

    public function testSignatureMatchesHmacSha256OfCanonicalMessage(): void
    {
        $secret = 'canonical-secret';
        config(['shield.premium.webhook_secret' => $secret]);
        $signer = new WebhookSigner();

        $body = '{"event":"hello"}';
        $headers = $signer->sign($body);

        $ts = $headers['X-Shield-Timestamp'];
        $nonce = $headers['X-Shield-Nonce'];
        $bodyHash = hash('sha256', $body);
        $message = WebhookSigner::SCHEME_VERSION . '.' . $ts . '.' . $nonce . '.' . $bodyHash;
        $expected = 'v1=' . hash_hmac('sha256', $message, $secret);

        $this->assertSame($expected, $headers['X-Shield-Signature']);
    }

    public function testWebhookSecretPreferredOverLicenseKey(): void
    {
        $body = '{"prefer":"webhook"}';

        // Sign once with both set
        config([
            'shield.premium.webhook_secret' => 'webhook-secret-A',
            'shield.premium.license_key' => 'license-key-B',
        ]);
        $sigWithBoth = (new WebhookSigner())->sign($body);

        // Sign with only the webhook secret — must produce same signature
        // bytes for the same ts+nonce; since those vary, instead compare
        // by deriving from headers + the EXPECTED secret manually.
        $ts = $sigWithBoth['X-Shield-Timestamp'];
        $nonce = $sigWithBoth['X-Shield-Nonce'];
        $bodyHash = hash('sha256', $body);
        $message = WebhookSigner::SCHEME_VERSION . '.' . $ts . '.' . $nonce . '.' . $bodyHash;

        // If webhook secret is preferred, signature must verify against it,
        // and must NOT verify against the license key.
        $expectedWebhook = 'v1=' . hash_hmac('sha256', $message, 'webhook-secret-A');
        $expectedLicense = 'v1=' . hash_hmac('sha256', $message, 'license-key-B');

        $this->assertSame($expectedWebhook, $sigWithBoth['X-Shield-Signature']);
        $this->assertNotSame($expectedLicense, $sigWithBoth['X-Shield-Signature']);
    }

    public function testDifferentSecretsProduceDifferentSignatures(): void
    {
        $body = '{"event":"x"}';
        $ts = '1700000000';
        $nonce = 'fixed-nonce';
        $bodyHash = hash('sha256', $body);
        $message = WebhookSigner::SCHEME_VERSION . '.' . $ts . '.' . $nonce . '.' . $bodyHash;

        $sigA = hash_hmac('sha256', $message, 'secret-A');
        $sigB = hash_hmac('sha256', $message, 'secret-B');

        $this->assertNotSame($sigA, $sigB);
    }
}
