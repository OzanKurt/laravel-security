<?php

namespace OzanKurt\Shield\Services\Premium;

/**
 * Outcome of a single outbound Central delivery attempt. Carries enough
 * detail for the WebhookDelivery audit row + for the calling job's
 * retry decision (retry on http_status >= 500 or 0, give up on 4xx).
 */
final class DeliveryResult
{
    /** @var 'success'|'failure'|'skipped' */
    public readonly string $outcome;

    public readonly int $httpStatus;

    public readonly ?string $error;

    public readonly ?string $responseExcerpt;

    public readonly string $operation;

    public readonly string $url;

    public function __construct(
        string $outcome,
        int $httpStatus,
        ?string $error,
        ?string $responseExcerpt,
        string $operation,
        string $url,
    ) {
        $this->outcome = $outcome;
        $this->httpStatus = $httpStatus;
        $this->error = $error;
        $this->responseExcerpt = $responseExcerpt;
        $this->operation = $operation;
        $this->url = $url;
    }

    public static function success(int $status, string $excerpt, string $operation, string $url): self
    {
        return new self('success', $status, null, $excerpt, $operation, $url);
    }

    public static function failure(int $status, string $error, string $operation, string $url, ?string $excerpt = null): self
    {
        return new self('failure', $status, $error, $excerpt, $operation, $url);
    }

    public static function skipped(string $reason): self
    {
        return new self('skipped', 0, $reason, null, '', '');
    }

    public function ok(): bool
    {
        return $this->outcome === 'success';
    }

    public function shouldRetry(): bool
    {
        if ($this->outcome === 'success' || $this->outcome === 'skipped') {
            return false;
        }
        // Retry on connection failures + 5xx; 4xx is permanent (signature
        // wrong, license inactive, payload malformed — won't fix by retry).
        return $this->httpStatus === 0 || $this->httpStatus >= 500;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'http_status' => $this->httpStatus,
            'error' => $this->error,
            'response_excerpt' => $this->responseExcerpt,
            'operation' => $this->operation,
            'url' => $this->url,
        ];
    }
}
