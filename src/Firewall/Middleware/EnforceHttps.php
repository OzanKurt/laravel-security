<?php

namespace OzanKurt\Shield\Firewall\Middleware;

use Closure;
use Illuminate\Http\Request;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirects HTTP requests to their HTTPS equivalent in production.
 * Disabled by default; enable via shield.https.enforce.
 */
class EnforceHttps
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('shield.https.enforce', false)) {
            return $next($request);
        }

        if ($request->isSecure()) {
            return $next($request);
        }

        // Optionally restrict to production
        if (config('shield.https.production_only', true) && app()->environment() !== 'production') {
            return $next($request);
        }

        $this->audit->log('acl.added', 'HTTPS enforcement redirect', [
            'severity' => 'low',
            'ip' => $request->ip(),
            'url' => $request->fullUrl(),
        ]);

        return redirect()->secure($request->getRequestUri(), 301);
    }
}
