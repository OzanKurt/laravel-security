<?php

namespace OzanKurt\Security\Notifications;

use Illuminate\Notifications\Notifiable as NotifiableTrait;

class Notifiable
{
    use NotifiableTrait;

    public function routeNotificationForMail()
    {
        return config('security.notifications.mail.to');
    }

    public function routeNotificationForSlack()
    {
        return config('security.notifications.slack.to');
    }

    public function getKey()
    {
        return 1;
    }
}
