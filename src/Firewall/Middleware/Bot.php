<?php

namespace OzanKurt\Shield\Firewall\Middleware;

use OzanKurt\Shield\Firewall\AbstractMiddleware;
use OzanKurt\Shield\Events\AttackDetected;
use OzanKurt\Agent\Agent;

class Bot extends AbstractMiddleware
{
    public function check($patterns)
    {
        $agent = new Agent();

        if (! $agent->isRobot()) {
            return false;
        }

        if (! $crawlers = config('shield.middleware.' . $this->middleware . '.crawlers')) {
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
