<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Services\Integrity\IntegrityScanner;

class IntegrityScanCommand extends Command
{
    protected $signature = 'shield:integrity {--disk=app : Configured integrity disk to scan} {--trigger=manual : Trigger source for this run}';
    protected $description = 'Run a file-integrity scan: diff the filesystem against the approved baseline and the previous run.';

    public function handle(IntegrityScanner $scanner): int
    {
        $disk = (string) $this->option('disk');
        $trigger = (string) $this->option('trigger');

        $this->info("Running integrity scan on disk [{$disk}]...");

        $run = $scanner->run($disk, $trigger);
        $status = $run->status->name ?? 'unknown';

        $this->table(['Field', 'Value'], [
            ['UUID', $run->uuid],
            ['Status', $status],
            ['Files total', $run->files_total],
            ['New', $run->count_new],
            ['Modified', $run->count_modified],
            ['Deleted', $run->count_deleted],
            ['Vs known-good', $run->count_vs_known_good],
        ]);

        if ($status === 'baseline_established') {
            $this->warn('Provisional baseline established from the current disk state. It is NOT yet trusted. Review the files, then run `shield:integrity-bless` to approve it.');
        }

        if ($status === 'tamper_suspected') {
            $this->error('Baseline signature did not verify. Possible tampering. The baseline was NOT rebuilt.');

            return 2;
        }

        return self::SUCCESS;
    }
}
