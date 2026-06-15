<?php

namespace OzanKurt\Shield\Notifications;

use Illuminate\Notifications\Notifiable as NotifiableTrait;

class Notifiable
{
    use NotifiableTrait;

    public function routeNotificationForMail()
    {
        return config('shield.notification_channels.mail.to');
    }

    public function routeNotificationForSlack()
    {
        return config('shield.notification_channels.slack.to'); // incoming webhook URL
    }

    public function routeNotificationForDiscord()
    {
        return config('shield.notification_channels.discord.webhook_url');
    }

    public function getKey()
    {
        return 1;
    }
}
