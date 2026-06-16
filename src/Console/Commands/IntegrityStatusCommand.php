<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Models\IntegrityBaseline;
use OzanKurt\Shield\Models\IntegrityRun;

class IntegrityStatusCommand extends Command
{
    protected $signature = 'shield:integrity-status {--disk=app : Configured integrity disk}';
    protected $description = 'Show the latest integrity run and baseline state for a disk.';

    public function handle(): int
    {
        $disk = (string) $this->option('disk');

        $run = IntegrityRun::where('disk', $disk)->latest('id')->first();
        $baseline = IntegrityBaseline::where('disk', $disk)->latest('id')->first();

        if ($run === null) {
            $this->warn("No integrity runs recorded for disk [{$disk}]. Run `shield:integrity --disk={$disk}`.");
        } else {
            $this->table(['Field', 'Value'], [
                ['Run', '#' . $run->id],
                ['Status', $run->status->name ?? '-'],
                ['Severity', $run->severity->name ?? '-'],
                ['Files total', $run->files_total],
                ['New / Modified / Deleted', "{$run->count_new} / {$run->count_modified} / {$run->count_deleted}"],
                ['Differs from baseline', $run->count_vs_known_good],
                ['Finished', (string) $run->finished_at],
            ]);
        }

        if ($baseline === null) {
            $this->line("Baseline: none established for disk [{$disk}].");
        } else {
            $state = $baseline->signed ? 'approved' : 'provisional (not yet trusted)';
            $this->line("Baseline: {$state}, {$baseline->files_total} files, root {$baseline->root_hash}.");
        }

        return self::SUCCESS;
    }
}
