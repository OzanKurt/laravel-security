<?php

namespace OzanKurt\Shield\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;
use OzanKurt\Shield\Models\AuthLog;
use OzanKurt\Shield\Notifications\Channels\Discord\DiscordChannel;
use OzanKurt\Shield\Notifications\Channels\Discord\DiscordMessage;

class SuccessfulLoginNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The configured event key this notification is delivered under.
     */
    protected string $event = 'successful_login';

    public function __construct(
        public AuthLog $authLog
    ) {
    }

    /**
     * Get the notification's channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable): array
    {
        $channels = [];

        foreach (config("shield.notifications.{$this->event}.channels", []) as $channel) {
            if (! config("shield.notification_channels.{$channel}.enabled")) {
                continue;
            }

            $channels[] = $this->getChannelClass($channel);
        }

        return $channels;
    }

    /**
     * Map each configured channel to the queue it should be sent on.
     *
     * Keyed by the same channel identifier `via()` returns (the channel class
     * for Discord, the channel name otherwise) so Laravel can resolve it.
     *
     * @return array
     */
    public function viaQueues(): array
    {
        $queues = [];

        foreach (config("shield.notifications.{$this->event}.channels", []) as $channel) {
            $queues[$this->getChannelClass($channel)] = config("shield.notification_channels.{$channel}.queue", 'default');
        }

        return $queues;
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

        $subject = trans('shield::notifications.successful_login.mail.subject', [
            'domain' => $domain,
        ]);

        $message = trans('shield::notifications.successful_login.mail.message', [
            'domain' => $domain,
        ]);

        return (new MailMessage)
            ->from(config('shield.notification_channels.mail.from'), config('shield.notification_channels.mail.name'))
            ->subject($subject)
            ->line($message)
            ->line('Email: '.$this->authLog->email)
            ->line('IP: '.$this->authLog->ip);
    }

    /**
     * Get the Slack representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return SlackMessage
     */
    public function toSlack($notifiable)
    {
        $message = trans('shield::notifications.successful_login.slack.message', [
            'domain' => request()->getHttpHost(),
        ]);

        return (new SlackMessage)
            ->success()
            ->from(config('shield.notification_channels.slack.from'), config('shield.notification_channels.slack.emoji'))
            ->to(config('shield.notification_channels.slack.channel'))
            ->content($message)
            ->attachment(function ($attachment) {
                $attachment->fields([
                    'Email' => $this->authLog->email,
                    'IP' => $this->authLog->ip,
                    'User ID' => $this->authLog->user_id ?? 'Guest',
                ]);
            });
    }

    public function toDiscord()
    {
        $body = trans('shield::notifications.successful_login.discord.message', [
            'domain' => request()->getHttpHost(),
        ]);

        return (new DiscordMessage)
            ->from(config('shield.notification_channels.discord.from'), config('shield.notification_channels.discord.from_img'))
            ->url(config('shield.notification_channels.discord.route'))
            ->title(config('shield.notification_channels.discord.title'))
            ->description($body)
            ->fields([
                'Email' => $this->authLog->email,
                'IP' => $this->authLog->ip,
                'User ID' => $this->authLog->user_id ?? 'Guest',
            ], true)
            ->timestamp(now())
            ->footer(config('shield.notification_channels.discord.footer'), config('shield.notification_channels.discord.footer_img'))
            ->success();
    }

    public function getChannelClass(string $channel): string
    {
        return match ($channel) {
            'discord' => DiscordChannel::class,
            default => $channel,
        };
    }
}
