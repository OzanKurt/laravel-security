<?php

namespace OzanKurt\Security\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use OzanKurt\Security\Models\AuthLog;

class FailedLoginNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public array $config;

    public function __construct(
        public AuthLog $authLog
    )
    {
        $this->config = config('security.notifications.successful_login');
    }

    /**
     * Get the notification's channels.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function via($notifiable): array
    {
        $channels = [];

        foreach ($this->config['channels'] as $channel => $settings) {
            if (empty($settings['enabled'])) {
                continue;
            }

            $channels[] = $channel;
        }

        return $channels;
    }

    /**
     * Get the notification's queues.
     *
     * @return array
     */
    public function viaQueues(): array
    {
        return array_map(static function ($channel) {
            return $channel['queue'] ?? 'default';
        }, $this->$this->config['channels']);
    }

    /**
     * Build the mail representation of the notification.
     *
     * @param mixed $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $domain = request()->getHttpHost();

        $subject = trans('security::notifications.mail.subject', [
            'domain' => $domain,
        ]);

        $message = trans('security::notifications.mail.message', [
            'domain' => $domain,
            'middleware' => ucfirst($this->log->middleware),
            'ip' => $this->log->ip,
            'url' => $this->log->url,
        ]);

        return (new MailMessage)
            ->from($this->notifications['mail']['from'], $this->notifications['mail']['name'])
            ->subject($subject)
            ->line($message);
    }

    /**
     * Get the Slack representation of the notification.
     *
     * @param mixed $notifiable
     * @return SlackMessage
     */
    public function toSlack($notifiable)
    {
        $message = trans('security::notifications.slack.message', [
            'domain' => request()->getHttpHost(),
        ]);

        return (new SlackMessage)
            ->error()
            ->from($this->notifications['slack']['from'], $this->notifications['slack']['emoji'])
            ->to($this->notifications['slack']['channel'])
            ->content($message)
            ->attachment(function ($attachment) {
                $attachment->fields([
                    'IP' => $this->log->ip,
                    'Type' => ucfirst($this->log->middleware),
                    'User ID' => $this->log->user_id,
                    'URL' => $this->log->url,
                ]);
            });
    }
}
