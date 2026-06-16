<?php

namespace OzanKurt\Shield\Services\Premium;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Runtime license-key checker for premium features.
 *
 * Modelled on Wordfence's wfLicense.php flow:
 *  - POST the license key + site context to the Central /api/license/check
 *    endpoint at most once per shield.premium.cache_ttl (default 24h).
 *  - Cache the JSON result (valid + features + expiry) in the configured
 *    cache store.
 *  - If the Central app is unreachable, keep honouring the last cached
 *    "valid" result for shield.premium.grace_period_days (default 7d).
 *    This prevents Ozan-side API outages from killing premium features
 *    on every protected site.
 *
 * The license key is treated as a secret: it never appears in logs,
 * exception messages, or rendered dashboard output in clear text.
 *
 * The check is SOFT ENFORCEMENT, by design. Real value is server-side
 * (Central API gates downloads, queue priority, etc.). The local class
 * is a thin client that lets honest buyers see status + grace banners.
 */
class LicenseChecker
{
    /**
     * Sentinel cache value for "we have no license key configured at all".
     * Lets us distinguish "key absent" from "key present but unchecked yet".
     */
    private const STATE_NO_KEY = 'no_key';

    /** Sentinel cache value for "API returned valid=false". */
    private const STATE_INVALID = 'invalid';

    /** Sentinel cache value for "API returned valid=true". */
    private const STATE_VALID = 'valid';

    /** Sentinel cache value for "API unreachable, grace period active". */
    private const STATE_GRACE = 'grace';

    public function __construct(private CacheRepository $cache)
    {
    }

    /**
     * Is a premium license active right now? True only if the latest
     * cached result is valid (or we're in grace period for a previously
     * valid license).
     */
    public function isPremium(): bool
    {
        $state = $this->state();

        return $state['state'] === self::STATE_VALID
            || $state['state'] === self::STATE_GRACE;
    }

    /**
     * Is the named feature available under the current license? Returns
     * false if no license is configured, the license is invalid, or the
     * license does not include this feature in its plan.
     */
    public function isFeatureAvailable(string $feature): bool
    {
        if (! $this->isPremium()) {
            return false;
        }

        $state = $this->state();
        $features = (array) ($state['features'] ?? []);

        // Empty features array means "all features for this plan".
        if (empty($features)) {
            return true;
        }

        return in_array($feature, $features, true);
    }

    /**
     * Get the full cached license state, refreshing from Central if the
     * cached entry is missing or expired.
     *
     * NOTE: this method may trigger a synchronous HTTP call to Central
     * on a cache miss (up to http_timeout seconds). DO NOT use it in a
     * hot path (every request, every audit log, etc.), use cachedState()
     * for fast, cache-only reads in those places.
     *
     * @return array{state: string, valid: bool, reason?: string, expires_at?: string|null, plan?: string|null, features?: array<int,string>, domain_limit?: int|null, domains_used?: int|null, last_checked_at?: string, grace_until?: string|null}
     */
    public function state(): array
    {
        $cacheKey = $this->cacheKey();
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached) && $this->isCachedStateFresh($cached)) {
            return $cached;
        }

        return $this->refresh();
    }

    /**
     * Read-only state from the cache. Returns the most-recently cached
     * value if any, never triggers an HTTP call. Use in hot paths where
     * a 10s synchronous Central round-trip is unacceptable: the navbar
     * license badge, AuditLogger's per-write Central forward gate, etc.
     *
     * Returns null when no cache entry exists (e.g. fresh deploy, cache
     * flush). Callers should treat null as "premium unknown, assume off
     * for safety; refresh will happen on the next cron/Artisan call".
     *
     * @return array<string,mixed>|null
     */
    public function cachedState(): ?array
    {
        $cached = $this->cache->get($this->cacheKey());
        return is_array($cached) ? $cached : null;
    }

    /**
     * Fast cached-only premium check for hot paths. Returns false when
     * the cache is empty rather than hitting Central, safe-by-default
     * (premium features off until a background refresh populates the
     * cache via cron/Artisan).
     */
    public function isPremiumCached(): bool
    {
        $cached = $this->cachedState();
        if ($cached === null) {
            return false;
        }
        return ($cached['state'] ?? null) === self::STATE_VALID
            || ($cached['state'] ?? null) === self::STATE_GRACE;
    }

    /**
     * Force a refresh from Central, bypassing the cache TTL. Returns the
     * new state. Used by the shield:license:check command and by the
     * License dashboard page's manual refresh button.
     *
     * @return array{state: string, valid: bool, reason?: string, expires_at?: string|null, plan?: string|null, features?: array<int,string>, domain_limit?: int|null, domains_used?: int|null, last_checked_at?: string, grace_until?: string|null}
     */
    public function refresh(): array
    {
        $key = $this->licenseKey();

        if ($key === null) {
            return $this->persist([
                'state' => self::STATE_NO_KEY,
                'valid' => false,
                'reason' => 'no_license_key_configured',
                'last_checked_at' => now()->toIso8601String(),
            ]);
        }

        try {
            $response = Http::timeout((int) config('shield.premium.http_timeout', 10))
                ->acceptJson()
                ->asJson()
                ->post((string) config('shield.premium.check_url'), [
                    'key' => $key,
                    'site_url' => $this->siteUrl(),
                    'package_version' => $this->packageVersion(),
                    'php_version' => PHP_VERSION,
                    'laravel_version' => $this->laravelVersion(),
                ]);
        } catch (\Throwable $e) {
            return $this->persistGraceOrInvalid($e->getMessage());
        }

        if (! $response->successful()) {
            return $this->persistGraceOrInvalid("HTTP {$response->status()}");
        }

        $body = (array) $response->json();
        $valid = (bool) ($body['valid'] ?? false);

        if (! $valid) {
            return $this->persist([
                'state' => self::STATE_INVALID,
                'valid' => false,
                'reason' => (string) ($body['reason'] ?? 'unknown'),
                'last_checked_at' => now()->toIso8601String(),
            ]);
        }

        return $this->persist([
            'state' => self::STATE_VALID,
            'valid' => true,
            'expires_at' => $body['expires_at'] ?? null,
            'plan' => $body['plan'] ?? null,
            'features' => (array) ($body['features'] ?? []),
            'domain_limit' => isset($body['domain_limit']) ? (int) $body['domain_limit'] : null,
            'domains_used' => isset($body['domains_used']) ? (int) $body['domains_used'] : null,
            'last_checked_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Clear the cached license state. Next call to isPremium() will hit
     * the Central API again.
     */
    public function clearCache(): void
    {
        $this->cache->forget($this->cacheKey());
    }

    /**
     * Is the user-facing license key configured at all?
     */
    public function hasKey(): bool
    {
        return $this->licenseKey() !== null;
    }

    /**
     * Redacted form of the key for dashboard rendering. Returns the first
     * 8 chars + last 4 chars only, never the full key.
     */
    public function maskedKey(): ?string
    {
        $key = $this->licenseKey();
        if ($key === null) {
            return null;
        }

        if (strlen($key) <= 16) {
            return str_repeat('*', strlen($key));
        }

        return substr($key, 0, 8) . str_repeat('*', strlen($key) - 12) . substr($key, -4);
    }

    /**
     * Cached state freshness check. Honours per-state lifetimes:
     *  - STATE_NO_KEY: 5 min (cheap to recheck; users add keys interactively)
     *  - STATE_INVALID: full cache_ttl (don't hammer Central for a known-bad key)
     *  - STATE_VALID: full cache_ttl
     *  - STATE_GRACE: 1 hour (so we recover quickly when Central comes back)
     *
     * @param array<string,mixed> $cached
     */
    private function isCachedStateFresh(array $cached): bool
    {
        if (! isset($cached['last_checked_at'])) {
            return false;
        }

        $checkedAt = Carbon::parse((string) $cached['last_checked_at']);
        // abs() so behavior is identical across Carbon 2.x (signed-by-default)
        // and Carbon 3.x (signed float). Previously: now()->diffInSeconds(past)
        // returned a value whose sign varied by Carbon version, breaking the
        // freshness check on either side depending on the host's PHP install.
        $ageSeconds = (int) abs(now()->diffInSeconds($checkedAt));

        // A STATE_INVALID caused by an unreachable API with no prior valid
        // check is treated as a SHORT-LIVED state (5 min) so a transient
        // outage at install time doesn't trap a fresh customer for 24h.
        // All other INVALID states (revoked, expired, unknown_key) honour
        // the full cache_ttl since they're definitive answers from Central.
        if (($cached['state'] ?? null) === self::STATE_INVALID
            && ($cached['reason'] ?? null) === 'api_unreachable_no_prior_valid'
        ) {
            return $ageSeconds < 300;
        }

        return match ($cached['state'] ?? null) {
            self::STATE_NO_KEY => $ageSeconds < 300,
            self::STATE_VALID, self::STATE_INVALID => $ageSeconds < (int) config('shield.premium.cache_ttl', 86400),
            self::STATE_GRACE => $ageSeconds < 3600,
            default => false,
        };
    }

    /**
     * When Central is unreachable, look at the last persisted state. If
     * the last KNOWN-VALID check was within shield.premium.grace_period_days,
     * persist STATE_GRACE so premium stays active. Otherwise fall back to
     * STATE_INVALID + reason=api_unreachable.
     *
     * @return array<string,mixed>
     */
    private function persistGraceOrInvalid(string $reason): array
    {
        $previous = $this->cache->get($this->cacheKey());
        $graceDays = (int) config('shield.premium.grace_period_days', 7);

        // Look at the last KNOWN-VALID timestamp, not just any "last checked".
        // (A previous STATE_INVALID or STATE_GRACE doesn't grant new grace.)
        $lastValidIso = $this->lastValidAt($previous);

        if ($lastValidIso !== null) {
            $lastValidAt = Carbon::parse($lastValidIso);
            $graceUntil = $lastValidAt->copy()->addDays($graceDays);

            if ($graceUntil->isFuture()) {
                Log::info('Shield premium: Central unreachable, entering grace period', [
                    'reason' => $reason,
                    'grace_until' => $graceUntil->toIso8601String(),
                ]);

                return $this->persist([
                    'state' => self::STATE_GRACE,
                    'valid' => true,
                    'reason' => 'api_unreachable_in_grace',
                    'message' => $reason,
                    'expires_at' => $previous['expires_at'] ?? null,
                    'plan' => $previous['plan'] ?? null,
                    'features' => (array) ($previous['features'] ?? []),
                    'last_valid_at' => $lastValidIso,
                    'last_checked_at' => now()->toIso8601String(),
                    'grace_until' => $graceUntil->toIso8601String(),
                ]);
            }
        }

        Log::warning('Shield premium: Central unreachable, no grace period', [
            'reason' => $reason,
        ]);

        // Fresh install OR previously-INVALID state hitting an unreachable
        // Central, we have no prior valid check to anchor grace on. Use a
        // short retry TTL (5 min) so a transient outage at install time
        // doesn't trap a new buyer in STATE_INVALID for the full 24h cache_ttl.
        // The state is still INVALID (premium features off), but the next
        // request will re-check Central in 5 minutes, not 24 hours.
        return $this->persistWithTtl(
            [
                'state' => self::STATE_INVALID,
                'valid' => false,
                'reason' => 'api_unreachable_no_prior_valid',
                'message' => $reason,
                'last_checked_at' => now()->toIso8601String(),
            ],
            300,
        );
    }

    /**
     * Find the most recent moment we had a confirmed valid license. Both
     * STATE_VALID and STATE_GRACE carry forward the original valid timestamp.
     *
     * @param mixed $previous
     */
    private function lastValidAt(mixed $previous): ?string
    {
        if (! is_array($previous)) {
            return null;
        }

        // STATE_GRACE preserves last_valid_at; STATE_VALID's own check was
        // the valid one, use its last_checked_at.
        if (($previous['state'] ?? null) === self::STATE_GRACE) {
            return $previous['last_valid_at'] ?? null;
        }

        if (($previous['state'] ?? null) === self::STATE_VALID) {
            return $previous['last_checked_at'] ?? null;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function persist(array $state): array
    {
        return $this->persistWithTtl(
            $state,
            (int) config('shield.premium.cache_ttl', 86400),
        );
    }

    /**
     * Persist with an explicit TTL. Used by persistGraceOrInvalid to give
     * a short retry window when no prior valid check exists, preventing
     * the new-install Central-outage trap where a fresh buyer gets stuck
     * in STATE_INVALID for 24h after a transient API blip at install time.
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function persistWithTtl(array $state, int $ttlSeconds): array
    {
        $this->cache->put($this->cacheKey(), $state, $ttlSeconds);
        return $state;
    }

    private function licenseKey(): ?string
    {
        $key = config('shield.premium.license_key');
        $key = is_string($key) ? trim($key) : null;

        return $key !== null && $key !== '' ? $key : null;
    }

    private function cacheKey(): string
    {
        return (string) config('shield.premium.cache_key', 'shield.premium.license');
    }

    private function siteUrl(): string
    {
        return (string) config('app.url', 'http://localhost');
    }

    private function packageVersion(): string
    {
        // From src/Services/Premium/LicenseChecker.php → package root is
        // THREE directories up (verified: __DIR__/../../../ = package
        // root). Note: composer.json doesn't ship a "version" field in
        // package.json (it's generated from Git tags), so this typically
        // returns "unknown" anyway, but the path itself is correct.
        $composerJson = __DIR__ . '/../../../composer.json';

        if (is_file($composerJson)) {
            $data = json_decode((string) file_get_contents($composerJson), true);
            if (isset($data['version']) && is_string($data['version'])) {
                return $data['version'];
            }
        }

        return 'unknown';
    }

    private function laravelVersion(): string
    {
        return defined('Illuminate\Foundation\Application::VERSION')
            ? \Illuminate\Foundation\Application::VERSION
            : (class_exists(\Illuminate\Foundation\Application::class)
                ? \Illuminate\Foundation\Application::VERSION
                : 'unknown');
    }
}
