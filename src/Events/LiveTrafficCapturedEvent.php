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

        // Opt-out path for self-hosted realtime: an operator running their
        // own Reverb/Pusher backend can keep v1.x broadcast behavior by
        // setting LS_LIVE_TRAFFIC_REQUIRE_PREMIUM=false. The default for
        // fresh installs is true, matching the published feature gating.
        if (! (bool) config('shield.live_traffic.real_time.require_premium', true)) {
            return true;
        }

        // Realtime live-traffic broadcast is a premium feature. Free tier
        // can poll the /shield/live-traffic page on a timer; only paid
        // sites get the WebSocket push channel. Silently returns false
        // on errors so a broken license check doesn't cascade — operators
        // diagnose via /shield/license + /shield/webhook-deliveries.
        try {
            return \OzanKurt\Shield\Facades\Shield::isFeatureAvailable('realtime_live_traffic');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Shield: broadcastWhen feature gate failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
