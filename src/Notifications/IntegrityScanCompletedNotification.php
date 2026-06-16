<?php

namespace OzanKurt\Shield\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;
use OzanKurt\Shield\Models\IntegrityChange;
use OzanKurt\Shield\Models\IntegrityRun;
use OzanKurt\Shield\Models\Lookups\IntegrityChangeType;
use OzanKurt\Shield\Models\Lookups\IntegrityComparisonBasis;
use OzanKurt\Shield\Notifications\Channels\Discord\DiscordChannel;
use OzanKurt\Shield\Notifications\Channels\Discord\DiscordMessage;
use OzanKurt\Shield\Services\Integrity\SeverityColor;

/**
 * The "file integrity scan summary" card: New / Modified / Deleted file groups
 * (per-run delta) plus a known-good drift line, routed to mail / Slack / Discord.
 *
 * Built from bounded queries + the run's persisted counts, never by hydrating
 * the full change set, so a deploy that changes thousands of files cannot OOM
 * the worker rendering the card.
 */
class IntegrityScanCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public IntegrityRun $run
    ) {}

    public function via($notifiable): array
    {
        $channels = [];

        foreach (config('shield.notifications.integrity_changed.channels', []) as $channel) {
            if (! config("shield.notification_channels.{$channel}.enabled")) {
                continue;
            }

            $channels[] = $this->getChannelClass($channel);
        }

        return $channels;
    }

    public function viaQueues(): array
    {
        $queues = [];

        foreach (config('shield.notifications.integrity_changed.channels', []) as $channel) {
            $queues[$this->getChannelClass($channel)] = config(
                "shield.notification_channels.{$channel}.queue",
                config('shield.integrity.queue', 'default')
            );
        }

        return $queues;
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->from(config('shield.notification_channels.mail.from'), config('shield.notification_channels.mail.name'))
            ->subject($this->title() . ' - ' . $this->summary());

        $mail->line('**' . $this->summary() . '**');

        if ($this->driftLine()) {
            $mail->line($this->driftLine());
        }

        foreach (['new' => 'New', 'modified' => 'Modified', 'deleted' => 'Deleted'] as $type => $label) {
            $count = $this->count($type);
            if ($count === 0) {
                continue;
            }
            $mail->line("**{$label} ({$count}):**");
            foreach ($this->pathsFor($type) as $path) {
                $mail->line('- ' . $path);
            }
            if (($more = $count - count($this->pathsFor($type))) > 0) {
                $mail->line("+{$more} more");
            }
        }

        return $mail;
    }

    public function toSlack($notifiable): SlackMessage
    {
        return (new SlackMessage)
            ->from(config('shield.notification_channels.slack.from'), config('shield.notification_channels.slack.emoji'))
            ->to(config('shield.notification_channels.slack.channel'))
            ->content($this->title() . "\n" . $this->summary() . ($this->driftLine() ? "\n" . $this->driftLine() : ''))
            ->attachment(function ($attachment) {
                $attachment->fields([
                    'New' => $this->slackBlock('new'),
                    'Modified' => $this->slackBlock('modified'),
                    'Deleted' => $this->slackBlock('deleted'),
                ]);
            });
    }

    public function toDiscord(): DiscordMessage
    {
        $message = (new DiscordMessage)
            ->from(config('shield.notification_channels.discord.from'), config('shield.notification_channels.discord.from_img'))
            ->url(config('shield.notification_channels.discord.route'))
            ->title($this->title())
            ->description($this->summary() . ($this->driftLine() ? "\n" . $this->driftLine() : ''))
            ->fields(['New (' . $this->count('new') . ')' => $this->discordBlock('new')], false)
            ->fields(['Modified (' . $this->count('modified') . ')' => $this->discordBlock('modified')], false)
            ->fields(['Deleted (' . $this->count('deleted') . ')' => $this->discordBlock('deleted')], false)
            ->timestamp($this->run->started_at ?? now())
            ->footer(config('shield.notification_channels.discord.footer'), config('shield.notification_channels.discord.footer_img'))
            ->color(SeverityColor::hex($this->run->severity->name ?? 'info'));

        return $message;
    }

    public function getChannelClass(string $channel): string
    {
        return match ($channel) {
            'discord' => DiscordChannel::class,
            default => $channel,
        };
    }

    private function title(): string
    {
        $tz = (string) config('shield.integrity.notify.timezone', 'UTC');
        $ts = ($this->run->started_at ?? now())->copy()->setTimezone($tz)->format('Y-m-d H:i') . ' ' . $tz;

        return trans('shield::notifications.integrity_changed.title', [
            'disk' => $this->run->disk,
            'time' => $ts,
        ]);
    }

    private function summary(): string
    {
        return trans('shield::notifications.integrity_changed.summary', [
            'new' => $this->count('new'),
            'modified' => $this->count('modified'),
            'deleted' => $this->count('deleted'),
            'total' => (int) $this->run->files_total,
        ]);
    }

    private function driftLine(): ?string
    {
        $drift = (int) $this->run->count_vs_known_good;
        if ($drift <= 0) {
            return null;
        }

        return trans('shield::notifications.integrity_changed.drift', ['count' => $drift]);
    }

    private function count(string $type): int
    {
        return (int) match ($type) {
            'new' => $this->run->count_new,
            'modified' => $this->run->count_modified,
            'deleted' => $this->run->count_deleted,
            default => 0,
        };
    }

    /** @return string[] up to max_paths_per_group paths for the per-run delta, highest severity first */
    private function pathsFor(string $changeType): array
    {
        $limit = (int) config('shield.integrity.notify.max_paths_per_group', 15);

        $changeTypeId = IntegrityChangeType::where('name', $changeType)->value('id');
        $deltaId = IntegrityComparisonBasis::where('name', 'last_run')->value('id');

        if ($changeTypeId === null || $deltaId === null) {
            return [];
        }

        return IntegrityChange::query()
            ->where('integrity_run_id', $this->run->id)
            ->where('compared_to_id', $deltaId)
            ->where('change_type_id', $changeTypeId)
            ->orderByDesc('severity_id') // critical (highest id) first, never truncated away
            ->orderBy('path')
            ->limit($limit)
            ->pluck('path')
            ->all();
    }

    private function disclosePaths(): bool
    {
        return (bool) config('shield.integrity.notify.disclose_paths_to_external_channels', true);
    }

    private function discordBlock(string $type): string
    {
        if (! $this->disclosePaths()) {
            $n = $this->count($type);

            return $n > 0 ? "{$n} change(s), view in dashboard" : '-';
        }

        $paths = $this->pathsFor($type);
        if (empty($paths)) {
            return '-';
        }

        $text = implode("\n", array_map(fn ($p) => '`' . $p . '`', $paths));

        if (($more = $this->count($type) - count($paths)) > 0) {
            $text .= "\n+{$more} more";
        }

        // Discord embed field values hard-cap at 1024 chars.
        return mb_strlen($text) > 1000 ? mb_substr($text, 0, 990) . "\n…" : $text;
    }

    private function slackBlock(string $type): string
    {
        if (! $this->disclosePaths()) {
            $n = $this->count($type);

            return $n > 0 ? "{$n} change(s), view in dashboard" : '-';
        }

        $paths = $this->pathsFor($type);
        if (empty($paths)) {
            return '-';
        }

        $text = implode("\n", $paths);
        if (($more = $this->count($type) - count($paths)) > 0) {
            $text .= "\n+{$more} more";
        }

        return $text;
    }
}
