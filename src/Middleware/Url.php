<?php

namespace OzanKurt\Security\Middleware;

use OzanKurt\Security\Abstracts\Middleware;
use OzanKurt\Security\Events\AttackDetected;

class Url extends Middleware
{
    public function check($patterns)
    {
        $protected = false;

        if (! $inspections = config('security.middleware.' . $this->middleware . '.inspections')) {
            return $protected;
        }

        foreach ($inspections as $inspection) {
            if (! $this->request->is($inspection)) {
                continue;
            }

            $protected = true;

            break;
        }

        if ($protected) {
            $log = $this->log();

            event(new AttackDetected($log));
        }

        return $protected;
    }
}
