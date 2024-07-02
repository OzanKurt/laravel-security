<?php

namespace OzanKurt\Security\Listeners;

use Illuminate\Auth\Events\Login as Event;
use OzanKurt\Security\Listeners\Traits\ListenerHelper;
use OzanKurt\Security\Notifications\Notifiable;
use OzanKurt\Security\Notifications\SuccessfulLoginNotification;

class SuccessfulLoginListener
{
    use ListenerHelper;

    /**
     * The callback that checks if the authLog should be recorded.
     */
    private static ?\Closure $shouldRecordCallback;

    /**
     * The callback that checks if the notification should be sent.
     */
    private static ?\Closure $shouldSendCallback;

    public function handle(Event $event): void
    {
        $this->notification = 'successful_login';
        $this->request = request();
        $this->user_id = $event->user?->id;

        $this->request['password'] = '[redacted]';

        if ($this->skip()) {
            return;
        }

        $shouldRecord = false;
        if (static::$shouldRecordCallback) {
            $shouldRecord = call_user_func(static::$shouldRecordCallback, $event);
        }

        if (! $shouldRecord) {
            return;
        }

        $authLog = $this->authLog(true);

        $shouldSend = false;
        if (static::$shouldSendCallback) {
            $shouldSend = call_user_func(static::$shouldSendCallback, $authLog);
        }

        if (! $shouldSend) {
            return;
        }

        try {
            (new Notifiable)->notify(new SuccessfulLoginNotification($authLog));

            $authLog->is_notification_sent = true;
            $authLog->save();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Set a callback that checks if the authLog should be recorded.
     */
    public static function setShouldRecordCallback(\Closure $callback): void
    {
        static::$shouldRecordCallback = $callback;
    }

    /**
     * Set a callback that checks if the notification should be sent.
     */
    public static function setShouldSendCallback(\Closure $callback): void
    {
        static::$shouldSendCallback = $callback;
    }
}
