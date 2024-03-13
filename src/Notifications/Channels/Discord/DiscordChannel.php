<?php

namespace OzanKurt\Security\Notifications\Channels\Discord;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

class DiscordChannel
{
    public function send($notifiable, Notification $notification): void
    {
        $discordMessage = $notification->toDiscord(); // @phpstan-ignore-line

        $discordWebhook = $notifiable->routeNotificationForDiscord();

        $response = Http::post($discordWebhook, $discordMessage->toArray());

        $response->throw();
    }
}
