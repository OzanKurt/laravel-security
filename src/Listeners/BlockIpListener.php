<?php

namespace OzanKurt\Security\Listeners;

use OzanKurt\Security\Events\AttackDetectedEvent;
use OzanKurt\Security\Models\Ip;
use OzanKurt\Security\Models\Log;
use Carbon\Carbon;

class BlockIpListener
{
    /**
     * Handle the event.
     *
     * @param AttackDetected $event
     *
     * @return void
     */
    public function handle(AttackDetectedEvent $event)
    {
        $end = Carbon::now(config('app.timezone'));
        $start = $end->copy()->subSeconds(config('security.middleware.' . $event->log->middleware . '.auto_block.frequency'));

        $log = config('security.database.log.model', Log::class);
        $count = $log::where('ip', $event->log->ip)
                    ->where('middleware', $event->log->middleware)
                    ->whereBetween('created_at', [$start, $end])
                    ->count();

        if ($count < config('security.middleware.' . $event->log->middleware . '.auto_block.attempts')) {
            return;
        }

        $ip = config('security.database.ip.model', Ip::class);
        $ip::create([
            'ip' => $event->log->ip,
            'log_id' => $event->log->id,
        ]);
    }
}
