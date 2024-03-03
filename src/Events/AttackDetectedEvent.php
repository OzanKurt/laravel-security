<?php

namespace OzanKurt\Security\Events;

class AttackDetectedEvent
{
    public $log;

    /**
     * Create a new event instance.
     */
    public function __construct($log)
    {
        $this->log = $log;
    }
}
