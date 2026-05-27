<?php

namespace OzanKurt\Shield\Observers;

use OzanKurt\Shield\Models\WafRule;
use OzanKurt\Shield\Services\Waf\WafRuleResolver;

class WafRuleObserver
{
    public function __construct(private WafRuleResolver $resolver) {}

    public function saved(WafRule $wafRule): void
    {
        $this->resolver->clearCache();
    }

    public function deleted(WafRule $wafRule): void
    {
        $this->resolver->clearCache();
    }

    public function restored(WafRule $wafRule): void
    {
        $this->resolver->clearCache();
    }
}
