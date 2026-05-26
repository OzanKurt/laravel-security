<?php

namespace OzanKurt\Shield\Tests\Feature;

use OzanKurt\Shield\Models\Log;
use OzanKurt\Shield\Traits\Helper;
use OzanKurt\Shield\Tests\TestCase;
use OzanKurt\Shield\Notifications\Notifiable;
use OzanKurt\Shield\Notifications\AttackDetected;

class NotificationTest extends TestCase
{
    use Helper;

    /** @test */
    public function can_send_notification_to_discord()
    {
        $this->request = request();
        $log = $this->log('ip', 0);

        (new Notifiable)->notify(new AttackDetected($log));
    }
}
