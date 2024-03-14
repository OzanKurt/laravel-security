<?php

namespace OzanKurt\Security\Firewall\Middleware;

use OzanKurt\Security\Firewall\AbstractMiddleware;
use OzanKurt\Security\Models\Ip as Model;
use Illuminate\Database\QueryException;

class Ip extends AbstractMiddleware
{
    public function check($patterns)
    {
        $isBlocked = false;

        try {
            $ip = config('security.database.ip.model', Model::class);

            $blockedIp = $ip::blocked($this->ip())->first();

            if ($blockedIp) {
                $blockedIp->increment('request_count');
                $isBlocked = true;
            }
        } catch (QueryException $e) {
            // Base table or view not found
            //$isBlocked = ($e->getCode() == '42S02') ? false : true;
        }

        return $isBlocked;
    }
}
