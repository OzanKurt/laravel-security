<?php

namespace OzanKurt\Shield\Services\Audit;

class HmacChain
{
    public function __construct(private string $secret) {}

    public function compute(?string $prevHash, array $record): string
    {
        $payload = ($prevHash ?? '') . '|' . $this->canonicalize($record);
        return hash_hmac('sha256', $payload, $this->secret);
    }

    private function canonicalize(array $record): string
    {
        ksort($record);
        return json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
