<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use OzanKurt\Shield\Services\Premium\CentralClient;
use OzanKurt\Shield\Services\Premium\LicenseChecker;

class HeartbeatCommand extends Command
{
    protected $signature = 'shield:heartbeat {--force : Send heartbeat even if license is inactive}';
    protected $description = 'Send a heartbeat ping to the Central app with summary stats';

    public function handle(LicenseChecker $checker, CentralClient $client): int
    {
        if (! $checker->hasKey()) {
            $this->warn('No premium license key configured — heartbeat skipped.');
            return self::SUCCESS;
        }

        if (! $checker->isPremium() && ! $this->option('force')) {
            $this->warn('Premium license inactive — heartbeat skipped (use --force to override).');
            return self::SUCCESS;
        }

        $stats = $this->collectStats();

        $ok = $client->heartbeat($stats);

        if ($ok) {
            $this->info('Heartbeat sent to Central.');
            return self::SUCCESS;
        }

        $this->warn('Heartbeat failed (Central unreachable or rejected) — see logs.');
        return self::FAILURE;
    }

    /**
     * Gather lightweight summary stats for the Central dashboard. Each
     * count is wrapped in a try/catch because some sites may not have
     * the table at all (Shield can be partially configured during early
     * setup), and a missing-table error should NOT crash the heartbeat.
     *
     * @return array<string,mixed>
     */
    private function collectStats(): array
    {
        return [
            'window_hours' => 1,
            'requests_total' => $this->safeCount('logs', '-1 hour'),
            'blocks_total' => $this->safeCount('logs', '-1 hour', ['action' => 'block']),
            'audit_events_24h' => $this->safeCount('audit_log', '-24 hours'),
            'scanner_findings_24h' => $this->safeCount('scan_findings', '-24 hours'),
            'live_acl_entries' => $this->safeAclCount(),
            'package_version' => $this->packageVersion(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'app_name' => (string) config('app.name'),
        ];
    }

    /**
     * Best-effort row count for the given prefixed table. Returns 0 if
     * the table is missing, the connection is wrong, or any error fires.
     *
     * @param array<string,mixed> $where
     */
    private function safeCount(string $tableSuffix, string $since, array $where = []): int
    {
        try {
            $table = config('shield.database.table_prefix', 'security_') . $tableSuffix;
            $query = DB::connection(config('shield.database.connection'))
                ->table($table)
                ->where('created_at', '>=', now()->modify($since));

            foreach ($where as $col => $val) {
                $query->where($col, $val);
            }

            return (int) $query->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function safeAclCount(): int
    {
        try {
            $table = config('shield.database.table_prefix', 'security_') . 'acl';
            return (int) DB::connection(config('shield.database.connection'))
                ->table($table)
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function packageVersion(): string
    {
        $composerJson = __DIR__ . '/../../../composer.json';

        if (is_file($composerJson)) {
            $data = json_decode((string) file_get_contents($composerJson), true);
            if (isset($data['version']) && is_string($data['version'])) {
                return $data['version'];
            }
        }

        return 'unknown';
    }
}
