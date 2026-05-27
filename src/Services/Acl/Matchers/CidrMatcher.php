<?php

namespace OzanKurt\Shield\Services\Acl\Matchers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

class CidrMatcher implements AclMatcher
{
    public function matches(Request $request, string $value): bool
    {
        $clientIp = $request->header('CF-Connecting-IP') ?? $request->ip() ?? '';
        if ($clientIp === '') {
            return false;
        }
        return IpUtils::checkIp($clientIp, $value);
    }
}
