<?php

namespace OzanKurt\Shield\Listeners;

use OzanKurt\Shield\Enums\IpEntryType;
use OzanKurt\Shield\Events\AttackDetectedEvent;
use OzanKurt\Shield\Models\Ip;
use OzanKurt\Shield\Models\Log;
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
        $middleware = $event->log->middleware ?? 'default';

        $start = $end->copy()->subSeconds(config("shield.middleware.{$middleware}.auto_block.frequency"));

        $log = config('shield.database.log.model', Log::class);
        $count = $log::where('ip', $event->log->ip)
                    ->where('middleware', $middleware)
                    ->whereBetween('created_at', [$start, $end])
                    ->count();

        if ($count < config("shield.middleware.{$middleware}.auto_block.attempts")) {
            return;
        }

        $ip = config('shield.database.ip.model', Ip::class);

        $ip::create([
            'ip' => $event->log->ip,
            'log_id' => $event->log->id,
            'entry_type' => IpEntryType::BLOCK,
            'request_count' => 0,
        ]);
    }
}
