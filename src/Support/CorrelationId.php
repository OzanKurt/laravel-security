<?php

namespace OzanKurt\Shield\Support;

use Ramsey\Uuid\Uuid;

class CorrelationId
{
    private ?string $current = null;

    public function get(): string
    {
        if ($this->current === null) {
            $this->current = Uuid::uuid7()->toString();
        }
        return $this->current;
    }

    public function set(string $uuid): void
    {
        $this->current = $uuid;
    }

    public function reset(): void
    {
        $this->current = null;
    }
}
