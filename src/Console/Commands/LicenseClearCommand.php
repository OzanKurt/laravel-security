<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Services\Premium\LicenseChecker;

class LicenseClearCommand extends Command
{
    protected $signature = 'shield:license:clear';
    protected $description = 'Clear the cached premium license state (forces next isPremium() call to hit Central)';

    public function handle(LicenseChecker $checker): int
    {
        $checker->clearCache();
        $this->info('Premium license cache cleared.');
        return self::SUCCESS;
    }
}
