<?php

namespace OzanKurt\Security\Firewall\Middleware;

use OzanKurt\Security\Firewall\AbstractMiddleware;
use OzanKurt\Security\Events\AttackDetected;
use OzanKurt\Agent\Agent;

class Bot extends AbstractMiddleware
{
    public function check($patterns)
    {
        $agent = new Agent();

        if (! $agent->isRobot()) {
            return false;
        }

        if (! $crawlers = config('security.middleware.' . $this->middleware . '.crawlers')) {
            return false;
        }

        $status = false;

        if (! empty($crawlers['allow']) && ! in_array((string) $agent->robot(), (array) $crawlers['allow'])) {
            $status = true;
        }

        if (in_array((string) $agent->robot(), (array) $crawlers['block'])) {
            $status = true;
        }

        if ($status) {
            $log = $this->log();

            event(new AttackDetected($log));
        }

        return $status;
    }
}
