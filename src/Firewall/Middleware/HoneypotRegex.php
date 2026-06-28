<?php

namespace OzanKurt\Shield\Firewall\Middleware;

use Closure;
use Illuminate\Http\Request;
use OzanKurt\Shield\Services\Honeypot\HoneypotTrap;

class HoneypotRegex
{
    public function handle(Request $request, Closure $next)
    {
        if (! config('shield.honeypot.enabled', false)) {
            return $next($request);
        }

        $path = $request->path(); // no leading slash
        $candidate = '/' . ltrim($path, '/');

        foreach ((array) config('shield.honeypot.regex_paths', []) as $pattern) {
            // Invalid patterns must never 500 the request; @preg_match returns
            // false on a bad pattern, which we treat as "no match".
            if (@preg_match($pattern, $path) === 1 || @preg_match($pattern, $candidate) === 1) {
                app(HoneypotTrap::class)->handle($request, $path);
            }
        }

        return $next($request);
    }
}
