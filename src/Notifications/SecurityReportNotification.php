<?php

namespace OzanKurt\Shield\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Support\Carbon;
use OzanKurt\Shield\Notifications\Channels\Discord\DiscordChannel;
use OzanKurt\Shield\Notifications\Channels\Discord\DiscordMessage;

class SecurityReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The configured event key this notification is delivered under.
     */
    protected string $event = 'security_report';

    /**
     * Create a notification instance.
     */
    public function __construct(
        public array $recentlyModifiedFiles,
        public Carbon $start,
        public Carbon $end,
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
        $domain = request()->getSchemeAndHttpHost();

        $subject = trans('shield::notifications.security_report.mail.subject', [
            'domain' => $domain,
        ]);

        $message = trans('shield::notifications.security_report.mail.message', [
            'domain' => "**[$domain]($domain)**",
            'start' => "**{$this->start->format('d/m/Y')}**",
            'end' => "**{$this->end->format('d/m/Y')}**",
        ]);

        return (new MailMessage)
            ->theme('shield::notifications.themes.default')
            ->markdown('shield::notifications.security-report-notification', [
                'message' => $message,
                'recentlyModifiedFiles' => $this->recentlyModifiedFiles,
            ])
            ->from(config('shield.notification_channels.mail.from'), config('shield.notification_channels.mail.name'))
            ->subject($subject)
            ->action('View Security Dashboard', app('shield')->route('dashboard.index'));
    }

    /**
     * Get the Slack representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return SlackMessage
     */
    public function toSlack($notifiable)
    {
        $message = trans('shield::notifications.security_report.slack.message', [
            'domain' => request()->getHttpHost(),
            'start' => $this->start->format('d/m/Y'),
            'end' => $this->end->format('d/m/Y'),
        ]);

        return (new SlackMessage)
            ->from(config('shield.notification_channels.slack.from'), config('shield.notification_channels.slack.emoji'))
            ->to(config('shield.notification_channels.slack.channel'))
            ->content($message);
    }

    public function toDiscord()
    {
        $body = trans('shield::notifications.security_report.discord.message', [
            'domain' => request()->getHttpHost(),
            'start' => $this->start->format('d/m/Y'),
            'end' => $this->end->format('d/m/Y'),
        ]);

        return (new DiscordMessage)
            ->from(config('shield.notification_channels.discord.from'), config('shield.notification_channels.discord.from_img'))
            ->url(config('shield.notification_channels.discord.route'))
            ->title(config('shield.notification_channels.discord.title'))
            ->description($body)
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
