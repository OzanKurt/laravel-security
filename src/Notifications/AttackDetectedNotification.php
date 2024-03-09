<?php

namespace OzanKurt\Security\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use OzanKurt\Security\Notifications\Channels\Discord\DiscordChannel;
use OzanKurt\Security\Notifications\Channels\Discord\DiscordMessage;

class AttackDetectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The log model.
     *
     * @var object
     */
    public $log;

    /**
     * The notification config.
     */
    public array $notifications;

    /**
     * Create a notification instance.
     *
     * @param  object  $log
     */
    public function __construct($log)
    {
        $this->log = $log;
        $this->notifications = config('security.notifications');
    }

    /**
     * Get the notification's channels.
     *
     * @param  mixed  $notifiable
     * @return array|string
     */
    public function via($notifiable)
    {
        $channels = [];

        foreach ($this->notifications as $channel => $settings) {
            if (empty($settings['enabled'])) {
                continue;
            }

            $channels[] = $this->getChannelClass($channel);
        }

        return $channels;
    }

    /**
     * Get the notification's queues.
     * @return array|string
     */
    public function viaQueues(): array
    {
        return array_map(function ($channel) {
            return $channel['queue'] ?? 'default';
        }, $this->notifications);
    }

    /**
     * Build the mail representation of the notification.
     *
     * @param  mixed  $notifiable
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
     * @param  mixed  $notifiable
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

    public function toDiscord()
    {
        $body = trans('security::notifications.discord.message', [
            'domain' => request()->getHttpHost(),
        ]);

        try {

            return (new DiscordMessage)
                ->from(config('security.notifications.discord.from'), config('security.notifications.discord.from_img'))
                ->url(config('security.notifications.discord.route'))
                ->title(config('security.notifications.discord.title'))
                ->description(config('security.notifications.discord.description'))
                ->fields([
                    'IP' => $this->log->ip,
                    'Type' => ucfirst($this->log->middleware),
                    'User ID' => $this->log->user_id === 0 ? 'Guest' : $this->log->user_id,
                ], true)
                ->fields([
                    'URL' => $this->log->url,
                ], false)
                ->timestamp(now())
                ->footer(config('security.notifications.discord.footer'), config('security.notifications.discord.footer_img'))
                ->warning();
        } catch (\Throwable $exception) {
            dd($exception);
        }
    }

    public function getChannelClass(string $channel): string
    {
        return match ($channel) {
            'discord' => DiscordChannel::class,
            default => $channel,
        };
    }
}
