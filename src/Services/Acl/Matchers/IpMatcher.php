<?php

namespace OzanKurt\Shield\Services\Acl\Matchers;

use Illuminate\Http\Request;

class IpMatcher implements AclMatcher
{
    public function matches(Request $request, string $value): bool
    {
        return $this->resolveClientIp($request) === $value;
    }

    private function resolveClientIp(Request $request): string
    {
        $cf = $request->header('CF-Connecting-IP');
        if ($cf) {
            return $cf;
        }
        return $request->ip() ?? '';
    }
}
