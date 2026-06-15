<?php

namespace OzanKurt\Shield\Tests\Unit\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Support\Carbon;
use OzanKurt\Shield\Notifications\Channels\Discord\DiscordMessage;
use OzanKurt\Shield\Notifications\Notifiable;
use OzanKurt\Shield\Notifications\SecurityReportNotification;
use OzanKurt\Shield\Tests\TestCase;

class SecurityReportNotificationTest extends TestCase
{
    private function makeNotification(): SecurityReportNotification
    {
        return new SecurityReportNotification(
            [['/var/www/app/config/app.php', 1718000000]],
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-08'),
        );
    }

    public function test_via_returns_enabled_channels_for_the_security_report_event(): void
    {
        config([
            'shield.notifications.security_report.channels' => ['mail'],
            'shield.notification_channels.mail.enabled' => true,
        ]);

        $this->assertSame(['mail'], $this->makeNotification()->via(new Notifiable));
    }

    public function test_via_returns_empty_when_the_channel_is_disabled(): void
    {
        config([
            'shield.notifications.security_report.channels' => ['mail'],
            'shield.notification_channels.mail.enabled' => false,
        ]);

        $this->assertSame([], $this->makeNotification()->via(new Notifiable));
    }

    public function test_via_queues_map_channels_to_configured_queue(): void
    {
        config([
            'shield.notifications.security_report.channels' => ['mail'],
            'shield.notification_channels.mail.queue' => 'reports',
        ]);

        $this->assertSame('reports', $this->makeNotification()->viaQueues()['mail']);
    }

    public function test_to_mail_builds_a_mail_message(): void
    {
        $mail = $this->makeNotification()->toMail(new Notifiable);

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertNotEmpty($mail->subject);
    }

    public function test_to_slack_builds_a_slack_message_without_undefined_properties(): void
    {
        $slack = $this->makeNotification()->toSlack(new Notifiable);

        $this->assertInstanceOf(SlackMessage::class, $slack);
    }

    public function test_to_discord_builds_a_discord_message_without_undefined_properties(): void
    {
        $discord = $this->makeNotification()->toDiscord();

        $this->assertInstanceOf(DiscordMessage::class, $discord);
    }
}
