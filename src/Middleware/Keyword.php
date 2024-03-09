<?php

namespace OzanKurt\Security\Middleware;

use OzanKurt\Security\Abstracts\Middleware;
use OzanKurt\Security\Events\AttackDetectedEvent;

class Keyword extends Middleware
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
