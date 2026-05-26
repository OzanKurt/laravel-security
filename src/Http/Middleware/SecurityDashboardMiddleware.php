<?php

namespace OzanKurt\Shield\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use OzanKurt\Shield\Security;
use Illuminate\Support\Facades\Gate;

class SecurityDashboardMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (! Gate::allows('viewSecurityDashboard')) {
            abort(403);
        }

        $isOutdated = Security::assetsOutdated();

        if ($isOutdated && session()->has('outdated') === false) {
            return redirect()->route('security.dashboard.index')->with('outdated', true);
        }

        return $next($request);
    }
}


