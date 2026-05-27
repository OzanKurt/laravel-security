<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ClamavUpdateCommand extends Command
{
    protected $signature = 'shield:clamav-update {--timeout=300}';
    protected $description = 'Update ClamAV signatures by invoking freshclam.';

    public function handle(): int
    {
        $process = new Process(['freshclam']);
        $process->setTimeout((float) $this->option('timeout'));

        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            $this->error('freshclam exited with code ' . $process->getExitCode());
            return self::FAILURE;
        }

        $this->info('ClamAV signatures updated.');
        return self::SUCCESS;
    }
}
