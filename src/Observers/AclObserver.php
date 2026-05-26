<?php

namespace OzanKurt\Shield\Observers;

use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Services\Acl\AclEvaluator;

class AclObserver
{
    public function __construct(private AclEvaluator $evaluator) {}

    public function saved(Acl $acl): void
    {
        $this->evaluator->clearCache();
    }

    public function deleted(Acl $acl): void
    {
        $this->evaluator->clearCache();
    }

    public function restored(Acl $acl): void
    {
        $this->evaluator->clearCache();
    }
}
