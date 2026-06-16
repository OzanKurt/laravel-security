<?php

namespace OzanKurt\Shield\Tests\Unit\Notifications\Channels\Discord;

use OzanKurt\Shield\Notifications\Channels\Discord\DiscordMessage;
use OzanKurt\Shield\Tests\TestCase;

class DiscordMessageTest extends TestCase
{
    public function test_to_array_is_safe_without_a_color_method(): void
    {
        $embed = (new DiscordMessage)->title('Test')->toArray()['embeds'][0];

        // Default gray color, not hexdec(null).
        $this->assertSame(hexdec(DiscordMessage::COLOR_DEFAULT), $embed['color']);
    }

    public function test_footer_icon_uses_the_footer_url(): void
    {
        $embed = (new DiscordMessage)
            ->footer('Shield', 'https://example.com/icon.png')
            ->toArray()['embeds'][0];

        $this->assertSame('https://example.com/icon.png', $embed['footer']['icon_url']);
    }

    public function test_timestamp_defaults_to_an_iso8601_string(): void
    {
        $embed = (new DiscordMessage)->title('Test')->toArray()['embeds'][0];

        $this->assertIsString($embed['timestamp']);
    }
}
