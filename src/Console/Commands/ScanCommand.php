<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Services\Scanner\Scanner;

class ScanCommand extends Command
{
    protected $signature = 'shield:scan {--target=* : Target name(s) to scan (default: all)} {--backend=* : Backend name(s) to use (default: all available)} {--trigger=manual : Trigger source for this run}';
    protected $description = 'Run a scanner pass across the configured targets and backends.';

    public function handle(Scanner $scanner): int
    {
        $targets = $this->option('target') ?: $this->defaultTargets();
        $backends = $this->option('backend') ?: [];
        $trigger = (string) $this->option('trigger');

        $this->info('Starting scan run...');
        $this->line('Targets: ' . implode(', ', $targets));
        $this->line('Backends: ' . (empty($backends) ? '(all available)' : implode(', ', $backends)));

        $run = $scanner->run($targets, $backends, $trigger);

        $this->info("Run #{$run->id} completed.");
        $this->table(
            ['Field', 'Value'],
            [
                ['UUID', $run->uuid],
                ['Files scanned', $run->files_scanned],
                ['Findings total', $run->findings_count],
                ['Findings critical', $run->findings_critical_count],
                ['Started', (string) $run->started_at],
                ['Finished', (string) $run->finished_at],
            ],
        );

        return $run->findings_critical_count > 0 ? 2 : self::SUCCESS;
    }

    /** @return string[] */
    private function defaultTargets(): array
    {
        return ['app_files', 'public_uploads', 'config_drift', 'env_audit', 'recently_modified'];
    }
}
