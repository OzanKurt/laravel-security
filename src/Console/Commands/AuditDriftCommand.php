<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Services\Audit\FileDriftDetector;

class AuditDriftCommand extends Command
{
    protected $signature = 'shield:audit-drift {--dry-run : Show findings without writing baseline or emitting audit entries}';

    protected $description = 'Detect file/config drift by comparing current SHA-256 hashes against a stored baseline';

    public function handle(FileDriftDetector $detector): int
    {
        if (! config('shield.audit.drift.enabled', true)) {
            $this->info('File drift detection is disabled (shield.audit.drift.enabled = false).');
            return self::SUCCESS;
        }

        $this->info('Running file drift detection…');

        $findings = $detector->detect();

        if (empty($findings)) {
            $this->info('No drift detected (or baseline was just created on first run).');
            return self::SUCCESS;
        }

        $this->warn(count($findings) . ' drift finding(s) detected and logged:');

        $rows = array_map(
            fn ($f) => [$f['path'], $f['kind'], $f['status']],
            $findings
        );

        $this->table(['Path', 'Kind', 'Status'], $rows);

        return self::SUCCESS;
    }
}
