<?php

namespace OzanKurt\Shield\Services\Audit;

/**
 * Inspects `.env` / config for common security misconfigurations:
 *   APP_DEBUG=true in production, missing/weak APP_KEY,
 *   SESSION_SECURE_COOKIE off, missing SESSION_HTTP_ONLY/SAME_SITE,
 *   missing APP_URL.
 *
 * Returns a structured report; intended for use by Scanner env_audit target
 * and the Diagnostics dashboard page (1.2.0).
 */
class EnvAuditor
{
    /** @return array<int, array{key: string, severity: string, message: string}> */
    public function audit(): array
    {
        $findings = [];

        if (config('app.env') === 'production') {
            if (config('app.debug') === true) {
                $findings[] = ['key' => 'APP_DEBUG', 'severity' => 'critical', 'message' => 'APP_DEBUG is enabled in production. Disable it.'];
            }
            if (in_array(config('session.driver'), ['file', 'cookie'], true)) {
                $findings[] = ['key' => 'SESSION_DRIVER', 'severity' => 'low', 'message' => 'Consider redis or database session driver in production for atomicity.'];
            }
        }

        $appKey = (string) config('app.key');
        if ($appKey === '') {
            $findings[] = ['key' => 'APP_KEY', 'severity' => 'critical', 'message' => 'APP_KEY is empty. Run `php artisan key:generate`.'];
        } elseif (str_starts_with($appKey, 'base64:') === false && strlen($appKey) < 32) {
            $findings[] = ['key' => 'APP_KEY', 'severity' => 'high', 'message' => 'APP_KEY looks too short. Regenerate with `php artisan key:generate`.'];
        }

        if (config('session.secure') !== true && config('app.env') === 'production') {
            $findings[] = ['key' => 'SESSION_SECURE_COOKIE', 'severity' => 'high', 'message' => 'Set SESSION_SECURE_COOKIE=true in production to ensure cookies are HTTPS-only.'];
        }
        if (config('session.http_only') !== true) {
            $findings[] = ['key' => 'SESSION_HTTP_ONLY', 'severity' => 'medium', 'message' => 'Set SESSION_HTTP_ONLY=true to prevent JavaScript access to session cookie.'];
        }
        if (! in_array(strtolower((string) config('session.same_site')), ['lax', 'strict', 'none'], true)) {
            $findings[] = ['key' => 'SESSION_SAME_SITE', 'severity' => 'medium', 'message' => 'Set SESSION_SAME_SITE to lax, strict, or none.'];
        }

        if (! config('app.url') || config('app.url') === 'http://localhost') {
            $findings[] = ['key' => 'APP_URL', 'severity' => 'low', 'message' => 'APP_URL is set to the default — set to your real domain for correct URL generation.'];
        }

        return $findings;
    }

    /** Score the env audit on a simple A-F scale based on finding severity. */
    public function grade(): string
    {
        $findings = $this->audit();
        if (empty($findings)) return 'A';

        $weights = ['critical' => 10, 'high' => 5, 'medium' => 2, 'low' => 1];
        $score = 0;
        foreach ($findings as $f) {
            $score += $weights[$f['severity']] ?? 0;
        }

        return match (true) {
            $score === 0 => 'A',
            $score <= 2 => 'B',
            $score <= 5 => 'C',
            $score <= 10 => 'D',
            $score <= 20 => 'E',
            default => 'F',
        };
    }
}
