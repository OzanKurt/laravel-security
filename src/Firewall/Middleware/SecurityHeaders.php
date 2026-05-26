<?php

namespace OzanKurt\Shield\Firewall\Middleware;

use Closure;
use Illuminate\Http\Request;
use OzanKurt\Shield\Support\CspNonce;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies a configurable set of security response headers:
 *   Strict-Transport-Security, Content-Security-Policy (with optional nonce),
 *   X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy.
 *
 * Configurable via shield.headers.*. Off when shield.headers.enabled=false.
 */
class SecurityHeaders
{
    public function __construct(private CspNonce $nonce) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('shield.headers.enabled', true)) {
            return $response;
        }

        $this->applyHsts($response);
        $this->applyCsp($response);
        $this->applyFrameOptions($response);
        $this->applyContentTypeOptions($response);
        $this->applyReferrerPolicy($response);
        $this->applyPermissionsPolicy($response);

        return $response;
    }

    private function applyHsts(Response $response): void
    {
        $cfg = (array) config('shield.headers.hsts', []);
        if (empty($cfg['enabled'])) return;

        $value = 'max-age=' . (int) ($cfg['max_age'] ?? 31536000);
        if (! empty($cfg['include_subdomains'])) $value .= '; includeSubDomains';
        if (! empty($cfg['preload'])) $value .= '; preload';

        $response->headers->set('Strict-Transport-Security', $value);
    }

    private function applyCsp(Response $response): void
    {
        $cfg = (array) config('shield.headers.csp', []);
        if (empty($cfg['enabled'])) return;

        $policy = (string) ($cfg['policy'] ?? "default-src 'self'");

        if (! empty($cfg['use_nonce'])) {
            $nonce = $this->nonce->get();
            $policy = str_replace("'nonce-PLACEHOLDER'", "'nonce-{$nonce}'", $policy);
            if (! str_contains($policy, "'nonce-{$nonce}'")) {
                // Append a script-src nonce directive if user didn't include the placeholder
                $policy .= "; script-src 'self' 'nonce-{$nonce}'";
            }
        }

        $header = ! empty($cfg['report_only']) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        if (! empty($cfg['report_uri'])) {
            $policy .= "; report-uri " . $cfg['report_uri'];
        }
        $response->headers->set($header, $policy);
    }

    private function applyFrameOptions(Response $response): void
    {
        $cfg = (array) config('shield.headers.x_frame_options', []);
        if (empty($cfg['enabled'])) return;
        $response->headers->set('X-Frame-Options', (string) ($cfg['value'] ?? 'SAMEORIGIN'));
    }

    private function applyContentTypeOptions(Response $response): void
    {
        if (config('shield.headers.x_content_type_options', true)) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }
    }

    private function applyReferrerPolicy(Response $response): void
    {
        $policy = config('shield.headers.referrer_policy');
        if ($policy) {
            $response->headers->set('Referrer-Policy', (string) $policy);
        }
    }

    private function applyPermissionsPolicy(Response $response): void
    {
        $policy = config('shield.headers.permissions_policy');
        if ($policy) {
            $response->headers->set('Permissions-Policy', (string) $policy);
        }
    }
}
