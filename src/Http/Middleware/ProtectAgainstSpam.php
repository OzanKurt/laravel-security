<?php

namespace OzanKurt\Shield\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use OzanKurt\Shield\Services\Scoring\SuspicionScorer;

/**
 * Form input-trap. Trips when a hidden honeypot field is filled, or the form
 * was submitted impossibly fast / too long ago. On a trip it does NOT process
 * the request (silent discard) and escalates the IP via SuspicionScorer, so
 * repeat offenders cross the scoring threshold and auto-block through the
 * normal path (which then drives the reaction layer).
 */
class ProtectAgainstSpam
{
    public function handle(Request $request, Closure $next)
    {
        if (! config('shield.honeypot.form.enabled', false) || $request->isMethod('GET')) {
            return $next($request);
        }

        $reason = $this->trippedReason($request);

        if ($reason === null) {
            return $next($request);
        }

        app(SuspicionScorer::class)->bump($request->ip(), (int) config('shield.honeypot.form.score', 50));

        app(AuditLogger::class)->log('honeypot.form_trap', "Form honeypot tripped ({$reason})", [
            'severity' => 'medium',
            'ip' => $request->ip(),
            'meta' => ['reason' => $reason, 'path' => $request->path()],
        ]);

        return $this->discardResponse();
    }

    /** @return string|null reason key, or null when the submission is clean */
    private function trippedReason(Request $request): ?string
    {
        // The companion field name is fixed; its encrypted value carries the
        // (possibly randomized) trap field name plus the submission timestamp.
        $timeField = (string) config('shield.honeypot.form.valid_from_field', 'shield_hp_time');
        $raw = $request->input($timeField);
        if (! is_string($raw) || $raw === '') {
            return 'tampered';
        }

        try {
            $payload = json_decode(Crypt::decryptString($raw), true);
        } catch (\Throwable) {
            return 'tampered';
        }

        if (! is_array($payload) || ! isset($payload['n'], $payload['t']) || ! is_string($payload['n']) || ! is_numeric($payload['t'])) {
            return 'tampered';
        }

        if (filled($request->input((string) $payload['n']))) {
            return 'filled';
        }

        if (! config('shield.honeypot.form.require_timestamp', true)) {
            return null;
        }

        $submittedAt = (int) $payload['t'];
        $elapsed = now()->timestamp - $submittedAt;
        if ($elapsed < (int) config('shield.honeypot.form.min_time_seconds', 1)) {
            return 'too_fast';
        }
        if ($elapsed > (int) config('shield.honeypot.form.max_time_seconds', 3600)) {
            return 'stale';
        }

        return null;
    }

    private function discardResponse()
    {
        $response = config('shield.honeypot.form.response', 'redirect_back');

        if ($response === 'redirect_back') {
            return redirect()->back();
        }
        if ($response === 'ok') {
            return response('', 200);
        }

        return response('', (int) $response);
    }
}
