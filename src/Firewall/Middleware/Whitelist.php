<?php

namespace OzanKurt\Shield\Firewall\Middleware;

use OzanKurt\Shield\Firewall\AbstractMiddleware;

class Whitelist extends AbstractMiddleware
{
    public function check($patterns)
    {
        return ($this->isWhitelist() === false);
    }
}
