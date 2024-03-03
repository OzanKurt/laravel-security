<?php

namespace OzanKurt\Security\Commands;

use OzanKurt\Security\Models\Ip;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendSecurityReportNotificationCommand extends Command
{
    protected $signature = 'security:send-security-report-notification';

    public function handle()
    {

    }
}
