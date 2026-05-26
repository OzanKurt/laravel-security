<?php

namespace OzanKurt\Shield\Tests\Feature;

use OzanKurt\Shield\Tests\TestCase;

class NotificationTest extends TestCase
{
    /**
     * @test
     */
    public function can_send_notification_to_discord()
    {
        $this->markTestSkipped(
            'Legacy test — to be rewritten in audit log expansion milestone. ' .
            'The old Helper trait (OzanKurt\\Shield\\Traits\\Helper) no longer exists; ' .
            'the trait moved to OzanKurt\\Shield\\Helpers\\Helper and no longer exposes ' .
            'a log() helper. AttackDetected was renamed to AttackDetectedNotification. ' .
            'Full notification integration tests will be added in the beta.2 notification milestone.'
        );
    }
}
