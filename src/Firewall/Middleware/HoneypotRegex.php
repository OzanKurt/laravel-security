<?php

namespace OzanKurt\Shield\Firewall\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

        // Process-cached set of patterns we have already warned about, so a
        // malformed pattern logs once per process rather than on every request.
        static $warned = [];

        foreach ((array) config('shield.honeypot.regex_paths', []) as $pattern) {
            // Invalid patterns must never 500 the request; @preg_match returns
            // false on a bad pattern, which we treat as "no match" but warn
            // about once so an operator can see why a honeypot never fires.
            $result = @preg_match($pattern, $path);

            if ($result === false) {
                if (! isset($warned[$pattern])) {
                    $warned[$pattern] = true;
                    Log::warning('Shield honeypot: invalid regex pattern skipped', ['pattern' => $pattern]);
                }

                continue;
            }

            if ($result === 1 || @preg_match($pattern, $candidate) === 1) {
                app(HoneypotTrap::class)->handle($request, $path);
            }
        }

        return $next($request);
    }
}
