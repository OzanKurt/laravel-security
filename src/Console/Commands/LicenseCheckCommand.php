<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Services\Premium\LicenseChecker;

class LicenseCheckCommand extends Command
{
    protected $signature = 'shield:license:check';
    protected $description = 'Force a fresh check against the Central license API, bypassing the 24h cache';

    public function handle(LicenseChecker $checker): int
    {
        if (! $checker->hasKey()) {
            $this->error('No LS_PREMIUM_LICENSE_KEY configured. Add it to .env and re-run.');
            return self::FAILURE;
        }

        $this->info('Contacting Central license API…');
        $state = $checker->refresh();

        $exit = match ($state['state'] ?? null) {
            'valid' => self::SUCCESS,
            'grace' => self::SUCCESS,
            default => self::FAILURE,
        };

        $this->call('shield:license:status');

        return $exit;
    }
}
