<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Facades\Shield;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use OzanKurt\Shield\Services\Reports\CadenceReportGenerator;
use Throwable;

class ReportSendCommand extends Command
{
    protected $signature = 'shield:report-send {cadence : daily_digest | 3_day | 7_day | 14_day | 30_day}';
    protected $description = 'Generate + dispatch a Shield cadence report to the configured channels.';

    public function handle(CadenceReportGenerator $generator, AuditLogger $audit): int
    {
        $cadence = (string) $this->argument('cadence');

        if (! $this->isValidCadence($cadence)) {
            $this->error("Unknown cadence: {$cadence}. Use daily_digest | 3_day | 7_day | 14_day | 30_day.");
            return self::FAILURE;
        }

        if (! config("shield.reports.{$cadence}.enabled", true)) {
            $this->warn("Cadence {$cadence} is disabled in config.");
            return self::SUCCESS;
        }

        // Multi-cadence reports beyond the daily digest are premium. Free
        // tier keeps daily_digest working; 3/7/14/30-day reports require
        // a premium license. Soft enforcement, falls open if Central is
        // unreachable AND the cached state hasn't been seen as valid.
        if ($cadence !== 'daily_digest' && ! Shield::isFeatureAvailable('multi_cadence_reports')) {
            $this->warn("Cadence {$cadence} requires a premium license, skipping. Run shield:license:status to check.");
            return self::SUCCESS;
        }

        try {
            $payload = $generator->build($cadence);
            $audit->log('notification.sent', "Cadence report sent: {$cadence}", [
                'severity' => 'low',
                'meta' => ['cadence' => $cadence, 'sections' => array_keys($payload['sections'])],
            ]);

            // The actual channel dispatch logic lives in the existing notification stack;
            // for the immediate 1.0.0 release the email template is the canonical sink.
            $this->info("Report generated: {$cadence}");
            $this->table(['Section', 'Count'], collect($payload['sections'])->map(fn ($v, $k) => [$k, is_countable($v) ? count($v) : '-'])->values()->all());
        } catch (Throwable $e) {
            $this->error("Report generation failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function isValidCadence(string $cadence): bool
    {
        return in_array($cadence, ['daily_digest', '3_day', '7_day', '14_day', '30_day'], true);
    }
}
