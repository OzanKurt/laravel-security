<?php

namespace OzanKurt\Shield\Support;

use Ramsey\Uuid\Uuid;

/**
 * Per-request CSP nonce. Singleton-bound so the SecurityHeaders middleware
 * and the @cspNonce Blade directive emit the same value within a request.
 */
class CspNonce
{
    private ?string $nonce = null;

    public function get(): string
    {
        if ($this->nonce === null) {
            $this->nonce = rtrim(strtr(base64_encode(Uuid::uuid7()->getBytes()), '+/', '-_'), '=');
        }
        return $this->nonce;
    }

    public function reset(): void
    {
        $this->nonce = null;
    }
}
