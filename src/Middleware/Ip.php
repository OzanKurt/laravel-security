<?php

namespace OzanKurt\Security\Middleware;

use OzanKurt\Security\Abstracts\Middleware;
use OzanKurt\Security\Models\Ip as Model;
use Illuminate\Database\QueryException;

class Ip extends Middleware
{
    public function check($patterns)
    {
        $isBlocked = false;

        try {
            $ip = config('security.database.ip.model', Model::class);

            $isBlocked = $ip::blocked($this->ip())->exists();
        } catch (QueryException $e) {
            // Base table or view not found
            //$isBlocked = ($e->getCode() == '42S02') ? false : true;
        }

        return $isBlocked;
    }
}
