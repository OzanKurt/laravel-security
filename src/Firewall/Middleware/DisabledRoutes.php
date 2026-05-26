<?php

namespace OzanKurt\Shield\Firewall\Middleware;

use Closure;
use Illuminate\Http\Request;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unconditionally returns 404 for paths matching any pattern in
 * shield.disabled_routes.patterns. Useful for disabling risky endpoints
 * (e.g. /_ignition/*, /install.php) without app-level routing changes.
 */
class DisabledRoutes
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('shield.disabled_routes.enabled', false)) {
            return $next($request);
        }

        $patterns = (array) config('shield.disabled_routes.patterns', []);
        foreach ($patterns as $pattern) {
            if ($request->is($pattern)) {
                $this->audit->log('acl.added', "Disabled-route hit: {$request->path()}", [
                    'severity' => 'medium',
                    'ip' => $request->ip(),
                    'url' => $request->fullUrl(),
                    'meta' => ['pattern' => $pattern],
                ]);

                abort(404);
            }
        }

        return $next($request);
    }
}
