<?php

namespace OzanKurt\Shield\Tests\Unit\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use OzanKurt\Shield\Models\AuthLog;
use OzanKurt\Shield\Notifications\Channels\Discord\DiscordChannel;
use OzanKurt\Shield\Notifications\Channels\Discord\DiscordMessage;
use OzanKurt\Shield\Notifications\FailedLoginNotification;
use OzanKurt\Shield\Notifications\Notifiable;
use OzanKurt\Shield\Tests\TestCase;

class FailedLoginNotificationTest extends TestCase
{
    private function makeAuthLog(): AuthLog
    {
        return new AuthLog([
            'email' => 'attacker@example.com',
            'ip' => '198.51.100.9',
            'user_id' => null,
            'is_successful' => false,
        ]);
    }

    public function test_via_reads_the_failed_login_event_and_returns_enabled_channels(): void
    {
        config([
            'shield.notifications.failed_login.channels' => ['mail', 'slack', 'discord'],
            'shield.notification_channels.mail.enabled' => true,
            'shield.notification_channels.slack.enabled' => false,
            'shield.notification_channels.discord.enabled' => true,
        ]);

        $channels = (new FailedLoginNotification($this->makeAuthLog()))->via(new Notifiable);

        // slack excluded (disabled); discord mapped to its channel class.
        $this->assertSame(['mail', DiscordChannel::class], $channels);
    }

    public function test_via_queues_map_channels_to_configured_queue(): void
    {
        config([
            'shield.notifications.failed_login.channels' => ['mail', 'discord'],
            'shield.notification_channels.mail.queue' => 'mail-q',
            'shield.notification_channels.discord.queue' => 'discord-q',
        ]);

        $queues = (new FailedLoginNotification($this->makeAuthLog()))->viaQueues();

        $this->assertSame('mail-q', $queues['mail']);
        $this->assertSame('discord-q', $queues[DiscordChannel::class]);
    }

    public function test_to_mail_builds_a_mail_message(): void
    {
        $mail = (new FailedLoginNotification($this->makeAuthLog()))->toMail(new Notifiable);

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertNotEmpty($mail->subject);
    }

    public function test_to_slack_builds_a_slack_message(): void
    {
        $slack = (new FailedLoginNotification($this->makeAuthLog()))->toSlack(new Notifiable);

        $this->assertInstanceOf(SlackMessage::class, $slack);
    }

    public function test_to_discord_builds_a_discord_message_with_authlog_data(): void
    {
        $discord = (new FailedLoginNotification($this->makeAuthLog()))->toDiscord();

        $this->assertInstanceOf(DiscordMessage::class, $discord);

        $embed = $discord->toArray()['embeds'][0];
        $values = array_column($embed['fields'], 'value');
        $this->assertContains('198.51.100.9', $values);
        $this->assertContains('attacker@example.com', $values);
    }
}
