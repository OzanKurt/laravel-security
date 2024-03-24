<?php

namespace OzanKurt\Security\Listeners;

use Illuminate\Auth\Events\Failed as Event;
use OzanKurt\Security\Notifications\Notifiable;
use OzanKurt\Security\Listeners\Traits\ListenerHelper;
use OzanKurt\Security\Notifications\FailedLoginNotification;

class FailedLoginListener
{
    use ListenerHelper;

    public ?string $notification = 'failed_login';

    public function handle(Event $event): void
    {
        $this->request = request();

        if ($this->skip()) {
            return;
        }

        $this->request['password'] = '[redacted]';

        $authLog = $this->authLog(false);

        $shouldSend = false;
        if (static::$shouldSendCallback) {
            $shouldSend = call_user_func(static::$shouldSendCallback, $authLog);
        }

        if (! $shouldSend) {
            return;
        }

        try {
            (new Notifiable)->notify(new FailedLoginNotification($event));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Set a callback that checks if the notification should be sent.
     *
     * @param  \Closure  $callback
     * @return void
     */
    public static function shouldSendCallback($callback)
    {
        static::$shouldSendCallback = $callback;
    }
}
