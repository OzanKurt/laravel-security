<?php

namespace OzanKurt\Security\Listeners;

use OzanKurt\Security\Events\AttackDetected as Event;
use OzanKurt\Security\Notifications\AttackDetected;
use OzanKurt\Security\Notifications\Notifiable;
use Throwable;

class NotifyUsers
{
    /**
     * Handle the event.
     *
     * @param Event $event
     *
     * @return void
     */
    public function handle(Event $event)
    {
        try {
            (new Notifiable)->notify(new AttackDetected($event->log));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
