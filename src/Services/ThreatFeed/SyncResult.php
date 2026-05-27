<?php

namespace OzanKurt\Shield\Services\ThreatFeed;

class SyncResult
{
    public function __construct(
        public readonly string $provider,
        public readonly int $imported = 0,
        public readonly int $updated = 0,
        public readonly int $deleted = 0,
        public readonly ?string $error = null,
    ) {}

    public function success(): bool
    {
        return $this->error === null;
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'imported' => $this->imported,
            'updated' => $this->updated,
            'deleted' => $this->deleted,
            'error' => $this->error,
            'success' => $this->success(),
        ];
    }
}
