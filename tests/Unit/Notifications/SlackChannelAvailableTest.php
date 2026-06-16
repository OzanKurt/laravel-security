<?php

namespace OzanKurt\Shield\Tests\Unit\Notifications;

use OzanKurt\Shield\Tests\TestCase;

class SlackChannelAvailableTest extends TestCase
{
    public function test_legacy_slack_message_class_is_available(): void
    {
        $this->assertTrue(
            class_exists(\Illuminate\Notifications\Messages\SlackMessage::class),
            'laravel/slack-notification-channel must be installed so the legacy webhook SlackMessage exists.'
        );
    }
}
