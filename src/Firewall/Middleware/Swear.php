<?php

namespace OzanKurt\Security\Firewall\Middleware;

use OzanKurt\Security\Firewall\AbstractMiddleware;

class Swear extends AbstractMiddleware
{
    public function getPatterns()
    {
        $patterns = [];

        if (! $words = config('security.middleware.' . $this->middleware . '.words')) {
            return $patterns;
        }

        foreach ((array) $words as $word) {
            $patterns[] = '#\b' . $word . '\b#i';
        }

        return $patterns;
    }
}
