<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use OzanKurt\Shield\Models\IntegrityRun;
use OzanKurt\Shield\Models\Lookups\IntegrityStatus;

/**
 * Dead-man's-switch: warns when integrity scans have silently stopped running.
 * A security control that quietly stops is indistinguishable from "all clear",
 * so the absence of a recent successful run is itself an alertable signal.
 */
class IntegrityHeartbeatCommand extends Command
{
    protected $signature = 'shield:integrity-heartbeat {--disk=app : Configured integrity disk}';
    protected $description = 'Warn when no successful integrity scan has completed within the freshness window.';

    public function handle(): int
    {
        $disk = (string) $this->option('disk');
        $maxAgeHours = (int) config('shield.integrity.heartbeat.max_age_hours', 26);

        $successIds = IntegrityStatus::whereIn('name', ['completed', 'baseline_established'])->pluck('id');

        $last = IntegrityRun::where('disk', $disk)
            ->whereIn('status_id', $successIds)
            ->latest('id')
            ->first();

        if ($last === null || $last->finished_at === null || $last->finished_at->lt(now()->subHours($maxAgeHours))) {
            $message = "Shield integrity: no successful scan for disk [{$disk}] within {$maxAgeHours}h. Scanning may be disabled or stuck.";
            Log::warning($message);
            $this->warn($message);

            return self::FAILURE;
        }

        $this->info("OK: last successful integrity scan for [{$disk}] finished {$last->finished_at->diffForHumans()}.");

        return self::SUCCESS;
    }
}
