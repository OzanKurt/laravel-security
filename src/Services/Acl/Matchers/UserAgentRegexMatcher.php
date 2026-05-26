<?php

namespace OzanKurt\Shield\Services\Acl\Matchers;

use Illuminate\Http\Request;

class UserAgentRegexMatcher implements AclMatcher
{
    public function matches(Request $request, string $value): bool
    {
        $ua = $request->userAgent() ?? '';
        return (bool) @preg_match($value, $ua);
    }
}
