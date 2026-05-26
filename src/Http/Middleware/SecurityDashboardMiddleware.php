<?php

namespace OzanKurt\Shield\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use OzanKurt\Shield\Shield;
use Illuminate\Support\Facades\Gate;

class SecurityDashboardMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (! Gate::allows('viewSecurityDashboard')) {
            abort(403);
        }

        $isOutdated = Shield::assetsOutdated();

        if ($isOutdated && session()->has('outdated') === false) {
            return redirect()->route('shield.dashboard.index')->with('outdated', true);
        }

        return $next($request);
    }
}


