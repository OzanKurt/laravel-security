<?php

namespace OzanKurt\Security\Middleware;

use OzanKurt\Security\Abstracts\Middleware;

class Swear extends Middleware
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
