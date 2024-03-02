<?php

namespace OzanKurt\Security\Commands;

use OzanKurt\Security\Models\Ip;
use Carbon\Carbon;
use Illuminate\Console\Command;

class UnblockIpsCommand extends Command
{
    protected $signature = 'security:unblock-ips';

    protected $description = 'Unblock ips based on their block period';

    public function handle()
    {
        $now = Carbon::now(config('app.timezone'));

        $ip = config('security.database.ip.model');

        $ip::with('log')->blocked()->each(function ($ip) use ($now) {
            if (empty($ip->log)) {
                return;
            }

            $period = config('security.middleware.' . $ip->log->middleware . '.auto_block.period');

            if ($ip->created_at->addSeconds($period) > $now) {
                return;
            }

            $ip->logs()->delete();
            $ip->delete();
        });
    }
}
