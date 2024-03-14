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
        // Check if the IP is whitelisted
        $ip = $this->getQuery()
            ->whereIn('entry_type', [IpEntryType::WHITELIST])
            ->first();

        if ($ip) {
            $ip->increment('request_count');

            return true;
        }

        // Check if the IP is blacklisted or blocked
        $ip = $this->getQuery()
            ->whereIn('entry_type', [IpEntryType::BLACKLIST, IpEntryType::BLOCK])
            ->first();

        if ($ip) {
            $ip->increment('request_count');

            return false;
        }

        return true;
    }

    public function getQuery(): Builder
    {
        $model = config('security.database.ip.model');

        return $model::query();
    }
}
