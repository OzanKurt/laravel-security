<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use OzanKurt\Shield\Services\Integrity\IntegrityScanner;

class IntegrityBlessCommand extends Command
{
    protected $signature = 'shield:integrity-bless {--disk=app : Configured integrity disk to bless}';
    protected $description = 'Promote the most recent integrity scan state to the approved known-good baseline.';

    public function handle(IntegrityScanner $scanner): int
    {
        $disk = (string) $this->option('disk');

        try {
            $baseline = $scanner->bless($disk);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Baseline approved for disk [{$disk}]: {$baseline->files_total} files (root {$baseline->root_hash}).");

        return self::SUCCESS;
    }
}
