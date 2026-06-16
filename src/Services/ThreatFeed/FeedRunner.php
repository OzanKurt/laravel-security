<?php

namespace OzanKurt\Shield\Services\ThreatFeed;

use OzanKurt\Shield\Contracts\ThreatFeedProvider;
use OzanKurt\Shield\Facades\Shield;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use Throwable;

class FeedRunner
{
    /**
     * Feed providers gated behind the premium license. Listed by their
     * name() value. These never run without a valid premium license.
     * The free defaults (OWASP CRS embedded fallback, AbuseIPDB free
     * tier when user supplies their own API key) remain available.
     */
    private const PREMIUM_ONLY_PROVIDERS = [
        'shield_realtime',          // Realtime push from Central
        'maxmind_geoip2_premium',   // GeoIP2 paid DB (not GeoLite2)
        'emerging_threats',         // Proofpoint ET Pro
        'crowdstrike',              // CrowdStrike Falcon X feed
    ];

    /** @param iterable<ThreatFeedProvider> $providers */
    public function __construct(
        private iterable $providers,
        private AuditLogger $audit,
    ) {}

    /** @return array<int, SyncResult> */
    public function runAll(?string $only = null): array
    {
        $results = [];

        foreach ($this->providers as $provider) {
            if ($only && $provider->name() !== $only) continue;
            if (! $provider->isAvailable()) continue;
            if (! $this->isProviderUnlocked($provider)) {
                $this->audit->log('threat_feed.sync_skipped', "Feed sync skipped (premium required): {$provider->name()}", [
                    'severity' => 'low',
                    'meta' => ['reason' => 'premium_license_required', 'provider' => $provider->name()],
                ]);
                continue;
            }

            $this->audit->log('threat_feed.sync_started', "Feed sync started: {$provider->name()}", ['severity' => 'low']);

            try {
                $result = $provider->sync();
                $this->audit->log('threat_feed.sync_completed', "Feed sync completed: {$provider->name()}", [
                    'severity' => 'low',
                    'meta' => $result->toArray(),
                ]);
                $results[] = $result;
            } catch (Throwable $e) {
                $result = new SyncResult($provider->name(), error: $e->getMessage());
                $this->audit->log('threat_feed.sync_failed', "Feed sync failed: {$provider->name()}: {$e->getMessage()}", [
                    'severity' => 'high',
                    'meta' => ['error' => $e->getMessage()],
                ]);
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * Gate check, providers in PREMIUM_ONLY_PROVIDERS require a valid
     * premium license. Other providers always run if they report
     * isAvailable() = true.
     *
     * Falls open if isFeatureAvailable() throws, better to skip a
     * premium provider than to crash the cron job.
     */
    private function isProviderUnlocked(ThreatFeedProvider $provider): bool
    {
        if (! in_array($provider->name(), self::PREMIUM_ONLY_PROVIDERS, true)) {
            return true;
        }

        try {
            return Shield::isFeatureAvailable('premium_threat_feeds');
        } catch (\Throwable) {
            return false;
        }
    }
}
