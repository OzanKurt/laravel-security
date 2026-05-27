<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Services\Scanner\Quarantine;
use Throwable;

class QuarantineRestoreCommand extends Command
{
    protected $signature = 'shield:quarantine-restore {uuid : UUID of the quarantined finding}';
    protected $description = 'Restore a quarantined file back to its original path.';

    public function handle(Quarantine $quarantine): int
    {
        $uuid = (string) $this->argument('uuid');

        try {
            $quarantine->restore($uuid);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Finding {$uuid} restored.");
        return self::SUCCESS;
    }
}
