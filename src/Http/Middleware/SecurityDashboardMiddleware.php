<?php

namespace OzanKurt\Security\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use OzanKurt\Security\Security;

class SecurityDashboardMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $isOutdated = Security::assetsOutdated();

        if ($isOutdated) {
            return view('security::layouts.bootstrap.outdated');
        }

        return $next($request);
    }
}


