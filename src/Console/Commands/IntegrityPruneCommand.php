<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Models\IntegrityChange;
use OzanKurt\Shield\Models\IntegrityRun;

class IntegrityPruneCommand extends Command
{
    protected $signature = 'shield:integrity-prune';
    protected $description = 'Hard-delete old integrity runs and change rows per the configured retention.';

    public function handle(): int
    {
        $runsDays = (int) config('shield.integrity.retention.runs_days', 90);
        $changesDays = (int) config('shield.integrity.retention.changes_days', 30);

        // Delete old runs together with ALL their change rows (avoids FK violations),
        // then prune leftover old change rows belonging to surviving runs.
        $oldRunIds = IntegrityRun::where('created_at', '<', now()->subDays($runsDays))->pluck('id');

        $deletedChanges = 0;
        if ($oldRunIds->isNotEmpty()) {
            $deletedChanges += IntegrityChange::whereIn('integrity_run_id', $oldRunIds)->forceDelete();
        }

        $deletedRuns = IntegrityRun::whereIn('id', $oldRunIds)->forceDelete();
        $deletedChanges += IntegrityChange::where('created_at', '<', now()->subDays($changesDays))->forceDelete();

        $this->info("Pruned {$deletedRuns} runs and {$deletedChanges} change rows.");

        return self::SUCCESS;
    }
}
