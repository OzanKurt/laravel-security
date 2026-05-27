<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Services\Reports\CadenceReportGenerator;

class ReportTestCommand extends Command
{
    protected $signature = 'shield:report-test {--cadence=7_day : daily_digest | 3_day | 7_day | 14_day | 30_day}';
    protected $description = 'Render a cadence report payload to stdout for inspection.';

    public function handle(CadenceReportGenerator $generator): int
    {
        $cadence = (string) $this->option('cadence');
        $payload = $generator->build($cadence);
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
