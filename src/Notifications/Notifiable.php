<?php

namespace OzanKurt\Shield\Notifications;

use Illuminate\Notifications\Notifiable as NotifiableTrait;

class Notifiable
{
    use NotifiableTrait;

    public function routeNotificationForMail()
    {
        return config('shield.notifications.mail.to');
    }

    public function routeNotificationForSlack()
    {
        return config('shield.notifications.slack.to');
    }

    public function routeNotificationForDiscord()
    {
        return config('shield.notifications.discord.webhook_url');
    }

    public function getKey()
    {
        return 1;
    }
}
