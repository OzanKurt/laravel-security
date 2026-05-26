<?php

namespace OzanKurt\Shield\Listeners;

use OzanKurt\Shield\Events\AttackDetectedEvent;
use OzanKurt\Shield\Notifications\AttackDetectedNotification;
use OzanKurt\Shield\Notifications\Notifiable;
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
