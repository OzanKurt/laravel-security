<?php

namespace OzanKurt\Shield\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use OzanKurt\Shield\Events\LiveTrafficCapturedEvent;
use OzanKurt\Shield\Models\Lookups\ActionKind;
use OzanKurt\Shield\Models\LiveTrafficRecord;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Support\CorrelationId;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Terminable middleware — records the request after the response is sent
 * so the capture cost stays off the request path.
 *
 * Sampling:
 *   shield.live_traffic.sample_rate (default 0.1 = 1/10 of non-attack requests)
 *   Attacks always 100% via the `shield.attack_detected` request attribute
 *   (set by firewall middlewares when a rule fires).
 */
class LiveTrafficCapture
{
    public function __construct(
        private LookupResolver $lookups,
        private CorrelationId $correlation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Mark request entry so `terminate` knows when the request started.
        $request->attributes->set('shield.live_traffic.start_us', microtime(true));
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! config('shield.live_traffic.enabled', true)) {
            return;
        }

        if ($this->shouldSkip($request)) {
            return;
        }

        $isAttack = (bool) $request->attributes->get('shield.attack_detected');

        if (! $isAttack && ! $this->sample()) {
            return;
        }

        try {
            $start = (float) $request->attributes->get('shield.live_traffic.start_us', microtime(true));
            $responseTimeMs = (int) round((microtime(true) - $start) * 1000);

            $actionTakenName = $isAttack
                ? ($request->attributes->get('shield.action_taken', 'blocked'))
                : ($request->attributes->get('shield.action_taken', 'passed'));

            $actionTakenId = $this->lookups->id(ActionKind::class, $actionTakenName);

            $record = LiveTrafficRecord::create([
                'correlation_id' => $this->correlation->get(),
                'ip' => $request->ip(),
                'country_code' => $request->attributes->get('shield.country_code'),
                'asn' => $request->attributes->get('shield.asn'),
                'user_id' => optional($request->user())->id,
                'method' => $request->method(),
                'url' => $this->truncate($request->fullUrl(), 1024),
                'status_code' => $response->getStatusCode(),
                'response_time_ms' => $responseTimeMs,
                'user_agent' => $this->truncate((string) $request->userAgent(), 1024),
                'referrer' => $this->truncate((string) $request->headers->get('referer'), 255),
                'bot_identity' => $request->attributes->get('shield.bot_identity'),
                'action_taken_id' => $actionTakenId,
                'matched_rule_id' => $request->attributes->get('shield.matched_rule_id'),
                'fingerprint_hash' => substr(md5($request->method() . '|' . $request->path()), 0, 32),
            ]);

            if (config('shield.live_traffic.real_time.enabled', false) && class_exists(LiveTrafficCapturedEvent::class)) {
                LiveTrafficCapturedEvent::dispatch($record->toArray());
            }
        } catch (Throwable $e) {
            // Never let live-traffic capture break the request lifecycle.
            report($e);
        }
    }

    private function sample(): bool
    {
        $rate = (float) config('shield.live_traffic.sample_rate', 0.1);
        if ($rate >= 1.0) {
            return true;
        }
        if ($rate <= 0.0) {
            return false;
        }
        return (mt_rand() / mt_getrandmax()) <= $rate;
    }

    private function shouldSkip(Request $request): bool
    {
        // Skip the dashboard itself and asset paths.
        $patterns = (array) config('shield.live_traffic.skip_paths', [
            '_debugbar/*',
            'shield/*',
            'vendor/shield/*',
            'horizon/*',
            'telescope/*',
            'css/*',
            'js/*',
            'images/*',
            'fonts/*',
            'favicon.ico',
        ]);

        foreach ($patterns as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }

    private function truncate(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        return mb_substr($value, 0, $max);
    }
}
