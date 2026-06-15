<?php

namespace OzanKurt\Shield\Events;

use Illuminate\Foundation\Events\Dispatchable;
use OzanKurt\Shield\Models\IntegrityRun;

class IntegrityScanCompletedEvent
{
    use Dispatchable;

    public function __construct(
        public readonly IntegrityRun $run,
    ) {}
}
