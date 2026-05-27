<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Services\ThreatFeed\FeedRunner;

class FeedSyncCommand extends Command
{
    protected $signature = 'shield:feed-sync {--source= : Only run a specific provider}';
    protected $description = 'Sync configured threat feed providers (Spamhaus, AbuseIPDB, MaxMind GeoLite2, OWASP CRS).';

    public function handle(FeedRunner $runner): int
    {
        $only = $this->option('source');

        $results = $runner->runAll($only);

        if (empty($results)) {
            $this->warn('No available feed providers to sync.');
            return self::SUCCESS;
        }

        $rows = array_map(fn ($r) => [
            $r->provider,
            $r->success() ? 'OK' : 'FAIL',
            $r->imported,
            $r->updated,
            $r->error ?? '—',
        ], $results);

        $this->table(['Provider', 'Status', 'Imported', 'Updated', 'Error'], $rows);

        return self::SUCCESS;
    }
}
