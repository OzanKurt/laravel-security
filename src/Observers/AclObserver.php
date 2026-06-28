<?php

namespace OzanKurt\Shield\Observers;

use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Services\Acl\AclEvaluator;
use OzanKurt\Shield\Services\Reactions\ReactionManager;

class AclObserver
{
    public function __construct(
        private AclEvaluator $evaluator,
        private ReactionManager $reactions,
    ) {}

    public function created(Acl $acl): void
    {
        $this->reactions->onBlock($acl);
    }

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
