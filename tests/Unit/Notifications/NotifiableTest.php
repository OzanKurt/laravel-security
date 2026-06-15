<?php

namespace OzanKurt\Shield\Tests\Unit\Notifications;

use OzanKurt\Shield\Notifications\Notifiable;
use OzanKurt\Shield\Tests\TestCase;

class NotifiableTest extends TestCase
{
    public function test_routes_resolve_from_notification_channels_layer(): void
    {
        config([
            'shield.notification_channels.mail.to' => 'ops@example.com',
            'shield.notification_channels.slack.to' => 'https://hooks.slack.com/services/T/B/x',
            'shield.notification_channels.discord.webhook_url' => 'https://discord.com/api/webhooks/1/abc',
        ]);

        $notifiable = new Notifiable();

        $this->assertSame('ops@example.com', $notifiable->routeNotificationForMail());
        $this->assertSame('https://hooks.slack.com/services/T/B/x', $notifiable->routeNotificationForSlack());
        $this->assertSame('https://discord.com/api/webhooks/1/abc', $notifiable->routeNotificationForDiscord());
    }
}
