<?php

namespace OzanKurt\Shield\Tests\Feature;

use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Models\Log;
use OzanKurt\Shield\Notifications\AttackDetectedNotification;
use OzanKurt\Shield\Notifications\Notifiable;
use OzanKurt\Shield\Tests\TestCase;

class NotificationTest extends TestCase
{
    private function makeLog(): Log
    {
        return new Log([
            'middleware' => 'sqli',
            'ip' => '203.0.113.77',
            'url' => 'https://example.com/?q=1%20OR%201=1',
            'user_id' => 0,
        ]);
    }

    /**
     * @test
     */
    public function it_delivers_an_attack_notification_to_the_discord_webhook(): void
    {
        config([
            'shield.notifications.attack_detected.channels' => ['discord'],
            'shield.notification_channels.discord.enabled' => true,
            'shield.notification_channels.discord.webhook_url' => 'https://discord.com/api/webhooks/123/token',
        ]);

        Http::fake([
            'discord.com/*' => Http::response('', 204),
        ]);

        // notifyNow drives the full via() -> DiscordChannel -> DiscordMessage::toArray() -> Http path
        // synchronously, regardless of the ShouldQueue contract.
        (new Notifiable)->notifyNow(new AttackDetectedNotification($this->makeLog()));

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return str_contains($request->url(), 'discord.com/api/webhooks/123/token')
                && isset($payload['embeds'][0])
                && in_array('203.0.113.77', array_column($payload['embeds'][0]['fields'], 'value'), true);
        });
    }

    /**
     * @test
     */
    public function it_sends_nothing_when_every_channel_is_disabled(): void
    {
        config([
            'shield.notifications.attack_detected.channels' => ['mail', 'slack', 'discord'],
            'shield.notification_channels.mail.enabled' => false,
            'shield.notification_channels.slack.enabled' => false,
            'shield.notification_channels.discord.enabled' => false,
        ]);

        Http::fake();

        (new Notifiable)->notifyNow(new AttackDetectedNotification($this->makeLog()));

        Http::assertNothingSent();
    }
}
