<?php

namespace OzanKurt\Shield\Firewall\Middleware;

use Closure;
use Illuminate\Http\Request;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use Symfony\Component\HttpFoundation\IpUtils;

class Bypass
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Request $request, Closure $next)
    {
        // If already marked bypassed (idempotent), just continue
        if ($request->attributes->get('shield.bypassed')) {
            return $next($request);
        }

        if ($this->shouldBypass($request)) {
            $request->attributes->set('shield.bypassed', true);

            $this->audit->log('bypass.used', 'Bypass mechanism exercised', [
                'severity' => 'high',
                'meta' => [
                    'mechanism' => $request->header('X-Security-Bypass') ? 'header_key' : 'config_ip',
                    'ip' => $request->ip(),
                    'url' => $request->fullUrl(),
                ],
            ]);
        }

        return $next($request);
    }

    private function shouldBypass(Request $request): bool
    {
        return $this->headerKeyMatches($request) || $this->configIpMatches($request);
    }

    private function headerKeyMatches(Request $request): bool
    {
        // Read directly from env() rather than config() so this still works
        // after `php artisan config:cache` — sensitive keys should not be cached.
        $expected = env('LS_BYPASS_KEY');

        if (! $expected) {
            return false;
        }

        $provided = $request->header('X-Security-Bypass');

        if (! $provided) {
            return false;
        }

        // hash_equals prevents timing attacks on key comparison
        return hash_equals($expected, $provided);
    }

    private function configIpMatches(Request $request): bool
    {
        $list = config('shield.bypass.ips', []);

        if (empty($list)) {
            return false;
        }

        // Prefer Cloudflare's real-IP header when behind CF proxy
        $clientIp = $request->header('CF-Connecting-IP') ?? $request->ip() ?? '';

        if ($clientIp === '') {
            return false;
        }

        return IpUtils::checkIp($clientIp, (array) $list);
    }
}
