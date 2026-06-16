<?php

namespace OzanKurt\Shield\Services\Premium;

/**
 * Signs outbound webhook payloads with HMAC-SHA256 so the Central app
 * can prove that:
 *   1. The payload came from a holder of the license key (or webhook
 *      secret if one is configured separately).
 *   2. The payload hasn't been modified in flight.
 *   3. The request is fresh (not a replayed capture).
 *   4. We haven't already processed this exact request (nonce dedup).
 *
 * Signature scheme (modelled after Stripe / Slack webhook signing):
 *
 *   ts      = unix timestamp (seconds, integer)
 *   nonce   = random 32-byte hex string
 *   body    = canonical raw JSON request body
 *   message = "v1." . ts . "." . nonce . "." . sha256(body)
 *   sig     = hmac_sha256(message, secret)
 *
 *   X-Shield-Signature: v1=<hex sig>
 *   X-Shield-Timestamp: <ts>
 *   X-Shield-Nonce:     <nonce>
 *
 * The body itself is hashed (not included in the signed message) so we
 * don't need to canonicalise JSON whitespace, both sides hash the
 * exact raw bytes. Central is responsible for capturing the raw body
 * BEFORE Laravel re-encodes it (via the file_get_contents('php://input')
 * fallback in the verifier).
 *
 * Secret precedence:
 *   1. LS_PREMIUM_WEBHOOK_SECRET (if set, preferred for rotation)
 *   2. LS_PREMIUM_LICENSE_KEY (fallback so v2.0 deployments still sign)
 */
class WebhookSigner
{
    public const SCHEME_VERSION = 'v1';

    /** How wide a window Central accepts (seconds). Mirrored in verifier. */
    public const TIMESTAMP_TOLERANCE_SECONDS = 300;

    /**
     * Compute the headers for a signed request. Caller must POST the
     * EXACT $body string we hash here, do NOT re-encode after this
     * call or the signature won't match.
     *
     * @return array<string, string>
     */
    public function sign(string $body): array
    {
        $secret = $this->secret();
        if ($secret === null) {
            return [];
        }

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $bodyHash = hash('sha256', $body);

        $message = self::SCHEME_VERSION . '.' . $timestamp . '.' . $nonce . '.' . $bodyHash;
        $signature = hash_hmac('sha256', $message, $secret);

        return [
            'X-Shield-Signature' => self::SCHEME_VERSION . '=' . $signature,
            'X-Shield-Timestamp' => $timestamp,
            'X-Shield-Nonce' => $nonce,
        ];
    }

    /**
     * Whether outbound signing is possible at all. Used by callers that
     * want to skip a request when no secret is configured (vs. sending
     * an unsigned request that Central will reject).
     */
    public function canSign(): bool
    {
        return $this->secret() !== null;
    }

    /**
     * Resolve the signing secret. Separate webhook secret takes
     * precedence over the license key, operators rotate the webhook
     * secret on incident response without revoking the whole license.
     */
    private function secret(): ?string
    {
        $explicit = config('shield.premium.webhook_secret');
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $key = config('shield.premium.license_key');
        if (is_string($key) && trim($key) !== '') {
            return trim($key);
        }

        return null;
    }
}
