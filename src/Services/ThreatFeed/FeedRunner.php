<?php

namespace OzanKurt\Shield\Services\ThreatFeed;

use OzanKurt\Shield\Contracts\ThreatFeedProvider;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use Throwable;

class FeedRunner
{
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
}
