<?php

namespace OzanKurt\Security\Listeners;

use Illuminate\Auth\Events\Login as Event;
use OzanKurt\Security\Notifications\Notifiable;
use OzanKurt\Security\Listeners\Traits\ListenerHelper;
use OzanKurt\Security\Notifications\SuccessfulLoginNotification;

class SuccessfulLoginListener
{
    use ListenerHelper;

    /**
     * The callback that checks if the notification should be sent.
     *
     * @var \Closure|null
     */
    public static ?\Closure $shouldSendCallback;

    public function handle(Event $event): void
    {
        $this->notification = 'successful_login';
        $this->request = request();
        $this->user_id = auth()->id() ?: 0;

        $this->request['password'] = '[redacted]';

        $authLog = $this->authLog(true);

        if ($this->skip()) {
            return;
        }

        $shouldSend = false;
        if (static::$shouldSendCallback) {
            $shouldSend = call_user_func(static::$shouldSendCallback, $authLog);
        }

        if (! $shouldSend) {
            return;
        }

        try {
            (new Notifiable)->notify(new SuccessfulLoginNotification($authLog));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Set a callback that checks if the notification should be sent.
     *
     * @param \Closure $callback
     * @return void
     */
    public static function setShouldSendCallback(\Closure $callback): void
    {
        static::$shouldSendCallback = $callback;
    }
}
