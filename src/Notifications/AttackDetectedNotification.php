<?php

namespace OzanKurt\Shield\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use OzanKurt\Shield\Notifications\Channels\Discord\DiscordChannel;
use OzanKurt\Shield\Notifications\Channels\Discord\DiscordMessage;

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
     * Create a notification instance.
     *
     * @param  object  $log
     */
    public function __construct($log)
    {
        $this->log = $log;
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

        foreach (config('shield.notifications.attack_detected.channels', []) as $channel) {
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

        foreach (config('shield.notifications.attack_detected.channels', []) as $channel) {
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

        $subject = trans('shield::notifications.attack_detected.mail.subject', [
            'domain' => $domain,
        ]);

        $message = trans('shield::notifications.attack_detected.mail.message', [
            'domain' => $domain,
            'middleware' => ucfirst($this->log->middleware),
            'ip' => $this->log->ip,
            'url' => $this->log->url,
        ]);

        return (new MailMessage)
            ->from(config('shield.notification_channels.mail.from'), config('shield.notification_channels.mail.name'))
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
        $message = trans('shield::notifications.attack_detected.slack.message', [
            'domain' => request()->getHttpHost(),
        ]);

        return (new SlackMessage)
            ->error()
            ->from(config('shield.notification_channels.slack.from'), config('shield.notification_channels.slack.emoji'))
            ->to(config('shield.notification_channels.slack.channel'))
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
        $body = trans('shield::notifications.attack_detected.discord.message', [
            'domain' => request()->getHttpHost(),
        ]);

        $url = preg_replace('/^https?:\/\/[^\/]+/', '', (string) $this->log->url);

        return (new DiscordMessage)
            ->from(config('shield.notification_channels.discord.from'), config('shield.notification_channels.discord.from_img'))
            ->url(config('shield.notification_channels.discord.route'))
            ->title(config('shield.notification_channels.discord.title'))
            ->description($body)
            ->fields([
                'IP' => $this->log->ip,
                'Type' => ucfirst($this->log->middleware),
                'User ID' => $this->log->user_id === 0 ? 'Guest' : $this->log->user_id,
            ], true)
            ->fields([
                'URL' => $url,
            ], false)
            ->timestamp(now())
            ->footer(config('shield.notification_channels.discord.footer'), config('shield.notification_channels.discord.footer_img'))
            ->warning();
    }

    public function getChannelClass(string $channel): string
    {
        return match ($channel) {
            'discord' => DiscordChannel::class,
            default => $channel,
        };
    }
}
