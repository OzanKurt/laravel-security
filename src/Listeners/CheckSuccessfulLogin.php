<?php

namespace OzanKurt\Security\Listeners;

use OzanKurt\Security\Traits\Helper;
use Illuminate\Auth\Events\Failed as Event;

class CheckSuccessfulLogin
{
    use Helper;

    public function handle(Event $event): void
    {
        $this->request = request();
        $this->middleware = 'successful_login';
        $this->user_id = 0;

        if ($this->skip($event)) {
            return;
        }

        $this->request['password'] = '[redacted]';

        try {
            (new Notifiable)->notify(new SuccessfulLogin($event));
        } catch (Throwable $e) {
            report($e);
        }
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
