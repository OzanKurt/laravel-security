<?php

namespace OzanKurt\Shield\Firewall\Middleware;

use OzanKurt\Shield\Firewall\AbstractMiddleware;
use OzanKurt\Shield\Events\AttackDetectedEvent;

class Keyword extends AbstractMiddleware
{
    public function check($patterns)
    {
        $log = null;
        $path = $this->request->path();

        foreach ($patterns as $pattern) {
            if (! $match = $this->match($pattern, $path)) {
                continue;
            }

            $log = $this->log();

            event(new AttackDetectedEvent($log));

            break;
        }

        return ! is_null($log);
    }
}
