<?php

namespace OzanKurt\Shield\Tests\Unit\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use OzanKurt\Shield\Models\AuthLog;
use OzanKurt\Shield\Notifications\Channels\Discord\DiscordChannel;
use OzanKurt\Shield\Notifications\Channels\Discord\DiscordMessage;
use OzanKurt\Shield\Notifications\Notifiable;
use OzanKurt\Shield\Notifications\SuccessfulLoginNotification;
use OzanKurt\Shield\Tests\TestCase;

class SuccessfulLoginNotificationTest extends TestCase
{
    private function makeAuthLog(): AuthLog
    {
        return new AuthLog([
            'email' => 'user@example.com',
            'ip' => '192.0.2.20',
            'user_id' => 42,
            'is_successful' => true,
        ]);
    }

    public function test_via_reads_the_successful_login_event_and_returns_enabled_channels(): void
    {
        config([
            'shield.notifications.successful_login.channels' => ['mail', 'slack', 'discord'],
            'shield.notification_channels.mail.enabled' => false,
            'shield.notification_channels.slack.enabled' => true,
            'shield.notification_channels.discord.enabled' => true,
        ]);

        $channels = (new SuccessfulLoginNotification($this->makeAuthLog()))->via(new Notifiable);

        $this->assertSame(['slack', DiscordChannel::class], $channels);
    }

    public function test_via_queues_map_channels_to_configured_queue(): void
    {
        config([
            'shield.notifications.successful_login.channels' => ['slack', 'discord'],
            'shield.notification_channels.slack.queue' => 'slack-q',
            'shield.notification_channels.discord.queue' => 'discord-q',
        ]);

        $queues = (new SuccessfulLoginNotification($this->makeAuthLog()))->viaQueues();

        $this->assertSame('slack-q', $queues['slack']);
        $this->assertSame('discord-q', $queues[DiscordChannel::class]);
    }

    public function test_to_mail_builds_a_mail_message(): void
    {
        $mail = (new SuccessfulLoginNotification($this->makeAuthLog()))->toMail(new Notifiable);

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertNotEmpty($mail->subject);
    }

    public function test_to_slack_builds_a_slack_message(): void
    {
        $slack = (new SuccessfulLoginNotification($this->makeAuthLog()))->toSlack(new Notifiable);

        $this->assertInstanceOf(SlackMessage::class, $slack);
    }

    public function test_to_discord_builds_a_discord_message_with_authlog_data(): void
    {
        $discord = (new SuccessfulLoginNotification($this->makeAuthLog()))->toDiscord();

        $this->assertInstanceOf(DiscordMessage::class, $discord);

        $embed = $discord->toArray()['embeds'][0];
        $values = array_column($embed['fields'], 'value');
        $this->assertContains('192.0.2.20', $values);
        $this->assertContains(42, $values);
    }
}
