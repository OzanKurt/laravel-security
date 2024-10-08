<?php

namespace OzanKurt\Security\Commands;

use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use OzanKurt\Security\Notifications\Notifiable;
use OzanKurt\Security\Notifications\SecurityReportNotification;
use Throwable;

class SendSecurityReportNotificationCommand extends Command
{
    protected $signature = 'security:send-security-report-notification';

    public function handle()
    {
        $expression = config('security.notifications.security_report.cron_expression', '');

        if (CronExpression::isValidExpression($expression) === false) {
            $this->error('Invalid cron expression');

            return;
        }

        $expression = new CronExpression($expression);
        $prevRunDate = new Carbon($expression->getPreviousRunDate());
        $currentRunDate = now();

        /** @var \OzanKurt\Security\Security $security */
        $security = app('security');
        $recentlyModifiedFiles = $security->getRecentlyModifiedFiles($prevRunDate, 15, true);

        $notification = new SecurityReportNotification($recentlyModifiedFiles, $prevRunDate, $currentRunDate);

        try {
            (new Notifiable)->notify($notification);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
