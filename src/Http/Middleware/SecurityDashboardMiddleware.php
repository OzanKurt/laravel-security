<?php

namespace OzanKurt\Security\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use OzanKurt\Security\Security;
use Illuminate\Support\Facades\Gate;

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

        if (! Gate::allows('viewSecurityDashboard')) {
            abort(403);
        }

        return $next($request);
    }
}


