<?php

namespace OzanKurt\Security\Listeners;

use OzanKurt\Security\Events\AttackDetectedEvent;
use OzanKurt\Security\Traits\Helper;
use Illuminate\Auth\Events\Failed as Event;

class FailedLoginListener
{
    use Helper;

    public function handle(Event $event): void
    {
        $this->request = request();
        $this->middleware = 'login';
        $this->user_id = 0;

        if ($this->skip($event)) {
            return;
        }

        $this->request['password'] = '[redacted]';

        $log = $this->log();

        event(new AttackDetectedEvent($log));
    }

    public function skip($event): bool
    {
        if ($this->isDisabled()) {
            return true;
        }

        if ($this->isWhitelist()) {
            return true;
        }

        return false;
    }
}
