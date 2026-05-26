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
        return (bool) config('shield.live_traffic.real_time.enabled', false);
    }
}
