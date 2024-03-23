<?php

namespace OzanKurt\Security\Firewall\Middleware;

use OzanKurt\Security\Firewall\AbstractMiddleware;
use OzanKurt\Security\Events\AttackDetected;

class Referrer extends AbstractMiddleware
{
    public function check($patterns)
    {
        $status = false;

        if (! $blocked = config('security.middleware.' . $this->middleware . '.blocked')) {
            return $status;
        }

        if (in_array((string) $this->request->server('HTTP_REFERER'), (array) $blocked)) {
            $status = true;
        }

        if ($status) {
            $log = $this->log();

            event(new AttackDetected($log));
        }

        return $status;
    }
}
