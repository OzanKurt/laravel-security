<?php

namespace OzanKurt\Security\Listeners;

use OzanKurt\Security\Events\AttackDetectedEvent;
use OzanKurt\Security\Notifications\AttackDetectedNotification;
use OzanKurt\Security\Notifications\Notifiable;
use Throwable;

class AttackDetectedListener
{
    /**
     * Handle the event.
     *
     * @param Event $event
     *
     * @return void
     */
    public function handle(AttackDetectedEvent $event)
    {
        try {
            (new Notifiable)->notify(new AttackDetectedNotification($event->log));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
