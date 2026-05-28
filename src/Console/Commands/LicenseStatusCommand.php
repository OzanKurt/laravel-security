<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Services\Premium\LicenseChecker;

class LicenseStatusCommand extends Command
{
    protected $signature = 'shield:license:status';
    protected $description = 'Show the current premium license state (uses cached value when available)';

    public function handle(LicenseChecker $checker): int
    {
        $state = $checker->state();

        $this->renderState($checker, $state);

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $state
     */
    private function renderState(LicenseChecker $checker, array $state): void
    {
        $stateLabel = match ($state['state'] ?? null) {
            'valid' => '<fg=green>active</>',
            'grace' => '<fg=yellow>grace period</>',
            'invalid' => '<fg=red>invalid</>',
            'no_key' => '<fg=gray>no license key configured</>',
            default => 'unknown',
        };

        $this->newLine();
        $this->line("  Status:        {$stateLabel}");

        if ($checker->hasKey()) {
            $this->line("  Key:           {$checker->maskedKey()}");
        }

        if (! empty($state['plan'])) {
            $this->line("  Plan:          {$state['plan']}");
        }

        if (! empty($state['reason'])) {
            $this->line("  Reason:        {$state['reason']}");
        }

        if (! empty($state['expires_at'])) {
            $this->line("  Expires:       {$state['expires_at']}");
        }

        if (! empty($state['grace_until'])) {
            $this->line("  Grace until:   {$state['grace_until']}");
        }

        if (isset($state['domain_limit'])) {
            $used = $state['domains_used'] ?? '?';
            $this->line("  Domains:       {$used} / {$state['domain_limit']}");
        }

        if (! empty($state['features'])) {
            $this->line('  Features:      ' . implode(', ', $state['features']));
        }

        if (! empty($state['last_checked_at'])) {
            $this->line("  Last checked:  {$state['last_checked_at']}");
        }

        $this->newLine();
    }
}
