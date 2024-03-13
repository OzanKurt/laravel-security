<?php

namespace OzanKurt\Security\Tests\Feature;

use OzanKurt\Security\Models\Log;
use OzanKurt\Security\Traits\Helper;
use OzanKurt\Security\Tests\TestCase;
use OzanKurt\Security\Notifications\Notifiable;
use OzanKurt\Security\Notifications\AttackDetected;

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
