<?php

namespace OzanKurt\Shield\Events;

use Illuminate\Foundation\Events\Dispatchable;

class FileChangeDetectedEvent
{
    use Dispatchable;

    public function __construct(
        public readonly string $path,
        public readonly string $changeType, // 'created' | 'updated' | 'deleted'
        public readonly ?string $hashBefore = null,
        public readonly ?string $hashAfter = null,
    ) {}
}
