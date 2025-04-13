<?php

namespace OzanKurt\Security\Firewall\Middleware;

use Illuminate\Http\Request;
use OzanKurt\Security\Enums\IpEntryType;
use OzanKurt\Security\Firewall\AbstractMiddleware;

class Ip extends AbstractMiddleware
{
    public function check($patterns)
    {
        $this->reason = 'ip_blocked';

        $model = config('security.database.ip.model');

        $clientIp = request()->ip();

        $ip = $model::query()
            ->where('ip', $clientIp)
            ->whereIn('entry_type', [IpEntryType::BLACKLIST, IpEntryType::BLOCK])
            ->first();

        if ($ip) {
            $ip->increment('request_count');
            return true;
        }

        return false;
    }
}
