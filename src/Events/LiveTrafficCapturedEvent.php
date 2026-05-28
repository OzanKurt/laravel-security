<?php

namespace OzanKurt\Shield\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class LiveTrafficCapturedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly array $record) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(config('shield.live_traffic.real_time.channel', 'shield.live-traffic')),
        ];
    }

    public function broadcastAs(): string
    {
        return 'live-traffic.captured';
    }

    public function broadcastWhen(): bool
    {
        if (! (bool) config('shield.live_traffic.real_time.enabled', false)) {
            return false;
        }

        // Realtime live-traffic broadcast is a premium feature. Free tier
        // can poll the /shield/live-traffic page on a timer; only paid
        // sites get the WebSocket push channel. Falls back to no-broadcast
        // when no license is configured.
        try {
            return \OzanKurt\Shield\Facades\Shield::isFeatureAvailable('realtime_live_traffic');
        } catch (\Throwable) {
            return false;
        }
    }
}
