<?php

namespace OzanKurt\Security\Firewall\Middleware;

use Illuminate\Database\Eloquent\Builder;
use OzanKurt\Security\Enums\IpEntryType;
use OzanKurt\Security\Firewall\AbstractMiddleware;
use OzanKurt\Security\Models\Ip as Model;
use Illuminate\Database\QueryException;

class Ip extends AbstractMiddleware
{
    public function check($patterns)
    {
        $this->reason = 'ip_blocked';

        // Check if the IP is blacklisted or blocked
        $model = config('security.database.ip.model');

        $ip = $model::query()
            ->whereIn('entry_type', [IpEntryType::BLACKLIST, IpEntryType::BLOCK])
            ->first();

        if ($ip) {
            $ip->increment('request_count');

            return true;
        }

        return false;
    }
}
