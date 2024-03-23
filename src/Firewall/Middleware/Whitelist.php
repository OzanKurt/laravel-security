<?php

namespace OzanKurt\Security\Firewall\Middleware;

use OzanKurt\Security\Firewall\AbstractMiddleware;

class Whitelist extends AbstractMiddleware
{
    public function check($patterns)
    {
        return ($this->isWhitelist() === false);
    }
}
