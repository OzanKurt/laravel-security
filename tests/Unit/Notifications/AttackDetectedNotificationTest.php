<?php

namespace OzanKurt\Shield\Tests\Unit\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use OzanKurt\Shield\Models\Log;
use OzanKurt\Shield\Notifications\AttackDetectedNotification;
use OzanKurt\Shield\Notifications\Channels\Discord\DiscordChannel;
use OzanKurt\Shield\Notifications\Channels\Discord\DiscordMessage;
use OzanKurt\Shield\Notifications\Notifiable;
use OzanKurt\Shield\Tests\TestCase;

class AttackDetectedNotificationTest extends TestCase
{
    private function makeLog(): Log
    {
        return new Log([
            'middleware' => 'sqli',
            'ip' => '203.0.113.5',
            'url' => 'https://example.com/login?id=1%20OR%201=1',
            'user_id' => 7,
        ]);
    }

    public function test_via_returns_only_enabled_channels_mapped_to_classes(): void
    {
        config([
            'shield.notifications.attack_detected.channels' => ['mail', 'slack', 'discord'],
            'shield.notification_channels.mail.enabled' => false,
            'shield.notification_channels.slack.enabled' => true,
            'shield.notification_channels.discord.enabled' => true,
        ]);

        $channels = (new AttackDetectedNotification($this->makeLog()))->via(new Notifiable);

        // mail excluded (disabled); discord mapped to its custom channel class.
        $this->assertSame(['slack', DiscordChannel::class], $channels);
    }

    public function test_via_returns_empty_when_no_channel_enabled(): void
    {
        config([
            'shield.notifications.attack_detected.channels' => ['mail', 'slack', 'discord'],
            'shield.notification_channels.mail.enabled' => false,
            'shield.notification_channels.slack.enabled' => false,
            'shield.notification_channels.discord.enabled' => false,
        ]);

        $this->assertSame([], (new AttackDetectedNotification($this->makeLog()))->via(new Notifiable));
    }

    public function test_via_queues_map_channel_classes_to_configured_queue(): void
    {
        config([
            'shield.notifications.attack_detected.channels' => ['slack', 'discord'],
            'shield.notification_channels.slack.queue' => 'alerts',
            'shield.notification_channels.discord.queue' => 'discord-q',
        ]);

        $queues = (new AttackDetectedNotification($this->makeLog()))->viaQueues();

        $this->assertSame('alerts', $queues['slack']);
        $this->assertSame('discord-q', $queues[DiscordChannel::class]);
    }

    public function test_to_mail_builds_a_mail_message(): void
    {
        $mail = (new AttackDetectedNotification($this->makeLog()))->toMail(new Notifiable);

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertNotEmpty($mail->subject);
    }

    public function test_to_slack_builds_a_slack_message(): void
    {
        $slack = (new AttackDetectedNotification($this->makeLog()))->toSlack(new Notifiable);

        $this->assertInstanceOf(SlackMessage::class, $slack);
    }

    public function test_to_discord_builds_a_discord_message(): void
    {
        $discord = (new AttackDetectedNotification($this->makeLog()))->toDiscord();

        $this->assertInstanceOf(DiscordMessage::class, $discord);

        // Fields carry the log data, proving it read real properties (not $this->notifications).
        $embed = $discord->toArray()['embeds'][0];
        $names = array_column($embed['fields'], 'value');
        $this->assertContains('203.0.113.5', $names);
    }
}
