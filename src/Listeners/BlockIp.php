<?php

namespace OzanKurt\Security\Listeners;

use OzanKurt\Security\Events\AttackDetected;
use OzanKurt\Security\Models\Ip;
use OzanKurt\Security\Models\Log;
use Carbon\Carbon;

class BlockIp
{
    /**
     * Handle the event.
     *
     * @param AttackDetected $event
     *
     * @return void
     */
    public function handle(AttackDetected $event)
    {
        $end = Carbon::now(config('app.timezone'));
        $start = $end->copy()->subSeconds(config('firewall.middleware.' . $event->log->middleware . '.auto_block.frequency'));

        $log = config('firewall.models.log', Log::class);
        $count = $log::where('ip', $event->log->ip)
                    ->where('middleware', $event->log->middleware)
                    ->whereBetween('created_at', [$start, $end])
                    ->count();

        if ($count != config('firewall.middleware.' . $event->log->middleware . '.auto_block.attempts')) {
            return;
        }

        $ip = config('firewall.models.ip', Ip::class);
        $ip::create([
            'ip' => $event->log->ip,
            'log_id' => $event->log->id,
        ]);
    }
}
