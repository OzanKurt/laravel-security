<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Services\Scanner\Backends\ClamAvBackend;

class ClamavStatusCommand extends Command
{
    protected $signature = 'shield:clamav-status';
    protected $description = 'Check ClamAV daemon reachability and version.';

    public function handle(): int
    {
        if (! class_exists(\Xenolope\Quahog\Client::class)) {
            $this->warn('xenolope/quahog is not installed. Run: composer require xenolope/quahog');
            return self::FAILURE;
        }

        $backend = app(ClamAvBackend::class);

        if (! $backend->isAvailable()) {
            $this->warn('ClamAV daemon is not reachable at ' . config('shield.scanner.clamav.socket'));
            $this->line('Set LS_CLAMAV_ENABLED=true and ensure clamd is running.');
            return self::FAILURE;
        }

        $this->info('ClamAV daemon is reachable.');
        $this->line('Socket: ' . config('shield.scanner.clamav.socket'));

        $version = method_exists($backend, 'version') ? $backend->version() : null;
        if ($version) {
            $this->line('Version: ' . $version);
        }

        return self::SUCCESS;
    }
}
