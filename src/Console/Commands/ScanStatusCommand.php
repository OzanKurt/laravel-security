<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Models\Lookups\ScannerStatus;
use OzanKurt\Shield\Models\ScannerRun;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

class ScanStatusCommand extends Command
{
    protected $signature = 'shield:scan-status {run-id? : Numeric ID or UUID of a run (defaults to latest)}';
    protected $description = 'Show status + summary for a scan run (defaults to the latest run).';

    public function handle(LookupResolver $lookups): int
    {
        $identifier = $this->argument('run-id');

        $run = $identifier
            ? $this->findRun($identifier)
            : ScannerRun::query()->latest('id')->first();

        if (! $run) {
            $this->error($identifier ? "Run not found: {$identifier}" : 'No scan runs found.');
            return self::FAILURE;
        }

        $statusName = $lookups->name(ScannerStatus::class, $run->status_id) ?? 'unknown';

        $this->table(
            ['Field', 'Value'],
            [
                ['ID',                $run->id],
                ['UUID',              $run->uuid],
                ['Status',            $statusName],
                ['Trigger',           json_encode($run->targets)],
                ['Backends',          json_encode($run->backends)],
                ['Files scanned',     $run->files_scanned],
                ['Findings total',    $run->findings_count],
                ['Findings critical', $run->findings_critical_count],
                ['Started',           (string) $run->started_at],
                ['Finished',          (string) $run->finished_at],
                ['Error',             $run->error_message ?: '(none)'],
            ],
        );

        return self::SUCCESS;
    }

    private function findRun(string $identifier): ?ScannerRun
    {
        if (is_numeric($identifier)) {
            return ScannerRun::find((int) $identifier);
        }

        return ScannerRun::query()->where('uuid', $identifier)->first();
    }
}
