<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Models\ScannerRun;
use OzanKurt\Shield\Services\Scanner\Scanner;

class ScanCancelCommand extends Command
{
    protected $signature = 'shield:scan-cancel {run-id : Numeric ID or UUID of a running scan}';
    protected $description = 'Mark a scan run as cancelled.';

    public function handle(Scanner $scanner): int
    {
        $identifier = (string) $this->argument('run-id');

        $run = is_numeric($identifier)
            ? ScannerRun::find((int) $identifier)
            : ScannerRun::query()->where('uuid', $identifier)->first();

        if (! $run) {
            $this->error("Run not found: {$identifier}");
            return self::FAILURE;
        }

        $scanner->cancel($run);
        $this->info("Run #{$run->id} marked as cancelled.");

        return self::SUCCESS;
    }
}
