<?php

namespace OzanKurt\Security\Listeners;

use Illuminate\Auth\Events\Login as Event;
use OzanKurt\Security\Notifications\Notifiable;
use OzanKurt\Security\Listeners\Traits\ListenerHelper;
use OzanKurt\Security\Notifications\SuccessfulLoginNotification;

class SuccessfulLoginListener
{
    use ListenerHelper;

    public ?string $notification = 'successful_login';

    /**
     * The callback that checks if the notification should be sent.
     *
     * @var \Closure|null
     */
    public static $shouldSendCallback;

    public function handle(Event $event): void
    {
        $this->request = request();
        $this->user_id = auth()->id() ?: 0;

        if ($this->skip()) {
            return;
        }

        $this->request['password'] = '[redacted]';

        $authLog = $this->authLog(true);

        $shouldSend = false;
        if (static::$shouldSendCallback) {
            $shouldSend = call_user_func(static::$shouldSendCallback, $authLog);
        }

        if (! $shouldSend) {
            return;
        }

        try {
            (new Notifiable)->notify(new SuccessfulLoginNotification($event));
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
